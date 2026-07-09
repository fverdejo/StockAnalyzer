<?php

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Services\Application;

$app = new Application();
$app->run();