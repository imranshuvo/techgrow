<?php

declare(strict_types=1);

/**
 * TechGrow Ltd — landing page + early-access lead collector.
 *
 * The form posts back to this file. We validate and store the submission,
 * then redirect (POST/Redirect/GET) so a refresh never re-submits.
 */

require __DIR__ . '/../src/functions.php';
require __DIR__ . '/../src/database.php';

start_secure_session();
send_security_headers();

/* ----------------------------------------------------------------------
 | Handle the form submission
 * ------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = [
        'name'    => sanitize_text($_POST['name'] ?? '', 80),
        'email'   => sanitize_text($_POST['email'] ?? '', 160),
        'message' => sanitize_text($_POST['message'] ?? '', 1000),
    ];

    // 1. Honeypot — real users never see or fill this field.
    //    If it is filled, silently accept (don't tip off the bot) but store nothing.
    if (trim((string) ($_POST['company_website'] ?? '')) !== '') {
        $_SESSION['flash'] = [
            'type'    => 'success',
            'message' => "Thanks! You're on the early list.",
        ];
        redirect(form_redirect_target());
    }

    // 2. CSRF protection.
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = [
            'type'    => 'error',
            'message' => 'Your session expired. Please try submitting again.',
            'old'     => $old,
        ];
        redirect(form_redirect_target());
    }

    // 3. Field validation.
    $errors = [];

    $name = $old['name'];
    if ($name === '' || mb_strlen($name) < 2) {
        $errors['name'] = 'Please enter your name.';
    }

    $email = normalize_email($_POST['email'] ?? '');
    if ($email === null) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    $message = $old['message']; // optional, already sanitised

    if ($errors !== []) {
        $_SESSION['flash'] = [
            'type'    => 'error',
            'message' => 'Please check the highlighted fields and try again.',
            'errors'  => $errors,
            'old'     => $old,
        ];
        redirect(form_redirect_target());
    }

    // 4. Store the subscriber.
    try {
        $pdo   = get_db();
        $added = add_subscriber(
            $pdo,
            $name,
            (string) $email,
            $message,
            client_ip(),
            sanitize_text($_SERVER['HTTP_USER_AGENT'] ?? '', 255)
        );

        csrf_rotate();

        $_SESSION['flash'] = $added
            ? ['type' => 'success', 'message' => "You're on the list. We'll be in touch when we launch something useful."]
            : ['type' => 'info', 'message' => "You're already on the early list — thank you!"];
    } catch (Throwable $e) {
        // Log the detail server-side; never leak it to the visitor.
        error_log('[techgrow] subscription failed: ' . $e->getMessage());
        $_SESSION['flash'] = [
            'type'    => 'error',
            'message' => 'Something went wrong on our side. Please try again shortly.',
            'old'     => $old,
        ];
    }

    redirect(form_redirect_target());
}

/**
 * Redirect target for the POST/Redirect/GET flow — current URL + #join anchor.
 */
function form_redirect_target(): string
{
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';
    return $path . '#join';
}

/* ----------------------------------------------------------------------
 | Read + clear flash state for rendering
 * ------------------------------------------------------------------- */
$flash  = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$old    = $flash['old']    ?? ['name' => '', 'email' => '', 'message' => ''];
$errors = $flash['errors'] ?? [];
$token  = csrf_token();

$buildCards = [
    [
        'title' => 'WooCommerce growth tools',
        'desc'  => 'Practical extensions and tweaks that lift average order value, retention, and store performance.',
        'icon'  => 'cart',
    ],
    [
        'title' => 'Search & recommendation systems',
        'desc'  => 'Faster, smarter on-site search and product recommendations that help customers find what they want.',
        'icon'  => 'search',
    ],
    [
        'title' => 'Conversion & checkout diagnostics',
        'desc'  => 'Find where customers drop off and fix the friction in checkout, forms, and key conversion paths.',
        'icon'  => 'funnel',
    ],
    [
        'title' => 'Tracking & analytics implementation',
        'desc'  => 'Reliable, privacy-aware tracking so you can trust your data and make confident decisions.',
        'icon'  => 'chart',
    ],
    [
        'title' => 'AI-powered automation tools',
        'desc'  => 'Workflows and assistants that remove repetitive work and put your operations on autopilot.',
        'icon'  => 'bot',
    ],
    [
        'title' => 'Custom web systems',
        'desc'  => 'Tailored applications and integrations built around how your business actually works.',
        'icon'  => 'code',
    ],
];

