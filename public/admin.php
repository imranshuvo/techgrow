<?php

declare(strict_types=1);

/**
 * TechGrow Ltd — admin dashboard.
 *
 * Password-protected view of collected subscribers: stats, search,
 * pagination, CSV export, and delete. Reads the same SQLite database
 * used by the public form. Enable it by setting ADMIN_PASSWORD_HASH.
 */

require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/functions.php';
require __DIR__ . '/../src/database.php';
require __DIR__ . '/../src/admin.php';

start_secure_session();
send_security_headers();
header('X-Robots-Tag: noindex, nofollow');

$self = strtok($_SERVER['REQUEST_URI'] ?? '/admin.php', '?') ?: '/admin.php';

/** Build a querystring that preserves the current search + page. */
function admin_query(string $search, int $page): string
{
    $params = [];
    if ($search !== '') {
        $params['q'] = $search;
    }
    if ($page > 1) {
        $params['page'] = $page;
    }

    return $params === [] ? '' : '?' . http_build_query($params);
}

/* ----------------------------------------------------------------------
 | Logout
 * ------------------------------------------------------------------- */
if (($_GET['action'] ?? '') === 'logout') {
    admin_logout();
    $_SESSION['flash'] = ['type' => 'info', 'message' => 'You have been logged out.'];
    redirect($self);
}

/* ----------------------------------------------------------------------
 | Not configured — show setup instructions instead of a broken page
 * ------------------------------------------------------------------- */
if (!admin_enabled()) {
    render_admin_shell('Admin not configured', function (): void { ?>
        <div class="mx-auto mt-16 max-w-xl rounded-2xl border border-amber-200 bg-amber-50 p-8">
            <h1 class="text-xl font-bold text-amber-900">Admin dashboard is not configured</h1>
            <p class="mt-3 text-sm leading-relaxed text-amber-800">
                Set an <code class="rounded bg-amber-100 px-1.5 py-0.5">ADMIN_PASSWORD_HASH</code>
                environment variable to enable this dashboard. Generate a hash with:
            </p>
            <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100"><code>php bin/hash-password.php</code></pre>
            <p class="mt-4 text-sm text-amber-800">
                Then add the printed hash to your <code class="rounded bg-amber-100 px-1.5 py-0.5">.env</code>
                file (local) or your Coolify environment variables, and reload.
            </p>
        </div>
    <?php });
    exit;
}

/* ----------------------------------------------------------------------
 | Login attempt
 * ------------------------------------------------------------------- */
$loginError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $loginError = 'Your session expired. Please try again.';
    } else {
        [$ok, $message] = admin_attempt_login((string) ($_POST['password'] ?? ''));
        if ($ok) {
            redirect($self);
        }
        $loginError = $message;
    }
}

/* ----------------------------------------------------------------------
 | Gate: show the login screen if not authenticated
 * ------------------------------------------------------------------- */
if (!admin_is_authenticated()) {
    $token = csrf_token();
    render_admin_shell('Admin login', function () use ($loginError, $token): void { ?>
        <div class="mx-auto mt-20 max-w-sm">
            <div class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
                <h1 class="text-lg font-bold text-slate-900">TechGrow Admin</h1>
                <p class="mt-1 text-sm text-slate-500">Enter your password to continue.</p>

                <?php if ($loginError !== null): ?>
                    <div class="mt-5 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-700" role="alert">
                        <?= e($loginError) ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= e($GLOBALS['self']) ?>" class="mt-6 space-y-4">
                    <input type="hidden" name="action" value="login">
                    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                    <div>
                        <label for="password" class="mb-1.5 block text-sm font-semibold text-slate-700">Password</label>
                        <input type="password" id="password" name="password" required autofocus autocomplete="current-password"
                               class="block w-full rounded-lg border border-slate-300 bg-white px-4 py-3 text-slate-900 shadow-sm transition focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/30">
                    </div>
                    <button type="submit" class="w-full rounded-lg bg-emerald-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700">
                        Sign in
                    </button>
                </form>
            </div>
            <p class="mt-6 text-center text-xs text-slate-400">TechGrow Ltd · internal</p>
        </div>
    <?php });
    exit;
}

