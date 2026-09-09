<?php

namespace nedarta\behaviors\tests;

use Yii;
use PDO;
use PHPUnit\Framework\TestCase;
use yii\db\ActiveRecord;
use yii\db\Connection;
use yii\web\UploadedFile;
use yii\helpers\FileHelper;
use yii\imagine\Image;
use nedarta\behaviors\MultipleUploadBehavior;

/**
 * Test ActiveRecord stubs backed by SQLite in-memory database.
 */
class TestEvent extends ActiveRecord
{
    public static ?Connection $db = null;

    public $images = [];

    public static function tableName(): string
    {
        return 'event';
    }

    public static function getDb(): Connection
    {
        if (self::$db === null) {
            self::$db = new Connection(['dsn' => 'sqlite::memory:']);
        }
        return self::$db;
    }
}

class TestEventImage extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'event_image';
    }

    public static function getDb(): Connection
    {
        return TestEvent::getDb();
    }
}

class TestMultipleUploadBehavior extends MultipleUploadBehavior
{
    public function captureUploads(): void
    {
        // no-op: uploads are injected directly in tests
    }

    protected function resetExif(string $filePath, string $ext): void
    {
        $ext = strtolower($ext);
        if (in_array($ext, ['jpg', 'jpeg'], true)) {
            $gd = @imagecreatefromjpeg($filePath);
            if ($gd) {
                @imagejpeg($gd, $filePath, 92);
                imagedestroy($gd);
            }
        }
    }
}

class MultipleUploadBehaviorTest extends TestCase
{
    protected string $testUploadDir;

    protected function setUp(): void
    {
        parent::setUp();

        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite extension is required for MultipleUploadBehavior tests.');
        }

