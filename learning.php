<?php
session_start();

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';

function learning_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (empty($_SESSION['learning_csrf_token'])) {
    $_SESSION['learning_csrf_token'] = bin2hex(random_bytes(32));
}

$errorMessage = '';
$successMessage = $_SESSION['learning_success'] ?? '';
unset($_SESSION['learning_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';

    if (!is_string($token) || !hash_equals($_SESSION['learning_csrf_token'], $token)) {
        $errorMessage = 'داواکارییەکە پشتڕاست نەکرایەوە. پەڕەکە نوێ بکەرەوە.';
    } elseif ($action === 'add') {
        $titleInput = $_POST['title'] ?? '';
        $title = is_string($titleInput) ? trim($titleInput) : '';
        $upload = $_FILES['media_file'] ?? null;

        if ($title === '' || mb_strlen($title, 'UTF-8') > 200) {
            $errorMessage = 'ناونیشان پێویستە و نابێت لە ٢٠٠ پیت زیاتر بێت.';
        } elseif (
            !is_array($upload)
            || !isset($upload['error'], $upload['size'], $upload['tmp_name'])
            || !is_int($upload['error'])
            || !is_int($upload['size'])
            || !is_string($upload['tmp_name'])
        ) {
            $errorMessage = 'فایلی وێنە یان ڤیدیۆ دروست نییە.';
        } elseif ($upload['error'] !== UPLOAD_ERR_OK) {
            $errorMessage = $upload['error'] === UPLOAD_ERR_NO_FILE
                ? 'تکایە وێنە یان ڤیدیۆیەک هەڵبژێرە.'
                : 'بارکردنی فایل سەرکەوتوو نەبوو؛ قەبارەی فایلەکە و ڕێکخستنی سێرڤەر بپشکنە.';
        } elseif ($upload['size'] < 1 || $upload['size'] > 100 * 1024 * 1024 || !is_uploaded_file($upload['tmp_name'])) {
            $errorMessage = 'قەبارەی فایلەکە دروست نییە یان لە ١٠٠ مێگابایت زیاترە.';
        } else {
            $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = $fileInfo !== false ? finfo_file($fileInfo, $upload['tmp_name']) : false;
            if ($fileInfo !== false) {
                finfo_close($fileInfo);
            }

            $allowedTypes = [
                'image/jpeg' => ['extension' => 'jpg', 'type' => 'image'],
                'image/png' => ['extension' => 'png', 'type' => 'image'],
                'image/webp' => ['extension' => 'webp', 'type' => 'image'],
                'video/mp4' => ['extension' => 'mp4', 'type' => 'video'],
                'video/webm' => ['extension' => 'webm', 'type' => 'video'],
                'video/ogg' => ['extension' => 'ogv', 'type' => 'video'],
            ];
            $imageInfo = $mimeType === 'image/jpeg' || $mimeType === 'image/png' || $mimeType === 'image/webp'
                ? getimagesize($upload['tmp_name'])
                : true;

            if (!is_string($mimeType) || !isset($allowedTypes[$mimeType]) || $imageInfo === false) {
                $errorMessage = 'تەنها وێنەی JPG، PNG، WebP یان ڤیدیۆی MP4، WebM، OGG ڕێگەپێدراوە.';
            } else {
                $mediaDirectory = __DIR__ . '/uploads/learning';
                $fileName = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType]['extension'];
                $filePath = $mediaDirectory . DIRECTORY_SEPARATOR . $fileName;
                $storedFile = false;

                try {
                    if (!is_dir($mediaDirectory) && !mkdir($mediaDirectory, 0755, true) && !is_dir($mediaDirectory)) {
                        throw new RuntimeException('Unable to create the learning media directory.');
                    }
                    if (!move_uploaded_file($upload['tmp_name'], $filePath)) {
                        throw new RuntimeException('Unable to save the uploaded learning media.');
                    }
                    $storedFile = true;

                    $stmt = $pdo->prepare(
                        'INSERT INTO learning_media (title, file_name, media_type, mime_type)
                         VALUES (:title, :file_name, :media_type, :mime_type)'
                    );
                    $stmt->execute([
                        'title' => $title,
                        'file_name' => $fileName,
                        'media_type' => $allowedTypes[$mimeType]['type'],
                        'mime_type' => $mimeType,
                    ]);

                    $_SESSION['learning_success'] = 'میدیاکە بە سەرکەوتوویی زیادکرا.';
                    header('Location: learning.php');
                    exit;
                } catch (PDOException $exception) {
                    if ($storedFile && is_file($filePath) && !unlink($filePath)) {
                        error_log('Removing unregistered learning media failed: ' . $filePath);
                    }
                    error_log('Saving learning media to database failed: ' . $exception->getMessage());
                    $errorMessage = 'هەڵەیەک لە تۆمارکردنی میدیا لە داتابەیسدا ڕوویدا.';
                } catch (RuntimeException $exception) {
                    if ($storedFile && is_file($filePath) && !unlink($filePath)) {
                        error_log('Removing unregistered learning media failed: ' . $filePath);
                    }
                    error_log('Saving learning media file failed: ' . $exception->getMessage());
                    $errorMessage = 'پاشەکەوتکردنی فایل سەرکەوتوو نەبوو. مۆڵەتی نووسین لە فۆڵدەری uploads/learning بپشکنە.';
                }
            }
        }
    } elseif ($action === 'delete') {
        $mediaId = filter_var($_POST['media_id'] ?? null, FILTER_VALIDATE_INT);
        if ($mediaId === false || $mediaId === null || $mediaId < 1) {
            $errorMessage = 'ژمارەی میدیا دروست نییە.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT file_name FROM learning_media WHERE media_id = :media_id');
                $stmt->execute(['media_id' => $mediaId]);
                $fileName = $stmt->fetchColumn();

                if (!is_string($fileName)) {
                    $errorMessage = 'میدیای داواکراو نەدۆزرایەوە.';
                } elseif (!preg_match('/\A[a-f0-9]{32}\.(?:jpg|png|webp|mp4|webm|ogv)\z/D', $fileName)) {
                    error_log('Unexpected learning media file name in database for ID ' . $mediaId);
                    $errorMessage = 'ناوی فایلی میدیا دروست نییە؛ سڕینەوەکە ئەنجام نەدرا.';
                } else {
                    $filePath = __DIR__ . '/uploads/learning/' . $fileName;
                    if (is_file($filePath) && !unlink($filePath)) {
                        error_log('Deleting learning media file failed: ' . $filePath);
                        $errorMessage = 'سڕینەوەی فایل سەرکەوتوو نەبوو.';
                    } else {
                        $deleteStmt = $pdo->prepare('DELETE FROM learning_media WHERE media_id = :media_id');
                        $deleteStmt->execute(['media_id' => $mediaId]);
                        $_SESSION['learning_success'] = 'میدیاکە بە سەرکەوتوویی سڕایەوە.';
                        header('Location: learning.php');
                        exit;
                    }
                }
            } catch (PDOException $exception) {
                error_log('Deleting learning media failed: ' . $exception->getMessage());
                $errorMessage = 'هەڵەیەک لە سڕینەوەی میدیا ڕوویدا.';
            }
        }
    } else {
        $errorMessage = 'جۆری داواکارییەکە دروست نییە.';
    }
}

