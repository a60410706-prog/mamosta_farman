<?php
/**
 * login.php — پەڕەی چوونەژوورەوە
 * -------------------------------------------------------------
 * - پەیوەندی بە داتابەیس دەکات لە ڕێگەی includes/db.php
 * - بە Prepared Statements بەکارهێنەر دەپشکنێت
 * - Session دروست دەکات و بەپێی ڕۆڵ ڕێنمایی دەکات
 * -------------------------------------------------------------
 * تێبینی: includes/db.php دەبێت یەکێک لەمانە دابین بکات:
 *   $pdo  => ئۆبجێکتی PDO
 *   $conn => ئۆبجێکتی mysqli
 */

// ───────────────────────────────────────────────
// 1) ڕێکخستنی سشن (پارێزراو)
// ───────────────────────────────────────────────
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,      // JavaScript نەتوانێت کوکی بخوێنێتەوە
    'samesite' => 'Lax',     // پاراستن لە CSRF
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();

// پەیوەندی بە داتابەیس
require_once __DIR__ . '/includes/db.php';

// ───────────────────────────────────────────────
// 2) نەخشەی ڕۆڵەکان => پەڕەی مەبەست
// ───────────────────────────────────────────────
const ROLE_REDIRECTS = [
    'admin'   => 'dashboard.php',
    'cashier' => 'pos.php',
];

/**
 * ئەگەر پێشتر چووبێتە ژوورەوە، ڕاستەوخۆ بیبە بۆ پەڕەی خۆی
 */
if (!empty($_SESSION['user_id']) && isset(ROLE_REDIRECTS[$_SESSION['role'] ?? ''])) {
    header('Location: ' . ROLE_REDIRECTS[$_SESSION['role']]);
    exit;
}

// ───────────────────────────────────────────────
// 3) فانکشنە یارمەتیدەرەکان
// ───────────────────────────────────────────────

/**
 * بەکارهێنەر بە ناوەکەی دەدۆزێتەوە (Prepared Statement)
 * دەتوانێت هەم PDO و هەم mysqli بەکار بهێنێت.
 */
function findUserByUsername(string $username): ?array
{
    global $pdo, $conn;
    $sql = 'SELECT user_id, full_name, username, password, role
            FROM users WHERE username = ? LIMIT 1';

    // ئەگەر db.php بە PDO نووسرابێت
    if (isset($pdo) && $pdo instanceof PDO) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ئەگەر db.php بە mysqli نووسرابێت
    if (isset($conn) && $conn instanceof mysqli) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    throw new RuntimeException('پەیوەندی داتابەیس لە includes/db.php نەدۆزرایەوە.');
}

/**
 * وشەی نهێنی دەپشکنێت.
 * - ئەگەر hash بێت (password_hash) => password_verify
 * - ئەگەر دەقی ئاسایی بێت (کۆن) => بەراوردی پارێزراو
 * پێشنیار: وشە نهێنییەکان بگۆڕە بۆ password_hash()
 */
function verifyPassword(string $input, string $stored): bool
{
    $info = password_get_info($stored);
    if (!empty($info['algo'])) {
        return password_verify($input, $stored);
    }
    return hash_equals($stored, $input);
}

// ───────────────────────────────────────────────
// 4) ئامادەکاری: CSRF و سنووردارکردنی هەوڵدان
// ───────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

const MAX_ATTEMPTS  = 5;    // زۆرترین هەوڵی هەڵە
const LOCKOUT_SECS  = 60;   // ماوەی بلۆککردن بە چرکە

$error    = '';
$username = '';

// ───────────────────────────────────────────────
// 5) کاتێک فۆڕمەکە نێردرا
// ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $token    = $_POST['csrf_token'] ?? '';

    $lockedUntil = $_SESSION['locked_until'] ?? 0;

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        // تۆکنی فۆڕم هەڵەیە
        $error = 'فۆڕمەکە بەسەرچووە، تکایە پەڕەکە نوێ بکەرەوە و دووبارە هەوڵ بدەرەوە.';

    } elseif ($lockedUntil > time()) {
        // زۆر هەوڵی هەڵە دراوە
        $error = 'هەوڵی هەڵەی زۆرت داوە. تکایە ' . ($lockedUntil - time()) . ' چرکە چاوەڕێ بکە.';

    } elseif ($username === '' || $password === '') {
        $error = 'تکایە ناوی بەکارهێنەر و وشەی نهێنی بنووسە.';

    } else {
        try {
            $user = findUserByUsername($username);

            // ناوی بەکارهێنەر هەبێت + وشەی نهێنی ڕاست بێت + ڕۆڵەکە ناسراو بێت
            if ($user
                && verifyPassword($password, $user['password'])
                && isset(ROLE_REDIRECTS[$user['role']])
            ) {
                // پاراستن لە Session Fixation
                session_regenerate_id(true);

                // دروستکردنی سشنەکان
                $_SESSION['user_id']   = (int) $user['user_id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role']      = $user['role'];

                // پاککردنەوەی ژمارەی هەوڵە هەڵەکان
                unset($_SESSION['attempts'], $_SESSION['locked_until']);
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                // ڕێنماییکردن بەپێی ڕۆڵ
                header('Location: ' . ROLE_REDIRECTS[$user['role']]);
                exit;
            }

            // چوونەژوورەوە سەرکەوتوو نەبوو
            $_SESSION['attempts'] = ($_SESSION['attempts'] ?? 0) + 1;
            if ($_SESSION['attempts'] >= MAX_ATTEMPTS) {
                $_SESSION['locked_until'] = time() + LOCKOUT_SECS;
                $_SESSION['attempts']     = 0;
            }

            // پەیامی گشتی: نادیار دەهێڵێتەوە کامیان هەڵەیە (ناو یان وشەی نهێنی)
            $error = 'ناوی بەکارهێنەر یان وشەی نهێنی هەڵەیە.';

        } catch (Throwable $e) {
            // هەڵەی تەکنیکی تۆمار دەکرێت، بەکارهێنەر وردەکاری نابینێت
            error_log('Login error: ' . $e->getMessage());
            $error = 'کێشەیەک ڕوویدا. تکایە دواتر هەوڵ بدەرەوە.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ckb" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>چوونەژوورەوە</title>

    <!-- فۆنتی کوردی (پشتگیری ڕ ڵ ۆ ێ ە دەکات) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;700;800&display=swap" rel="stylesheet">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Vazirmatn', 'Tahoma', 'sans-serif'] },
                    colors: {
                        navy:  { 950: '#07152B', 900: '#0B1F3A', 800: '#10294D', 700: '#1C3A66' },
                        brand: { DEFAULT: '#E89B1A', dark: '#CF8509' }
                    }
                }
            }
        }
    </script>
