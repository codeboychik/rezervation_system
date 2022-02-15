<?php

use Slim\Factory\AppFactory;

require __DIR__ . '\vendor\autoload.php';
require __DIR__ . '\settings.php';
session_start();
$app = AppFactory::create();

$app->addBodyParsingMiddleware();
$app->addErrorMiddleware(true,true,false);

require __DIR__ . '\routes.php';

$app->run();
