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
 * - optional forced format conversion (forceConvert: jpg, png, webp, jpeg)
 */
class UploadBehavior extends Behavior
{
    /** @var string attribute that receives UploadedFile */
    public string $uploadAttribute = 'upload';

    /** @var string attribute where filename will be stored */
    public string $imageAttribute = 'image';

    /** @var string Yii alias for storage folder, e.g. '@upload/images/event' */
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
     * Example:
     * 'forceConvert' => 'jpg'
     *
     * Supported:
     * - jpg / jpeg
     * - png
     * - webp
     *
     * @var string|null
     */
    public ?string $forceConvert = null;

    /**
     * Variant definitions with pipeline support.
     *
     * Example:
     *
     * 'variants' => [
     *     '' => ['resize' => [2500, 2500]],
     *     'r_' => ['resize' => [1920, 1920], 'dependsOn' => ''],
     *     'c_' => ['smartcrop' => [200, 200], 'dependsOn' => 'r_'],
     *     'xc_' => ['thumbnail' => [100, 100], 'dependsOn' => 'c_'],
     * ],
     *
     * @var array
     */
    public array $variants = [];

    private ?UploadedFile $uploadedFile = null;
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

        // Final filename
        $this->newFileName = $baseName . '-' . mt_rand(1000, 9999) . '.' . $ext;

        $dir = Yii::getAlias($this->uploadAlias);
        $originalPath = $dir . DIRECTORY_SEPARATOR . $this->newFileName;
        $tempPath = $originalPath . '.tmp';

        // Save original upload temporarily (if converting)
        if (!empty($this->forceConvert)) {
            $this->uploadedFile->saveAs($tempPath);

            // Convert temp file to forced extension
            Image::getImagine()
                ->open($tempPath)
                ->save($originalPath, ['quality' => 90]);

            @unlink($tempPath);

        } else {
            // No conversion, save directly
            $this->uploadedFile->saveAs($originalPath);
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

        // Determine each variant's input
        $variantSources = [];
        foreach ($this->variants as $prefix => $config) {
            $variantSources[$prefix] = $config['dependsOn'] ?? null;
        }

        foreach ($this->variants as $prefix => $config) {

            $target = $dir . DIRECTORY_SEPARATOR . $prefix . $this->newFileName;

            // Determine input file
            if (!empty($variantSources[$prefix])) {
                $inputPrefix = $variantSources[$prefix];
                $inputFile = $dir . DIRECTORY_SEPARATOR . $inputPrefix . $this->newFileName;

                if (!is_file($inputFile)) {
                    throw new \RuntimeException("Variant '{$prefix}' dependsOn '{$inputPrefix}', but '{$inputFile}' does not exist.");
                }
            } else {
                $inputFile = $originalPath;
            }

            // --- PROCESS VARIANT ---

            if (isset($config['resize'])) {
                [$w, $h] = $config['resize'];
                Image::resize($inputFile, $w, $h)
                    ->save($target, ['quality' => $config['quality'] ?? 80])
                    ->strip();
            }

            elseif (isset($config['thumbnail'])) {
                [$w, $h] = $config['thumbnail'];
                Image::thumbnail($inputFile, $w, $h)
                    ->save($target, ['quality' => $config['quality'] ?? 80]);
            }

            elseif (isset($config['smartcrop'])) {
                [$w, $h] = $config['smartcrop'];

                if (class_exists('\nedarta\smartcropper\AutoCropper')) {
                    \nedarta\smartcropper\AutoCropper::cropAndSave($inputFile, $w, $h, $target);
                } else {
                    Image::thumbnail($inputFile, $w, $h)
                        ->save($target, ['quality' => $config['quality'] ?? 80]);
                }
            }

            else {
                // fallback: copy
                if ($inputFile !== $target) {
                    copy($inputFile, $target);
                }
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
}
