<?php

declare(strict_types=1);

namespace StockAnalyzer\Config;

/**
 * URL base absoluta de la aplicacion (esquema + dominio, sin barra final),
 * usada para construir enlaces absolutos y clicables fuera del navegador
 * (por ejemplo el del correo de verificacion de email, ver
 * Auth\AuthService). En ddev y en produccion el dominio es distinto
 * (`https://stockanalyzer.ddev.site` vs el dominio real), asi que no se
 * puede hardcodear: se lee de la variable de entorno APP_URL (`.env`),
 * con el mismo mecanismo de Dotenv que ya usa
 * Infrastructure\Database\Connection para DB_DSN/DB_USER/DB_PASSWORD.
 *
 * Si APP_URL no esta definida (por ejemplo, entorno local sin `.env`),
 * cae en `http://localhost` en vez de fallar: un enlace con el dominio
 * equivocado es preferible a que el registro de usuarios se rompa.
 */
class AppUrlConfig
{
    private const DEFAULT_URL = 'http://localhost';

    public function getBaseUrl(): string
    {
        $this->loadDotenv();

        return rtrim($this->env('APP_URL', self::DEFAULT_URL), '/');
    }

    private function loadDotenv(): void
    {
        if (!class_exists(\Dotenv\Dotenv::class)) {
            return;
        }

        $root = dirname(__DIR__, 2);

        if (!is_file($root . '/.env')) {
            return;
        }

        \Dotenv\Dotenv::createImmutable($root)->safeLoad();
    }

    private function env(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
