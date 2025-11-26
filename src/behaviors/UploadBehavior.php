<?php

namespace nedarta\behaviors;

use Yii;
use yii\base\Behavior;
use yii\db\ActiveRecord;
use yii\web\UploadedFile;
use yii\helpers\FileHelper;
use yii\imagine\Image;

/**
 * UploadBehavior handles file uploads, optional format conversion,
 * EXIF-based auto-rotation and generation of image variants.
 */
class UploadBehavior extends Behavior
{
    /** @var string Attribute containing the UploadedFile instance */
    public string $uploadAttribute = 'upload';

    /** @var string Attribute where the final filename is stored */
    public string $imageAttribute = 'image';

    /** @var string Yii alias pointing to the upload directory */
    public string $uploadAlias;

    /**
     * Base filename for generated images (without extension).
     * Can be a string or a callable fn($owner): string.
     *
     * @var string|callable
     */
    public $baseName = 'image';

    /**
     * Force-convert uploaded file to this extension (e.g. "jpg", "jiff", "png", "webp").
     *
     * @var string|null
     */
    public ?string $forceConvert = null;

    /**
     * Whether to auto-rotate JPEG images based on EXIF orientation.
     *
     * @var bool
     */
    public bool $autoRotate = true;

    /**
     * Variant configuration.
     *
     * Example:
     * [
     *     ''   => ['resize'    => [1600, 1600]],
     *     'r_' => ['resize'    => [1200, 1200]],
     *     'c_' => ['smartcrop' => [200, 200], 'dependsOn' => 'r_'],
     *     'xc_' => ['thumbnail' => [100, 100], 'dependsOn' => 'c_'],
     * ]
     *
     * @var array
     */
    public array $variants = [];

    /** @var UploadedFile|null */
    private ?UploadedFile $uploadedFile = null;

    /** @var string|null */
    private ?string $newFileName = null;

    /**
     * @inheritdoc
     */
    public function events(): array
    {
        return [
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'captureUpload',
            ActiveRecord::EVENT_AFTER_INSERT    => 'processUpload',
            ActiveRecord::EVENT_AFTER_UPDATE    => 'processUpload',
            ActiveRecord::EVENT_BEFORE_DELETE   => 'deleteVariants',
        ];
    }

    /**
     * Captures the uploaded file instance from the owner model.
     *
     * @return void
     */
    public function captureUpload(): void
    {
        $this->uploadedFile = UploadedFile::getInstance($this->owner, $this->uploadAttribute);
    }

    /**
     * Ensures that the base upload directory and the target directory exist.
     *
     * @return void
     */
    protected function ensureUploadDir(): void
    {
        $base   = Yii::getAlias('@upload');
        $target = Yii::getAlias($this->uploadAlias);

        if (!is_dir($base)) {
            FileHelper::createDirectory($base, 0777, true);
        }

        if (!is_dir($target)) {
            FileHelper::createDirectory($target, 0777, true);
        }
    }

