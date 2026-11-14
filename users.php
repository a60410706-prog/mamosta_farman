<?php
session_start();

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';

if (!$pdo instanceof PDO) {
    throw new RuntimeException('پەیوەندی PDO لە includes/db.php بەردەست نییە.');
}

if (empty($_SESSION['users_csrf_token'])) {
    $_SESSION['users_csrf_token'] = bin2hex(random_bytes(32));
}

function user_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$page_title = 'بەڕێوەبردنی بەکارهێنەران';
$errorMessage = '';
$successMessage = $_SESSION['user_success'] ?? '';
unset($_SESSION['user_success']);
$fullName = '';
$username = '';
$phone = '';
$role = 'cashier';
$users = [];
$editUser = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? 'add';
    $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
    if ($action === 'update') {
        $editUser = ['user_id' => $userId];
    }
    $fullNameInput = $_POST['full_name'] ?? '';
    $usernameInput = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $phoneInput = $_POST['phone'] ?? '';
    $roleInput = $_POST['role'] ?? '';

    $fullName = is_string($fullNameInput) ? trim($fullNameInput) : '';
    $username = is_string($usernameInput) ? trim($usernameInput) : '';
    $phone = is_string($phoneInput) ? trim($phoneInput) : '';
    $role = is_string($roleInput) ? $roleInput : '';

    if (!is_string($token) || !hash_equals($_SESSION['users_csrf_token'], $token)) {
        $errorMessage = 'داواکارییەکە پشتڕاست نەکرایەوە. پەڕەکە نوێ بکەرەوە و دووبارە هەوڵ بدەرەوە.';
    } elseif ($action === 'delete') {
        if (!$userId || $userId < 1) {
            $errorMessage = 'بەکارهێنەری داواکراو دروست نییە.';
        } elseif ($userId === (int) $_SESSION['user_id']) {
            $errorMessage = 'ناتوانیت هەژماری خۆت بسڕیتەوە.';
        } else {
            try {
                $stmt = $pdo->prepare('DELETE FROM users WHERE user_id = :user_id');
                $stmt->execute(['user_id' => $userId]);

                if ($stmt->rowCount() !== 1) {
                    $errorMessage = 'بەکارهێنەری داواکراو نەدۆزرایەوە.';
                } else {
                    $_SESSION['user_success'] = 'بەکارهێنەرەکە بە سەرکەوتوویی سڕایەوە.';
                    header('Location: users.php');
                    exit;
                }
            } catch (PDOException $exception) {
                error_log('Deleting user failed: ' . $exception->getMessage());
                $errorMessage = 'نەتوانرا ئەم بەکارهێنەرە بسڕدرێتەوە؛ ڕەنگە لە تۆمارەکانی سیستەمدا بەکارهاتبێت.';
            }
        }
    } elseif (!in_array($action, ['add', 'update'], true)) {
        $errorMessage = 'کرداری داواکراو ناسراو نییە.';
    } elseif ($fullName === '' || $username === '' || !is_string($password)) {
        $errorMessage = 'ناو، ناوی بەکارهێنەر و وشەی نهێنی پێویستن.';
    } elseif ($action === 'add' && strlen($password) < 8) {
        $errorMessage = 'وشەی نهێنی دەبێت لانیکەم ٨ پیت بێت.';
    } elseif ($action === 'update' && (!$userId || $userId < 1)) {
        $errorMessage = 'بەکارهێنەری داواکراو دروست نییە.';
    } elseif ($action === 'update' && $password !== '' && strlen($password) < 8) {
        $errorMessage = 'وشەی نهێنی نوێ دەبێت لانیکەم ٨ پیت بێت.';
    } elseif (!in_array($role, ['admin', 'cashier'], true)) {
        $errorMessage = 'ڕۆڵی هەڵبژێردراو دروست نییە.';
    } else {
        try {
            if ($action === 'add') {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare(
                    'INSERT INTO users (full_name, username, `password`, role, phone, created_at)
                     VALUES (:full_name, :username, :password, :role, :phone, NOW())'
                );
                $stmt->execute([
                    'full_name' => $fullName,
                    'username' => $username,
                    'password' => $passwordHash,
                    'role' => $role,
                    'phone' => $phone !== '' ? $phone : null,
                ]);
                $_SESSION['user_success'] = 'بەکارهێنەرەکە بە سەرکەوتوویی زیاد کرا.';
            } elseif ($password !== '') {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare(
                    'UPDATE users
                     SET full_name = :full_name, username = :username, `password` = :password,
                         role = :role, phone = :phone
                     WHERE user_id = :user_id'
                );
                $stmt->execute([
                    'full_name' => $fullName,
                    'username' => $username,
                    'password' => $passwordHash,
                    'role' => $role,
                    'phone' => $phone !== '' ? $phone : null,
                    'user_id' => $userId,
                ]);
                if ($userId === (int) $_SESSION['user_id']) {
                    $_SESSION['full_name'] = $fullName;
                    $_SESSION['role'] = $role;
                }
                $_SESSION['user_success'] = 'زانیاریی بەکارهێنەر نوێ کرایەوە.';
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE users
                     SET full_name = :full_name, username = :username, role = :role, phone = :phone
                     WHERE user_id = :user_id'
                );
                $stmt->execute([
                    'full_name' => $fullName,
                    'username' => $username,
                    'role' => $role,
                    'phone' => $phone !== '' ? $phone : null,
                    'user_id' => $userId,
                ]);
                if ($userId === (int) $_SESSION['user_id']) {
                    $_SESSION['full_name'] = $fullName;
                    $_SESSION['role'] = $role;
                }
                $_SESSION['user_success'] = 'زانیاریی بەکارهێنەر نوێ کرایەوە.';
            }

            header('Location: users.php');
            exit;
        } catch (PDOException $exception) {
            error_log('Saving user failed: ' . $exception->getMessage());
            $errorMessage = 'هەڵەیەک لە پاشەکەوتکردنی بەکارهێنەر ڕوویدا. ناوی بەکارهێنەر دووبارە بپشکنە.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if (!$editId || $editId < 1) {
        $errorMessage = 'بەکارهێنەری داواکراو دروست نییە.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'SELECT user_id, full_name, username, role, phone
                 FROM users WHERE user_id = :user_id'
            );
            $stmt->execute(['user_id' => $editId]);
            $editUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$editUser) {
                $errorMessage = 'بەکارهێنەری داواکراو نەدۆزرایەوە.';
            } else {
                $fullName = $editUser['full_name'];
                $username = $editUser['username'];
                $phone = $editUser['phone'] ?? '';
                $role = $editUser['role'];
            }
        } catch (PDOException $exception) {
            error_log('Loading user for edit failed: ' . $exception->getMessage());
            $errorMessage = 'نەتوانرا زانیاریی بەکارهێنەر بۆ دەستکاری بهێنرێت.';
        }
    }
}

