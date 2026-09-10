<?php

declare(strict_types=1);

namespace Inni;

final class View
{
    public static function render(string $template, array $data = [], string $layout = 'layouts/app'): void
    {
        $data['user'] = $data['user'] ?? Auth::user();
        $data['flash'] = App::pullFlash();
        $data['app_name'] = App::config('app_name', 'inni');
        $data['school_name'] = self::schoolName();
        $data['current_route'] = $data['current_route'] ?? ($_GET['r'] ?? 'home');

        extract($data, EXTR_SKIP);
        $contentTemplate = App::root() . '/templates/' . $template . '.php';
        if (!is_file($contentTemplate)) {
            http_response_code(500);
            echo 'Template not found: ' . Support::e($template);
            return;
        }

        ob_start();
        require $contentTemplate;
        $content = ob_get_clean();

        require App::root() . '/templates/' . $layout . '.php';
    }

    public static function schoolName(): string
    {
        try {
            $stmt = Database::pdo()->prepare('SELECT value FROM settings WHERE key = ?');
            $stmt->execute(['school_name']);
            $v = $stmt->fetchColumn();
            if ($v) {
                return (string) $v;
            }
        } catch (\Throwable) {
            // ignore before DB ready
        }
        return (string) App::config('school_name', 'inni');
    }
}
