<?php

declare(strict_types=1);

namespace Inni;

final class App
{
    private static array $config = [];
    private static string $root = '';

    public static function boot(string $root): void
    {
        self::$root = rtrim($root, '/');
        $configFile = self::$root . '/config.php';
        $example = self::$root . '/config.example.php';
        if (!is_file($configFile)) {
            if (!is_file($example)) {
                throw new \RuntimeException('config.example.php missing');
            }
            // Docker / read-only app root: boot from the example instead of failing.
            if (!@copy($example, $configFile)) {
                $configFile = $example;
            }
        }
        self::$config = require $configFile;
        date_default_timezone_set(self::$config['timezone'] ?? 'Asia/Seoul');

        $sessionName = self::$config['session_name'] ?? 'inni_sess';
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name($sessionName);
            session_start();
        }

        Database::pdo();
    }

    public static function root(): string
    {
        return self::$root;
    }

    public static function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return self::$config;
        }
        $parts = explode('.', $key);
        $val = self::$config;
        foreach ($parts as $p) {
            if (!is_array($val) || !array_key_exists($p, $val)) {
                return $default;
            }
            $val = $val[$p];
        }
        return $val;
    }

    public static function baseUrl(): string
    {
        $configured = rtrim((string) self::config('base_url', ''), '/');
        if ($configured !== '') {
            return $configured;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443);
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if ($dir === '/' || $dir === '\\') {
            $dir = '';
        }
        return $scheme . '://' . $host . $dir;
    }

    public static function url(string $route = 'home', array $query = []): string
    {
        $query = array_merge(['r' => $route], $query);
        return self::baseUrl() . '/index.php?' . http_build_query($query);
    }

    public static function redirect(string $route, array $query = []): never
    {
        header('Location: ' . self::url($route, $query));
        exit;
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function pullFlash(): array
    {
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flash;
    }
}
