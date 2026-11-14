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

function activity_log_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$page_title = 'مێژووی چالاکییەکان';
$pageInput = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$currentPage = $pageInput === false || $pageInput === null ? 1 : max(1, $pageInput);
$pageSize = 50;
$offset = ($currentPage - 1) * $pageSize;
$logs = [];
$totalLogs = 0;
$totalPages = 1;
$errorMessage = '';

try {
    $totalLogs = (int) $pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
    $totalPages = max(1, (int) ceil($totalLogs / $pageSize));
    if ($currentPage > $totalPages) {
        $currentPage = $totalPages;
        $offset = ($currentPage - 1) * $pageSize;
    }

    $stmt = $pdo->prepare(
        'SELECT
            l.log_id,
            l.action_type,
            l.description,
            l.ip_address,
            l.created_at,
            u.full_name,
            u.username
         FROM activity_logs AS l
         LEFT JOIN users AS u ON u.user_id = l.user_id
         ORDER BY l.created_at DESC, l.log_id DESC
         LIMIT :page_size OFFSET :offset'
    );
    $stmt->bindValue(':page_size', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log('Loading activity logs failed: ' . $exception->getMessage());
    http_response_code(500);
    $errorMessage = 'هەڵەیەک لە بارکردنی مێژووی چالاکییەکاندا ڕوویدا.';
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
    <div class="mx-auto max-w-7xl">
        <div class="mb-6 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-2xl font-bold text-slate-800">مێژووی چالاکییەکان</h2>
                <p class="mt-1 text-sm text-slate-500">چوونەژوورەوە، سڕینەوە و گۆڕانکارییە تۆمارکراوەکانی بەکارهێنەران.</p>
            </div>
            <p class="text-sm text-slate-500">کۆی تۆمارەکان: <span class="font-bold text-slate-800"><?= number_format($totalLogs) ?></span></p>
        </div>

        <?php if ($errorMessage !== ''): ?>
            <div role="alert" class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-800">
                <?= activity_log_escape($errorMessage) ?>
            </div>
        <?php endif; ?>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-right text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th scope="col" class="px-5 py-3 font-semibold">ژمارە</th>
                            <th scope="col" class="px-5 py-3 font-semibold">بەروار</th>
                            <th scope="col" class="px-5 py-3 font-semibold">بەکارهێنەر</th>
                            <th scope="col" class="px-5 py-3 font-semibold">جۆری چالاکی</th>
                            <th scope="col" class="px-5 py-3 font-semibold">وردەکاری</th>
                            <th scope="col" class="px-5 py-3 font-semibold">ناونیشانی IP</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($logs as $log): ?>
                            <tr class="align-top hover:bg-slate-50/70">
                                <td class="whitespace-nowrap px-5 py-4 font-semibold text-slate-700">#<?= (int) $log['log_id'] ?></td>
                                <td class="whitespace-nowrap px-5 py-4 text-slate-600"><?= activity_log_escape((string) $log['created_at']) ?></td>
                                <td class="px-5 py-4 font-medium text-slate-700">
                                    <?= activity_log_escape((string) ($log['full_name'] ?? $log['username'] ?? 'بەکارهێنەری سڕاوە')) ?>
                                    <?php if (!empty($log['username'])): ?>
                                        <span class="mt-1 block text-xs text-slate-400"><?= activity_log_escape((string) $log['username']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="whitespace-nowrap px-5 py-4"><span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700"><?= activity_log_escape((string) $log['action_type']) ?></span></td>
                                <td class="max-w-xl whitespace-pre-wrap break-words px-5 py-4 text-slate-600"><?= activity_log_escape((string) $log['description']) ?></td>
                                <td class="whitespace-nowrap px-5 py-4 font-mono text-xs text-slate-500"><?= activity_log_escape((string) ($log['ip_address'] ?? '—') ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$logs): ?>
                            <tr><td colspan="6" class="px-5 py-12 text-center text-slate-500">هێشتا هیچ چالاکییەک تۆمار نەکراوە.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <nav aria-label="پەڕەکانی مێژووی چالاکی" class="flex items-center justify-between border-t border-slate-100 px-5 py-4">
                    <?php if ($currentPage > 1): ?>
                        <a href="?page=<?= $currentPage - 1 ?>" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">پێشوو</a>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>
                    <span class="text-sm text-slate-500">پەڕەی <?= number_format($currentPage) ?> لە <?= number_format($totalPages) ?></span>
                    <?php if ($currentPage < $totalPages): ?>
                        <a href="?page=<?= $currentPage + 1 ?>" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">دواتر</a>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>
