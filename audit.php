<?php
require_once __DIR__ . '/config.php';
$user      = require_role('admin');
$active    = 'audit';
$pageTitle = 'Audit & Integritas';

$fAct = trim((string)($_GET['aksi'] ?? ''));

/* ------------------------ Ekspor CSV jejak audit ------------------------ */
if (($_GET['export'] ?? '') === 'csv') {
    audit('ekspor_audit', 'audit_log', null, 'Ekspor jejak audit');
    $rows = db()->query('SELECT a.*, u.name uname FROM audit_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id')->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="jagaraksa-audit-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['id', 'waktu', 'pelaksana', 'aksi', 'entitas', 'id_entitas', 'detail', 'ip', 'hash_sebelumnya', 'hash']);
    foreach ($rows as $x) {
        fputcsv($out, [$x['id'], $x['created_at'], csv_safe($x['uname'] ?? 'Sistem'), $x['action'], $x['entity'], $x['entity_id'],
                       csv_safe($x['detail']), $x['ip'], $x['prev_hash'], $x['entry_hash']]);
    }
    fclose($out);
    exit;
}

$rep = verify_report_chain();
$aud = verify_audit_chain();

$trg = [];
try {
    $trg = db()->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
                        WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE IN ('report_logs','audit_log')")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $ex) {
}
$trgOk = count($trg) >= 4;