$mediaItems = [];
try {
    $mediaItems = $pdo->query(
        'SELECT media_id, title, file_name, media_type, mime_type, created_at
         FROM learning_media
         ORDER BY created_at DESC, media_id DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log('Loading learning media failed: ' . $exception->getMessage());
    $errorMessage = 'نەتوانرا میدیاکان باربکرێن. دڵنیابە مایگرەیشنی learning_media جێبەجێ کراوە.';
}

$page_title = 'فێربوون';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
        <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
            <div class="mx-auto max-w-7xl">
                <div class="mb-6">
                    <h2 class="text-2xl font-bold text-slate-800">فێربوون</h2>
                    <p class="mt-1 text-sm text-slate-500">وێنە و ڤیدیۆی فێرکاری بار بکە و لێرە بیبینە.</p>
                </div>

                <?php if ($errorMessage !== ''): ?>
                    <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
                        <?= learning_escape($errorMessage) ?>
                    </div>
                <?php elseif ($successMessage !== ''): ?>
                    <div class="mb-5 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700" role="status">
                        <?= learning_escape($successMessage) ?>
                    </div>
                <?php endif; ?>

                <section class="mb-8 rounded-xl bg-white p-6 shadow-sm" aria-labelledby="learning-upload-title">
                    <h3 id="learning-upload-title" class="mb-5 text-lg font-semibold text-slate-800">زیادکردنی وێنە یان ڤیدیۆ</h3>
                    <form method="POST" action="learning.php" enctype="multipart/form-data" class="grid grid-cols-1 items-end gap-4 md:grid-cols-3">
                        <input type="hidden" name="csrf_token" value="<?= learning_escape($_SESSION['learning_csrf_token']) ?>">
                        <input type="hidden" name="action" value="add">
                        <div>
                            <label for="learning-title" class="mb-2 block text-sm font-medium text-slate-700">ناونیشان</label>
                            <input id="learning-title" name="title" type="text" maxlength="200" required class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-slate-800 outline-none focus:border-cyan-500 focus:ring-2 focus:ring-cyan-100">
                        </div>
                        <div>
                            <label for="learning-file" class="mb-2 block text-sm font-medium text-slate-700">فایل (تا ١٠٠ مێگابایت)</label>
                            <input id="learning-file" name="media_file" type="file" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,video/ogg" required class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700">
                        </div>
                        <button type="submit" class="rounded-lg bg-cyan-600 px-4 py-2.5 font-semibold text-white transition hover:bg-cyan-700 focus:outline-none focus:ring-2 focus:ring-cyan-300">
                            بارکردنی میدیا
                        </button>
                    </form>
                </section>

                <section aria-labelledby="learning-items-title">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 id="learning-items-title" class="text-lg font-semibold text-slate-800">میدیا فێرکارییەکان</h3>
                        <span class="rounded-full bg-cyan-100 px-3 py-1 text-sm font-medium text-cyan-800"><?= count($mediaItems) ?> فایل</span>
                    </div>
                    <?php if ($mediaItems): ?>
                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            <?php foreach ($mediaItems as $item): ?>
                                <?php
                                $fileName = (string) $item['file_name'];
                                $safeFileName = preg_match('/\A[a-f0-9]{32}\.(?:jpg|png|webp|mp4|webm|ogv)\z/D', $fileName)
                                    ? $fileName
                                    : '';
                                $mediaUrl = 'uploads/learning/' . rawurlencode($safeFileName);
                                ?>
                                <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                                    <?php if ($safeFileName === ''): ?>
                                        <div class="flex h-56 items-center justify-center bg-slate-100 text-sm text-red-600">ناوی فایل دروست نییە.</div>
                                    <?php elseif ($item['media_type'] === 'image'): ?>
                                        <img src="<?= learning_escape($mediaUrl) ?>" alt="<?= learning_escape((string) $item['title']) ?>" class="h-56 w-full bg-slate-100 object-contain">
                                    <?php else: ?>
                                        <video controls preload="metadata" class="h-56 w-full bg-black">
                                            <source src="<?= learning_escape($mediaUrl) ?>" type="<?= learning_escape((string) $item['mime_type']) ?>">
                                            وێبگەڕەکەت پشتگیری لەم جۆرە ڤیدیۆیە ناکات.
                                        </video>
                                    <?php endif; ?>
                                    <div class="flex items-start justify-between gap-3 p-4">
                                        <div class="min-w-0">
                                            <h4 class="break-words font-semibold text-slate-800"><?= learning_escape((string) $item['title']) ?></h4>
                                            <p class="mt-1 text-xs text-slate-500"><?= $item['media_type'] === 'image' ? 'وێنە' : 'ڤیدیۆ' ?> · <?= learning_escape((string) $item['created_at']) ?></p>
                                        </div>
                                        <form method="POST" action="learning.php" onsubmit="return confirm('دڵنیایت لە سڕینەوەی ئەم فایلە؟');">
                                            <input type="hidden" name="csrf_token" value="<?= learning_escape($_SESSION['learning_csrf_token']) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="media_id" value="<?= (int) $item['media_id'] ?>">
                                            <button type="submit" class="shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium text-red-600 transition hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-200">سڕینەوە</button>
                                        </form>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="rounded-xl border border-slate-200 bg-white px-4 py-10 text-center text-slate-500">
                            هێشتا هیچ وێنە یان ڤیدیۆیەک زیاد نەکراوە.
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </main>
    </div>
</div>
</body>
</html>
