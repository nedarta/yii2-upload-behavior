<?php

namespace nedarta\behaviors\tests;

use Yii;
use PHPUnit\Framework\TestCase;
use yii\base\Model;
use yii\web\UploadedFile;
use yii\helpers\FileHelper;
use nedarta\behaviors\UploadBehavior;
use yii\imagine\Image;

/**
 * A stub class to simulate ActiveRecord without database dependency.
 */
class TestModel extends Model 
{
    public $image;

    public function updateAttributes($attributes) 
    {
        foreach ($attributes as $name => $value) {
            $this->$name = $value;
        }
        return true;
    }
}

class TestUploadBehavior extends UploadBehavior
{
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

class UploadBehaviorTest extends TestCase
{
    protected string $testUploadDir;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension is required for UploadBehavior tests.');
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
        
        if (!is_dir($this->testUploadDir)) {
            FileHelper::createDirectory($this->testUploadDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir(__DIR__ . '/runtime')) {
            FileHelper::removeDirectory(__DIR__ . '/runtime');
        }
        parent::tearDown();
    }

    protected function createFakeImage(): string
    {
        $path = $this->testUploadDir . '/test_source.jpg';
        $img = imagecreatetruecolor(10, 10);
        imagefill($img, 0, 0, imagecolorallocate($img, 0, 0, 0));
        imagejpeg($img, $path, 92);
        imagedestroy($img);
        return $path;
    }

    public function testEventsAreAttached()
    {
        $behavior = new TestUploadBehavior();
        $events = $behavior->events();
        
        $this->assertArrayHasKey(\yii\db\ActiveRecord::EVENT_BEFORE_VALIDATE, $events);
        $this->assertArrayHasKey(\yii\db\ActiveRecord::EVENT_AFTER_INSERT, $events);
    }

    public function testProcessUploadGeneratesFiles()
    {
        $model = new TestModel();
        $behavior = new TestUploadBehavior([
            'uploadAlias' => '@upload',
            'imageAttribute' => 'image',
            'variants' => [
                't_' => ['thumbnail' => [10, 10]],
            ]
        ]);
        $model->attachBehavior('upload', $behavior);

        $filePath = $this->createFakeImage();
        
        // Use a real UploadedFile object. Yii2 parses extension from the 'name' property.
        $uploadedFile = new UploadedFile();
        $uploadedFile->tempName = $filePath;
        $uploadedFile->name = 'test.jpg'; 
        $uploadedFile->type = 'image/jpeg';
        $uploadedFile->size = filesize($filePath);
        $uploadedFile->error = 0;

        // Inject the object into the behavior's private property
        $prop = new \ReflectionProperty(UploadBehavior::class, 'uploadedFile');
        $prop->setAccessible(true);
        $prop->setValue($behavior, $uploadedFile);

        // Run processing
        $behavior->processUpload();

        // Assertions
        $this->assertNotEmpty($model->image, "The image filename should be stored in the model.");
        $this->assertStringEndsWith('.jpg', $model->image, "The filename must have an extension.");
        $this->assertFileExists($this->testUploadDir . '/' . $model->image);
        $this->assertFileExists($this->testUploadDir . '/t_' . $model->image);
    }

    public function testDeleteVariants()
    {
        $model = new TestModel();
        $model->image = 'test-9999.jpg';
        
        $behavior = new TestUploadBehavior([
            'uploadAlias' => '@upload',
            'imageAttribute' => 'image',
        ]);
        $model->attachBehavior('upload', $behavior);

        touch($this->testUploadDir . '/test-9999.jpg');
        touch($this->testUploadDir . '/t_test-9999.jpg');

        $behavior->deleteVariants();

        $this->assertFileDoesNotExist($this->testUploadDir . '/test-9999.jpg');
        $this->assertFileDoesNotExist($this->testUploadDir . '/t_test-9999.jpg');
    }
}
