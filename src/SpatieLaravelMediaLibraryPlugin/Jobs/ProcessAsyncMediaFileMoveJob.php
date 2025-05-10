<?php

namespace Filament\SpatieLaravelMediaLibraryPlugin\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Config;
use Illuminate\Container\Container;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;
use Throwable;

class ProcessAsyncMediaFileMoveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Количество попыток выполнения задания.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Идентификатор медиа-файла для перемещения.
     *
     * @var int
     */
    protected $mediaId;

    /**
     * Создание нового экземпляра задания.
     *
     * @param int $mediaId
     * @return void
     */
    public function __construct(int $mediaId)
    {
        $this->mediaId = $mediaId;
    }

    /**
     * Статический метод для синхронного выполнения процесса перемещения файла.
     * Используется для оптимизации, если исходный и целевой диски одинаковы.
     *
     * @param int $mediaId
     * @return void
     */
    public static function processSynchronously(int $mediaId): void
    {
        $job = new static($mediaId);
        $job->handle();
    }

    /**
     * Выполнение задания.
     *
     * @return void
     */
    public function handle(): void
    {
        echo "🚀 Начинаем асинхронное перемещение файла (ID: {$this->mediaId})\n";
        Log::info("Начинаем асинхронное перемещение файла", ['media_id' => $this->mediaId]);

        try {
            // Получаем запись медиа
            echo "📄 Получаем информацию о медиа-записи...\n";
            $mediaClass = Config::get('media-library.media_model', Media::class);
            $media = $mediaClass::find($this->mediaId);

            if (!$media) {
                echo "❌ Медиа не найдено (ID: {$this->mediaId})\n";
                Log::error('Медиа не найдено для асинхронного перемещения', [
                    'media_id' => $this->mediaId,
                ]);
                return;
            }

            echo "✅ Медиа найдено: {$media->file_name} (ID: {$media->id})\n";

            // Проверяем наличие временных данных в кастомных свойствах
            if (
                !$media->hasCustomProperty('path') ||
                !$media->hasCustomProperty('disk')
            ) {
                echo "❌ Отсутствуют необходимые данные в кастомных свойствах\n";
                Log::error('Отсутствуют необходимые данные для асинхронного перемещения', [
                    'media_id' => $this->mediaId,
                    'custom_properties' => $media->custom_properties,
                ]);
                return;
            }

            // Получаем информацию о временном файле
            $tempDisk = $media->getCustomProperty('disk');
            $tempPath = $media->getCustomProperty('path');

            echo "📁 Временный файл: {$tempDisk}:{$tempPath}\n";
            Log::info("Информация о временном файле", [
                'media_id' => $this->mediaId,
                'temp_disk' => $tempDisk,
                'temp_path' => $tempPath
            ]);

            // Проверяем, существует ли временный файл
            echo "🔍 Проверяем существование временного файла...\n";
            if (!Storage::disk($tempDisk)->exists($tempPath)) {
                echo "❌ Временный файл не найден: {$tempDisk}:{$tempPath}\n";
                Log::error('Временный файл не найден для асинхронного перемещения', [
                    'media_id' => $this->mediaId,
                    'temp_disk' => $tempDisk,
                    'temp_path' => $tempPath,
                ]);
                return;
            }
            echo "✅ Временный файл существует\n";

            // Определяем целевой диск
            echo "🔍 Определяем целевой диск для хранения...\n";
            $finalDisk = $media->getCustomProperty('disk', null);
            if (!$finalDisk) {
                // Если диск не определен, используем диск по умолчанию
                $collection = $media->collection_name ?? 'default';
                $model = Container::getInstance()->make($media->model_type);
                $finalDisk = $model->getMediaCollection($collection)->diskName ?? Config::get('media-library.disk_name', 's3');

                if (!$finalDisk) {
                    echo "❌ Не удалось определить целевой диск\n";
                    Log::error('Не удалось определить целевой диск для асинхронного перемещения', [
                        'media_id' => $this->mediaId,
                        'collection' => $collection,
                    ]);
                    return;
                }
            }
            echo "✅ Целевой диск: {$finalDisk}\n";

            // --- Исправление вычисления целевой директории ---
            // Нам нужно скопировать файл из временного livewire-tmp в папку с ID медиа,
            // а не создавать вложенную структуру livewire-tmp/...
            // Поэтому игнорируем custom property `path`, если она существует.
            $prefix = Config::get('media-library.prefix', '');
            $baseDirectory = $media->getKey();
            if ($prefix !== '') {
                $baseDirectory = $prefix . '/' . $baseDirectory;
            }
            $finalDirectory = rtrim($baseDirectory, '/') . '/';

            // Имя файла остаётся тем же
            $filename = $media->file_name;
            $finalPath = $finalDirectory . $filename;

            echo "📁 Целевой путь: {$finalDisk}:{$finalPath}\n";

            Log::info("Копирование файла", [
                'media_id' => $this->mediaId,
                'from' => "{$tempDisk}:{$tempPath}",
                'to' => "{$finalDisk}:{$finalPath}",
                'size' => Storage::disk($tempDisk)->size($tempPath)
            ]);

            // Копируем файл из временного хранилища в целевое
            // Оптимизация для S3: используем прямое копирование на стороне сервера вместо загрузки файла в память
            if ($this->isS3Disk($tempDisk) && $this->isS3Disk($finalDisk)) {
                echo "☁️ Используем прямое копирование S3 -> S3...\n";
                try {
                    $this->copyBetweenS3($tempDisk, $tempPath, $finalDisk, $finalPath);
                    echo "✅ S3 копирование успешно завершено\n";
                } catch (\Exception $e) {
                    echo "❌ Ошибка при S3 копировании: " . $e->getMessage() . "\n";
                    throw $e;
                }
            } else {
                echo "📤 Используем потоковую передачу между дисками...\n";
                try {
                    $this->copyUsingStreams($tempDisk, $tempPath, $finalDisk, $finalPath);
                    echo "✅ Потоковое копирование успешно завершено\n";
                } catch (\Exception $e) {
                    echo "❌ Ошибка при потоковом копировании: " . $e->getMessage() . "\n";
                    throw $e;
                }
            }

            // Проверяем, существует ли файл по новому пути
            echo "🔍 Проверяем, что файл успешно скопирован...\n";
            if (Storage::disk($finalDisk)->exists($finalPath)) {
                $fileSize = Storage::disk($finalDisk)->size($finalPath);
                echo "✅ Файл успешно скопирован в {$finalDisk}:{$finalPath} (размер: " . $this->formatBytes($fileSize) . ")\n";

                // Удаляем временный файл после успешного копирования и проверки
                echo "🗑️ Удаляем временный файл {$tempDisk}:{$tempPath}...\n";
                $deleteResult = Storage::disk($tempDisk)->delete($tempPath);

                if ($deleteResult) {
                    echo "✅ Временный файл успешно удален\n";
                } else {
                    echo "⚠️ Не удалось удалить временный файл, но копирование прошло успешно\n";
                    Log::warning('Не удалось удалить временный файл после асинхронного перемещения', [
                        'media_id' => $this->mediaId,
                        'temp_disk' => $tempDisk,
                        'temp_path' => $tempPath,
                    ]);
                }
            } else {
                echo "⚠️ Не удалось найти скопированный файл по пути {$finalDisk}:{$finalPath}. Временный файл не будет удален.\n";
                Log::warning('Файл не обнаружен по целевому пути после копирования', [
                    'media_id' => $this->mediaId,
                    'target_disk' => $finalDisk,
                    'target_path' => $finalPath,
                ]);
            }

            // Обновляем запись медиа
            echo "📝 Обновляем запись медиа...\n";
            $customProperties = $media->custom_properties;

            echo "🔍 Текущие custom_properties: " . json_encode($customProperties) . "\n";

            // Удаляем временные свойства
            unset($customProperties['original_filename']);
            unset($customProperties['is_processing_async']);
            // Также удаляем свойства пути и диска, т.к. они относятся к временному файлу
            // Важно! Удаляем свойства path и disk, чтобы в дальнейшем медиа использовало
            // стандартные механизмы определения пути через PathGenerator
            unset($customProperties['path']);
            unset($customProperties['disk']);

            echo "🔄 Обновленные custom_properties: " . json_encode($customProperties) . "\n";

            // Делаем файл публичным
            echo "🔓 Устанавливаем публичный доступ к файлу...\n";
            if (method_exists($media, 'markAsPubliclyAccessible')) {
                $media->markAsPubliclyAccessible();
                echo "✅ Файл отмечен как публично доступный через markAsPubliclyAccessible()\n";
            } else {
                // Альтернативный способ, если метод не существует
                // Убедимся, что файл доступен публично на соответствующем диске
                try {
                    // Проверяем, является ли диск S3
                    if ($this->isS3Disk($finalDisk)) {
                        echo "☁️ Обнаружен S3 диск, настраиваем публичный доступ...\n";

                        // Получаем S3 клиент и информацию о бакете
                        $s3Client = Storage::disk($finalDisk)->getClient();
                        $bucket = config("filesystems.disks.{$finalDisk}.bucket");

                        // Проверяем, есть ли кастомный endpoint (Ceph/MinIO). Если он есть, то пропускаем проверку
                        $endpoint = config("filesystems.disks.{$finalDisk}.endpoint");

                        if (!$endpoint) {
                            // Проверяем, есть ли блокировка публичного доступа на уровне бакета
                            try {
                                $blockConfig = $s3Client->getPublicAccessBlock([
                                    'Bucket' => $bucket,
                                ]);
                                $isBlocked = $blockConfig['BlockPublicAcls'] ?? false;

                                if ($isBlocked) {
                                    echo "⚠️ Публичный доступ заблокирован на уровне бакета S3. ACL не будут применены.\n";
                                    Log::info('Публичный доступ заблокирован на уровне бакета S3', [
                                        'media_id' => $this->mediaId,
                                        'bucket' => $bucket
                                    ]);
                                    // Всё равно пробуем установить видимость, т.к. это может работать через политики бакета
                                }
                            } catch (\Throwable $e) {
                                // Если не удалось получить настройки блокировки, продолжаем
                                echo "⚠️ Не удалось проверить настройки блокировки публичного доступа: " . $e->getMessage() . "\n";
                            }
                        }

                        // Пробуем установить публичный доступ через Laravel Storage
                        Storage::disk($finalDisk)->setVisibility($finalPath, 'public');
                        echo "✅ Публичный доступ установлен через setVisibility() для S3\n";

                        // Дополнительно проверяем, действительно ли изменилась видимость
                        $visibility = Storage::disk($finalDisk)->getVisibility($finalPath);
                        echo "📊 Текущая видимость файла: {$visibility}\n";

                        // Если видимость не public, возможно, блокировка переопределила настройки
                        if ($visibility !== 'public') {
                            echo "⚠️ Файл не имеет публичной видимости. Возможно, из-за настроек бакета S3.\n";
                            Log::warning('Не удалось установить публичную видимость для S3 файла', [
                                'media_id' => $this->mediaId,
                                'final_disk' => $finalDisk,
                                'final_path' => $finalPath,
                                'current_visibility' => $visibility
                            ]);
                        }
                    } else {
                        // Для не-S3 дисков просто устанавливаем видимость
                        Storage::disk($finalDisk)->setVisibility($finalPath, 'public');
                        echo "✅ Публичный доступ установлен через setVisibility()\n";
                    }
                } catch (\Throwable $e) {
                    echo "⚠️ Не удалось установить публичный доступ через setVisibility(): " . $e->getMessage() . "\n";
                    Log::warning('Не удалось установить публичный доступ к файлу', [
                        'media_id' => $this->mediaId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Обновляем запись медиа
            $media->custom_properties = $customProperties;
            $media->disk = $finalDisk;
            $media->save();

            // Проверяем, что изменения сохранились
            $refreshedMedia = $mediaClass::find($this->mediaId);
            echo "✅ Проверка после сохранения. Custom properties: " . json_encode($refreshedMedia->custom_properties) . "\n";
            echo "✅ Финальный диск: " . $refreshedMedia->disk . "\n";

            // Не вызываем regenerateAllDerivedFiles(), так как этот метод не существует

            echo "🎉 Файл успешно перемещен асинхронно\n";
            Log::info('Файл успешно перемещен асинхронно', [
                'media_id' => $this->mediaId,
                'from' => "{$tempDisk}:{$tempPath}",
                'to' => "{$finalDisk}:{$finalPath}",
            ]);
        } catch (Throwable $e) {
            echo "❌ ОШИБКА: " . $e->getMessage() . "\n";
            Log::error('Ошибка при асинхронном перемещении файла', [
                'media_id' => $this->mediaId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Пробрасываем исключение для повторного выполнения задания
            throw $e;
        }
    }

    /**
     * Проверяет, является ли диск типом S3.
     *
     * @param string $diskName
     * @return bool
     */
    protected function isS3Disk(string $diskName): bool
    {
        $driver = config("filesystems.disks.{$diskName}.driver");
        return $driver === 's3';
    }

    /**
     * Копирует файл между двумя S3 дисками используя операцию копирования AWS на стороне сервера.
     * Это не загружает данные на сервер, что оптимально для больших файлов.
     *
     * @param string $sourceDisk
     * @param string $sourcePath
     * @param string $targetDisk
     * @param string $targetPath
     * @return void
     */
    protected function copyBetweenS3(string $sourceDisk, string $sourcePath, string $targetDisk, string $targetPath): void
    {
        // Получаем S3 клиенты для обоих дисков
        echo "  📊 Получаем клиенты S3...\n";
        $s3Source = Storage::disk($sourceDisk)->getClient();
        $s3Target = Storage::disk($targetDisk)->getClient();

        // Получаем информацию о бакетах
        echo "  📊 Получаем информацию о бакетах...\n";
        $sourceBucket = config("filesystems.disks.{$sourceDisk}.bucket");
        $targetBucket = config("filesystems.disks.{$targetDisk}.bucket");

        echo "  📊 Исходный бакет: {$sourceBucket}, Целевой бакет: {$targetBucket}\n";

        // Если это один и тот же бакет, используем простое копирование
        if ($sourceBucket === $targetBucket && $s3Source === $s3Target) {
            echo "  📊 Копирование внутри одного бакета: {$sourceBucket}\n";
            $s3Source->copyObject([
                'Bucket' => $targetBucket,
                'CopySource' => urlencode($sourceBucket . '/' . $sourcePath),
                'Key' => $targetPath,
            ]);
        } else {
            // Если разные бакеты, используем объект источника как источник копирования
            echo "  📊 Копирование между разными бакетами: {$sourceBucket} -> {$targetBucket}\n";
            $s3Target->copyObject([
                'Bucket' => $targetBucket,
                'CopySource' => urlencode($sourceBucket . '/' . $sourcePath),
                'Key' => $targetPath,
            ]);
        }
        echo "  ✅ S3 операция копирования выполнена\n";
    }

    /**
     * Копирует файл между дисками с использованием потоковой передачи.
     * Это не загружает весь файл в память одновременно.
     *
     * @param string $sourceDisk
     * @param string $sourcePath
     * @param string $targetDisk
     * @param string $targetPath
     * @return void
     */
    protected function copyUsingStreams(string $sourceDisk, string $sourcePath, string $targetDisk, string $targetPath): void
    {
        // Открываем поток для чтения из исходного файла
        echo "  📊 Открываем поток для чтения из {$sourceDisk}:{$sourcePath}...\n";
        $sourceStream = Storage::disk($sourceDisk)->readStream($sourcePath);

        if ($sourceStream === false) {
            echo "  ❌ Не удалось открыть поток для чтения\n";
            throw new \RuntimeException("Не удалось открыть поток для чтения из {$sourceDisk}:{$sourcePath}");
        }
        echo "  ✅ Поток успешно открыт\n";

        // Записываем поток в целевой файл
        echo "  📊 Записываем поток в {$targetDisk}:{$targetPath}...\n";
        $success = Storage::disk($targetDisk)->writeStream($targetPath, $sourceStream);

        if (is_resource($sourceStream)) {
            echo "  📊 Закрываем исходный поток...\n";
            fclose($sourceStream);
        }

        if (!$success) {
            echo "  ❌ Не удалось записать файл\n";
            throw new \RuntimeException("Не удалось записать файл в {$targetDisk}:{$targetPath}");
        }

        echo "  ✅ Поток успешно записан в целевой файл\n";
    }

    /**
     * Форматирует размер файла в человекочитаемый вид
     *
     * @param int $bytes
     * @param int $precision
     * @return string
     */
    protected function formatBytes($bytes, $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
