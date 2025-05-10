# Filament Spatie Media Library Plugin

## Форк IXBT с асинхронной загрузкой файлов

Данный форк расширяет стандартный функционал `SpatieMediaLibraryFileUpload` компонента, добавляя поддержку асинхронной обработки загруженных файлов через очереди. Это особенно полезно при работе с большими файлами, когда пользовательский интерфейс должен оставаться отзывчивым.

### Новый функционал

#### Асинхронное перемещение файлов

Компонент получил новый метод `useAsyncFileMove()`, который включает режим асинхронной обработки файлов:

```php
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;

SpatieMediaLibraryFileUpload::make('video')
    ->collection('videos')
    ->useAsyncFileMove() // Включает асинхронную обработку
    ->acceptedFileTypes(['video/mp4'])
    ->maxSize(1024 * 1024 * 100) // 100MB
```

#### Принцип работы

1. Когда пользователь выбирает файл, он сначала загружается во временное хранилище Livewire.
2. Компонент создает запись Media в базе данных и связывает ее с моделью, устанавливая следующие custom_properties:
   - `path` - путь к директории временного файла для отображения
   - `tmp_file` - полный относительный путь к временному файлу
   - `tmp_disk` - имя диска, на котором хранится временный файл
   - `original_filename` - оригинальное имя файла клиента
   - `is_processing_async` - флаг, указывающий на асинхронную обработку
3. Плагин проверяет, совпадают ли временный и целевой диски:
   - **Если диски совпадают** (например, оба используют S3 или локальное хранилище), перемещение выполняется **синхронно** без использования очередей, даже если включен режим `useAsyncFileMove()`. Это оптимизирует процесс обработки и ускоряет перемещение файлов.
   - **Если диски различаются** (например, файл загружен на локальный диск, но должен быть сохранен в S3), запускается асинхронная задача `ProcessAsyncMediaFileMoveJob` через систему очередей Laravel.
4. В обоих случаях выполняются следующие шаги:
   - Перемещение файла из временного хранилища в целевое хранилище
   - Установка публичного доступа к файлу через markAsPubliclyAccessible() или setVisibility()
   - Обновление записи Media с окончательными параметрами
   - Очистка временных метаданных в custom_properties

#### Преимущества

- Пользовательский интерфейс остается отзывчивым даже при загрузке больших файлов
- Сразу после выбора файла пользователь может продолжить работу с формой
- Обработка файлов происходит в фоновом режиме, не блокируя основные потоки выполнения
- Автоматическая установка публичного доступа к файлам
- Оптимизированная передача данных между разными дисками и S3 бакетами
- Корректная обработка ошибок и повторные попытки для надежности

#### Настройка

По умолчанию для выполнения фоновой задачи используется очередь Laravel по умолчанию. Убедитесь, что у вас настроен воркер для обработки очередей:

```bash
php artisan queue:work
```

Вы можете настроить количество повторных попыток и таймаут для задач в конфигурации queue.php или через переменные окружения.

#### Совместимость с CustomPathGenerator

Механизм асинхронной загрузки файлов полностью совместим с кастомным генератором путей `App\Services\CustomPathGenerator`, который может определять диски и пути из custom_properties. Это позволяет гибко управлять хранением файлов и их URL-адресами.

#### Оптимизации производительности

- **S3 Direct Copy**: Если исходный и целевой диски являются S3, используется API для прямого копирования файлов между бакетами на стороне сервера, минуя загрузку файла на ваш сервер
- **Потоковая передача**: Для других дисков используется потоковая передача данных, минимизирующая использование памяти
- **Оптимизированные проверки**: Задача проверяет успешность операций на каждом шаге и логирует подробную информацию
- **Умное определение режима обработки**: Если временный и целевой диски совпадают, плагин автоматически использует синхронное перемещение вместо постановки задачи в очередь, даже если включен режим `useAsyncFileMove()`
- **Мгновенное перемещение**: При работе в пределах одного диска операция перемещения выполняется моментально без создания задачи, что ускоряет процесс загрузки

#### Особенности работы с S3

Плагин включает специальную обработку для работы с AWS S3:

- **Умная обработка публичного доступа**: Распознает S3 диски и учитывает особенности публичного доступа в AWS
- **Проверка блокировки публичного доступа**: Определяет, настроена ли блокировка публичного доступа на уровне бакета
- **Валидация результата**: После установки публичного доступа проверяется фактическая видимость файла
- **Детальное логирование**: Каждый шаг работы с S3 логируется для упрощения отладки

> **Важно:** Для корректной работы публичного доступа в S3, убедитесь, что:
> 1. В настройках бакета не включена полная блокировка публичных ACL ("Block public access")
> 2. IAM-пользователь или роль имеет права на изменение ACL (`s3:PutObjectAcl`)
> 3. Корректно настроены CORS-правила, если доступ к файлам нужен из браузера

Если в вашей конфигурации S3 заблокирован публичный доступ на уровне бакета, вы можете:
- Использовать CloudFront или другой CDN с Origin Access Identity для доступа к файлам
- Создать bucket policy, разрешающую доступ к определенным путям
- Использовать пресайн-URL для временного доступа к файлам

## Полное описание плагина

### Обзор

Плагин Filament Spatie Media Library предоставляет интеграцию между [Filament PHP](https://filamentphp.com) и популярным пакетом [Spatie Laravel Media Library](https://spatie.be/docs/laravel-medialibrary). Он добавляет компоненты для форм, таблиц и информационных списков, позволяющие легко управлять медиа-файлами в админ-панели.

### Ключевые компоненты

#### Компоненты форм
- `SpatieMediaLibraryFileUpload` - компонент для загрузки файлов с поддержкой всех возможностей Media Library
- Поддержка асинхронной загрузки и обработки файлов (в форке IXBT)
- Полная совместимость с функциями базового компонента `FileUpload` из Filament

#### Компоненты таблиц
- `SpatieMediaLibraryImageColumn` - колонка для отображения изображений из Media Library в таблицах
- Поддерживает коллекции, конверсии и фильтрацию медиа

#### Компоненты информационных списков
- `SpatieMediaLibraryImageEntry` - элемент для отображения изображений из Media Library в информационных списках
- Поддерживает коллекции, конверсии и фильтрацию медиа

### Основные возможности

- **Коллекции медиа**: Группировка файлов по категориям с помощью коллекций Spatie Media Library
- **Конверсии**: Автоматическое создание и отображение различных версий изображений (превью, миниатюры и т.д.)
- **Адаптивные изображения**: Генерация и использование адаптивных изображений для разных устройств
- **Настраиваемые свойства**: Добавление дополнительных метаданных к медиа-файлам
- **Сортировка файлов**: Поддержка сортировки и переупорядочивания файлов через интерфейс
- **Фильтрация медиа**: Отображение только определенных файлов на основе пользовательских критериев
- **Интеграция с S3**: Полная поддержка хранения файлов в облачных хранилищах
- **Асинхронная обработка**: Обработка файлов в фоновом режиме для улучшения отзывчивости интерфейса

### Расширенные возможности форка IXBT

- **Асинхронная загрузка**: Обработка файлов в фоновом режиме через очереди Laravel
- **Оптимизация для S3**: Прямое копирование между бакетами для больших файлов
- **Умная оптимизация перемещения**: Автоматическое определение необходимости использования очередей – при работе в пределах одного диска перемещение выполняется синхронно и мгновенно
- **Потоковая обработка**: Минимизация использования памяти при работе с файлами
- **Автоматическая установка публичного доступа**: Файлы автоматически становятся публично доступными
- **Подробное логирование**: Детальное логирование процесса обработки файлов для отладки
- **Обработка ошибок**: Надежная система повторных попыток и обработки исключений

### Варианты использования

1. **Галереи изображений**: Создание и управление галереями изображений с поддержкой сортировки
2. **Документы**: Управление документами с метаданными и категориями
3. **Видео/аудио файлы**: Работа с медиа-файлами большого размера через асинхронную обработку
4. **Аватары пользователей**: Управление профильными изображениями с автоматическими конверсиями
5. **Многофайловые вложения**: Прикрепление нескольких файлов к записям с возможностью сортировки

## Installation

Install the plugin with Composer:

```bash
composer require filament/spatie-laravel-media-library-plugin:"^3.2" -W
```

If you haven't already done so, you need to publish the migration to create the media table:

```bash
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="medialibrary-migrations"
```

Run the migrations:

```bash
php artisan migrate
```

You must also [prepare your Eloquent model](https://spatie.be/docs/laravel-medialibrary/basic-usage/preparing-your-model) for attaching media.

> For more information, check out [Spatie's documentation](https://spatie.be/docs/laravel-medialibrary).

## Form component

You may use the field in the same way as the [original file upload](https://filamentphp.com/docs/forms/fields/file-upload) field:

```php
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;

SpatieMediaLibraryFileUpload::make('avatar')
```

The media library file upload supports all the customization options of the [original file upload component](https://filamentphp.com/docs/forms/fields/file-upload).

> The field will automatically load and save its uploads to your model. To set this functionality up, **you must also follow the instructions set out in the [setting a form model](https://filamentphp.com/docs/forms/adding-a-form-to-a-livewire-component#setting-a-form-model) section**. If you're using a [panel](../panels), you can skip this step.

### Passing a collection

Optionally, you may pass a [`collection()`](https://spatie.be/docs/laravel-medialibrary/working-with-media-collections/simple-media-collections) allows you to group files into categories:

```php
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;

SpatieMediaLibraryFileUpload::make('avatar')
    ->collection('avatars')
```

### Configuring the storage disk and directory

By default, files will be uploaded publicly to your storage disk defined in the [Filament configuration file](https://filamentphp.com/docs/forms/installation#publishing-configuration). You can also set the `FILAMENT_FILESYSTEM_DISK` environment variable to change this. This is to ensure consistency between all Filament packages. Spatie's disk configuration will not be used, unless you [define a disk for a registered collection](https://spatie.be/docs/laravel-medialibrary/working-with-media-collections/defining-media-collections#content-using-a-specific-disk).

Alternatively, you can manually set the disk with the `disk()` method:

```php
use Filament\Forms\Components\FileUpload;

FileUpload::make('attachment')
    ->disk('s3')
```

The base file upload component also has configuration options for setting the `directory()` and `visibility()` of uploaded files. These are not used by the media library file upload component. Spatie's package has its own system for determining the directory of a newly-uploaded file, and it does not support uploading private files out of the box. One way to store files privately is to configure this in your S3 bucket settings, in which case you should also use `visibility('private')` to ensure that Filament generates temporary URLs for your files.

### Reordering files

In addition to the behavior of the normal file upload, Spatie's Media Library also allows users to reorder files.

To enable this behavior, use the `reorderable()` method:

```php
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;

SpatieMediaLibraryFileUpload::make('attachments')
    ->multiple()
    ->reorderable()
```

You may now drag and drop files into order.

### Adding custom properties

You may pass in [custom properties](https://spatie.be/docs/laravel-medialibrary/advanced-usage/using-custom-properties) when uploading files using the `customProperties()` method:

```php
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;

SpatieMediaLibraryFileUpload::make('attachments')
    ->multiple()
    ->customProperties(['zip_filename_prefix' => 'folder/subfolder/'])
```

### Adding custom headers

You may pass in custom headers when uploading files using the `customHeaders()` method:

```php
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;

SpatieMediaLibraryFileUpload::make('attachments')
    ->multiple()
    ->customHeaders(['CacheControl' => 'max-age=86400'])
```

### Generating responsive images

You may [generate responsive images](https://spatie.be/docs/laravel-medialibrary/responsive-images/getting-started-with-responsive-images) when the files are uploaded using the `responsiveImages()` method:

```php
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;

SpatieMediaLibraryFileUpload::make('attachments')
    ->multiple()
    ->responsiveImages()
```

### Using conversions

You may also specify a `conversion()` to load the file from showing it in the form, if present:

```php
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;

SpatieMediaLibraryFileUpload::make('attachments')
    ->conversion('thumb')
```

#### Storing conversions on a separate disk

You can store your conversions and responsive images on a disk other than the one where you save the original file. Pass the name of the disk where you want conversion to be saved to the `conversionsDisk()` method:

```php
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;

SpatieMediaLibraryFileUpload::make('attachments')
    ->conversionsDisk('s3')
```

### Storing media-specific manipulations

You may pass in [manipulations](https://spatie.be/docs/laravel-medialibrary/advanced-usage/storing-media-specific-manipulations#breadcrumb) that are run when files are uploaded using the `manipulations()` method:

```php
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;

SpatieMediaLibraryFileUpload::make('attachments')
    ->multiple()
    ->manipulations([
        'thumb' => ['orientation' => '90'],
    ])
```

### Filtering media

It's possible to target a file upload component to only handle a certain subset of media in a collection. To do that, you can filter the media collection using the `filterMediaUsing()` method. This method accepts a function that receives the `$media` collection and manipulates it. You can use any [collection method](https://laravel.com/docs/collections#available-methods) to filter it.

For example, you could scope the field to only handle media that has certain custom properties:

```php
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Get;
use Illuminate\Support\Collection;

SpatieMediaLibraryFileUpload::make('images')
    ->customProperties(fn (Get $get): array => [
        'gallery_id' => $get('gallery_id'),
    ])
    ->filterMediaUsing(
        fn (Collection $media, Get $get): Collection => $media->where(
            'custom_properties.gallery_id',
            $get('gallery_id')
        ),
    )
```

## Table column

To use the media library image column:

```php
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;

SpatieMediaLibraryImageColumn::make('avatar')
```

The media library image column supports all the customization options of the [original image column](https://filamentphp.com/docs/tables/columns/image).

### Passing a collection

Optionally, you may pass a `collection()`:

```php
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;

SpatieMediaLibraryImageColumn::make('avatar')
    ->collection('avatars')
```

The [collection](https://spatie.be/docs/laravel-medialibrary/working-with-media-collections/simple-media-collections) allows you to group files into categories.

By default, only media without a collection (using the `default` collection) will be shown. If you want to show media from all collections, you can use the `allCollections()` method:

```php
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;

SpatieMediaLibraryImageColumn::make('avatar')
    ->allCollections()
```

### Using conversions

You may also specify a `conversion()` to load the file from showing it in the table, if present:

```php
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;

SpatieMediaLibraryImageColumn::make('avatar')
    ->conversion('thumb')
```

### Filtering media

It's possible to target the column to only display a subset of media in a collection. To do that, you can filter the media collection using the `filterMediaUsing()` method. This method accepts a function that receives the `$media` collection and manipulates it. You can use any [collection method](https://laravel.com/docs/collections#available-methods) to filter it.

For example, you could scope the column to only display media that has certain custom properties:

```php
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Illuminate\Support\Collection;

SpatieMediaLibraryImageColumn::make('images')
    ->filterMediaUsing(
        fn (Collection $media): Collection => $media->where(
            'custom_properties.gallery_id',
            12345,
        ),
    )
```

## Infolist entry

To use the media library image entry:

```php
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;

SpatieMediaLibraryImageEntry::make('avatar')
```

The media library image entry supports all the customization options of the [original image entry](https://filamentphp.com/docs/infolists/entries/image).

### Passing a collection

Optionally, you may pass a `collection()`:

```php
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;

SpatieMediaLibraryImageEntry::make('avatar')
    ->collection('avatars')
```

The [collection](https://spatie.be/docs/laravel-medialibrary/working-with-media-collections/simple-media-collections) allows you to group files into categories.

By default, only media without a collection (using the `default` collection) will be shown. If you want to show media from all collections, you can use the `allCollections()` method:

```php
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;

SpatieMediaLibraryImageEntry::make('avatar')
    ->allCollections()
```

### Using conversions

You may also specify a `conversion()` to load the file from showing it in the infolist, if present:

```php
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;

SpatieMediaLibraryImageEntry::make('avatar')
    ->conversion('thumb')
```

### Filtering media

It's possible to target the entry to only display a subset of media in a collection. To do that, you can filter the media collection using the `filterMediaUsing()` method. This method accepts a function that receives the `$media` collection and manipulates it. You can use any [collection method](https://laravel.com/docs/collections#available-methods) to filter it.

For example, you could scope the entry to only display media that has certain custom properties:

```php
use Filament\Tables\Columns\SpatieMediaLibraryImageEntry;
use Illuminate\Support\Collection;

SpatieMediaLibraryImageEntry::make('images')
    ->filterMediaUsing(
        fn (Collection $media): Collection => $media->where(
            'custom_properties.gallery_id',
            12345,
        ),
    )
```
