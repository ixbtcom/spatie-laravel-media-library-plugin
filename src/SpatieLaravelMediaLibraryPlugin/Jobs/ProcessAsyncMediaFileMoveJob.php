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
     * Выполнение задания.
     *
     * @return void
     */
    public function handle(): void
    {
        try {
            // Получаем запись медиа
            $mediaClass = Config::get('media-library.media_model', Media::class);
            $media = $mediaClass::find($this->mediaId);

            if (!$media) {
                Log::error('Медиа не найдено для асинхронного перемещения', [
                    'media_id' => $this->mediaId,
                ]);
                return;
            }

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

            // Проверяем, существует ли временный файл
            if (!Storage::disk($tempDisk)->exists($tempPath)) {
                Log::error('Временный файл не найден для асинхронного перемещения', [
                    'media_id' => $this->mediaId,
                    'temp_disk' => $tempDisk,
                    'temp_path' => $tempPath,
                ]);
                return;
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

            // Получаем генератор путей и определяем целевой путь
            $pathGenerator = PathGeneratorFactory::create($media);
            $finalDirectory = $pathGenerator->getPath($media);
            $filename = $media->file_name;
            $finalPath = $finalDirectory . $filename;

            // Копируем файл из временного хранилища в целевое
            // Оптимизация для S3: используем прямое копирование на стороне сервера вместо загрузки файла в память
            if ($this->isS3Disk($tempDisk) && $this->isS3Disk($finalDisk)) {
                // Для S3->S3 используем прямое копирование на стороне сервера
                $this->copyBetweenS3($tempDisk, $tempPath, $finalDisk, $finalPath);
            } else {
                // Для разных дисков или не S3 используем потоковую передачу
                $this->copyUsingStreams($tempDisk, $tempPath, $finalDisk, $finalPath);
            }

            // Удаляем временный файл после успешного копирования
            // Storage::disk($tempDisk)->delete($tempPath);  // Временно отключаем, чтобы проверить, здесь ли проблема

            // Обновляем запись медиа
            $customProperties = $media->custom_properties;

            // Удаляем временные свойства, но сохраняем path для CustomPathGenerator
            unset($customProperties['original_filename']);
            unset($customProperties['is_processing_async']);

            // Обновляем запись медиа
            $media->custom_properties = $customProperties;
            $media->disk = $finalDisk;
            $media->save();

            // Не вызываем regenerateAllDerivedFiles(), так как этот метод не существует

            Log::info('Файл успешно перемещен асинхронно', [
                'media_id' => $this->mediaId,
                'from' => "{$tempDisk}:{$tempPath}",
                'to' => "{$finalDisk}:{$finalPath}",
            ]);
        } catch (Throwable $e) {
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
}