    /**
     * Reads EXIF orientation value from a file.
     *
     * @param string $file Absolute path to the image.
     * @return int|null EXIF orientation or null if not available.
     */
    protected function readExifOrientation(string $file): ?int
    {
        if (!function_exists('exif_read_data')) {
            return null;
        }

        try {
            $exif = @exif_read_data($file, 'IFD0', false);
            return !empty($exif['Orientation']) ? (int)$exif['Orientation'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Removes EXIF orientation and other metadata from an image,
     * using Imagick if available, otherwise falling back to GD (JPEG only).
     *
     * @param string $filePath Absolute path to image.
     * @param string $ext      File extension (jpg, jpeg, etc.).
     * @param int    $quality  JPEG quality for re-encoding in fallback.
     * @return void
     */
    protected function resetOrientationTag(string $filePath, string $ext, int $quality = 92): void
    {
        $ext = strtolower($ext);

        // Imagick path
        if (class_exists(\Imagick::class)) {
            try {
                $im = new \Imagick($filePath);
                $im->setImageOrientation(\Imagine\Image\ImageInterface::ORIENTATION_TOPLEFT);
                $im->stripImage();
                $im->setImageCompressionQuality($quality);
                $im->writeImage($filePath);
                $im->destroy();
                return;
            } catch (\Throwable) {
                // fall through to GD
            }
        }

        // GD fallback (JPEG only)
        if (in_array($ext, ['jpg', 'jpeg'], true)
            && function_exists('imagecreatefromjpeg')
            && function_exists('imagejpeg')
        ) {
            try {
                $gd = @imagecreatefromjpeg($filePath);
                if ($gd) {
                    @imagejpeg($gd, $filePath, $quality);
                    imagedestroy($gd);
                }
            } catch (\Throwable) {
                // silently ignore
            }
        }
    }

    /**
     * Applies EXIF-based rotation to the image file (if needed),
     * then resets orientation metadata.
     *
     * @param string   $filePath    Absolute path to the image.
     * @param int|null $orientation EXIF orientation value.
     * @param string   $ext         File extension.
     * @return void
     */
    protected function autoRotateImage(string $filePath, ?int $orientation, string $ext): void
    {
        if (!$this->autoRotate) {
            return;
        }

        $ext = strtolower($ext);
        if (!in_array($ext, ['jpg', 'jpeg'], true)) {
            return;
        }

        if (!$orientation || $orientation === 1) {
            // no rotation needed, still ensure orientation tag is clean
            $this->resetOrientationTag($filePath, $ext);
            return;
        }

        try {
            $image = Image::getImagine()->open($filePath);

            switch ($orientation) {
                case 2:
                    $image->flipHorizontally();
                    break;
                case 3:
                    $image->rotate(0);
                    break;
                case 4:
                    $image->flipVertically();
                    break;
                case 5:
                    $image->flipHorizontally()->rotate(-90);
                    break;

                /**
                 * Orientation 6:
                 * For Android/Samsung it effectively means "rotate +90°".
                 */
                case 6:
                    $image->rotate(90);
                    break;

                case 7:
                    $image->flipHorizontally()->rotate(90);
                    break;
                case 8:
                    $image->rotate(90);
                    break;
            }

            if (method_exists($image, 'strip')) {
                $image->strip();
            }

            $image->save($filePath, ['jpeg_quality' => 92]);

        } catch (\Throwable) {
            // ignore rotation failures
        }

        $this->resetOrientationTag($filePath, $ext, 92);
    }

    /**
     * Main upload processing:
     *  - removes old variants
     *  - saves original (optionally converted)
     *  - applies EXIF-based rotation
     *  - generates all configured variants
     *
     * @return void
     */
    public function processUpload(): void
    {
        if (!$this->uploadedFile) {
            return;
        }

        $owner = $this->owner;
        $this->ensureUploadDir();

        // remove old versions
        if (!empty($owner->{$this->imageAttribute})) {
            $this->deleteVariants();
        }

        // compute filename
        $baseName = is_callable($this->baseName)
            ? call_user_func($this->baseName, $owner)
            : $this->baseName;

        $ext = strtolower($this->uploadedFile->extension);
        if ($this->forceConvert) {
            $ext = strtolower($this->forceConvert);
        }

        $this->newFileName = $baseName . '-' . mt_rand(1000, 9999) . '.' . $ext;

        $dir          = Yii::getAlias($this->uploadAlias);
        $originalPath = $dir . DIRECTORY_SEPARATOR . $this->newFileName;
        $tempPath     = $originalPath . '.tmp';

        $originalTemp = $this->uploadedFile->tempName;

        // read EXIF orientation from temp file
        $orientation = $this->readExifOrientation($originalTemp);

        // save original (optionally converting)
        if ($this->forceConvert) {
            $this->uploadedFile->saveAs($tempPath);

            Image::getImagine()
                ->open($tempPath)
                ->save($originalPath, ['quality' => 90]);

            @unlink($tempPath);
        } else {
            Image::getImagine()
                ->open($originalTemp)
                ->save($originalPath, ['jpeg_quality' => 95]);
        }

        // apply rotation + orientation cleanup on original
        $this->autoRotateImage($originalPath, $orientation, $ext);

        // default variants if none provided
        if (empty($this->variants)) {
            $this->variants = [
                '' => ['resize' => [2500, 2500]],
            ];
        }

        // derive extension once for variant cleanup
        $variantExt = strtolower(pathinfo($this->newFileName, PATHINFO_EXTENSION));

        // generate variants (respecting dependsOn)
        foreach ($this->variants as $prefix => $config) {
            $inputFile = $originalPath;

            if (isset($config['dependsOn'])) {
                $inputFile = $dir . DIRECTORY_SEPARATOR . $config['dependsOn'] . $this->newFileName;
            }

            $target = $dir . DIRECTORY_SEPARATOR . $prefix . $this->newFileName;

            // resize
            if (isset($config['resize'])) {
                [$w, $h] = $config['resize'];
                $img     = Image::resize($inputFile, $w, $h);
                if (method_exists($img, 'strip')) {
                    $img->strip();
                }
                $img->save($target, ['quality' => $config['quality'] ?? 80]);
                $this->resetOrientationTag($target, $variantExt);

            // thumbnail
            } elseif (isset($config['thumbnail'])) {
                [$w, $h] = $config['thumbnail'];
                $img     = Image::thumbnail($inputFile, $w, $h);
                if (method_exists($img, 'strip')) {
                    $img->strip();
                }
                $img->save($target, ['quality' => $config['quality'] ?? 80]);
                $this->resetOrientationTag($target, $variantExt);

            // smartcrop (external library)
            } elseif (isset($config['smartcrop'])) {
                [$w, $h] = $config['smartcrop'];

                if (class_exists('\nedarta\autocrop\AutoCropper')) {
                    \nedarta\autocrop\AutoCropper::cropAndSave($inputFile, $w, $h, $target);
                    // enforce clean EXIF even if AutoCropper doesn't strip
                    $this->resetOrientationTag($target, $variantExt);
                } else {
                    $img = Image::thumbnail($inputFile, $w, $h);
                    if (method_exists($img, 'strip')) {
                        $img->strip();
                    }
                    $img->save($target, ['quality' => $config['quality'] ?? 80]);
                    $this->resetOrientationTag($target, $variantExt);
                }

            // fallback: simple copy
            } else {
                if ($inputFile !== $target) {
                    copy($inputFile, $target);
                }
                $this->resetOrientationTag($target, $variantExt);
            }
        }

        // store filename in owner
        $owner->{$this->imageAttribute} = $this->newFileName;
        $owner->updateAttributes([$this->imageAttribute => $this->newFileName]);

        $this->uploadedFile = null;
    }

    /**
     * Deletes all variants and the original image for the current record.
     *
     * @return void
     */
    public function deleteVariants(): void
    {
        $owner    = $this->owner;
        $filename = $owner->{$this->imageAttribute};

        if (!$filename) {
            return;
        }

        $dir      = Yii::getAlias($this->uploadAlias);
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
