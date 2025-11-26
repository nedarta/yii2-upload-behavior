<?php

namespace nedarta\behaviors;

use Yii;
use yii\base\Behavior;
use yii\web\UploadedFile;
use yii\helpers\FileHelper;
use yii\imagine\Image;

/**
 * UploadBehavior – full-featured Yii2 image upload behavior with:
 *
 * - UploadedFile handling
 * - automatic nested directory creation
 * - variant processing:
 *      - resize
 *      - thumbnail
 *      - smartcrop (via nedarta/yii2-smart-cropper)
 *      - copy fallback
 * - dependency-based variant pipeline (dependsOn)
 * - removing old image variants on update
 * - deleting all variants on model delete
 * - optional forced format conversion (e.g. everything -> webp)
 *
 * Typical usage:
 *
 * ```php
 * public function behaviors()
 * {
 *     return [
 *         [
 *             'class' => UploadBehavior::class,
 *             'imageAttribute' => 'image',
 *             'uploadAlias' => '@webroot/uploads/news',
 *             'variants' => [
 *                 '' => ['resize' => [2500, 2500]], // original
 *                 'thumb_' => ['thumbnail' => [400, 300]],
 *             ],
 *         ],
 *     ];
 * }
 * ```
 */
class UploadBehavior extends Behavior
{
    /**
     * @var string Attribute name in the owner model that stores filename.
     */
    public string $imageAttribute = 'image';

    /**
     * @var string UploadedFile instance attribute name in the owner model.
     */
    public string $fileAttribute = 'uploadedFile';

    /**
     * @var string Upload base path alias (directory where images will be stored).
     *
     * Example: '@webroot/uploads/news'
     */
    public string $uploadAlias = '@webroot/uploads';

    /**
     * @var array Variants configuration.
     *
     * Example:
     * [
     *     '' => ['resize' => [2500, 2500]],
     *     'thumb_' => ['thumbnail' => [400, 300]],
     * ]
     *
     * Each variant may contain:
     *  - 'resize' => [width, height]
     *  - 'thumbnail' => [width, height]
     *  - 'smartcrop' => [width, height]
     *  - 'copy' => true (just copy original)
     *  - 'dependsOn' => 'prefix' (string) – variant dependency
     */
    public array $variants = [];

    /**
     * @var string|null Forced output extension for stored images (e.g. 'webp', 'jpg').
     * If null, original extension is preserved.
     */
    public ?string $forceConvert = null;

    /**
     * @var callable|string Base filename (without extension).
     * If callable, it receives $owner and must return string.
     * Otherwise static string is used.
     */
    public $baseName = 'image';

    /**
     * @var UploadedFile|null
     */
    protected ?UploadedFile $uploadedFile = null;

    /**
     * @var string|null New generated filename.
     */
    protected ?string $newFileName = null;

    /**
     * {@inheritdoc}
     */
    public function events(): array
    {
        return [
            \yii\db\ActiveRecord::EVENT_BEFORE_VALIDATE => 'handleFile',
            \yii\db\ActiveRecord::EVENT_BEFORE_INSERT => 'beforeSave',
            \yii\db\ActiveRecord::EVENT_BEFORE_UPDATE => 'beforeSave',
            \yii\db\ActiveRecord::EVENT_AFTER_INSERT => 'afterSave',
            \yii\db\ActiveRecord::EVENT_AFTER_UPDATE => 'afterSave',
            \yii\db\ActiveRecord::EVENT_BEFORE_DELETE => 'beforeDelete',
        ];
    }

    /**
     * Grab UploadedFile from owner.
     */
    public function handleFile(): void
    {
        $owner = $this->owner;
        $this->uploadedFile = UploadedFile::getInstance($owner, $this->fileAttribute);
    }

    /**
     * Ensure upload directory exists.
     */
    protected function ensureUploadDir(): void
    {
        $dir = Yii::getAlias($this->uploadAlias);
        if (!is_dir($dir)) {
            FileHelper::createDirectory($dir, 0775, true);
        }
    }

    /**
     * Before saving model – if new file uploaded, prepare upload.
     */
    public function beforeSave(): void
    {
        if (!$this->uploadedFile) {
            return;
        }

        $this->processUpload();
    }

