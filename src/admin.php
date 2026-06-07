<?php

declare(strict_types=1);

/**
 * TechGrow Ltd — admin authentication.
 *
 * A single-password gate for the admin dashboard. The password is never
 * stored in the repo: only its bcrypt hash, supplied via the
 * ADMIN_PASSWORD_HASH environment variable. Generate one with:
 *
 *     php bin/hash-password.php
 *
 * Depends on functions.php (sessions, CSRF) and config.php being loaded.
 */

/** Maximum failed attempts before a short lockout. */
const ADMIN_MAX_ATTEMPTS = 5;

/** Lockout duration in seconds once the attempt limit is hit. */
const ADMIN_LOCKOUT_SECONDS = 60;

/**
 * Is the admin area configured at all? (False when no password hash is set.)
 */
function admin_enabled(): bool
{
    $hash = config('admin_password_hash');

    return is_string($hash) && $hash !== '';
}

/**
 * Is the current session an authenticated admin? Enforces idle timeout.
 */
function admin_is_authenticated(): bool
{
    if (empty($_SESSION['admin_authenticated'])) {
        return false;
    }

    $idle = config('admin_session_idle');
    if ($idle > 0
        && isset($_SESSION['admin_last_activity'])
        && (time() - (int) $_SESSION['admin_last_activity']) > $idle
    ) {
        admin_logout();
        return false;
    }

    $_SESSION['admin_last_activity'] = time();

    return true;
}

/**
 * Attempt a login. Returns [success(bool), message(string)].
 * Applies a small delay and a lockout after repeated failures.
 */
function admin_attempt_login(string $password): array
{
    $now       = time();
    $lockUntil = (int) ($_SESSION['admin_lock_until'] ?? 0);

    if ($lockUntil > $now) {
        return [false, 'Too many attempts. Try again in ' . ($lockUntil - $now) . 's.'];
    }

    // Throttle: makes brute-forcing impractical without hurting real use.
    usleep(250_000);

    $hash = config('admin_password_hash');

    if ($password !== '' && is_string($hash) && password_verify($password, $hash)) {
        $_SESSION['admin_fails'] = 0;
        unset($_SESSION['admin_lock_until']);

        // Prevent session fixation: new session id on privilege change.
        session_regenerate_id(true);
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['admin_last_activity'] = time();

        return [true, ''];
    }

    $fails = (int) ($_SESSION['admin_fails'] ?? 0) + 1;
    $_SESSION['admin_fails'] = $fails;

    error_log(sprintf(
        '[techgrow] admin login failed from %s (attempt %d)',
        client_ip(config('trust_proxy')),
        $fails
    ));

    if ($fails >= ADMIN_MAX_ATTEMPTS) {
        $_SESSION['admin_lock_until'] = $now + ADMIN_LOCKOUT_SECONDS;
        $_SESSION['admin_fails']      = 0;

        return [false, 'Too many attempts. Locked for ' . ADMIN_LOCKOUT_SECONDS . 's.'];
    }

    return [false, 'Incorrect password.'];
}

/**
 * Drop admin privileges from the current session (keeps CSRF token intact).
 */
function admin_logout(): void
{
    unset($_SESSION['admin_authenticated'], $_SESSION['admin_last_activity']);
}