/* ======================================================================
 | Authenticated area
 * ===================================================================== */
$pdo = get_db();

/* CSV export */
if (($_GET['action'] ?? '') === 'export') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="techgrow-subscribers-' . date('Ymd') . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads it correctly
    fputcsv($out, ['id', 'name', 'email', 'message', 'ip_address', 'user_agent', 'created_at'], ',', '"', '');

    $stmt = $pdo->query(
        'SELECT id, name, email, message, ip_address, user_agent, created_at
         FROM subscribers ORDER BY id DESC'
    );
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        fputcsv($out, $row, ',', '"', '');
    }
    fclose($out);
    exit;
}

/* Delete */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $backTo = $self . admin_query(
        sanitize_text($_POST['q'] ?? '', 100),
        max(1, (int) ($_POST['page'] ?? 1))
    );

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Your session expired. Please try again.'];
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        $_SESSION['flash'] = ($id > 0 && delete_subscriber($pdo, $id))
            ? ['type' => 'success', 'message' => 'Subscriber deleted.']
            : ['type' => 'error', 'message' => 'Could not delete that subscriber.'];
    }
    redirect($backTo);
}

/* Dashboard data */
$search   = sanitize_text($_GET['q'] ?? '', 100);
$pageSize = config('admin_page_size');
$total    = count_subscribers($pdo, $search);
$pages    = max(1, (int) ceil($total / $pageSize));
$page     = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);
$offset   = ($page - 1) * $pageSize;
$rows     = get_subscribers($pdo, $search, $pageSize, $offset);

$statTotal = count_subscribers($pdo, '');
$stat7     = count_subscribers_last_days($pdo, 7);
$statToday = count_subscribers_today($pdo);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$token = csrf_token();

