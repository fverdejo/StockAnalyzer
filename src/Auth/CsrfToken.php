<?php

declare(strict_types=1);

namespace StockAnalyzer\Auth;

class CsrfToken
{
    private const SESSION_KEY = 'stock_analyzer_csrf_token';

    public static function get(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function validate(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        return hash_equals(self::get(), $token);
    }
}