    /**
     * Core upload processing (filename generation, saving original, variants).
     */
    function processUpload(): void
    {
        if (!$this->uploadedFile) {
            return;
        }

        $owner = $this->owner;
        $this->ensureUploadDir();

        // Remove old files first
        if (!empty($owner->{$this->imageAttribute})) {
            $this->deleteVariants();
        }

        // Compute base filename
        $baseName = is_callable($this->baseName)
            ? call_user_func($this->baseName, $owner)
            : $this->baseName;

        // Define extension
        $ext = $this->uploadedFile->extension;
        if (!empty($this->forceConvert)) {
            $ext = $this->forceConvert;
        }

        // Final filename
        $this->newFileName = $baseName . '-' . mt_rand(1000, 9999) . '.' . $ext;

        $dir = Yii::getAlias($this->uploadAlias);
        $originalPath = $dir . DIRECTORY_SEPARATOR . $this->newFileName;
        $tempPath = $originalPath . '.tmp';

        // Save original upload temporarily (if converting)
        if (!empty($this->forceConvert)) {
            $this->uploadedFile->saveAs($tempPath);

            // Fix orientation & strip EXIF on the temporary original file
            $this->fixOrientationAndStripExif($tempPath);

            // Convert temp file to forced extension
            Image::getImagine()
                ->open($tempPath)
                ->save($originalPath, ['quality' => 90]);

            @unlink($tempPath);

        } else {
            // No conversion, save directly
            $this->uploadedFile->saveAs($originalPath);

            // Fix orientation & strip EXIF on the saved file
            $this->fixOrientationAndStripExif($originalPath);
        }

        // Default variant
        if (empty($this->variants)) {
            $this->variants = [
                '' => ['resize' => [2500, 2500]],
            ];
        }

        // -------------------------
        // VARIANT PIPELINE PROCESS
        // -------------------------

        // Determine variant dependency order
        $ordered = $this->orderVariantsByDependencies($this->variants);

        // Keep track of generated variant paths
        $variantPaths = [];

        foreach ($ordered as $prefix => $config) {
            $variantPath = $dir . DIRECTORY_SEPARATOR . $prefix . $this->newFileName;

            // Determine source path:
            // - if dependsOn is set, use that variant as input
            // - otherwise use the original file
            $sourcePath = $originalPath;

            if (!empty($config['dependsOn'])) {
                $depPrefix = $config['dependsOn'];
                if (!isset($variantPaths[$depPrefix])) {
                    // If dependency missing, skip generating this variant
                    continue;
                }
                $sourcePath = $variantPaths[$depPrefix];
            }

            // Process variant according to config
            $this->processVariant($sourcePath, $variantPath, $config);

            // Save path for possible dependent variants
            $variantPaths[$prefix] = $variantPath;
        }

        // Persist new filename to owner
        $owner->{$this->imageAttribute} = $this->newFileName;
    }

    /**
     * Determine variant processing order based on `dependsOn` relations.
     *
     * Simple topological ordering, assuming no circular dependencies.
     *
     * @param array $variants
     * @return array
     */
    protected function orderVariantsByDependencies(array $variants): array
    {
        $ordered = [];
        $visited = [];

        $visit = function ($prefix) use (&$visit, &$ordered, &$visited, $variants) {
            if (isset($visited[$prefix])) {
                return;
            }
            $visited[$prefix] = true;

            $config = $variants[$prefix];

            if (!empty($config['dependsOn'])) {
                $dep = $config['dependsOn'];
                if (isset($variants[$dep])) {
                    $visit($dep);
                }
            }

            $ordered[$prefix] = $config;
        };

        foreach (array_keys($variants) as $prefix) {
            $visit($prefix);
        }

        return $ordered;
    }

