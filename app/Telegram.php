<?php

declare(strict_types=1);

namespace Inni;

final class Telegram
{
    public const STATUS_EMPTY = 'empty';
    public const STATUS_READY = 'ready';

    /** @var null|callable(string, array<string, mixed>): array */
    private static $httpPostHandler = null;

    public static function botToken(): string
    {
        return trim((string) App::config('telegram.bot_token', ''));
    }

    public static function defaultChatId(): string
    {
        return trim((string) App::config('telegram.default_chat_id', ''));
    }

    /** @return self::STATUS_* */
    public static function configStatus(): string
    {
        return self::botToken() === '' ? self::STATUS_EMPTY : self::STATUS_READY;
    }

    public static function isReady(): bool
    {
        return self::configStatus() === self::STATUS_READY;
    }

    /** @internal CLI tests stub sendMessage — never used in production. */
    public static function setHttpHandler(?callable $handler): void
    {
        self::$httpPostHandler = $handler;
    }

    /**
     * Fail closed when the server token or chat id is empty, or the API rejects the send.
     *
     * @return array{ok: bool, error: ?string}
     */
    public static function sendMessage(string $chatId, string $text): array
    {
        $token = self::botToken();
        if ($token === '') {
            return ['ok' => false, 'error' => 'telegram_token_empty'];
        }
        $chatId = trim($chatId);
        if ($chatId === '') {
            return ['ok' => false, 'error' => 'telegram_chat_empty'];
        }
        $text = trim($text);
        if ($text === '') {
            return ['ok' => false, 'error' => 'telegram_text_empty'];
        }

        $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
        $fields = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => 'true',
        ];

        try {
            $data = self::httpPost($url, $fields);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'telegram_http_failed'];
        }

        if (!empty($data['ok'])) {
            return ['ok' => true, 'error' => null];
        }
        return ['ok' => false, 'error' => 'telegram_api_rejected'];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function httpPost(string $url, array $fields): array
    {
        if (self::$httpPostHandler !== null) {
            $data = (self::$httpPostHandler)($url, $fields);
            return is_array($data) ? $data : [];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return [];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno !== 0 || !is_string($body) || $body === '') {
            return [];
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : [];
    }
}
