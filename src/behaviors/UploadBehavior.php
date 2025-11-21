<?php

namespace nedarta\behaviors;

use Yii;
use yii\base\Behavior;
use yii\web\UploadedFile;
use yii\helpers\FileHelper;
use yii\imagine\Image;

/**
 * UploadBehavior – configurable image upload handler with variant pipelines.
 *
 * Supports:
 * - Uploading via UploadedFile
 * - Per-variant processing:
 *      - resize
 *      - thumbnail
 *      - smartcrop  (via nedarta/yii2-smart-cropper)
 *      - copy fallback
 * - Variant dependency graph (dependsOn → chain processing)
 * - Deleting all variants automatically on new upload or model delete
 */
class UploadBehavior extends Behavior
{
	/** @var string */
	public string $uploadAttribute = 'upload';

	/** @var string */
	public string $imageAttribute = 'image';

	/**
	 * @var string Yii alias where variants will be written.
	 * Example: '@upload/images/event'
	 */
	public string $uploadAlias;

	/**
	 * Filename base before random string.
	 * e.g. "event-image" → event-image-1234.jpg
	 *
	 * @var string|\Closure
	 */
	public $baseName = 'image';

	/**
	 * Variant configuration array with pipeline support.
	 *
	 * Example:
	 * 'variants' => [
	 *     '' => [
	 *         'resize' => [2500, 2500],
	 *     ],
	 *     'r_' => [
	 *         'resize' => [1920, 1920],
	 *         'dependsOn' => '',
	 *     ],
	 *     'c_' => [
	 *         'smartcrop' => [200, 200],
	 *         'dependsOn' => 'r_',
	 *     ],
	 *     'xc_' => [
	 *         'thumbnail' => [100, 100],
	 *         'dependsOn' => 'c_',
	 *     ],
	 * ],
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

		// Remove old variants
		if (!empty($owner->{$this->imageAttribute})) {
			$this->deleteVariants();
		}

		// Determine filename base
		$baseName = is_callable($this->baseName)
			? call_user_func($this->baseName, $owner)
			: $this->baseName;

		// Build new filename
		$this->newFileName = $baseName . '-' . mt_rand(1000, 9999) . '.' . $this->uploadedFile->extension;

		$dir = Yii::getAlias($this->uploadAlias);
		$originalPath = $dir . DIRECTORY_SEPARATOR . $this->newFileName;

		// Save original file
		$this->uploadedFile->saveAs($originalPath);

		// Default variant if none supplied
		if (empty($this->variants)) {
			$this->variants = [
				'' => ['resize' => [2500, 2500]],
			];
		}

		// -------------------------------------------------------
		// PIPELINE-BASED VARIANT PROCESSING WITH dependsOn
		// -------------------------------------------------------

		// First pass: determine each variant's input source
		$variantSources = [];
		foreach ($this->variants as $prefix => $config) {
			$variantSources[$prefix] = $config['dependsOn'] ?? null;
		}

		// Second pass: generate in declared order
		foreach ($this->variants as $prefix => $config) {

			$target = $dir . DIRECTORY_SEPARATOR . $prefix . $this->newFileName;

			// Determine input for processing
			if (!empty($variantSources[$prefix])) {
				$inputPrefix = $variantSources[$prefix];
				$inputFile = $dir . DIRECTORY_SEPARATOR . $inputPrefix . $this->newFileName;

				if (!is_file($inputFile)) {
					throw new \RuntimeException("Variant '{$prefix}' dependsOn '{$inputPrefix}', but '{$inputFile}' not found.");
				}

			} else {
				// Default input: original uploaded file
				$inputFile = $originalPath;
			}

			// ----- PROCESS VARIANT -----

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
				// Copy fallback
				if ($inputFile !== $target) {
					copy($inputFile, $target);
				}
			}
		}

		// Save filename into AR without triggering validation
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

		// Ensure original is deleted as well
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