    /**
     * Process a single variant according to its configuration.
     *
     * Supported keys:
     *  - 'resize'    => [w, h]
     *  - 'thumbnail' => [w, h]
     *  - 'smartcrop' => [w, h]
     *  - 'copy'      => true
     *
     * @param string $sourcePath
     * @param string $targetPath
     * @param array $config
     */
    protected function processVariant(string $sourcePath, string $targetPath, array $config): void
    {
        // Copy-only variant
        if (!empty($config['copy'])) {
            copy($sourcePath, $targetPath);
            return;
        }

        $imagine = Image::getImagine();
        $image = $imagine->open($sourcePath);

        // Smart crop variant via separate extension
        if (isset($config['smartcrop'][0], $config['smartcrop'][1])) {
            $width = (int)$config['smartcrop'][0];
            $height = (int)$config['smartcrop'][1];

            if (class_exists('\\nedarta\\smartcrop\\SmartCropper')) {
                /** @var \nedarta\smartcrop\SmartCropper $cropper */
                $cropper = Yii::createObject([
                    'class' => '\\nedarta\\smartcrop\\SmartCropper',
                ]);

                $image = $cropper->smartCrop($image, $width, $height);
                $image->save($targetPath, ['quality' => 90]);
                return;
            }

            // Fallback to simple thumbnail if SmartCropper not available
            $thumbnail = Image::thumbnail($sourcePath, $width, $height);
            $thumbnail->save($targetPath, ['quality' => 90]);
            return;
        }

        // Thumbnail variant
        if (isset($config['thumbnail'][0], $config['thumbnail'][1])) {
            $width = (int)$config['thumbnail'][0];
            $height = (int)$config['thumbnail'][1];

            $thumbnail = Image::thumbnail($sourcePath, $width, $height);
            $thumbnail->save($targetPath, ['quality' => 90]);
            return;
        }

        // Resize variant
        if (isset($config['resize'][0], $config['resize'][1])) {
            $width = (int)$config['resize'][0];
            $height = (int)$config['resize'][1];

            $size = $image->getSize();
            $ratio = min($width / $size->getWidth(), $height / $size->getHeight(), 1);

            $newSize = $size->scale($ratio);
            $image->resize($newSize)->save($targetPath, ['quality' => 90]);
            return;
        }

        // Default: just copy
        copy($sourcePath, $targetPath);
    }

    /**
     * After model is saved, write attribute with final filename.
     */
    public function afterSave(): void
    {
        if ($this->newFileName === null) {
            return;
        }

        $owner = $this->owner;
        $owner->{$this->imageAttribute} = $this->newFileName;
        $owner->updateAttributes([$this->imageAttribute => $this->newFileName]);
    }

    public function deleteVariants(): void
    {
        $owner = $this->owner;
        $filename = $owner->{$this->imageAttribute};

        if (empty($filename)) {
            return;
        }

        $dir = Yii::getAlias($this->uploadAlias);

        // Must delete original even if no original variant defined
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

    /**
     * Fix JPEG EXIF orientation and then strip EXIF/metadata.
     *
     * Tries Imagick first (if available), falls back to GD.
     *
     * @param string $path Absolute filesystem path to the saved image.
     */
    protected function fixOrientationAndStripExif(string $path): void
    {
        // Only handle JPEGs; EXIF orientation is relevant there.
        if (!is_file($path)) {
            return;
        }

        $info = @getimagesize($path);
        if (!$info || empty($info['mime']) || $info['mime'] !== 'image/jpeg') {
            return;
        }

        // If EXIF extension is not available, we can still try Imagick auto-orientation.
        $hasExif = function_exists('exif_read_data');

        // Prefer Imagick if available
        if (class_exists('\\Imagick')) {
            try {
                $img = new \Imagick($path);

                $orientation = null;

                // Try to read orientation from Imagick property first
                $orientationProp = $img->getImageProperty('exif:Orientation');
                if (!empty($orientationProp)) {
                    $orientation = (int)$orientationProp;
                } elseif ($hasExif) {
                    $exif = @exif_read_data($path);
                    if (!empty($exif['Orientation'])) {
                        $orientation = (int)$exif['Orientation'];
                    }
                }

                if (!empty($orientation)) {
                    switch ($orientation) {
                        case 3:
                            $img->rotateImage('#000000', 180);
                            break;
                        case 6:
                            $img->rotateImage('#000000', 90);
                            break;
                        case 8:
                            $img->rotateImage('#000000', -90);
                            break;
                    }

                    // Normalize orientation so that consumers don't re-rotate
                    if (method_exists($img, 'setImageOrientation')) {
                        $img->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
                    }
                }

                // Strip all metadata, including EXIF
                if (method_exists($img, 'stripImage')) {
                    $img->stripImage();
                }

                $img->writeImage($path);
                $img->destroy();

                return;
            } catch (\Throwable $e) {
                // Fallback to GD below
            }
        }

        // GD fallback: rotate & re-encode (this drops EXIF automatically)
        if (!$hasExif) {
            return;
        }

        $exif = @exif_read_data($path);
        if (empty($exif['Orientation'])) {
            return;
        }

        $orientation = (int)$exif['Orientation'];

        $img = @imagecreatefromjpeg($path);
        if (!$img) {
            return;
        }

        switch ($orientation) {
            case 3:
                $img = imagerotate($img, 180, 0);
                break;
            case 6:
                $img = imagerotate($img, -90, 0);
                break;
            case 8:
                $img = imagerotate($img, 90, 0);
                break;
            default:
                // No change needed
                break;
        }

        // Re-save JPEG; EXIF is discarded.
        @imagejpeg($img, $path, 90);
        imagedestroy($img);
    }
}