/** Minimal inline icon set (no JS, no icon library). */
function icon(string $name): string
{
    $paths = [
        'cart'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121 0 2.1-.768 2.37-1.856l1.387-5.55A1.125 1.125 0 0 0 20.625 5.4H5.106M7.5 14.25 5.106 5.4m0 0L4.5 3M7.5 18.75a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm11.25 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />',
        'search' => '<path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />',
        'funnel' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 0 1-.659 1.591l-5.432 5.432a2.25 2.25 0 0 0-.659 1.591v2.927a2.25 2.25 0 0 1-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 0 0-.659-1.591L3.659 7.409A2.25 2.25 0 0 1 3 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0 1 12 3Z" />',
        'chart'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />',
        'bot'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.456-2.456L14.25 6l1.035-.259a3.375 3.375 0 0 0 2.456-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" />',
        'code'   => '<path stroke-linecap="round" stroke-linejoin="round" d="m6.75 7.5 3 2.25-3 2.25m4.5 0h3m-9 8.25h13.5A2.25 2.25 0 0 0 21 18V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v12a2.25 2.25 0 0 0 2.25 2.25Z" />',
    ];

    return $paths[$name] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TechGrow Ltd — Practical technology built for business growth</title>
    <meta name="description" content="TechGrow Ltd builds practical web systems, WooCommerce tools, automation workflows, analytics setups, and AI-powered products for modern businesses. Join the early list.">
    <meta name="theme-color" content="#0f172a">

    <!-- Open Graph -->
    <meta property="og:title" content="TechGrow Ltd — Technology built for real business growth">
    <meta property="og:description" content="Practical web systems, WooCommerce tools, automation, analytics, and AI products. Join the early list.">
    <meta property="og:type" content="website">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                    },
                },
            },
        };
    </script>
    <style>
        body { -webkit-font-smoothing: antialiased; text-rendering: optimizeLegibility; }
        /* Subtle dotted grid used behind the hero. */
        .grid-bg {
            background-image: radial-gradient(circle at 1px 1px, rgba(15, 23, 42, 0.06) 1px, transparent 0);
            background-size: 28px 28px;
        }
    </style>
