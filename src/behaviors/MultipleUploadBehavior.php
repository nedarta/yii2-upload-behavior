<?php

namespace nedarta\behaviors;

use Yii;
use yii\db\ActiveRecord;
use yii\web\UploadedFile;
use yii\helpers\Inflector;

/**
 * MultipleUploadBehavior stores multiple uploaded images in a related
 * ActiveRecord table (one-to-many gallery).
 */
class MultipleUploadBehavior extends UploadBehavior
{
    public string $uploadAttribute = 'images';

    /** Fully qualified related ActiveRecord class, e.g. EventImage::class */
    public string $relatedModelClass;
    /** Attribute on the related model holding the filename */
    public string $relatedImageAttribute = 'image';
    /** FK attribute on the related model pointing to the owner (auto-derived if null) */
    public ?string $relatedFkAttribute = null;

    /** @var UploadedFile[] */
    protected array $uploadedFiles = [];

    public function events(): array
    {
        return [
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'captureUploads',
            ActiveRecord::EVENT_AFTER_INSERT    => 'processUploads',
            ActiveRecord::EVENT_AFTER_UPDATE    => 'processUploads',
            ActiveRecord::EVENT_BEFORE_DELETE   => 'deleteAllImages',
        ];
    }

    public function captureUploads(): void
    {
        $this->uploadedFiles = UploadedFile::getInstances(
            $this->owner,
            $this->uploadAttribute
        );
    }

    protected function getRelatedFkAttribute(): string
    {
        return $this->relatedFkAttribute
            ?? Inflector::underscore($this->owner->formName()) . '_id';
    }

    /**
     * @return ActiveRecord[]
     */
    protected function findRelatedRows(): array
    {
        $class = $this->relatedModelClass;
        return $class::find()
            ->where([$this->getRelatedFkAttribute() => $this->owner->primaryKey])
            ->all();
    }

    public function processUploads(): void
    {
        if (empty($this->uploadedFiles)) {
            return;
        }

        $db = $this->owner::getDb();
        $transaction = $db->beginTransaction();

        try {
            $this->deleteGallery();

            foreach ($this->uploadedFiles as $file) {
                $this->insertRelatedRow($this->saveSingle($file));
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $this->uploadedFiles = [];
    }

    protected function insertRelatedRow(string $filename): void
    {
        /** @var ActiveRecord $row */
        $row = Yii::createObject($this->relatedModelClass);
        $row->{$this->getRelatedFkAttribute()} = $this->owner->primaryKey;
        $row->{$this->relatedImageAttribute} = $filename;

        if (!$row->save(false)) {
            throw new \RuntimeException('Failed to save related image record.');
        }
    }

    public function deleteAllImages(): void
    {
        $this->deleteGallery();
    }

    protected function deleteGallery(): void
    {
        $rows = $this->findRelatedRows();
        if (empty($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $this->deleteFilesFor($row->{$this->relatedImageAttribute});
        }

        $class = $this->relatedModelClass;
        $class::deleteAll([
            $this->getRelatedFkAttribute() => $this->owner->primaryKey,
        ]);
    }
}
