<?php

namespace nedarta\behaviors;

use Yii;
use yii\base\Behavior;
use yii\web\UploadedFile;
use yii\helpers\FileHelper;
use yii\imagine\Image;
use Imagine\Gd\Imagine as GdImagine;
use Imagine\Imagick\Imagine as ImagickImagine;
use Imagine\Image\Box;

/**
 * UploadBehavior – full-featured Yii2 image upload behavior with:
 *
 * - UploadedFile handling
 * - automatic nested directory creation
 * - variant processing with pipeline
 * - resize, thumbnail, smartcrop, copy fallback
 * - forced conversion
 * - selectable local cropEngine: gd / imagick / auto
 */
class UploadBehavior extends Behavior
{
    public string $uploadAttribute = 'upload';
    public string $imageAttribute = 'image';
    public string $uploadAlias;

    public $baseName = 'image';
    public ?string $forceConvert = null;

    /**
     * cropEngine:
     * - auto      (default)
     * - gd
     * - imagick
     *
     * @var string
     */
    public string $cropEngine = 'auto';

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
     * Initialize local Imagine engine (not global Yii2 Image engine)
     */
    protected function initEngine(): void
    {
        if ($this->cropEngine === 'imagick') {
            if (!extension_loaded('imagick')) {
                throw new \RuntimeException("cropEngine='imagick' requires imagick extension.");
            }
            $this->imagine = new ImagickImagine();
            return;
        }

        if ($this->cropEngine === 'gd') {
            $this->imagine = new GdImagine();
            return;
        }

        // auto
        if (extension_loaded('imagick')) {
            $this->imagine = new ImagickImagine();
        } else {
            $this->imagine = new GdImagine();
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

        if (!empty($owner->{$this->imageAttribute})) {
            $this->deleteVariants();
        }

        // Generate filename
        $baseName = is_callable($this->baseName)
            ? call_user_func($this->baseName, $owner)
            : $this->baseName;

        $ext = $this->forceConvert ?: $this->uploadedFile->extension;

        $this->newFileName = $baseName . '-' . mt_rand(1000, 9999) . '.' . $ext;

        $dir = Yii::getAlias($this->uploadAlias);
        $originalPath = $dir . DIRECTORY_SEPARATOR . $this->newFileName;
        $tempPath = $originalPath . '.tmp';

        // Save file
        if ($this->forceConvert) {
            $this->uploadedFile->saveAs($tempPath);

            $this->imagine
                ->open($tempPath)
                ->save($originalPath, ['quality' => 90]);

            @unlink($tempPath);
        } else {
            $this->uploadedFile->saveAs($originalPath);
        }

        if (empty($this->variants)) {
            $this->variants = [
                '' => ['resize' => [2500, 2500]],
            ];
        }

        // Map input sources
        $variantSources = [];
        foreach ($this->variants as $prefix => $config) {
            $variantSources[$prefix] = $config['dependsOn'] ?? null;
        }

        /**
         * PROCESS VARIANTS
         */
        foreach ($this->variants as $prefix => $config) {

            $target = $dir . DIRECTORY_SEPARATOR . $prefix . $this->newFileName;

            // Source file
            if (!empty($variantSources[$prefix])) {
                $srcPrefix = $variantSources[$prefix];
                $inputFile = $dir . DIRECTORY_SEPARATOR . $srcPrefix . $this->newFileName;
            } else {
                $inputFile = $originalPath;
            }

            // Resize
            if (isset($config['resize'])) {
                [$w, $h] = $config['resize'];

                $this->imagine
                    ->open($inputFile)
                    ->resize(new Box($w, $h))
                    ->save($target, ['quality' => $config['quality'] ?? 80]);

                continue;
            }

            // Thumbnail
            if (isset($config['thumbnail'])) {
                [$w, $h] = $config['thumbnail'];

                $this->imagine
                    ->open($inputFile)
                    ->thumbnail(new Box($w, $h))
                    ->save($target, ['quality' => $config['quality'] ?? 80]);

                continue;
            }

            // Smartcrop
            if (isset($config['smartcrop'])) {
                [$w, $h] = $config['smartcrop'];

                if (class_exists('\nedarta\autocrop\AutoCropper')) {
                    \nedarta\autocrop\AutoCropper::cropAndSave($inputFile, $w, $h, $target);
                } else {
                    $this->imagine
                        ->open($inputFile)
                        ->thumbnail(new Box($w, $h))
                        ->save($target, ['quality' => $config['quality'] ?? 80]);
                }

                continue;
            }

            // Fallback
            if ($inputFile !== $target) {
                copy($inputFile, $target);
            }
        }

        // Save in model
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
