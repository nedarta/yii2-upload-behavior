<?php

namespace nedarta\upload\behaviors;

use Yii;
use yii\base\Behavior;
use yii\web\UploadedFile;
use yii\helpers\FileHelper;
use yii\imagine\Image;

/**
 * Class UploadBehavior
 *
 * Configurable image upload behavior for Yii2 ActiveRecord models.
 *
 * Features:
 * - Uploads file from model attribute (e.g. $upload)
 * - Stores filename in another attribute (e.g. $image)
 * - Creates nested directories (e.g. @upload/images/news)
 * - Generates variants with prefixes (original, r_, c_, xc_, etc.)
 * - Operations per variant: resize, thumbnail, smartcrop, or copy
 * - Automatically deletes variants on model delete
 *
 * Typical config:
 *
 * 'uploadAlias'    => '@upload/images/event',
 * 'uploadAttribute'=> 'upload',
 * 'imageAttribute' => 'image',
 * 'baseName'       => 'trio-metamorfoze',
 * 'variants' => [
 *     ''    => ['resize'    => [2500, 2500]],  // original
 *     'r_'  => ['resize'    => [1920, 1920]],
 *     'c_'  => ['smartcrop' => [200, 200]],
 *     'xc_' => ['thumbnail' => [100, 100]],
 * ],
 */
class UploadBehavior extends Behavior
{
    /** @var string attribute holding UploadedFile */
    public string $uploadAttribute = 'upload';

    /** @var string attribute storing final filename */
    public string $imageAttribute = 'image';

    /**
     * @var string Yii alias to upload base dir, e.g. '@upload/images/event'
     *
     * IMPORTANT:
     *  You should have alias '@upload' defined in your app, e.g.:
     *  Yii::setAlias('@upload', dirname(dirname(__DIR__)) . '/upload');
     */
    public string $uploadAlias;

    /**
     * @var string|\Closure base name for generated file (without extension)
     *
     * - string: 'trio-metamorfoze'
     * - Closure: fn($owner) => \yii\helpers\Inflector::slug($owner->title)
     */
    public $baseName = 'image';

    /**
     * Variants config:
     *
     * 'variants' => [
     *     ''    => ['resize'    => [2500, 2500]],  // original
     *     'r_'  => ['resize'    => [1920, 1920]],
     *     'c_'  => ['smartcrop' => [200, 200]],
     *     'xc_' => ['thumbnail' => [100, 100]],
     * ]
     *
     * Supported keys per variant:
     * - resize    => [width, height]
     * - thumbnail => [width, height]
     * - smartcrop => [width, height]   (uses AutoCropper if available)
     *
     * If no key is provided, the original file is copied to the prefixed name.
     *
     * @var array
     */
    public array $variants = [];

    /** @var UploadedFile|null */
    private ?UploadedFile $uploadedFile = null;

    /** @var string|null */
    private ?string $newFileName = null;

    public function events(): array
    {
        return [
            \yii\db\ActiveRecord::EVENT_BEFORE_VALIDATE => 'captureUpload',
            \yii\db\ActiveRecord::EVENT_AFTER_INSERT    => 'processUpload',
            \yii\db\ActiveRecord::EVENT_AFTER_UPDATE    => 'processUpload',
            \yii\db\ActiveRecord::EVENT_BEFORE_DELETE   => 'deleteVariants',
        ];
    }

    /**
     * Capture UploadedFile instance before validation.
     */
    public function captureUpload(): void
    {
        $this->uploadedFile = UploadedFile::getInstance($this->owner, $this->uploadAttribute);
    }

    /**
     * Ensure upload directories exist:
     * - @upload
     * - $this->uploadAlias (which can be nested)
     */
    protected function ensureUploadDir(): void
    {
        $base = Yii::getAlias('@upload');
        $target = Yii::getAlias($this->uploadAlias);

        if (!is_dir($base)) {
            FileHelper::createDirectory($base, 0777, true);
        }

        if (!is_dir($target)) {
            FileHelper::createDirectory($target, 0777, true);
        }
    }

    /**
     * Process the uploaded file and generate variants after INSERT/UPDATE.
     */
    public function processUpload(): void
    {
        if (!$this->uploadedFile) {
            return;
        }

        $owner = $this->owner;
        $this->ensureUploadDir();

        // Remove old files first, if present
        $oldFilename = $owner->{$this->imageAttribute};
        if (!empty($oldFilename)) {
            $this->deleteVariants();
        }

        // Determine base name
        $baseName = is_callable($this->baseName)
            ? call_user_func($this->baseName, $owner)
            : $this->baseName;

        // Generate new file name
        $this->newFileName = $baseName . '-' . mt_rand(1000, 9999) . '.' . $this->uploadedFile->extension;

        $dir = Yii::getAlias($this->uploadAlias);
        $originalPath = $dir . DIRECTORY_SEPARATOR . $this->newFileName;

        // Save original upload
        $this->uploadedFile->saveAs($originalPath);

        // If no variants defined, at least store the original
        if (empty($this->variants)) {
            $this->variants = [
                '' => ['resize' => [2500, 2500]],
            ];
        }

        // Generate variants
        foreach ($this->variants as $prefix => $config) {
            $target = $dir . DIRECTORY_SEPARATOR . $prefix . $this->newFileName;

            if (isset($config['resize'])) {
                [$w, $h] = $config['resize'];
                Image::resize($originalPath, $w, $h)
                    ->save($target, ['quality' => $config['quality'] ?? 80])
                    ->strip();
            } elseif (isset($config['thumbnail'])) {
                [$w, $h] = $config['thumbnail'];
                Image::thumbnail($originalPath, $w, $h)
                    ->save($target, ['quality' => $config['quality'] ?? 80]);
            } elseif (isset($config['smartcrop'])) {
                [$w, $h] = $config['smartcrop'];

                if (class_exists('\nedarta\smartcropper\AutoCropper')) {
                    /** @var callable $cropper */
                    $cropper = ['\nedarta\smartcropper\AutoCropper', 'cropAndSave'];
                    $cropper($originalPath, $w, $h, $target);
                } else {
                    // Fallback: simple thumbnail if AutoCropper is not installed
                    Image::thumbnail($originalPath, $w, $h)
                        ->save($target, ['quality' => $config['quality'] ?? 80]);
                }
            } else {
                // Default: copy original as-is
                if ($originalPath !== $target) {
                    copy($originalPath, $target);
                }
            }
        }

        // Persist filename in the model (without re-triggering validation)
        $owner->{$this->imageAttribute} = $this->newFileName;
        $owner->updateAttributes([$this->imageAttribute => $this->newFileName]);
    }

    /**
     * Delete all variant files (called on BEFORE_DELETE).
     */
    public function deleteVariants(): void
    {
        $owner = $this->owner;
        $filename = $owner->{$this->imageAttribute};

        if (empty($filename)) {
            return;
        }

        $dir = Yii::getAlias($this->uploadAlias);

        // Ensure we also delete the "original" with empty prefix if configured
        $prefixes = array_keys($this->variants);
        if (!in_array('', $prefixes, true)) {
            $prefixes[] = '';
        }

        foreach ($prefixes as $prefix) {
            $file = $dir . DIRECTORY_SEPARATOR . $prefix . $filename;
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
