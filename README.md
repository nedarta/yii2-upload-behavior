# UploadBehavior for Yii2

Full-featured Yii2 image upload behavior with advanced processing capabilities.

## Features

- **Automatic file uploads** via `UploadedFile`
- **Nested directory creation** with proper permissions
- **EXIF auto-rotation** - automatically corrects image orientation from cameras/phones
- **Multiple image variants** with flexible processing options:
  - Resize (maintain aspect ratio)
  - Thumbnail (crop to exact dimensions)
  - Smart crop (intelligent cropping with optional nedarta/yii2-smart-cropper)
  - Copy (fallback option)
- **Dependency-based variant pipeline** - create variants from other variants
- **Format conversion** - force output to JPG, PNG, or WebP
- **Automatic cleanup** - removes old variants on update and delete
- **Random filename generation** - prevents naming conflicts

## Requirements

- Yii2
- yii2-imagine extension
- PHP GD or Imagick extension
- PHP EXIF extension (for auto-rotation)

## Installation

Install via Composer:

```bash
composer require nedarta/yii2-upload-behavior
```

The extension will automatically install the required dependency `yiisoft/yii2-imagine`.

Configure the `@upload` alias in your application config:

```php
'aliases' => [
    '@upload' => '@webroot/upload',
],
```

## Basic Usage

### Simple Configuration

```php
use nedarta\behaviors\UploadBehavior;

class Event extends \yii\db\ActiveRecord
{
    public $upload; // Virtual attribute for file upload

    public function rules()
    {
        return [
            [['upload'], 'file', 'extensions' => 'png, jpg, jpeg', 'maxSize' => 1024 * 1024 * 10],
        ];
    }

    public function behaviors()
    {
        return [
            [
                'class' => UploadBehavior::class,
                'uploadAttribute' => 'upload',
                'imageAttribute' => 'image',
                'uploadAlias' => '@upload/images/event',
            ],
        ];
    }
}
```

### Form Example

```php
<?php $form = ActiveForm::begin(['options' => ['enctype' => 'multipart/form-data']]); ?>

<?= $form->field($model, 'upload')->fileInput() ?>

<div class="form-group">
    <?= Html::submitButton('Upload', ['class' => 'btn btn-success']) ?>
</div>

<?php ActiveForm::end(); ?>
```

### Controller Example

```php
public function actionCreate()
{
    $model = new Event();

    if ($model->load(Yii::$app->request->post()) && $model->save()) {
        return $this->redirect(['view', 'id' => $model->id]);
    }

    return $this->render('create', ['model' => $model]);
}
```

## Configuration Options

### Basic Properties

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `uploadAttribute` | string | `'upload'` | Virtual attribute that receives the UploadedFile |
| `imageAttribute` | string | `'image'` | Database attribute where filename is stored |
| `uploadAlias` | string | required | Yii alias for storage folder |
| `baseName` | string\|Closure | `'image'` | Base filename before random number |
| `autoRotate` | bool | `true` | Reserved flag (auto-rotation currently always runs) |
| `forceConvert` | string\|null | `null` | Force output format: 'jpg', 'png', 'webp', 'jpeg' |
| `variants` | array | `['' => ['resize' => [2500, 2500]]]` | Variant definitions |

### Base Name with Closure

```php
'baseName' => function ($model) {
    return 'event-' . $model->id;
},
```

### EXIF Auto-Rotation

By default, images are automatically rotated based on their EXIF orientation data (common with phone cameras).

```php
'autoRotate' => false,
```

**Note:** Auto-rotation only works with JPEG files and requires the PHP EXIF extension. The `autoRotate` flag is present for future control, but in the current version rotation always runs when EXIF orientation is detected.

### Format Conversion

Force all images (original and variants) to a specific format:

```php
'forceConvert' => 'jpg', // Converts all uploads to JPG
```

Supported formats: `jpg`, `jpeg`, `png`, `webp`

## Image Variants

### Simple Variants

```php
'variants' => [
    '' => ['resize' => [2500, 2500]],           // Original (no prefix)
    'thumb_' => ['thumbnail' => [300, 300]],    // Square thumbnail
    'medium_' => ['resize' => [800, 600]],      // Medium size
],
```

This creates:
- `image-1234.jpg` (original, resized to max 2500x2500)
- `thumb_image-1234.jpg` (300x300 thumbnail)
- `medium_image-1234.jpg` (resized to max 800x600)

### Variant Options

#### Resize
Maintains aspect ratio, scales down to fit within dimensions:
```php
'variant_' => ['resize' => [1920, 1080], 'quality' => 85]
```

#### Thumbnail
Crops to exact dimensions:
```php
'variant_' => ['thumbnail' => [400, 400], 'quality' => 80]
```

#### Smart Crop
Intelligent cropping (requires nedarta/yii2-smart-cropper):
```php
'variant_' => ['smartcrop' => [600, 400], 'quality' => 85]
```

#### Copy
Simply copies the source image:
```php
'variant_' => []  // Empty config = copy
```

### Dependency Pipeline

Create variants from other variants for optimized processing:

