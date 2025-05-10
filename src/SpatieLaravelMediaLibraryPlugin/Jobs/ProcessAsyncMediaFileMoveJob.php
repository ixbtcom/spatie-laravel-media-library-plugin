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
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Illuminate\Support\Facades\Event;
use Throwable;
use App\Services\BunnyCdnVideoServiceV2;

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
        $this->onQueue('default');
    }

    /**
     * Выполнение задания.
     *
     * @return void
     */
    public function handle(): void
    {
        Log::info("Начинаем асинхронное перемещение файла", ['media_id' => $this->mediaId]);

        try {
            // Находим запись Media
            $media = Media::find($this->mediaId);

            if (!$media) {
                throw new \Exception("Media не найдена! ID: {$this->mediaId}");
            }

            Log::debug('Media найдена. Файл для перемещения', [
                'media_id' => $this->mediaId,
                'file_name' => $media->file_name,
                'size' => $this->formatBytes($media->size)
            ]);

            // Проверяем наличие временных данных в кастомных свойствах
            if (
                !$media->hasCustomProperty('path') ||
                !$media->hasCustomProperty('disk')
            ) {
                Log::error('Отсутствуют необходимые данные для асинхронного перемещения', [
                    'media_id' => $this->mediaId,
                    'custom_properties' => $media->custom_properties,
                ]);
                return;
            }

            // Получаем информацию о временном файле
            $tempDisk = $media->getCustomProperty('disk');
            $tempPath = $media->getCustomProperty('path');

            Log::info("Информация о временном файле", [
                'media_id' => $this->mediaId,
                'temp_disk' => $tempDisk,
                'temp_path' => $tempPath
            ]);

            // Проверяем, существует ли временный файл
            if (!Storage::disk($tempDisk)->exists($tempPath)) {
                Log::error('Временный файл не найден для асинхронного перемещения', [
                    'media_id' => $this->mediaId,
                    'temp_disk' => $tempDisk,
                    'temp_path' => $tempPath,
                ]);
                return;
            }

            // Сначала делаем временный файл публичным, сразу же
            // это позволит сразу использовать файл до завершения других операций
            if ($this->isS3Disk($tempDisk)) {
                Storage::disk($tempDisk)->setVisibility($tempPath, 'public');

                // Проверяем видимость
                $visibility = Storage::disk($tempDisk)->getVisibility($tempPath);

                // Если видимость не установлена, это критическая ошибка - останавливаем процесс
                if ($visibility !== 'public') {
                    Log::warning("Не удалось установить публичную видимость для временного файла: {$tempDisk}:{$tempPath}");
                    // Продолжаем выполнение, несмотря на ошибку видимости, т.к. это некритично
                }
            } else {
                // Для не-S3 дисков просто устанавливаем видимость
                Storage::disk($tempDisk)->setVisibility($tempPath, 'public');
            }

            // Определяем целевой диск
            $finalDisk = $media->getCustomProperty('disk', null);
            if (!$finalDisk) {
                // Если диск не определен, используем диск по умолчанию
                $collection = $media->collection_name ?? 'default';
                $model = Container::getInstance()->make($media->model_type);
                $finalDisk = $model->getMediaCollection($collection)->diskName ?? Config::get('media-library.disk_name', 's3');

                if (!$finalDisk) {
                    Log::error('Не удалось определить целевой диск для асинхронного перемещения', [
                        'media_id' => $this->mediaId,
                        'collection' => $collection,
                    ]);
                    return;
                }
            }

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

            Log::info("Копирование файла", [
                'media_id' => $this->mediaId,
                'from' => "{$tempDisk}:{$tempPath}",
                'to' => "{$finalDisk}:{$finalPath}",
                'size' => Storage::disk($tempDisk)->size($tempPath)
            ]);

            // 1. КОПИРУЕМ ФАЙЛ из временного хранилища в целевое
            // Оптимизация для S3: используем прямое копирование на стороне сервера вместо загрузки файла в память
            if ($this->isS3Disk($tempDisk) && $this->isS3Disk($finalDisk)) {
                try {
                    $this->copyBetweenS3($tempDisk, $tempPath, $finalDisk, $finalPath);
                } catch (\Exception $e) {
                    throw $e;
                }
            } else {
                try {
                    $this->copyUsingStreams($tempDisk, $tempPath, $finalDisk, $finalPath);
                } catch (\Exception $e) {
                    throw $e;
                }
            }

            // Проверяем, существует ли файл по новому пути
            if (Storage::disk($finalDisk)->exists($finalPath)) {
                $fileSize = Storage::disk($finalDisk)->size($finalPath);
                Log::info("Файл успешно скопирован", [
                    'media_id' => $this->mediaId,
                    'path' => "{$finalDisk}:{$finalPath}",
                    'size' => $this->formatBytes($fileSize)
                ]);

                // 2. ДЕЛАЕМ ФАЙЛ ПУБЛИЧНЫМ - без try/catch и без проверки method_exists
                if ($this->isS3Disk($finalDisk)) {
                    Storage::disk($finalDisk)->setVisibility($finalPath, 'public');

                    // Проверяем видимость
                    $visibility = Storage::disk($finalDisk)->getVisibility($finalPath);

                    // Если видимость не установлена, это критическая ошибка - останавливаем процесс
                    if ($visibility !== 'public') {
                        throw new \RuntimeException("Не удалось установить публичную видимость для файла: {$finalDisk}:{$finalPath}");
                    }
                } else {
                    // Для не-S3 дисков просто устанавливаем видимость
                    Storage::disk($finalDisk)->setVisibility($finalPath, 'public');
                }

                // 3. ОБНОВЛЯЕМ МОДЕЛЬ И УСТАНАВЛИВАЕМ СТАТУС - только после публичного доступа

                // Удаляем временные свойства
                $media->forgetCustomProperty('original_filename');
                $media->forgetCustomProperty('is_processing_async');
                $media->forgetCustomProperty('path');
                $media->forgetCustomProperty('disk');

                // Устанавливаем статус 'uploaded' для файла
                $media->setCustomProperty('status', 'uploaded');
                Log::info('Установлен статус "uploaded" для файла', [
                    'media_id' => $this->mediaId,
                    'mime_type' => $media->mime_type,
                    'collection' => $media->collection_name,
                    'size' => $media->size
                ]);

                // Обновляем запись медиа
                $media->disk = $finalDisk;
                $media->save();

                // Вызываем событие MediaHasBeenAddedEvent для запуска обработчиков медиа-файла
                Event::dispatch(new MediaHasBeenAddedEvent($media));

                // 4. УДАЛЯЕМ ВРЕМЕННЫЙ ФАЙЛ - только после успешного обновления модели
                $deleteResult = Storage::disk($tempDisk)->delete($tempPath);

                if (!$deleteResult) {
                    Log::warning('Не удалось удалить временный файл после асинхронного перемещения', [
                        'media_id' => $this->mediaId,
                        'temp_disk' => $tempDisk,
                        'temp_path' => $tempPath,
                    ]);
                }

                Log::info('Файл успешно перемещен асинхронно', [
                    'media_id' => $this->mediaId,
                    'from' => "{$tempDisk}:{$tempPath}",
                    'to' => "{$finalDisk}:{$finalPath}",
                ]);
            } else {
                Log::error('Файл не обнаружен по целевому пути после копирования', [
                    'media_id' => $this->mediaId,
                    'target_disk' => $finalDisk,
                    'target_path' => $finalPath,
                ]);
                throw new \RuntimeException("Файл не найден по целевому пути: {$finalDisk}:{$finalPath}");
            }
        } catch (Throwable $e) {
            Log::error('Ошибка при асинхронном перемещении файла', [
                'media_id' => $this->mediaId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
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
        $s3Source = Storage::disk($sourceDisk)->getClient();
        $s3Target = Storage::disk($targetDisk)->getClient();

        // Получаем информацию о бакетах
        $sourceBucket = config("filesystems.disks.{$sourceDisk}.bucket");
        $targetBucket = config("filesystems.disks.{$targetDisk}.bucket");

        // Если это один и тот же бакет, используем простое копирование
        if ($sourceBucket === $targetBucket && $s3Source === $s3Target) {
            $s3Source->copyObject([
                'Bucket' => $targetBucket,
                'CopySource' => urlencode($sourceBucket . '/' . $sourcePath),
                'Key' => $targetPath,
            ]);
        } else {
            // Если разные бакеты, используем объект источника как источник копирования
            $s3Target->copyObject([
                'Bucket' => $targetBucket,
                'CopySource' => urlencode($sourceBucket . '/' . $sourcePath),
                'Key' => $targetPath,
            ]);
        }
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
        $sourceStream = Storage::disk($sourceDisk)->readStream($sourcePath);

        if ($sourceStream === false) {
            throw new \RuntimeException("Не удалось открыть поток для чтения из {$sourceDisk}:{$sourcePath}");
        }

        // Записываем поток в целевой файл
        $success = Storage::disk($targetDisk)->writeStream($targetPath, $sourceStream);

        if (is_resource($sourceStream)) {
            fclose($sourceStream);
        }

        if (!$success) {
            throw new \RuntimeException("Не удалось записать файл в {$targetDisk}:{$targetPath}");
        }
    }

    /**
     * Форматирует размер в байтах в человекочитаемый формат
     *
     * @param int $bytes
     * @param int $precision
     * @return string
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
