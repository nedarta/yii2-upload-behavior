# yii2-upload-behavior

Configurable **image upload behavior** for Yii2 ActiveRecord models.

## Features
- Uploads files from a model attribute (e.g. `$upload`)
- Stores final filename in another attribute (e.g. `$image`)
- Creates nested directories (e.g. `@upload/images/news`)
- Supports **variants**:
  - `resize`
  - `thumbnail`
  - `smartcrop` (if `nedarta/yii2-smart-cropper` is installed)
  - copy fallback
- Deletes old images when uploading a new one
- Deletes all variants automatically in `beforeDelete`
- Customizable file base name (string or Closure)

---

## Installation

```
composer require nedarta/yii2-upload-behavior
```

Define alias in `common/config/bootstrap.php`:

```php
Yii::setAlias('@upload', dirname(dirname(__DIR__)) . '/upload');
```

This will create:

```
project/upload/
```

on first upload.

---

## Usage

### Model

```php
use nedarta\behaviors\UploadBehavior;

class Event extends ActiveRecord
{
    public $upload;

    public function behaviors()
    {
        return [
            [
                'class' => UploadBehavior::class,
                'uploadAlias'     => '@upload/images/event',
                'uploadAttribute' => 'upload',
                'imageAttribute'  => 'image',
                'forceConvert' => 'jpg',
                'baseName'        => 'trio-metamorfoze',

                'variants' => [
                    ''    => ['resize'    => [2500, 2500]],
                    'r_'  => ['resize'    => [1920, 1920]],
                    'c_'  => ['smartcrop' => [200, 200]],
                    'xc_' => ['thumbnail' => [100, 100]],
                ],
            ],
        ];
    }
}
```

---

### Controller

```php
public function actionCreate()
{
    $model = new Event();

    if ($model->load(Yii::$app->request->post()) && $model->save()) {
        return $this->redirect(['view', 'id' => $model->id]);
    }

    return $this->render('create', ['model' => $model]);
}

public function actionUpdate($id)
{
    $model = Event::findOne($id);

    if ($model->load(Yii::$app->request->post()) && $model->save()) {
        return $this->redirect(['view', 'id' => $model->id]);
    }

    return $this->render('update', ['model' => $model]);
}
```

---

### actionDelete

```php
public function actionDelete($id)
{
    $model = $this->findModel($id);

    // UploadBehavior will automatically delete all image files
    $model->delete();

    return $this->redirect(['index']);
}
```

---

## Variants

### Example

```php
'variants' => [
    ''    => ['resize'    => [2500, 2500]],
    'r_'  => ['resize'    => [1920, 1920]],
    'c_'  => ['smartcrop' => [200, 200]],
    'xc_' => ['thumbnail' => [100, 100]],
],
```

### Supported operations

- `resize` → `['resize' => [width, height]]`
- `thumbnail` → `['thumbnail' => [width, height]]`
- `smartcrop` → `['smartcrop' => [width, height]]`
- fallback → copy original

---

## Deletes old images automatically

When uploading a new file:

1. Old filename detected  
2. All old variants are deleted  
3. New file + new variants are created  

---

## License

MIT
