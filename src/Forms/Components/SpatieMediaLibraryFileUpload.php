<?php

namespace Filament\Forms\Components;

use Closure;
use Filament\Support\Concerns\HasMediaFilter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use League\Flysystem\UnableToCheckFileExistence;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\FileAdder;
use Spatie\MediaLibrary\MediaCollections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use Illuminate\Support\Arr;

class SpatieMediaLibraryFileUpload extends FileUpload
{
    use HasMediaFilter;

    protected string | Closure | null $collection = null;

    protected string | Closure | null $conversion = null;

    protected string | Closure | null $conversionsDisk = null;

    protected bool | Closure $hasResponsiveImages = false;

    protected string | Closure | null $mediaName = null;

    /**
     * @var array<string, mixed> | Closure | null
     */
    protected array | Closure | null $customHeaders = null;

    /**
     * @var array<string, mixed> | Closure | null
     */
    protected array | Closure | null $customProperties = null;

    /**
     * @var array<string, array<string, string>> | Closure | null
     */
    protected array | Closure | null $manipulations = null;

    /**
     * @var array<string, mixed> | Closure | null
     */
    protected array | Closure | null $properties = null;

    /**
     * Флаг, указывающий, следует ли использовать асинхронное перемещение файлов.
     *
     * @var bool | Closure
     */
    protected bool | Closure $useAsyncFileMove = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadStateFromRelationshipsUsing(static function (SpatieMediaLibraryFileUpload $component, HasMedia $record): void {
            /** @var Model&HasMedia $record */
            $media = $record->load('media')->getMedia($component->getCollection() ?? 'default')
                ->when(
                    $component->hasMediaFilter(),
                    fn(Collection $media) => $component->filterMedia($media)
                )
                ->when(
                    ! $component->isMultiple(),
                    fn(Collection $media): Collection => $media->take(1),
                )
                ->mapWithKeys(function (Media $media): array {
                    $uuid = $media->getAttributeValue('uuid');

                    return [$uuid => $uuid];
                })
                ->toArray();

            $component->state($media);
        });

        $this->afterStateHydrated(static function (BaseFileUpload $component, string | array | null $state): void {
            if (is_array($state)) {
                return;
            }

            $component->state([]);
        });

        $this->beforeStateDehydrated(null);

        $this->dehydrated(false);

        $this->getUploadedFileUsing(static function (SpatieMediaLibraryFileUpload $component, string $file): ?array {
            if (! $component->getRecord()) {
                return null;
            }

            /** @var ?Media $media */
            $media = $component->getRecord()->getRelationValue('media')->firstWhere('uuid', $file);

            if (!$media) {
                return null;
            }

            // Для асинхронной обработки файлов
            if ($media->hasCustomProperty('is_processing_async') && $media->hasCustomProperty('path') && $media->hasCustomProperty('disk')) {
                $tmpDisk = $media->getCustomProperty('disk');
                $tmpPath = $media->getCustomProperty('path');
                $originalFilename = $media->getCustomProperty('original_filename', $media->file_name);

                try {
                    // Всегда используем подписанный URL для временных файлов, т.к. они обычно приватные
                    // Для S3 это сгенерирует presigned URL с ограниченным сроком действия
                    $expiration = now()->addMinutes(60);
                    $url = Storage::disk($tmpDisk)->temporaryUrl($tmpPath, $expiration);

                    return [
                        'name' => $media->name ?? $originalFilename,
                        'size' => $media->size,
                        'type' => $media->mime_type,
                        'url' => $url,
                    ];
                } catch (Throwable $exception) {
                    // Если не удалось получить signed URL, просто продолжаем стандартным способом
                    // Но логируем ошибку
                    \Illuminate\Support\Facades\Log::warning(
                        'Не удалось получить временный URL для асинхронного файла',
                        [
                            'media_id' => $media->id,
                            'path' => $tmpPath,
                            'disk' => $tmpDisk,
                            'error' => $exception->getMessage()
                        ]
                    );
                }
            }

            $url = null;

            if ($component->getVisibility() === 'private') {
                $conversion = $component->getConversion();

                try {
                    $url = $media->getTemporaryUrl(
                        now()->addMinutes(5),
                        (filled($conversion) && $media->hasGeneratedConversion($conversion)) ? $conversion : '',
                    );
                } catch (Throwable $exception) {
                    // This driver does not support creating temporary URLs.
                }
            }

            if ($component->getConversion() && $media->hasGeneratedConversion($component->getConversion())) {
                $url ??= $media->getUrl($component->getConversion());
            }

            $url ??= $media->getUrl();

            return [
                'name' => $media->getAttributeValue('name') ?? $media->getAttributeValue('file_name'),
                'size' => $media->getAttributeValue('size'),
                'type' => $media->getAttributeValue('mime_type'),
                'url' => $url,
            ];
        });

