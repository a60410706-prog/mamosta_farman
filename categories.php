<?php
session_start();

require_once __DIR__ . '/includes/db.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

if (!$pdo instanceof PDO) {
    throw new RuntimeException('پەیوەندی PDO لە includes/db.php بەردەست نییە.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$error = '';
$categoryName = '';
$editCategory = null;
$flash = $_SESSION['category_flash'] ?? '';
unset($_SESSION['category_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';

    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'داواکارییەکە پشتڕاست نەکرایەوە. تکایە پەڕەکە نوێ بکەرەوە.';
    } elseif ($action === 'add' || $action === 'update') {
        $submittedName = $_POST['category_name'] ?? '';
        $categoryName = is_string($submittedName) ? trim($submittedName) : '';
        $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);

        if ($categoryName === '') {
            $error = 'تکایە ناوی پۆلێن بنووسە.';
        } elseif ($action === 'update' && (!$categoryId || $categoryId < 1)) {
            $error = 'پۆلێنی داواکراو دروست نییە.';
        } else {
            try {
                if ($action === 'add') {
                    $stmt = $pdo->prepare(
                        'INSERT INTO categories (category_name) VALUES (:category_name)'
                    );
                    $stmt->execute(['category_name' => $categoryName]);
                    $_SESSION['category_flash'] = 'پۆلێنەکە بە سەرکەوتوویی زیاد کرا.';
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE categories
                         SET category_name = :category_name
                         WHERE category_id = :category_id'
                    );
                    $stmt->execute([
                        'category_name' => $categoryName,
                        'category_id' => $categoryId,
                    ]);
                    $_SESSION['category_flash'] = $stmt->rowCount() > 0
                        ? 'پۆلێنەکە نوێ کرایەوە.'
                        : 'هیچ گۆڕانکارییەک لە پۆلێنەکەدا نەکرا.';
                }

                header('Location: categories.php');
                exit;
            } catch (PDOException $exception) {
                error_log('Category save error: ' . $exception->getMessage());
                $error = 'هەڵەیەک لە پاشەکەوتکردنی پۆلێن ڕوویدا.';
            }
        }
    } elseif ($action === 'delete') {
        $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);

        if (!$categoryId || $categoryId < 1) {
            $error = 'پۆلێنی داواکراو دروست نییە.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'DELETE FROM categories WHERE category_id = :category_id'
                );
                $stmt->execute(['category_id' => $categoryId]);
                $_SESSION['category_flash'] = $stmt->rowCount() > 0
                    ? 'پۆلێنەکە سڕایەوە.'
                    : 'پۆلێنی داواکراو نەدۆزرایەوە.';
                header('Location: categories.php');
                exit;
            } catch (PDOException $exception) {
                error_log('Category delete error: ' . $exception->getMessage());
                $error = 'هەڵەیەک لە سڕینەوەی پۆلێن ڕوویدا.';
            }
        }
    } else {
        $error = 'جۆری داواکارییەکە دروست نییە.';
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['edit'])) {
    $categoryId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);

    if ($categoryId && $categoryId > 0) {
        try {
            $stmt = $pdo->prepare(
                'SELECT category_id, category_name
                 FROM categories
                 WHERE category_id = :category_id'
            );
            $stmt->execute(['category_id' => $categoryId]);
            $editCategory = $stmt->fetch();
        } catch (PDOException $exception) {
            error_log('Category lookup error: ' . $exception->getMessage());
            http_response_code(500);
            exit('هەڵەیەک لە بارکردنی زانیاریی پۆلێن ڕوویدا.');
        }
    }
}

try {
    $categories = $pdo->query(
        'SELECT category_id, category_name
         FROM categories
         ORDER BY category_id DESC'
    )->fetchAll();
} catch (PDOException $exception) {
    error_log('Category list error: ' . $exception->getMessage());
    http_response_code(500);
    exit('هەڵەیەک لە بارکردنی پۆلێنەکان ڕوویدا.');
}

