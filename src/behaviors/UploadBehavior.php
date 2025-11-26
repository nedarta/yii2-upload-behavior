<?php

namespace nedarta\behaviors;

use Yii;
use yii\base\Behavior;
use yii\db\ActiveRecord;
use yii\web\UploadedFile;
use yii\helpers\FileHelper;
use yii\imagine\Image;

/**
 * UploadBehavior handles uploads, EXIF auto-rotation and image variants.
 */
class UploadBehavior extends Behavior
{
    public string $uploadAttribute = 'upload';
    public string $imageAttribute  = 'image';
    public string $uploadAlias;

    public $baseName = 'image';
    public ?string $forceConvert = null;
    public bool $autoRotate = true;

    public array $variants = [];

    private ?UploadedFile $uploadedFile = null;
    private ?string $newFileName = null;

    public function events(): array
    {
        return [
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'captureUpload',
            ActiveRecord::EVENT_AFTER_INSERT    => 'processUpload',
            ActiveRecord::EVENT_AFTER_UPDATE    => 'processUpload',
            ActiveRecord::EVENT_BEFORE_DELETE   => 'deleteVariants',
        ];
    }

    public function captureUpload(): void
    {
        $this->uploadedFile = UploadedFile::getInstance(
            $this->owner,
            $this->uploadAttribute
        );
    }

    protected function ensureUploadDir(): void
    {
        FileHelper::createDirectory(Yii::getAlias('@upload'), 0777, true);
        FileHelper::createDirectory(Yii::getAlias($this->uploadAlias), 0777, true);
    }