</head>

<body class="min-h-screen bg-navy-950 font-sans text-slate-100 antialiased">

    <!-- تیشکێکی نەرمی پرتەقاڵی لە پشتەوە -->
    <div aria-hidden="true"
         class="pointer-events-none fixed inset-0 bg-[radial-gradient(60rem_40rem_at_50%_-10%,rgba(232,155,26,0.14),transparent)]"></div>

    <main class="relative flex min-h-screen items-center justify-center px-4 py-10">

        <!-- کارتی ناوەندی -->
        <section class="w-full max-w-md overflow-hidden rounded-2xl border border-navy-700 bg-navy-900 shadow-2xl shadow-black/40">

            <!-- هێڵی پرتەقاڵی سەرەوەی کارتەکە -->
            <div class="h-1.5 bg-brand"></div>

            <div class="px-6 py-8 sm:px-10 sm:py-10">

                <!-- لۆگۆ و سەردێڕ -->
                <div class="mb-8 text-center">
                    <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-2xl bg-brand text-navy-950">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="10" rx="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                    </div>
                    <h1 class="text-2xl font-extrabold">چوونەژوورەوە</h1>
                    <p class="mt-2 text-sm text-slate-400">ناوی بەکارهێنەر و وشەی نهێنی بنووسە بۆ بەردەوامبوون.</p>
                </div>

                <!-- پەیامی هەڵە (سور) -->
                <?php if ($error !== ''): ?>
                    <div role="alert"
                         class="mb-6 flex items-start gap-3 rounded-xl border border-red-500/50 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                        <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 h-5 w-5 shrink-0" viewBox="0 0 24 24"
                             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                        <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                <?php endif; ?>

                <!-- فۆڕمی چوونەژوورەوە -->
                <form method="POST" action="" class="space-y-5" novalidate>

                    <!-- تۆکنی پاراستن (CSRF) -->
                    <input type="hidden" name="csrf_token"
                           value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                    <!-- ناوی بەکارهێنەر -->
                    <div>
                        <label for="username" class="mb-2 block text-sm font-medium text-slate-200">ناوی بەکارهێنەر</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-4 text-slate-400">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                    <circle cx="12" cy="7" r="4"/>
                                </svg>
                            </span>
                            <input type="text" id="username" name="username" required autofocus
                                   autocomplete="username"
                                   value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>"
                                   class="block w-full rounded-xl border border-navy-700 bg-navy-950 py-3 pe-4 ps-12 text-slate-100 placeholder-slate-500 transition focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40">
                        </div>
                    </div>

                    <!-- وشەی نهێنی -->
                    <div>
                        <label for="password" class="mb-2 block text-sm font-medium text-slate-200">وشەی نهێنی</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-4 text-slate-400">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.78 7.78 5.5 5.5 0 0 1 7.78-7.78zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>
                                </svg>
                            </span>
                            <input type="password" id="password" name="password" required
                                   autocomplete="current-password"
                                   class="block w-full rounded-xl border border-navy-700 bg-navy-950 py-3 pe-12 ps-12 text-slate-100 placeholder-slate-500 transition focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40">

                            <!-- دوگمەی پیشاندان/شاردنەوەی وشەی نهێنی -->
                            <button type="button" id="togglePassword" aria-label="پیشاندانی وشەی نهێنی"
                                    class="absolute inset-y-0 end-0 flex items-center pe-4 text-slate-400 transition hover:text-brand focus:outline-none focus-visible:text-brand">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- دوگمەی چوونەژوورەوە -->
                    <button type="submit"
                            class="w-full rounded-xl bg-brand px-4 py-3.5 text-base font-bold text-navy-950 transition hover:bg-brand-dark focus:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 focus-visible:ring-offset-navy-900 active:scale-[0.99]">
                        چوونەژوورەوە
                    </button>
                </form>
            </div>
        </section>
    </main>

    <script>
        // پیشاندان / شاردنەوەی وشەی نهێنی
        (function () {
            var btn   = document.getElementById('togglePassword');
            var input = document.getElementById('password');
            btn.addEventListener('click', function () {
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.setAttribute('aria-label', show ? 'شاردنەوەی وشەی نهێنی' : 'پیشاندانی وشەی نهێنی');
            });
        })();
    </script>
</body>
</html>