$archived  = (int)db()->query('SELECT COUNT(*) FROM reports WHERE deleted_at IS NOT NULL')->fetchColumn();
$totalLogs = (int)db()->query('SELECT COUNT(*) FROM report_logs')->fetchColumn();
$actions   = [];
try {
    $actions = db()->query('SELECT DISTINCT action FROM audit_log ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $ex) {
}

$sql = 'SELECT a.*, u.name uname FROM audit_log a LEFT JOIN users u ON u.id = a.user_id' . ($fAct !== '' ? ' WHERE a.action = ?' : '') . ' ORDER BY a.id DESC LIMIT 100';
$st  = db()->prepare($sql);
try {
    $st->execute($fAct !== '' ? [$fAct] : []);
    $logs = $st->fetchAll();
} catch (Throwable $ex) {
    $logs = [];
}

$repOk = !$rep['broken'] && empty($rep['error']);
$audOk = !$aud['broken'] && empty($aud['error']);
$card  = 'bg-surface-container-lowest rounded-xl shadow-sm p-space-lg';

include __DIR__ . '/includes/app_top.php';
?>
<div class="flex flex-col md:flex-row md:items-end justify-between gap-space-md mb-space-lg">
  <div class="max-w-2xl">
    <h1 class="font-headline-xl text-headline-xl tracking-tight">Audit &amp; Integritas Data</h1>
    <p class="font-body-md text-body-md text-on-surface-variant mt-space-2xs">Setiap aktivitas laporan dan aksi administratif dicatat dalam rantai sidik jari SHA-256: mengubah atau menghapus satu catatan lama akan memutus rantai dan terdeteksi di sini.</p>
  </div>
  <a href="audit.php?export=csv" class="inline-flex items-center gap-space-xs px-space-md py-space-xs rounded bg-surface-container-high font-label-md text-label-md hover:bg-surface-container-highest self-start"><span class="material-symbols-outlined text-lg">download</span>Ekspor jejak audit (CSV)</a>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-gutter-sm mb-space-lg">
  <div class="<?= $card ?> border-l-4 <?= $repOk ? 'border-emerald-600' : 'border-red-600' ?>">
    <div class="flex items-center gap-2 font-label-md text-label-md <?= $repOk ? 'text-emerald-700' : 'text-error' ?>"><span class="material-symbols-outlined"><?= $repOk ? 'verified_user' : 'gpp_bad' ?></span>Riwayat laporan</div>
    <div class="font-headline-md text-headline-md mt-space-2xs"><?= $repOk ? 'Utuh' : count($rep['broken']) . ' catatan janggal' ?></div>
    <p class="font-body-sm text-body-sm text-on-surface-variant"><?= (int)$rep['checked'] ?> catatan berantai diperiksa<?= $rep['legacy'] ? ', ' . (int)$rep['legacy'] . ' catatan lama (sebelum fitur ini) tidak berantai' : '' ?>. Total <?= $totalLogs ?> catatan.</p>
  </div>
  <div class="<?= $card ?> border-l-4 <?= $audOk ? 'border-emerald-600' : 'border-red-600' ?>">
    <div class="flex items-center gap-2 font-label-md text-label-md <?= $audOk ? 'text-emerald-700' : 'text-error' ?>"><span class="material-symbols-outlined"><?= $audOk ? 'verified_user' : 'gpp_bad' ?></span>Jejak aksi admin</div>
    <div class="font-headline-md text-headline-md mt-space-2xs"><?= $audOk ? 'Utuh' : count($aud['broken']) . ' catatan janggal' ?></div>
    <p class="font-body-sm text-body-sm text-on-surface-variant"><?= (int)$aud['checked'] ?> catatan diperiksa.</p>
  </div>
  <div class="<?= $card ?> border-l-4 <?= $trgOk ? 'border-emerald-600' : 'border-amber-500' ?>">
    <div class="flex items-center gap-2 font-label-md text-label-md <?= $trgOk ? 'text-emerald-700' : 'text-amber-800' ?>"><span class="material-symbols-outlined"><?= $trgOk ? 'lock' : 'lock_open' ?></span>Proteksi database</div>
    <div class="font-headline-md text-headline-md mt-space-2xs"><?= $trgOk ? 'Append-only aktif' : 'Belum aktif' ?></div>
    <p class="font-body-sm text-body-sm text-on-surface-variant"><?= $trgOk ? 'Riwayat tidak dapat diubah atau dihapus lewat SQL biasa.' : 'Trigger belum terpasang (hosting tanpa hak TRIGGER atau migrasi belum dijalankan). Rantai hash tetap mendeteksi perubahan.' ?> <?= $archived ?> laporan berada di arsip (tidak pernah dihapus permanen).</p>
  </div>
</div>

<?php if (!$repOk && $rep['broken']): ?>
<div class="<?= $card ?> mb-space-lg border border-red-300">
  <h2 class="font-headline-sm text-headline-sm text-error mb-space-xs">Catatan riwayat laporan yang tidak sesuai</h2>
  <p class="font-body-sm text-body-sm text-on-surface-variant mb-space-sm">Sidik jari catatan tidak cocok dengan isinya. Kemungkinan data diubah langsung di database. Periksa log akses dan pulihkan dari cadangan.</p>
  <div class="flex flex-wrap gap-space-xs">
    <?php foreach (array_slice($rep['broken'], 0, 30) as $b): ?>
      <a href="kelola-laporan.php?id=<?= (int)$b['report_id'] ?>" class="px-2 py-1 rounded bg-red-100 text-red-800 font-label-sm text-label-sm">Log #<?= (int)$b['id'] ?> (laporan <?= (int)$b['report_id'] ?>)</a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="<?= $card ?>">
  <div class="flex flex-wrap items-center justify-between gap-space-sm mb-space-sm">
    <h2 class="font-headline-md text-headline-md">Jejak Aksi Administratif</h2>
    <form method="get" class="flex items-center gap-space-xs">
      <select name="aksi" onchange="this.form.submit()" class="rounded bg-surface-container-low px-space-sm py-space-xs font-body-md text-body-md">
        <option value="">Semua aksi</option>
        <?php foreach ($actions as $a): ?><option value="<?= e($a) ?>" <?= $fAct === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?>
      </select>
    </form>
  </div>
  <?php if (!$logs): ?><p class="font-body-md text-on-surface-variant">Belum ada aksi administratif tercatat.</p><?php endif; ?>
  <div class="overflow-x-auto">
  <table class="w-full text-left min-w-[640px]">
    <thead class="font-label-sm text-label-sm text-on-surface-variant uppercase"><tr><th class="py-2 pr-3">Waktu</th><th class="py-2 pr-3">Pelaksana</th><th class="py-2 pr-3">Aksi</th><th class="py-2 pr-3">Keterangan</th><th class="py-2">Sidik jari</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $x): ?>
      <tr class="border-t border-surface-container align-top">
        <td class="py-2 pr-3 font-body-sm text-body-sm whitespace-nowrap"><?= e(format_tanggal($x['created_at'], true)) ?><br><span class="text-on-surface-variant"><?= e((string)$x['ip']) ?></span></td>
        <td class="py-2 pr-3 font-body-sm text-body-sm"><?= e($x['uname'] ?? 'Sistem') ?></td>
        <td class="py-2 pr-3"><span class="px-2 py-0.5 rounded bg-surface-container font-label-sm text-label-sm"><?= e($x['action']) ?></span></td>
        <td class="py-2 pr-3 font-body-sm text-body-sm"><?= e($x['entity']) ?><?= $x['entity_id'] !== null ? ' #' . e($x['entity_id']) : '' ?><br><span class="text-on-surface-variant"><?= e((string)$x['detail']) ?></span></td>
        <td class="py-2 font-mono text-[12px] text-on-surface-variant"><?= e(substr((string)$x['entry_hash'], 0, 12)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="mt-space-sm font-body-sm text-body-sm text-on-surface-variant">Menampilkan hingga 100 aksi terbaru. Ekspor CSV memuat seluruh jejak.</p>
</div>
<?php include __DIR__ . '/includes/app_bottom.php'; ?>
