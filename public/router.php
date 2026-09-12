<?php
/**
 * Built-in server router: php -S 0.0.0.0:8080 -t public public/router.php
 * No shebang — text before <?php is sent to the client and breaks JSON.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}
require __DIR__ . '/index.php';
