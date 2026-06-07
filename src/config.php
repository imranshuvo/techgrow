<?php

declare(strict_types=1);

/**
 * TechGrow Ltd — configuration.
 *
 * Reads settings from real environment variables (set in Coolify, your
 * web server, or docker-compose). For local development a plain `.env`
 * file in the project root is loaded automatically — real environment
 * variables always take precedence over it.
 */

/**
 * Minimal `.env` loader (no Composer / no dependency).
 * Only sets a variable if it is not already present in the real environment.
 */
function load_env(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Strip a single pair of surrounding quotes.
        if (strlen($value) >= 2
            && ($value[0] === '"' || $value[0] === "'")
            && $value[strlen($value) - 1] === $value[0]
        ) {
            $value = substr($value, 1, -1);
        }

        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

/**
 * Read an environment variable, returning $default when unset or empty.
 */
function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);

    return ($value === false || $value === '') ? $default : $value;
}

/**
 * Typed access to application settings.
 */
function config(string $key): mixed
{
    return match ($key) {
        'app_env'             => env('APP_ENV', 'production'),
        'admin_password_hash' => env('ADMIN_PASSWORD_HASH'),
        'trust_proxy'         => filter_var(env('TRUST_PROXY', 'false'), FILTER_VALIDATE_BOOL),
        'admin_session_idle'  => (int) env('ADMIN_SESSION_IDLE', '7200'),
        'admin_page_size'     => max(5, (int) env('ADMIN_PAGE_SIZE', '25')),
        default               => null,
    };
}

// Bootstrap: load .env (project root) then apply error-display policy.
load_env(dirname(__DIR__) . '/.env');

error_reporting(E_ALL);
ini_set('display_errors', config('app_env') === 'production' ? '0' : '1');
ini_set('log_errors', '1');