    protected function readExifOrientation(string $file): ?int
    {
        if (!function_exists('exif_read_data')) {
            return null;
        }

        try {
            $exif = @exif_read_data($file, 'IFD0', false);
            return $exif['Orientation'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * GD fallback rotation
     */
    protected function gdRotate(string $filePath, int $orientation): void
    {
        if (!function_exists('imagecreatefromjpeg')) {
            return;
        }

        $gd = @imagecreatefromjpeg($filePath);
        if (!$gd) return;

        switch ($orientation) {
            case 2:
                if (function_exists('imageflip')) imageflip($gd, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $gd = imagerotate($gd, 180, 0);
                break;
            case 4:
                if (function_exists('imageflip')) imageflip($gd, IMG_FLIP_VERTICAL);
                break;
            case 5:
                if (function_exists('imageflip')) imageflip($gd, IMG_FLIP_HORIZONTAL);
                $gd = imagerotate($gd, -90, 0);
                break;
            case 6:
                $gd = imagerotate($gd, -90, 0);
                break;
            case 7:
                if (function_exists('imageflip')) imageflip($gd, IMG_FLIP_HORIZONTAL);
                $gd = imagerotate($gd, -90, 0);
                break;
            case 8:
                $gd = imagerotate($gd, 90, 0);
                break;
        }

        @imagejpeg($gd, $filePath, 92);
        imagedestroy($gd);
    }

    /**
     * Remove EXIF orientation tag
     */
    protected function resetExif(string $filePath, string $ext): void
    {
        $ext = strtolower($ext);

        if (class_exists(\Imagick::class)) {
            try {
                $im = new \Imagick($filePath);
                $im->stripImage();
                $im->writeImage($filePath);
                $im->destroy();
                return;
            } catch (\Throwable) {}
        }

        if (in_array($ext, ['jpg','jpeg'])) {
            $gd = @imagecreatefromjpeg($filePath);
            if ($gd) {
                @imagejpeg($gd, $filePath, 92);
                imagedestroy($gd);
            }
        }
    }

    protected function autoRotateImage(string $filePath, ?int $orientation, string $ext): void
    {
        $ext = strtolower($ext);

        if (!$orientation || $orientation === 1) {
            $this->resetExif($filePath, $ext);
            return;
        }

        // TRY IMAGINE FIRST
        $rotated = false;

        try {
            $img = Image::getImagine()->open($filePath);

            switch ($orientation) {
                case 2: $img->flipHorizontally(); break;
                case 3: $img->rotate(180); break;
                case 4: $img->flipVertically(); break;
                case 5: $img->flipHorizontally()->rotate(-90); break;
                case 6: $img->rotate(90); break;
                case 7: $img->flipHorizontally()->rotate(90); break;
                case 8: $img->rotate(270); break;
            }

            if (method_exists($img, 'strip')) {
                $img->strip();
            }

            $img->save($filePath, ['jpeg_quality' => 92]);
            $rotated = true;

        } catch (\Throwable $e) {
            // skip to GD
        }

        if (!$rotated) {
            $this->gdRotate($filePath, $orientation);
        }

        // EXIF tag cleanup
        $this->resetExif($filePath, $ext);
    }

    public function processUpload(): void
    {
        if (!$this->uploadedFile) {
            return;
        }

        $this->ensureUploadDir();

        $owner = $this->owner;

        if (!empty($owner->{$this->imageAttribute})) {
            $this->deleteVariants();
        }

        // -----------------------------
        // File naming
        // -----------------------------
        $baseName = is_callable($this->baseName)
            ? call_user_func($this->baseName, $owner)
            : $this->baseName;

        $ext = strtolower($this->uploadedFile->extension);
        if ($this->forceConvert) {
            $ext = strtolower($this->forceConvert);
        }

        $this->newFileName = $baseName . '-' . mt_rand(1000, 9999) . '.' . $ext;

        $dir  = Yii::getAlias($this->uploadAlias);
        $path = $dir . '/' . $this->newFileName;

        // -----------------------------
        // EXIF orientation read
        // -----------------------------
        $orientation = $this->readExifOrientation($this->uploadedFile->tempName);

        // -----------------------------
        // Save original
        // -----------------------------
        Image::getImagine()
            ->open($this->uploadedFile->tempName)
            ->save($path, ['jpeg_quality' => 95]);

        // -----------------------------
        // Apply EXIF rotation
        // -----------------------------
        $this->autoRotateImage($path, $orientation, $ext);

        // -----------------------------
        // Generate variants
        // -----------------------------
        if (empty($this->variants)) {
            $this->variants = [
                '' => ['resize' => [2500, 2500]],
            ];
        }

        foreach ($this->variants as $prefix => $config) {

            $input = $path;
            if (isset($config['dependsOn'])) {
                $input = $dir . '/' . $config['dependsOn'] . $this->newFileName;
            }

            $target = $dir . '/' . $prefix . $this->newFileName;

            // resize
            if (isset($config['resize'])) {
                [$w, $h] = $config['resize'];
                $img = Image::resize($input, $w, $h);
                if (method_exists($img, 'strip')) $img->strip();
                $img->save($target, ['quality' => $config['quality'] ?? 80]);
                $this->resetExif($target, $ext);
                continue;
            }

            // thumbnail
            if (isset($config['thumbnail'])) {
                [$w, $h] = $config['thumbnail'];
                $img = Image::thumbnail($input, $w, $h);
                if (method_exists($img, 'strip')) $img->strip();
                $img->save($target, ['quality' => $config['quality'] ?? 80]);
                $this->resetExif($target, $ext);
                continue;
            }

            // smartcrop
            if (isset($config['smartcrop'])) {
                [$w, $h] = $config['smartcrop'];

                if (class_exists('\nedarta\autocrop\AutoCropper')) {
                    \nedarta\autocrop\AutoCropper::cropAndSave($input, $w, $h, $target);
                } else {
                    $img = Image::thumbnail($input, $w, $h);
                    if (method_exists($img, 'strip')) $img->strip();
                    $img->save($target, ['quality' => $config['quality'] ?? 80]);
                }

                $this->resetExif($target, $ext);
                continue;
            }

            // copy
            copy($input, $target);
            $this->resetExif($target, $ext);
        }

        // -----------------------------
        // Save to model
        // -----------------------------
        $owner->{$this->imageAttribute} = $this->newFileName;
        $owner->updateAttributes([$this->imageAttribute => $this->newFileName]);
    }

        public function deleteVariants(): void
        {
            $filename = $this->owner->{$this->imageAttribute};
            if (!$filename) {
                return;
            }

            $dir = Yii::getAlias($this->uploadAlias);

            // find basename and extension
            $pathInfo = pathinfo($filename);
            $base = $pathInfo['filename'];
            $ext = $pathInfo['extension'];

            // scan directory
            foreach (scandir($dir) as $file) {

                // skip dots
                if ($file === '.' || $file === '..') {
                    continue;
                }

                // delete ANY file that starts with the same baseName
                // example: image-1234.jpg, r_image-1234.jpg, xc_image-1234.jpg
                if (str_contains($file, $base) && str_ends_with($file, ".$ext")) {

                    @unlink($dir . DIRECTORY_SEPARATOR . $file);
                }
            }
        }

}
