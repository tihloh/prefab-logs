<?php

namespace Tihloh\Prefab\Logs\Support;

use JsonException;
use RuntimeException;

final class LogPayloadCodec
{
    private const REDACTED = '[REDACTED]';
    private const SENSITIVE = [
        'password', 'password_hash', 'passwd', 'secret', 'client_secret',
        'token', 'access_token', 'refresh_token', 'api_key', 'apikey',
        'authorization', 'cookie', 'private_key', 'credit_card', 'card_number',
    ];

    public static function encode(array $details): string
    {
        try {
            $json = json_encode(self::sanitize($details), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new RuntimeException('Unable to encode log details.', 0, $e);
        }

        if ($json === '{}') { return ''; }

        if (function_exists('gzencode')) {
            $compressed = gzencode($json, 6);
            if ($compressed !== false && strlen($compressed) < strlen($json)) {
                return "\x01" . $compressed;
            }
        }

        return "\x00" . $json;
    }

    public static function decode(mixed $payload): array
    {
        if ($payload === null || $payload === '') { return []; }

        $payload = (string) $payload;
        $mode = ord($payload[0]);
        $body = substr($payload, 1);

        if ($mode === 1) {
            $decoded = function_exists('gzdecode') ? gzdecode($body) : false;
            if ($decoded === false) { return []; }
            $body = $decoded;
        } elseif ($mode !== 0) {
            // Backward/foreign payload: treat the whole value as plain JSON.
            $body = $payload;
        }

        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    public static function sanitize(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && self::isSensitive($key)) { return self::REDACTED; }
        if (!is_array($value)) { return $value; }

        $sanitized = [];
        foreach ($value as $childKey => $childValue) {
            $sanitized[$childKey] = self::sanitize($childValue, is_string($childKey) ? $childKey : null);
        }
        return $sanitized;
    }

    private static function isSensitive(string $key): bool
    {
        $key = strtolower(trim($key));
        if (in_array($key, self::SENSITIVE, true)) { return true; }

        foreach (['password', 'secret', 'token', 'api_key', 'private_key', 'authorization'] as $needle) {
            if (str_contains($key, $needle)) { return true; }
        }
        return false;
    }
}