if ($editCategory && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $categoryName = $editCategory['category_name'];
}
?>
<!DOCTYPE html>
<html lang="ckb" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>بەڕێوەبردنی پۆلێنەکان</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Vazirmatn', 'Tahoma', 'sans-serif'] }
                }
            }
        };
    </script>
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-800">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-5 sm:px-6 lg:px-8">
            <div>
                <p class="text-sm font-medium text-indigo-600">بەڕێوەبەرایەتی</p>
                <h1 class="mt-1 text-xl font-bold text-slate-900">بەڕێوەبردنی پۆلێنەکان</h1>
            </div>
            <a href="dashboard.php" class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100">
                گەڕانەوە بۆ داشبۆرد
            </a>
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <?php if ($flash !== ''): ?>
            <div role="status" class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">
                <?= escapeHtml($flash) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div role="alert" class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-800">
                <?= escapeHtml($error) ?>
            </div>
        <?php endif; ?>

        <div dir="ltr" class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <section dir="rtl" class="h-fit rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-1">
                <h2 class="text-lg font-bold text-slate-900">
                    <?= $editCategory ? 'دەستکاری پۆلێن' : 'زیادکردنی پۆلێنی نوێ' ?>
                </h2>
                <p class="mt-1 text-sm text-slate-500">ناوی پۆلێن بنووسە و پاشەکەوتی بکە.</p>

                <form method="post" action="categories.php" class="mt-6 space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="<?= $editCategory ? 'update' : 'add' ?>">
                    <?php if ($editCategory): ?>
                        <input type="hidden" name="category_id" value="<?= (int) $editCategory['category_id'] ?>">
                    <?php endif; ?>

                    <div>
                        <label for="category_name" class="mb-2 block text-sm font-semibold text-slate-700">ناوی پۆلێن</label>
                        <input
                            id="category_name"
                            name="category_name"
                            type="text"
                            required
                            maxlength="255"
                            value="<?= escapeHtml($categoryName) ?>"
                            placeholder="بۆ نموونە: خواردنەوە"
                            class="w-full rounded-xl border border-slate-300 px-4 py-3 outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100"
                        >
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-200">
                            <?= $editCategory ? 'پاشەکەوتکردنی گۆڕانکاری' : 'زیادکردنی پۆلێن' ?>
                        </button>
                        <?php if ($editCategory): ?>
                            <a href="categories.php" class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                پاشگەزبوونەوە
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>

            <section dir="rtl" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm lg:col-span-2">
                <div class="flex items-center justify-between border-b border-slate-200 px-6 py-5">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">پۆلێنە تۆمارکراوەکان</h2>
                        <p class="mt-1 text-sm text-slate-500">ژمارەی پۆلێنەکان: <?= count($categories) ?></p>
                    </div>
                </div>

                <?php if ($categories): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[36rem] text-right text-sm">
                            <thead class="bg-slate-50 text-slate-600">
                                <tr>
                                    <th scope="col" class="px-6 py-4 font-semibold">#</th>
                                    <th scope="col" class="px-6 py-4 font-semibold">ناوی پۆلێن</th>
                                    <th scope="col" class="px-6 py-4 font-semibold">کردارەکان</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($categories as $category): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="whitespace-nowrap px-6 py-4 text-slate-500"><?= (int) $category['category_id'] ?></td>
                                        <td class="px-6 py-4 font-medium text-slate-800"><?= escapeHtml($category['category_name']) ?></td>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center gap-2">
                                                <a href="categories.php?edit=<?= (int) $category['category_id'] ?>" class="rounded-lg border border-indigo-200 px-3 py-2 text-xs font-semibold text-indigo-700 hover:bg-indigo-50">
                                                    دەستکاری
                                                </a>
                                                <form method="post" action="categories.php" onsubmit="return confirm('دڵنیایت لە سڕینەوەی ئەم پۆلێنە؟');">
                                                    <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="category_id" value="<?= (int) $category['category_id'] ?>">
                                                    <button type="submit" class="rounded-lg border border-red-200 px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-50">
                                                        سڕینەوە
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="px-6 py-14 text-center">
                        <p class="font-semibold text-slate-700">هێشتا هیچ پۆلێنێک تۆمار نەکراوە.</p>
                        <p class="mt-1 text-sm text-slate-500">لە فۆڕمی لای چەپەوە یەکەم پۆلێن زیاد بکە.</p>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</body>
</html>
