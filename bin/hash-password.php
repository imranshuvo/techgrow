#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate a bcrypt hash for the admin password.
 *
 * Usage:
 *   php bin/hash-password.php                 (prompts, input hidden)
 *   php bin/hash-password.php 'your-password' (one-shot; clear your shell history)
 *
 * Paste the printed hash into ADMIN_PASSWORD_HASH in your .env file
 * (local) or your Coolify / server environment variables.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$password = $argv[1] ?? null;

if ($password === null) {
    fwrite(STDOUT, 'Enter admin password: ');

    // Best-effort: hide the typed characters on a real terminal.
    $hideEcho = stream_isatty(STDIN);
    if ($hideEcho) {
        shell_exec('stty -echo 2>/dev/null');
    }

    $password = rtrim((string) fgets(STDIN), "\r\n");

    if ($hideEcho) {
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
    }
}

if ($password === '') {
    fwrite(STDERR, "Password cannot be empty.\n");
    exit(1);
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Warning: use at least 8 characters for a real deployment.\n");
}

$hash = password_hash($password, PASSWORD_BCRYPT);

fwrite(STDOUT, "\nAdd this to your environment:\n\n");
fwrite(STDOUT, 'ADMIN_PASSWORD_HASH=' . $hash . "\n\n");
