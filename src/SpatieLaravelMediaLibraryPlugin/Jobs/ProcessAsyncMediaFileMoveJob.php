<?php

namespace Filament\SpatieLaravelMediaLibraryPlugin\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
                !$media->hasCustomProperty('disk') ||
                !$media->hasCustomProperty('is_processing_async')
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
            $tempFileContents = Storage::disk($tempDisk)->get($tempPath);
            Storage::disk($finalDisk)->put($finalPath, $tempFileContents);

            // Удаляем временный файл после успешного копирования
            Storage::disk($tempDisk)->delete($tempPath);

            // Обновляем запись медиа
            $customProperties = $media->custom_properties;

            // Удаляем временные свойства, но сохраняем path для CustomPathGenerator
            unset($customProperties['original_filename']);
            unset($customProperties['is_processing_async']);

            // Обновляем запись медиа
            $media->custom_properties = $customProperties;
            $media->disk = $finalDisk;
            $media->save();

            // Регенерируем все производные файлы (конверсии, адаптивные изображения)
            $media->regenerateAllDerivedFiles();

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
}