        $this->saveRelationshipsUsing(static function (SpatieMediaLibraryFileUpload $component) {
            $component->deleteAbandonedFiles();
            $component->saveUploadedFiles();
        });

        $this->saveUploadedFileUsing(static function (SpatieMediaLibraryFileUpload $component, TemporaryUploadedFile $file, ?Model $record): ?string {
            if (! method_exists($record, 'addMediaFromString')) {
                return $file;
            }

            try {
                if (! $file->exists()) {
                    return null;
                }
            } catch (UnableToCheckFileExistence $exception) {
                return null;
            }

            $disk = config('livewire.temporary_file_upload_disk', 's3');

            $pathToTemporaryLivewireFile = (string) Str::of($file->getRealPath())
                ->after(Storage::disk($disk)->path('/'))
                ->ltrim('/');

            // Асинхронная обработка, если включена
            if ($component->shouldUseAsyncFileMove()) {
                // Подготавливаем данные для Media
                $uuid = Str::uuid()->toString();
                $mediaClass = ($record && method_exists($record, 'getMediaModel')) ? $record->getMediaModel() : null;
                $mediaClass ??= config('media-library.media_model', Media::class);

                // Информация для создания записи в БД
                $mediaRecord = new $mediaClass();
                $mediaRecord->uuid = $uuid;
                $mediaRecord->model_type = method_exists($record, 'getMorphClass') ? $record->getMorphClass() : get_class($record);
                $mediaRecord->model_id = $record->getKey();
                $mediaRecord->collection_name = $component->getCollection() ?? 'default';
                $mediaRecord->name = $component->getMediaName($file) ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $mediaRecord->file_name = $file->getFilename();
                $mediaRecord->mime_type = $file->getMimeType();
                $mediaRecord->disk = $disk;
                $mediaRecord->size = $file->getSize();
                $mediaRecord->manipulations = $component->getManipulations();
                $mediaRecord->responsive_images = [];

                // Путь к временному файлу для выборки при отображении
                $directoryPath = dirname($pathToTemporaryLivewireFile);

                // Кастомные свойства для асинхронного перемещения
                $customProperties = $component->getCustomProperties();
                $customProperties['path'] = $pathToTemporaryLivewireFile;
                $customProperties['disk'] = $disk;
                $customProperties['original_filename'] = $file->getClientOriginalName();
                $customProperties['is_processing_async'] = true;

                $mediaRecord->custom_properties = $customProperties;

                // Сохраняем запись и запускаем асинхронное задание
                $mediaRecord->save();

                // Позиция в порядке сортировки
                $mediaClass::setNewOrder([$mediaRecord->id]);

                // Всегда используем асинхронную очередь для перемещения файлов
                // Это позволяет не блокировать пользовательский интерфейс
                $component->dispatchAsyncJob($mediaRecord->id);

                return $uuid;
            }

            // Синхронная обработка (существующий код)
            /** @var FileAdder $mediaAdder */
            $mediaAdder = $record->addMediaFromDisk($pathToTemporaryLivewireFile, $disk);

            $filename = $component->getUploadedFileNameForStorage($file);

            $media = $mediaAdder
                ->addCustomHeaders($component->getCustomHeaders())
                ->usingFileName($filename)
                ->usingName($component->getMediaName($file) ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
                ->storingConversionsOnDisk($component->getConversionsDisk() ?? '')
                ->withCustomProperties($component->getCustomProperties())
                ->withManipulations($component->getManipulations())
                ->withResponsiveImagesIf($component->hasResponsiveImages())
                ->withProperties($component->getProperties())
                ->toMediaCollection($component->getCollection() ?? 'default', $component->getDiskName());

            return $media->getAttributeValue('uuid');
        });

        $this->reorderUploadedFilesUsing(static function (SpatieMediaLibraryFileUpload $component, ?Model $record, array $state): array {
            $uuids = array_filter(array_values($state));

            $mediaClass = ($record && method_exists($record, 'getMediaModel')) ? $record->getMediaModel() : null;
            $mediaClass ??= config('media-library.media_model', Media::class);

            $mappedIds = $mediaClass::query()->whereIn('uuid', $uuids)->pluck(app($mediaClass)->getKeyName(), 'uuid')->toArray();

            $mediaClass::setNewOrder([
                ...array_flip($uuids),
                ...$mappedIds,
            ]);

            return $state;
        });
    }

