<?php

declare(strict_types=1);

foreach (['pdo_sqlite', 'sqlite3', 'curl', 'fileinfo', 'mbstring'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "missing PHP extension: {$ext}\n");
        exit(1);
    }
}

$ctx = stream_context_create([
    'http' => [
        'timeout' => 3,
        'ignore_errors' => true,
    ],
]);
$body = @file_get_contents('http://127.0.0.1/index.php?r=login', false, $ctx);
if ($body === false || $body === '') {
    fwrite(STDERR, "login page unreachable\n");
    exit(1);
}

exit(0);
