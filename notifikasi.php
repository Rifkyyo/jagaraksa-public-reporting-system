<?php
require_once __DIR__ . '/config.php';
$user   = require_login();
$active = 'notifikasi';
$pageTitle = 'Notifikasi';

$perPage = 30;
$total   = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ?');
$total->execute([$user['id']]);
$total = (int)$total->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page  = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$off   = ($page - 1) * $perPage;
$st = db()->prepare("SELECT n.*, r.ticket_code FROM notifications n LEFT JOIN reports r ON r.id = n.report_id
                     WHERE n.user_id = ? ORDER BY n.id DESC LIMIT $perPage OFFSET $off");
$st->execute([$user['id']]);
$rows = $st->fetchAll();
include __DIR__ . '/includes/app_top.php';
?>
<div class="flex flex-wrap items-center justify-between gap-space-sm mb-space-lg">
  <div>
    <h1 class="font-headline-xl text-headline-xl tracking-tight">Notifikasi</h1>
    <p class="font-body-md text-body-md text-on-surface-variant mt-space-2xs"><?= $total ?> pemberitahuan<?= $unread ? ', ' . $unread . ' belum dibaca' : '' ?>.</p>
  </div>
  <?php if ($unread): ?>
  <form method="post" action="api.php?a=read_notif"><?= csrf_field() ?><input type="hidden" name="to" value="notifikasi.php">
    <button class="px-space-md py-space-xs rounded-lg bg-surface-container font-label-md text-label-md hover:bg-surface-container-high">Tandai semua dibaca</button></form>
  <?php endif; ?>
</div>
<div class="bg-surface-container-lowest rounded-xl shadow-sm overflow-hidden">
  <?php if (!$rows): ?><p class="p-space-xl text-center text-on-surface-variant">Belum ada notifikasi.</p><?php endif; ?>
  <?php foreach ($rows as $n):
    $to = $n['report_id'] ? (is_staff($user) ? 'kelola-laporan.php?id=' . (int)$n['report_id'] : 'laporan-saya.php?tiket=' . urlencode((string)$n['ticket_code'])) : 'dashboard.php'; ?>
    <form method="post" action="api.php?a=read_notif"><?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><input type="hidden" name="to" value="<?= e($to) ?>">
      <button type="submit" class="w-full text-left flex items-start gap-space-sm p-space-md border-b border-surface-container hover:bg-surface-container-low <?= $n['is_read'] ? '' : 'bg-primary-fixed/30' ?>">
        <span class="material-symbols-outlined text-primary mt-0.5"><?= $n['is_read'] ? 'drafts' : 'mark_email_unread' ?></span>
        <span class="flex-1 min-w-0">
          <span class="block font-body-md text-body-md"><?= e($n['message']) ?></span>
          <span class="block font-label-sm text-label-sm text-on-surface-variant"><?= e(format_tanggal($n['created_at'], true)) ?> &bull; <?= e(time_ago($n['created_at'])) ?></span>
        </span>
      </button>
    </form>
  <?php endforeach; ?>
</div>
<?php if ($pages > 1): ?>
<div class="flex items-center justify-between mt-space-md font-body-sm text-body-sm text-on-surface-variant">
  <span>Halaman <?= $page ?> dari <?= $pages ?></span>
  <span class="flex gap-1">
    <?php if ($page > 1): ?><a class="px-3 py-1 rounded hover:bg-surface-container" href="?page=<?= $page - 1 ?>">&lsaquo;</a><?php endif; ?>
    <?php if ($page < $pages): ?><a class="px-3 py-1 rounded hover:bg-surface-container" href="?page=<?= $page + 1 ?>">&rsaquo;</a><?php endif; ?>
  </span>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/app_bottom.php'; ?>