    public function collection(string | Closure | null $collection): static
    {
        $this->collection = $collection;

        return $this;
    }

    public function conversion(string | Closure | null $conversion): static
    {
        $this->conversion = $conversion;

        return $this;
    }

    public function conversionsDisk(string | Closure | null $disk): static
    {
        $this->conversionsDisk = $disk;

        return $this;
    }

    /**
     * @param  array<string, mixed> | Closure | null  $headers
     */
    public function customHeaders(array | Closure | null $headers): static
    {
        $this->customHeaders = $headers;

        return $this;
    }

    /**
     * @param  array<string, mixed> | Closure | null  $properties
     */
    public function customProperties(array | Closure | null $properties): static
    {
        $this->customProperties = $properties;

        return $this;
    }

    /**
     * @param  array<string, array<string, string>> | Closure | null  $manipulations
     */
    public function manipulations(array | Closure | null $manipulations): static
    {
        $this->manipulations = $manipulations;

        return $this;
    }

    /**
     * @param  array<string, mixed> | Closure | null  $properties
     */
    public function properties(array | Closure | null $properties): static
    {
        $this->properties = $properties;

        return $this;
    }

    public function responsiveImages(bool | Closure $condition = true): static
    {
        $this->hasResponsiveImages = $condition;

        return $this;
    }

    public function deleteAbandonedFiles(): void
    {
        /** @var Model&HasMedia $record */
        $record = $this->getRecord();

        $record
            ->getMedia($this->getCollection() ?? 'default')
            ->whereNotIn('uuid', array_keys($this->getState() ?? []))
            ->when($this->hasMediaFilter(), fn(Collection $media): Collection => $this->filterMedia($media))
            ->each(fn(Media $media) => $media->delete());
    }

    public function getDiskName(): string
    {
        if ($diskName = $this->evaluate($this->diskName)) {
            return $diskName;
        }

        /** @var Model&HasMedia $model */
        $model = $this->getModelInstance();

        $collection = $this->getCollection() ?? 'default';

        /** @phpstan-ignore-next-line */
        $diskNameFromRegisteredConversions = $model
            ->getRegisteredMediaCollections()
            ->filter(fn(MediaCollection $mediaCollection): bool => $mediaCollection->name === $collection)
            ->first()
            ?->diskName;

        return $diskNameFromRegisteredConversions ?? config('filament.default_filesystem_disk');
    }

    public function getCollection(): ?string
    {
        return $this->evaluate($this->collection);
    }