</head>
<body class="bg-white text-slate-800 font-sans antialiased">

    <!-- ============================ NAV ============================ -->
    <header class="sticky top-0 z-50 border-b border-slate-200/70 bg-white/80 backdrop-blur">
        <nav class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
            <a href="#top" class="flex items-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 text-white shadow-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 17l6-6 4 4 8-8" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14 7h7v7" />
                    </svg>
                </span>
                <span class="text-lg font-bold tracking-tight text-slate-900">TechGrow<span class="text-emerald-600"> Ltd</span></span>
            </a>

            <div class="hidden items-center gap-8 md:flex">
                <a href="#about" class="text-sm font-medium text-slate-600 transition hover:text-slate-900">About</a>
                <a href="#building" class="text-sm font-medium text-slate-600 transition hover:text-slate-900">What we're building</a>
                <a href="#join" class="text-sm font-medium text-slate-600 transition hover:text-slate-900">Early list</a>
            </div>

            <a href="#join" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-700">
                Join the early list
            </a>
        </nav>
    </header>

    <main id="top">

        <!-- ============================ HERO ============================ -->
        <section class="relative overflow-hidden">
            <div class="grid-bg absolute inset-0 -z-10"></div>
            <div class="absolute inset-x-0 top-0 -z-10 h-96 bg-gradient-to-b from-emerald-50/80 to-transparent"></div>

            <div class="mx-auto max-w-4xl px-6 pb-20 pt-20 text-center sm:pt-28">
                <div class="mb-6 inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-4 py-1.5 text-xs font-semibold text-emerald-700">
                    <span class="relative flex h-2 w-2">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                    </span>
                    Early access — building in the open
                </div>

                <h1 class="mx-auto max-w-3xl text-4xl font-extrabold leading-tight tracking-tight text-slate-900 sm:text-5xl md:text-6xl">
                    Technology products and systems built for <span class="bg-gradient-to-r from-emerald-600 to-teal-600 bg-clip-text text-transparent">real business growth</span>.
                </h1>

                <p class="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-slate-600">
                    TechGrow Ltd builds practical web systems, WooCommerce tools, automation workflows,
                    analytics setups, and AI-powered products for modern businesses.
                </p>

                <div class="mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <a href="#join" class="w-full rounded-lg bg-emerald-600 px-7 py-3.5 text-base font-semibold text-white shadow-sm transition hover:bg-emerald-700 sm:w-auto">
                        Join the early list
                    </a>
                    <a href="#building" class="w-full rounded-lg border border-slate-300 bg-white px-7 py-3.5 text-base font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50 sm:w-auto">
                        See what we're building
                    </a>
                </div>

                <p class="mt-5 text-sm text-slate-500">
                    Get updates when we launch new tools, products, and services. No spam.
                </p>
            </div>

            <!-- Tech keyword strip -->
            <div class="border-y border-slate-200 bg-white/60">
                <div class="mx-auto flex max-w-5xl flex-wrap items-center justify-center gap-x-8 gap-y-3 px-6 py-5 text-sm font-medium text-slate-400">
                    <span>WordPress</span>
                    <span class="text-slate-300">•</span>
                    <span>WooCommerce</span>
                    <span class="text-slate-300">•</span>
                    <span>Laravel &amp; PHP</span>
                    <span class="text-slate-300">•</span>
                    <span>JavaScript</span>
                    <span class="text-slate-300">•</span>
                    <span>Tracking &amp; Analytics</span>
                    <span class="text-slate-300">•</span>
                    <span>Performance</span>
                    <span class="text-slate-300">•</span>
                    <span>AI &amp; Automation</span>
                </div>
            </div>
        </section>

        <!-- ============================ ABOUT ============================ -->
        <section id="about" class="mx-auto max-w-6xl px-6 py-20 sm:py-28">
            <div class="grid items-start gap-12 md:grid-cols-2 md:gap-16">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-wider text-emerald-600">About TechGrow</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">
                        A founder-led technology company.
                    </h2>
                    <p class="mt-6 text-lg leading-relaxed text-slate-600">
                        TechGrow Ltd is built on nearly a decade of hands-on web development —
                        WordPress and WooCommerce, PHP and Laravel, JavaScript, tracking,
                        performance optimisation, automation, and AI-powered systems.
                    </p>
                    <p class="mt-4 text-lg leading-relaxed text-slate-600">
                        The focus is simple: solve practical business problems with technology.
                        Not just building websites — building the systems, tools, and products
                        that actually move the numbers that matter.
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <?php
                    $stats = [
                        ['~10 yrs', 'Building for the web'],
                        ['eCommerce', 'WooCommerce &amp; growth'],
                        ['Data-led', 'Tracking &amp; analytics'],
                        ['AI-driven', 'Automation &amp; tooling'],
                    ];
                    foreach ($stats as [$big, $small]): ?>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50/60 p-6">
                            <div class="text-xl font-bold text-slate-900"><?= $big ?></div>
                            <div class="mt-1 text-sm text-slate-500"><?= $small ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- ====================== WHAT WE'RE BUILDING ====================== -->
        <section id="building" class="border-y border-slate-200 bg-slate-50/60">
            <div class="mx-auto max-w-6xl px-6 py-20 sm:py-28">
                <div class="mx-auto max-w-2xl text-center">
                    <p class="text-sm font-semibold uppercase tracking-wider text-emerald-600">What we're building</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">
                        Practical tools for growth-focused businesses.
                    </h2>
                    <p class="mt-4 text-lg text-slate-600">
                        A roadmap of systems and products aimed at eCommerce, conversion, data, and automation.
                    </p>
                </div>

                <div class="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <?php foreach ($buildCards as $card): ?>
                        <article class="group rounded-2xl border border-slate-200 bg-white p-7 shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-200 hover:shadow-md">
                            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 transition group-hover:bg-emerald-600 group-hover:text-white">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                                    <?= icon($card['icon']) ?>
                                </svg>
                            </div>
                            <h3 class="mt-5 text-lg font-semibold text-slate-900"><?= e($card['title']) ?></h3>
                            <p class="mt-2 text-sm leading-relaxed text-slate-600"><?= e($card['desc']) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- ======================= EMAIL COLLECTOR ======================= -->
        <section id="join" class="mx-auto max-w-6xl scroll-mt-24 px-6 py-20 sm:py-28">
            <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="grid md:grid-cols-2">

                    <!-- Copy -->
                    <div class="bg-gradient-to-br from-slate-900 to-slate-800 p-8 text-white sm:p-12">
                        <h2 class="text-3xl font-bold tracking-tight sm:text-4xl">
                            Be the first to know what we launch.
                        </h2>
                        <p class="mt-5 text-lg leading-relaxed text-slate-300">
                            We are building practical tools for eCommerce, automation, analytics,
                            and AI-assisted business growth. Join the early list for updates.
                        </p>

                        <ul class="mt-8 space-y-4 text-slate-200">
                            <?php foreach ([
                                'Early access to new tools and products',
                                'Practical growth and automation insights',
                                'No spam — only relevant updates',
                            ] as $point): ?>
                                <li class="flex items-start gap-3">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 h-5 w-5 flex-none text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                    </svg>
                                    <span class="text-sm sm:text-base"><?= e($point) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <!-- Form -->
                    <div class="p-8 sm:p-12">
                        <?php if ($flash !== null): ?>
                            <?php
                            $styles = [
                                'success' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
                                'info'    => 'border-sky-200 bg-sky-50 text-sky-800',
                                'error'   => 'border-red-200 bg-red-50 text-red-700',
                            ];
                            $cls = $styles[$flash['type']] ?? $styles['info'];
                            ?>
                            <div class="mb-6 rounded-xl border px-4 py-3 text-sm font-medium <?= $cls ?>" role="status" aria-live="polite">
                                <?= e($flash['message']) ?>
                            </div>
                        <?php endif; ?>

                        <form method="post" action="#join" novalidate class="space-y-5">
                            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">

                            <!-- Honeypot: hidden from humans, tempting to bots. -->
                            <div class="absolute left-[-9999px]" aria-hidden="true">
                                <label for="company_website">Company website (leave blank)</label>
                                <input type="text" id="company_website" name="company_website" tabindex="-1" autocomplete="off">
                            </div>

                            <div>
                                <label for="name" class="mb-1.5 block text-sm font-semibold text-slate-700">Name</label>
                                <input
                                    type="text" id="name" name="name" required maxlength="80"
                                    value="<?= e($old['name']) ?>"
                                    autocomplete="name"
                                    class="block w-full rounded-lg border <?= isset($errors['name']) ? 'border-red-400' : 'border-slate-300' ?> bg-white px-4 py-3 text-slate-900 shadow-sm transition placeholder:text-slate-400 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/30"
                                    placeholder="Your name">
                                <?php if (isset($errors['name'])): ?>
                                    <p class="mt-1.5 text-sm text-red-600"><?= e($errors['name']) ?></p>
                                <?php endif; ?>
                            </div>

                            <div>
                                <label for="email" class="mb-1.5 block text-sm font-semibold text-slate-700">Email</label>
                                <input
                                    type="email" id="email" name="email" required maxlength="160"
                                    value="<?= e($old['email']) ?>"
                                    autocomplete="email"
                                    class="block w-full rounded-lg border <?= isset($errors['email']) ? 'border-red-400' : 'border-slate-300' ?> bg-white px-4 py-3 text-slate-900 shadow-sm transition placeholder:text-slate-400 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/30"
                                    placeholder="you@company.com">
                                <?php if (isset($errors['email'])): ?>
                                    <p class="mt-1.5 text-sm text-red-600"><?= e($errors['email']) ?></p>
                                <?php endif; ?>
                            </div>

                            <div>
                                <label for="message" class="mb-1.5 block text-sm font-semibold text-slate-700">
                                    Message or interests <span class="font-normal text-slate-400">(optional)</span>
                                </label>
                                <textarea
                                    id="message" name="message" rows="3" maxlength="1000"
                                    class="block w-full rounded-lg border border-slate-300 bg-white px-4 py-3 text-slate-900 shadow-sm transition placeholder:text-slate-400 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/30"
                                    placeholder="What are you most interested in? (e.g. WooCommerce, analytics, AI automation)"><?= e($old['message']) ?></textarea>
                            </div>

                            <button type="submit" class="w-full rounded-lg bg-emerald-600 px-6 py-3.5 text-base font-semibold text-white shadow-sm transition hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500/40">
                                Join the early list
                            </button>

                            <p class="text-center text-xs text-slate-400">
                                We only use your email to send relevant updates. Unsubscribe anytime.
                            </p>
                        </form>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <!-- ============================ FOOTER ============================ -->
    <footer class="border-t border-slate-200 bg-slate-50/60">
        <div class="mx-auto max-w-6xl px-6 py-12">
            <div class="flex flex-col items-start justify-between gap-6 sm:flex-row sm:items-center">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 text-white">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 17l6-6 4 4 8-8" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 7h7v7" />
                        </svg>
                    </span>
                    <span class="font-bold tracking-tight text-slate-900">TechGrow Ltd</span>
                </div>

                <a href="mailto:hello@techgrow.ltd" class="text-sm font-medium text-slate-600 transition hover:text-emerald-600">
                    hello@techgrow.ltd
                </a>
            </div>

            <div class="mt-8 border-t border-slate-200 pt-6 text-sm text-slate-500">
                <p>&copy; <?= date('Y') ?> TechGrow Ltd. All rights reserved.</p>
                <p class="mt-1 text-slate-400">We only use your email to send relevant updates.</p>
            </div>
        </div>
    </footer>

</body>
</html>
