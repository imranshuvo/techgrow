<?php

declare(strict_types=1);

/**
 * TechGrow Ltd — helper functions.
 *
 * Small, dependency-free helpers for sessions, CSRF protection,
 * input sanitisation, validation and safe HTML output.
 */

/**
 * Start a session with hardened cookie parameters.
 * Safe to call multiple times.
 */
function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) === '443')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,   // only sent over HTTPS when available
        'httponly' => true,     // not readable from JavaScript
        'samesite' => 'Lax',    // mitigates CSRF on cross-site requests
    ]);

    session_name('techgrow_session');
    session_start();
}

/**
 * Return the current CSRF token, generating one if needed.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Constant-time validation of a submitted CSRF token.
 */
function csrf_verify(?string $token): bool
{
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Rotate the CSRF token (call after a successful submission).
 */
function csrf_rotate(): void
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Normalise and trim arbitrary user text. Strips control characters
 * and tags, collapses surrounding whitespace and enforces a max length.
 */
function sanitize_text(mixed $value, int $maxLength = 1000): string
{
    if (!is_string($value)) {
        return '';
    }

    // Remove NULL bytes and other control characters (keep tab/newline).
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    $value = strip_tags($value);
    $value = trim($value);

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength);
    }

    return substr($value, 0, $maxLength);
}

/**
 * Validate and normalise an email address. Returns the lowercased
 * email on success or null when invalid.
 */
function normalize_email(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $email = strtolower(trim($value));
    $email = filter_var($email, FILTER_VALIDATE_EMAIL) ?: null;

    return ($email && strlen($email) <= 160) ? $email : null;
}

/**
 * Best-effort client IP address. Defaults to REMOTE_ADDR; only trusts
 * forwarded headers when explicitly enabled (behind a known proxy).
 */
function client_ip(bool $trustProxy = false): string
{
    if ($trustProxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $candidate = trim($parts[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

/**
 * Escape a string for safe output in HTML context.
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Issue a redirect and stop execution (used for the POST/redirect/GET flow).
 */
function redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

/**
 * Send a small set of sensible security headers.
 */
function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}
