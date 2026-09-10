<?php

declare(strict_types=1);

use Inni\App;
use Inni\Router;

require dirname(__DIR__) . '/app/bootstrap.php';

App::boot(dirname(__DIR__));

$route = (string) ($_GET['r'] ?? 'home');
Router::dispatch($route);
