<?php

// Ensure we find the autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Include the Yii class file
require_once __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

// Set essential aliases for the test environment
Yii::setAlias('@tests', __DIR__);
Yii::setAlias('@vendor', __DIR__ . '/../vendor');
Yii::setAlias('@runtime', __DIR__ . '/runtime');