        if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension is required for MultipleUploadBehavior tests.');
        }

        // Enforce GD driver to avoid Imagick stability issues on Windows/PHP 8.4
        if (class_exists('yii\imagine\Image')) {
            Image::$driver = Image::DRIVER_GD2;
        }

        if (Yii::$app === null) {
            new \yii\console\Application([
                'id' => 'testapp',
                'basePath' => __DIR__,
            ]);
        }

        $this->testUploadDir = __DIR__ . '/runtime/uploads';
        Yii::setAlias('@upload', $this->testUploadDir);

        TestEvent::$db = new Connection(['dsn' => 'sqlite::memory:']);
        TestEvent::getDb()
            ->createCommand('CREATE TABLE event (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)')
            ->execute();
        TestEvent::getDb()
            ->createCommand('CREATE TABLE event_image (id INTEGER PRIMARY KEY AUTOINCREMENT, event_id INTEGER, image TEXT)')
            ->execute();

        FileHelper::createDirectory($this->testUploadDir, 0777, true);
    }

    protected function tearDown(): void
    {
        TestEvent::$db = null;

        if (is_dir(__DIR__ . '/runtime')) {
            FileHelper::removeDirectory(__DIR__ . '/runtime');
        }
        parent::tearDown();
    }

    protected function createFakeImage(): string
    {
        $path = $this->testUploadDir . '/src_' . uniqid() . '.jpg';
        $img = imagecreatetruecolor(10, 10);
        imagefill($img, 0, 0, imagecolorallocate($img, 0, 0, 0));
        imagejpeg($img, $path, 92);
        imagedestroy($img);
        return $path;
    }

    /**
     * @return UploadedFile[]
     */
    protected function makeFakeUploads(int $count): array
    {
        $files = [];
        for ($i = 0; $i < $count; $i++) {
            $filePath = $this->createFakeImage();
            $file = new UploadedFile();
            $file->tempName = $filePath;
            $file->name = 'photo' . $i . '.jpg';
            $file->type = 'image/jpeg';
            $file->size = filesize($filePath);
            $file->error = 0;
            $files[] = $file;
        }
        return $files;
    }

    protected function attachBehavior(TestEvent $model): TestMultipleUploadBehavior
    {
        $behavior = new TestMultipleUploadBehavior([
            'uploadAlias' => '@upload',
            'relatedModelClass' => TestEventImage::class,
            'relatedImageAttribute' => 'image',
            'relatedFkAttribute' => 'event_id',
            'variants' => [
                't_' => ['thumbnail' => [10, 10]],
            ],
        ]);
        $model->attachBehavior('multiUpload', $behavior);
        return $behavior;
    }

    /**
     * @param UploadedFile[] $files
     */
    protected function injectUploads(TestMultipleUploadBehavior $behavior, array $files): void
    {
        $prop = new \ReflectionProperty(MultipleUploadBehavior::class, 'uploadedFiles');
        $prop->setAccessible(true);
        $prop->setValue($behavior, $files);
    }

    public function testEventsAreAttached()
    {
        $model = new TestEvent();
        $behavior = $this->attachBehavior($model);
        $events = $behavior->events();

        $this->assertArrayHasKey(ActiveRecord::EVENT_BEFORE_VALIDATE, $events);
        $this->assertArrayHasKey(ActiveRecord::EVENT_AFTER_INSERT, $events);
        $this->assertArrayHasKey(ActiveRecord::EVENT_AFTER_UPDATE, $events);
        $this->assertArrayHasKey(ActiveRecord::EVENT_BEFORE_DELETE, $events);
    }

    public function testMultipleUploadCreatesFilesAndRows()
    {
        $model = new TestEvent();
        $model->name = 'test';
        $behavior = $this->attachBehavior($model);
        $this->injectUploads($behavior, $this->makeFakeUploads(3));

        $this->assertTrue($model->save());

        $rows = TestEventImage::find()->where(['event_id' => $model->id])->all();
        $this->assertCount(3, $rows);

        $names = [];
        foreach ($rows as $row) {
            $names[] = $row->image;
            $this->assertFileExists($this->testUploadDir . '/' . $row->image);
            $this->assertFileExists($this->testUploadDir . '/t_' . $row->image);
        }

        $this->assertCount(3, array_unique($names), 'Filenames must be unique within a batch.');
    }

    public function testNoUploadsKeepsGallery()
    {
        $model = new TestEvent();
        $model->name = 'test';
        $behavior = $this->attachBehavior($model);
        $this->injectUploads($behavior, $this->makeFakeUploads(2));
        $this->assertTrue($model->save());
        $this->assertCount(2, TestEventImage::find()->all());

        $this->injectUploads($behavior, []);
        $model->name = 'changed';
        $this->assertSame(1, $model->update(false));

        $this->assertCount(2, TestEventImage::find()->where(['event_id' => $model->id])->all());
    }

    public function testUpdateReplacesGallery()
    {
        $model = new TestEvent();
        $model->name = 'test';
        $behavior = $this->attachBehavior($model);
        $this->injectUploads($behavior, $this->makeFakeUploads(2));
        $this->assertTrue($model->save());

        $oldNames = array_map(
            fn($row) => $row->image,
            TestEventImage::find()->all()
        );
        $this->assertCount(2, $oldNames);

        $this->injectUploads($behavior, $this->makeFakeUploads(1));
        $model->name = 'changed';
        $this->assertSame(1, $model->update(false));

        $rows = TestEventImage::find()->where(['event_id' => $model->id])->all();
        $this->assertCount(1, $rows);
        $newName = $rows[0]->image;
        $this->assertNotContains($newName, $oldNames);

        foreach ($oldNames as $old) {
            $this->assertFileDoesNotExist($this->testUploadDir . '/' . $old);
            $this->assertFileDoesNotExist($this->testUploadDir . '/t_' . $old);
        }
        $this->assertFileExists($this->testUploadDir . '/' . $newName);
        $this->assertFileExists($this->testUploadDir . '/t_' . $newName);
    }

    public function testDeleteRemovesFilesAndRows()
    {
        $model = new TestEvent();
        $model->name = 'test';
        $behavior = $this->attachBehavior($model);
        $this->injectUploads($behavior, $this->makeFakeUploads(2));
        $this->assertTrue($model->save());

        $names = array_map(
            fn($row) => $row->image,
            TestEventImage::find()->all()
        );
        $this->assertCount(2, $names);

        $this->assertNotFalse($model->delete());

        $this->assertCount(0, TestEventImage::find()->where(['event_id' => $model->id])->all());
        foreach ($names as $name) {
            $this->assertFileDoesNotExist($this->testUploadDir . '/' . $name);
            $this->assertFileDoesNotExist($this->testUploadDir . '/t_' . $name);
        }
    }
}