```php
'variants' => [
    '' => ['resize' => [2500, 2500]],                    // Step 1: Resize original
    'large_' => ['resize' => [1920, 1920], 'dependsOn' => ''],    // Step 2: From original
    'thumb_' => ['smartcrop' => [300, 300], 'dependsOn' => 'large_'],  // Step 3: From large
    'mini_' => ['thumbnail' => [100, 100], 'dependsOn' => 'thumb_'],   // Step 4: From thumb
],
```

**Benefits:**
- Faster processing (work from smaller images)
- Better quality chain
- Efficient resource usage

## Advanced Examples

### Blog Post with Multiple Variants

```php
public function behaviors()
{
    return [
        [
            'class' => UploadBehavior::class,
            'uploadAttribute' => 'upload',
            'imageAttribute' => 'image',
            'uploadAlias' => '@upload/images/blog',
            'baseName' => function ($model) {
                return Inflector::slug($model->title);
            },
            'forceConvert' => 'webp',
            'variants' => [
                '' => ['resize' => [2000, 2000]],
                'hero_' => ['resize' => [1920, 1080], 'dependsOn' => '', 'quality' => 90],
                'card_' => ['smartcrop' => [600, 400], 'dependsOn' => 'hero_'],
                'thumb_' => ['thumbnail' => [300, 200], 'dependsOn' => 'card_'],
            ],
        ],
    ];
}
```

### Product Image with Watermark Pipeline

```php
'variants' => [
    '' => ['resize' => [3000, 3000]],
    'display_' => ['resize' => [1200, 1200], 'dependsOn' => ''],
    'thumb_' => ['thumbnail' => [400, 400], 'dependsOn' => 'display_'],
    'micro_' => ['thumbnail' => [150, 150], 'dependsOn' => 'thumb_'],
],
```

### User Avatar

```php
public function behaviors()
{
    return [
        [
            'class' => UploadBehavior::class,
            'uploadAttribute' => 'avatar_file',
            'imageAttribute' => 'avatar',
            'uploadAlias' => '@upload/avatars',
            'baseName' => function ($model) {
                return 'user-' . $model->id;
            },
            'forceConvert' => 'jpg',
            'variants' => [
                '' => ['thumbnail' => [800, 800]],
                'thumb_' => ['thumbnail' => [200, 200], 'dependsOn' => ''],
                'icon_' => ['thumbnail' => [64, 64], 'dependsOn' => 'thumb_'],
            ],
        ],
    ];
}
```

## Displaying Images

### In Views

```php
// Original
<?= Html::img('@web/upload/images/event/' . $model->image) ?>

// Variant
<?= Html::img('@web/upload/images/event/thumb_' . $model->image) ?>

// With Yii2 helper
use yii\helpers\Url;

<img src="<?= Url::to('@web/upload/images/event/' . $model->image) ?>" alt="">
<img src="<?= Url::to('@web/upload/images/event/medium_' . $model->image) ?>" alt="">
```

### Helper Method

Add to your model:

```php
public function getImageUrl($variant = '')
{
    if (empty($this->image)) {
        return null;
    }
    
    $filename = $variant . $this->image;
    return Yii::getAlias('@web/upload/images/event/' . $filename);
}

// Usage in view:
<?= Html::img($model->getImageUrl()) ?>
<?= Html::img($model->getImageUrl('thumb_')) ?>
```

## File Structure Example

After uploading with variants, your directory will look like:

```
@upload/images/event/
├── event-1234.jpg           (original)
├── large_event-1234.jpg     (large variant)
├── thumb_event-1234.jpg     (thumbnail variant)
└── mini_event-1234.jpg      (mini variant)
```

## How It Works

### Event Flow

1. **Before Validation** - Captures the uploaded file
2. **After Insert/Update** - Processes the upload:
   - Creates upload directories if needed
   - Removes old variants if updating
   - Saves uploaded file to temporary location
   - Applies EXIF auto-rotation
   - Converts format if `forceConvert` is set
   - Generates all variants in dependency order
   - Updates model with new filename
3. **Before Delete** - Removes all variants

### Variant Processing Pipeline

1. Determine source for each variant (original or another variant)
2. Process variants in order
3. Apply transformation (resize/thumbnail/smartcrop/copy)
4. Save with specified quality
5. Use result as source for dependent variants

## Troubleshooting

### Images not rotating correctly
- Ensure PHP EXIF extension is installed: `php -m | grep exif`
- Check that images are JPEG format (EXIF only works with JPEG)
- If you need to fully disable rotation, you will need to customize `UploadBehavior::autoRotateImage()` (the `autoRotate` flag is currently not enforced)

### Variants not created
- Check directory permissions (775 or 777)
- Verify GD or Imagick extension is installed
- Check error logs for specific errors

### "Saving image in 'tmp' format is not supported"
- This is fixed in the current version
- Ensure you're using the latest UploadBehavior code

### Old images not deleted
- Verify `deleteVariants()` is being called
- Check file permissions on upload directory
- Ensure variant prefixes match your configuration

## License

Free to use and modify.

## Credits

Created by nedarta