try {
    $users = $pdo->query(
        'SELECT user_id, full_name, username, role, phone, created_at
         FROM users
         ORDER BY user_id DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log('Loading users failed: ' . $exception->getMessage());
    http_response_code(500);
    $errorMessage = 'هەڵەیەک لە بارکردنی لیستی بەکارهێنەراندا ڕوویدا.';
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
    <div class="mx-auto max-w-7xl">
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-slate-800">بەڕێوەبردنی بەکارهێنەران</h2>
            <p class="mt-1 text-sm text-slate-500">هەژماری بەکارهێنەری نوێ دروست بکە و ڕۆڵەکانی بەڕێوەببە.</p>
        </div>

        <?php if ($successMessage !== ''): ?>
            <div role="status" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">
                <?= user_escape($successMessage) ?>
            </div>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?>
            <div role="alert" class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-800">
                <?= user_escape($errorMessage) ?>
            </div>
        <?php endif; ?>

        <div dir="ltr" class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
            <section dir="rtl" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-1">
                <h3 class="text-lg font-bold text-slate-800"><?= $editUser ? 'نوێکردنەوەی بەکارهێنەر' : 'زیادکردنی بەکارهێنەر' ?></h3>
                <p class="mt-1 text-sm text-slate-500">
                    <?= $editUser ? 'وشەی نهێنی بەتاڵ بهێڵەوە ئەگەر ناتەوێت بیگۆڕیت.' : 'وشەی نهێنی بە شێوەی hash پارێزراو دەبێت.' ?>
                </p>

                <form method="post" action="users.php" class="mt-5 space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= user_escape($_SESSION['users_csrf_token']) ?>">
                    <input type="hidden" name="action" value="<?= $editUser ? 'update' : 'add' ?>">
                    <?php if ($editUser): ?>
                        <input type="hidden" name="user_id" value="<?= (int) $editUser['user_id'] ?>">
                    <?php endif; ?>
                    <div>
                        <label for="full_name" class="mb-2 block text-sm font-semibold text-slate-700">ناوی تەواو</label>
                        <input id="full_name" name="full_name" type="text" required maxlength="255" value="<?= user_escape($fullName) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                    </div>
                    <div>
                        <label for="username" class="mb-2 block text-sm font-semibold text-slate-700">ناوی بەکارهێنەر</label>
                        <input id="username" name="username" type="text" required maxlength="100" autocomplete="username" value="<?= user_escape($username) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                    </div>
                    <div>
                        <label for="password" class="mb-2 block text-sm font-semibold text-slate-700"><?= $editUser ? 'وشەی نهێنی نوێ (ئارەزوومەندانە)' : 'وشەی نهێنی' ?></label>
                        <input id="password" name="password" type="password" <?= $editUser ? '' : 'required' ?> minlength="8" autocomplete="new-password" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                        <p class="mt-1 text-xs text-slate-500">ئەگەر نوێ دەکرێتەوە، لانیکەم ٨ پیت.</p>
                    </div>
                    <div>
                        <label for="phone" class="mb-2 block text-sm font-semibold text-slate-700">ژمارەی مۆبایل</label>
                        <input id="phone" name="phone" type="tel" maxlength="50" value="<?= user_escape($phone) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                    </div>
                    <div>
                        <label for="role" class="mb-2 block text-sm font-semibold text-slate-700">ڕۆڵ</label>
                        <select id="role" name="role" required class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                            <option value="cashier" <?= $role === 'cashier' ? 'selected' : '' ?>>کاشێر</option>
                            <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>بەڕێوەبەر</option>
                        </select>
                    </div>
                    <button type="submit" class="w-full rounded-xl bg-amber-500 px-5 py-3 font-bold text-slate-950 hover:bg-amber-400">
                        <?= $editUser ? 'پاشەکەوتکردنی گۆڕانکاری' : 'تۆمارکردنی بەکارهێنەر' ?>
                    </button>
                    <?php if ($editUser): ?>
                        <a href="users.php" class="block rounded-xl border border-slate-300 px-5 py-3 text-center font-semibold text-slate-700 hover:bg-slate-50">
                            پاشگەزبوونەوە
                        </a>
                    <?php endif; ?>
                </form>
            </section>

            <section dir="rtl" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm lg:col-span-2">
                <div class="border-b border-slate-100 px-6 py-5">
                    <h3 class="text-lg font-bold text-slate-800">لیستی بەکارهێنەران</h3>
                    <p class="mt-1 text-sm text-slate-500"><?= number_format(count($users)) ?> بەکارهێنەر</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[850px] text-right text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th scope="col" class="px-5 py-4 font-semibold">ناو</th>
                                <th scope="col" class="px-5 py-4 font-semibold">ناوی بەکارهێنەر</th>
                                <th scope="col" class="px-5 py-4 font-semibold">ڕۆڵ</th>
                                <th scope="col" class="px-5 py-4 font-semibold">مۆبایل</th>
                                <th scope="col" class="px-5 py-4 font-semibold">بەرواری دروستکردن</th>
                                <th scope="col" class="px-5 py-4 font-semibold">نوێکردنەوە</th>
                                <th scope="col" class="px-5 py-4 font-semibold">سڕینەوە</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <?php if ($users): ?>
                                <?php foreach ($users as $user): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-5 py-4 font-semibold text-slate-800"><?= user_escape((string) $user['full_name']) ?></td>
                                        <td class="px-5 py-4"><?= user_escape((string) $user['username']) ?></td>
                                        <td class="px-5 py-4">
                                            <span class="rounded-full px-3 py-1 text-xs font-semibold <?= $user['role'] === 'admin' ? 'bg-violet-50 text-violet-700' : 'bg-sky-50 text-sky-700' ?>">
                                                <?= $user['role'] === 'admin' ? 'بەڕێوەبەر' : 'کاشێر' ?>
                                            </span>
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-4"><?= user_escape((string) ($user['phone'] ?: '—')) ?></td>
                                        <td class="whitespace-nowrap px-5 py-4 text-slate-500"><?= user_escape((string) $user['created_at']) ?></td>
                                        <td class="whitespace-nowrap px-5 py-4">
                                            <a href="users.php?edit=<?= (int) $user['user_id'] ?>" class="inline-flex rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-300">
                                                نوێکردنەوە
                                            </a>
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-4">
                                            <?php if ((int) $user['user_id'] !== (int) $_SESSION['user_id']): ?>
                                                <form method="post" action="users.php" onsubmit="return confirm('دڵنیایت لە سڕینەوەی ئەم بەکارهێنەرە؟');">
                                                    <input type="hidden" name="csrf_token" value="<?= user_escape($_SESSION['users_csrf_token']) ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>">
                                                    <button type="submit" class="inline-flex rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-300">
                                                        سڕینەوە
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-xs text-slate-400">هەژماری ئێستا</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="px-6 py-12 text-center text-slate-500">هێشتا هیچ بەکارهێنەرێک تۆمار نەکراوە.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</main>
</div>
</div>
</body>
</html>
