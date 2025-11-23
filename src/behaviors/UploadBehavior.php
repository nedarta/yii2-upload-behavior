<?php

namespace nedarta\behaviors;

use Yii;
use yii\base\Behavior;
use yii\web\UploadedFile;
use yii\helpers\FileHelper;
use yii\imagine\Image;
use Imagine\Gd\Imagine as GdImagine;
use Imagine\Imagick\Imagine as ImagickImagine;

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
 * - optional forced format conversion (forceConvert: jpg, png, webp, jpeg)
 * - selectable image engine: GD or Imagick
 */
class UploadBehavior extends Behavior
{
    /** @var string attribute that receives UploadedFile */
    public string $uploadAttribute = 'upload';

    /** @var string attribute where filename will be stored */
    public string $imageAttribute = 'image';

    /** @var string Yii alias for storage folder */
    public string $uploadAlias;

    /**
     * Base filename before random number.
     * Can be string or Closure.
     *
     * @var string|\Closure
     */
    public $baseName = 'image';

    /**
     * Forces output file extension for original + variants.
     *
     * @var string|null
     */
    public ?string $forceConvert = null;

    /**
     * Image engine:
     * - auto (default): Imagick if available, else GD
     * - imagick: use Imagick
     * - gd: use GD
     *
     * @var string
     */
    public string $cropEngine = 'auto';

    /**
     * Variants configuration.
     *
     * @var array
     */
    public array $variants = [];

    private ?UploadedFile $uploadedFile = null;
    private ?string $newFileName = null;

    /** @var \Imagine\Image\ImagineInterface */
    private $imagine;

    public function init()
    {
        parent::init();
        $this->initEngine();
    }

    /**
     * Initialize the Imagine engine based on cropEngine setting.
     */
    protected function initEngine(): void
    {
        if ($this->cropEngine === 'imagick') {
            if (extension_loaded('imagick')) {
                $this->imagine = new ImagickImagine();
            } else {
                throw new \RuntimeException("cropEngine='imagick' requires the imagick PHP extension.");
            }
        }

        elseif ($this->cropEngine === 'gd') {
            $this->imagine = new GdImagine();
        }

        else { // auto
            if (extension_loaded('imagick')) {
                $this->imagine = new ImagickImagine();
            } else {
                $this->imagine = new GdImagine();
            }
        }
    }

    public function events(): array
    {
        return [
            \yii\db\ActiveRecord::EVENT_BEFORE_VALIDATE => 'captureUpload',
            \yii\db\ActiveRecord::EVENT_AFTER_INSERT    => 'processUpload',
            \yii\db\ActiveRecord::EVENT_AFTER_UPDATE    => 'processUpload',
            \yii\db\ActiveRecord::EVENT_BEFORE_DELETE   => 'deleteVariants',
        ];
    }

    public function captureUpload(): void
    {
        $this->uploadedFile = UploadedFile::getInstance($this->owner, $this->uploadAttribute);
    }

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

    public function processUpload(): void
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

        $this->newFileName = $baseName . '-' . mt_rand(1000, 9999) . '.' . $ext;

        $dir = Yii::getAlias($this->uploadAlias);
        $originalPath = $dir . DIRECTORY_SEPARATOR . $this->newFileName;
        $tempPath = $originalPath . '.tmp';

        // Save original
        if (!empty($this->forceConvert)) {
            $this->uploadedFile->saveAs($tempPath);

            // Use selected Imagine engine
            $this->imagine->open($tempPath)->save($originalPath, ['quality' => 90]);

            @unlink($tempPath);
        } else {
            $this->uploadedFile->saveAs($originalPath);
        }

        // Default variant
        if (empty($this->variants)) {
            $this->variants = [
                '' => ['resize' => [2500, 2500]],
            ];
        }

        // Determine dependsOn sources
        $variantSources = [];
        foreach ($this->variants as $prefix => $config) {
            $variantSources[$prefix] = $config['dependsOn'] ?? null;
        }

        /**
         * PROCESS VARIANTS
         */
        foreach ($this->variants as $prefix => $config) {
            $target = $dir . DIRECTORY_SEPARATOR . $prefix . $this->newFileName;

            // Determine input
            if (!empty($variantSources[$prefix])) {
                $inputPrefix = $variantSources[$prefix];
                $inputFile = $dir . DIRECTORY_SEPARATOR . $inputPrefix . $this->newFileName;

                if (!is_file($inputFile)) {
                    throw new \RuntimeException("Variant '{$prefix}' dependsOn '{$inputPrefix}', but '{$inputFile}' does not exist.");
                }
            } else {
                $inputFile = $originalPath;
            }

            //
            // PROCESS VARIANT
            //
            if (isset($config['resize'])) {
                [$w, $h] = $config['resize'];

                $this->imagine
                    ->open($inputFile)
                    ->resize(new \Imagine\Image\Box($w, $h))
                    ->save($target, ['quality' => $config['quality'] ?? 80]);

            } elseif (isset($config['thumbnail'])) {
                [$w, $h] = $config['thumbnail'];

                Image::thumbnail($inputFile, $w, $h, null, $this->imagine)
                    ->save($target, ['quality' => $config['quality'] ?? 80]);

            } elseif (isset($config['smartcrop'])) {
                [$w, $h] = $config['smartcrop'];

                if (class_exists('\nedarta\autocrop\AutoCropper')) {
                    \nedarta\autocrop\AutoCropper::cropAndSave($inputFile, $w, $h, $target);
                } else {
                    Image::thumbnail($inputFile, $w, $h, null, $this->imagine)
                        ->save($target, ['quality' => $config['quality'] ?? 80]);
                }

            } else {
                copy($inputFile, $target);
            }
        }

        // Save filename into model
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
