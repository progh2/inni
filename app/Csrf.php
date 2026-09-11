<?php

declare(strict_types=1);

namespace Inni;

final class Csrf
{
    public const SESSION_KEY = 'csrf_token';
    public const FIELD_NAME = 'csrf_token';

    public static function token(): string
    {
        $existing = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($existing) || $existing === '') {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD_NAME . '" value="' . Support::e(self::token()) . '">';
    }

    /**
     * Inspect the current request without sending a response.
     * 200 = POST with a valid session token, 405 = not POST, 403 = missing/invalid token.
     */
    public static function inspect(): int
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return 405;
        }
        $token = $_POST[self::FIELD_NAME] ?? null;
        $expected = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($token) || $token === '' || !is_string($expected) || $expected === '' || !hash_equals($expected, $token)) {
            return 403;
        }
        return 200;
    }

    /**
     * Fail closed: reject GET/non-POST with 405 and missing/invalid CSRF with 403.
     */
    public static function requirePost(): void
    {
        $status = self::inspect();
        if ($status === 200) {
            return;
        }
        if ($status === 405) {
            http_response_code(405);
            header('Allow: POST');
            echo 'POST만 허용됩니다.';
            exit;
        }
        http_response_code(403);
        echo '요청이 만료되었습니다. 화면을 새로고침한 뒤 다시 시도하세요.';
        exit;
    }
}