    public function getConversion(): ?string
    {
        return $this->evaluate($this->conversion);
    }

    public function getConversionsDisk(): ?string
    {
        return $this->evaluate($this->conversionsDisk);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCustomHeaders(): array
    {
        return $this->evaluate($this->customHeaders) ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getCustomProperties(): array
    {
        return $this->evaluate($this->customProperties) ?? [];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function getManipulations(): array
    {
        return $this->evaluate($this->manipulations) ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getProperties(): array
    {
        return $this->evaluate($this->properties) ?? [];
    }

    public function hasResponsiveImages(): bool
    {
        return (bool) $this->evaluate($this->hasResponsiveImages);
    }

    public function mediaName(string | Closure | null $name): static
    {
        $this->mediaName = $name;

        return $this;
    }

    public function getMediaName(TemporaryUploadedFile $file): ?string
    {
        return $this->evaluate($this->mediaName, [
            'file' => $file,
        ]);
    }

    /**
     * Устанавливает флаг использования асинхронного перемещения файлов.
     *
     * @param bool | Closure $condition
     * @return static
     */
    public function useAsyncFileMove(bool | Closure $condition = true): static
    {
        $this->useAsyncFileMove = $condition;

        return $this;
    }

    /**
     * Проверяет, следует ли использовать асинхронное перемещение файлов.
     *
     * @return bool
     */
    public function shouldUseAsyncFileMove(): bool
    {
        return (bool) $this->evaluate($this->useAsyncFileMove);
    }

    /**
     * Диспетчеризирует задачу для асинхронного перемещения файла.
     *
     * @param int $mediaId
     * @return void
     */
    protected function dispatchAsyncJob(int $mediaId): void
    {
        $jobClass = "\\Filament\\SpatieLaravelMediaLibraryPlugin\\Jobs\\ProcessAsyncMediaFileMoveJob";

        // Вместо запуска задачи в очереди, используем defer() для выполнения после завершения запроса
        defer(function () use ($jobClass, $mediaId) {
            // Создаем экземпляр класса и вызываем handle() напрямую
            $job = new $jobClass($mediaId);
            $job->handle();
        });
    }

    /**
     * Переопределяем сохранение файлов, чтобы в асинхронном режиме не удалять временный файл
     * Livewire, пока асинхронное задание не переместит его.
     */
    public function saveUploadedFiles(): void
    {
        // Если асинхронный режим выключен, используем стандартную логику родителя
        if (! $this->shouldUseAsyncFileMove()) {
            parent::saveUploadedFiles();
            return;
        }

        // В асинхронном режиме нам нужно выполнить ту же логику, что и у родителя,
        // но пропустить $file->delete() для временного файла.
        if (blank($this->getState())) {
            $this->state([]);
            return;
        }

        if (! $this->shouldStoreFiles()) {
            return;
        }

        $state = array_filter(array_map(function (TemporaryUploadedFile | string $file) {
            if (! $file instanceof TemporaryUploadedFile) {
                return $file;
            }

            // Файл уже сохранён в saveUploadedFileUsing (mediaRecord создан),
            // поэтому просто возвращаем uuid из кастомного метода и НЕ удаляем файл.
            $callback = $this->saveUploadedFileUsing;

            if (! $callback) {
                return $file; // оставляем как есть
            }

            $storedFile = $this->evaluate($callback, [
                'file' => $file,
            ]);

            if ($storedFile === null) {
                return null;
            }

            // Сохраняем оригинальное имя файла, если настроено
            $this->storeFileName($storedFile, $file->getClientOriginalName());

            // ВАЖНО: не вызываем $file->delete(); оставляем файл во временном хранилище

            return $storedFile;
        }, Arr::wrap($this->getState())));

        if ($this->isReorderable && ($callback = $this->reorderUploadedFilesUsing)) {
            $state = $this->evaluate($callback, [
                'state' => $state,
            ]);
        }

        $this->state($state);
    }
}