render_admin_shell('Subscribers', function () use (
    $rows, $search, $page, $pages, $total, $offset, $pageSize,
    $statTotal, $stat7, $statToday, $flash, $token
): void {
    $self = $GLOBALS['self'];
    ?>
    <div class="mx-auto max-w-6xl px-6 py-10">

        <!-- Header -->
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 text-white">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 17l6-6 4 4 8-8" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14 7h7v7" />
                    </svg>
                </span>
                <span class="font-bold tracking-tight text-slate-900">TechGrow <span class="font-medium text-slate-400">/ Admin</span></span>
            </div>
            <div class="flex items-center gap-3">
                <a href="<?= e($self) ?>?action=export" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" /></svg>
                    Export CSV
                </a>
                <a href="<?= e($self) ?>?action=logout" class="rounded-lg px-3.5 py-2 text-sm font-semibold text-slate-500 transition hover:text-slate-900">Log out</a>
            </div>
        </div>

        <?php if ($flash !== null): ?>
            <?php
            $styles = [
                'success' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
                'info'    => 'border-sky-200 bg-sky-50 text-sky-800',
                'error'   => 'border-red-200 bg-red-50 text-red-700',
            ];
            $cls = $styles[$flash['type']] ?? $styles['info'];
            ?>
            <div class="mt-6 rounded-xl border px-4 py-3 text-sm font-medium <?= $cls ?>" role="status"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="mt-8 grid gap-4 sm:grid-cols-3">
            <?php foreach ([
                ['Total subscribers', $statTotal],
                ['Last 7 days', $stat7],
                ['Today', $statToday],
            ] as [$label, $value]): ?>
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="text-sm font-medium text-slate-500"><?= e($label) ?></div>
                    <div class="mt-1 text-3xl font-extrabold tracking-tight text-slate-900"><?= number_format((int) $value) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Search -->
        <form method="get" action="<?= e($self) ?>" class="mt-8 flex gap-3">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search name or email…"
                   class="block w-full max-w-md rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/30">
            <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-700">Search</button>
            <?php if ($search !== ''): ?>
                <a href="<?= e($self) ?>" class="inline-flex items-center px-2 text-sm font-medium text-slate-500 hover:text-slate-900">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Table -->
        <div class="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-5 py-3">#</th>
                            <th class="px-5 py-3">Name</th>
                            <th class="px-5 py-3">Email</th>
                            <th class="px-5 py-3">Interests</th>
                            <th class="px-5 py-3">IP</th>
                            <th class="px-5 py-3 whitespace-nowrap">Signed up (UTC)</th>
                            <th class="px-5 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if ($rows === []): ?>
                            <tr><td colspan="7" class="px-5 py-12 text-center text-slate-400">
                                <?= $search !== '' ? 'No subscribers match your search.' : 'No subscribers yet.' ?>
                            </td></tr>
                        <?php else: foreach ($rows as $row):
                            $msg = (string) ($row['message'] ?? ''); ?>
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 text-slate-400"><?= (int) $row['id'] ?></td>
                                <td class="px-5 py-3 font-medium text-slate-900"><?= e($row['name']) ?></td>
                                <td class="px-5 py-3"><a href="mailto:<?= e($row['email']) ?>" class="text-emerald-700 hover:underline"><?= e($row['email']) ?></a></td>
                                <td class="px-5 py-3 max-w-xs truncate text-slate-600" title="<?= e($msg) ?>"><?= $msg !== '' ? e($msg) : '<span class="text-slate-300">—</span>' ?></td>
                                <td class="px-5 py-3 text-slate-500"><?= e($row['ip_address'] ?? '—') ?></td>
                                <td class="px-5 py-3 whitespace-nowrap text-slate-500"><?= e($row['created_at']) ?></td>
                                <td class="px-5 py-3 text-right">
                                    <form method="post" action="<?= e($self) ?>" onsubmit="return confirm('Delete <?= e($row['email']) ?>? This cannot be undone.');" class="inline">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <input type="hidden" name="q" value="<?= e($search) ?>">
                                        <input type="hidden" name="page" value="<?= (int) $page ?>">
                                        <button type="submit" class="text-sm font-medium text-red-600 transition hover:text-red-800">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <div class="mt-5 flex items-center justify-between text-sm text-slate-500">
            <div>
                <?php if ($total > 0): ?>
                    Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $pageSize, $total)) ?> of <?= number_format($total) ?>
                <?php else: ?>
                    0 results
                <?php endif; ?>
            </div>
            <div class="flex items-center gap-2">
                <?php
                $prevDisabled = $page <= 1;
                $nextDisabled = $page >= $pages;
                ?>
                <a href="<?= $prevDisabled ? '#' : e($self . admin_query($search, $page - 1)) ?>"
                   class="rounded-lg border px-3 py-1.5 font-medium <?= $prevDisabled ? 'pointer-events-none border-slate-200 text-slate-300' : 'border-slate-300 text-slate-700 hover:bg-slate-50' ?>">Previous</a>
                <span class="px-1">Page <?= $page ?> of <?= $pages ?></span>
                <a href="<?= $nextDisabled ? '#' : e($self . admin_query($search, $page + 1)) ?>"
                   class="rounded-lg border px-3 py-1.5 font-medium <?= $nextDisabled ? 'pointer-events-none border-slate-200 text-slate-300' : 'border-slate-300 text-slate-700 hover:bg-slate-50' ?>">Next</a>
            </div>
        </div>
    </div>
    <?php
});

/**
 * Shared HTML shell for every admin screen.
 *
 * @param callable():void $body Echoes the page body.
 */
function render_admin_shell(string $title, callable $body): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?> · TechGrow Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] } } } };</script>
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-800 antialiased">
    <?php $body(); ?>
</body>
</html>
    <?php
}
