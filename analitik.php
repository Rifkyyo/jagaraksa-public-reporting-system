<?php
require_once __DIR__ . '/config.php';
$user      = require_role('admin', 'petugas');
$active    = 'analitik';
$pageTitle = 'Analitik & SLA';

$periods = [30 => '30 hari', 90 => '90 hari', 365 => '1 tahun', 0 => 'Semua'];
$days    = (int)($_GET['hari'] ?? 90);
if (!isset($periods[$days])) {
    $days = 90;
}
$since = $days ? "AND r.created_at >= (NOW() - INTERVAL $days DAY)" : '';
$base  = "r.deleted_at IS NULL $since";
$OPEN  = "r.status IN ('pending','proses')";
$OVER  = sql_overdue_expr('r');

/* ------------------------ Ekspor CSV (tanpa identitas pelapor) ------------------------ */
if (($_GET['export'] ?? '') === 'csv') {
    $rows = db()->query("SELECT r.ticket_code, r.created_at, c.name cat, d.name dist, a.code agency, r.priority, r.status,
                                r.respond_due_at, r.first_response_at, r.resolve_due_at, r.resolved_at, r.escalation_level,
                                ($OPEN AND $OVER) overdue,
                                CASE WHEN r.status = 'selesai' THEN TIMESTAMPDIFF(HOUR, r.created_at, r.resolved_at) END durasi_jam
                         FROM reports r JOIN categories c ON c.id = r.category_id
                         LEFT JOIN districts d ON d.id = r.district_id LEFT JOIN agencies a ON a.id = r.agency_id
                         WHERE $base ORDER BY r.created_at DESC")->fetchAll();
    audit('ekspor_csv', 'report', null, 'Ekspor analitik periode ' . ($days ?: 'semua') . ' hari, ' . count($rows) . ' baris');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="jagaraksa-analitik-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['tiket', 'dibuat', 'kategori', 'kecamatan', 'instansi', 'prioritas', 'status', 'tenggat_verifikasi', 'respons_pertama',
                   'tenggat_selesai', 'selesai', 'tingkat_eskalasi', 'terlambat_saat_ini', 'durasi_selesai_jam']);
    foreach ($rows as $x) {
        fputcsv($out, [csv_safe($x['ticket_code']), $x['created_at'], csv_safe($x['cat']), csv_safe($x['dist']), csv_safe($x['agency']), $x['priority'], $x['status'],
                       $x['respond_due_at'], $x['first_response_at'], $x['resolve_due_at'], $x['resolved_at'], $x['escalation_level'],
                       $x['overdue'] ? 'ya' : 'tidak', $x['durasi_jam']]);
    }
    fclose($out);
    exit;
}

/* ------------------------ Data ------------------------ */
$k = db()->query("SELECT COUNT(*) total,
        SUM($OPEN) open_n,
        SUM($OPEN AND $OVER) overdue,
        SUM($OPEN AND r.escalation_level > 0) escalated,
        SUM(r.first_response_at IS NOT NULL) responded,
        SUM(r.first_response_at IS NOT NULL AND r.first_response_at <= r.respond_due_at) resp_ok,
        SUM(r.status = 'selesai' AND r.resolved_at IS NOT NULL) resolved,
        SUM(r.status = 'selesai' AND r.resolved_at <= r.resolve_due_at) res_ok,
        AVG(TIMESTAMPDIFF(MINUTE, r.created_at, r.first_response_at)) avg_resp,
        AVG(CASE WHEN r.status = 'selesai' THEN TIMESTAMPDIFF(MINUTE, r.created_at, r.resolved_at) END) avg_res
    FROM reports r WHERE $base")->fetch();
foreach (['total', 'open_n', 'overdue', 'escalated', 'responded', 'resp_ok', 'resolved', 'res_ok'] as $f) {
    $k[$f] = (int)$k[$f];
}
$pct = fn($a, $b) => $b > 0 ? round($a / $b * 100) : null;
$fmtMin = function ($m) {
    if ($m === null) {
        return '-';
    }
    $h = (float)$m / 60;
    return $h < 48 ? round($h, 1) . ' jam' : round($h / 24, 1) . ' hari';
};

// Antrean tindakan segera (selalu laporan terbuka, tidak terpengaruh periode)
$queue = db()->query("SELECT r.id, r.ticket_code, r.title, r.status, r.priority, r.created_at, r.respond_due_at, r.resolve_due_at, r.resolved_at, r.escalation_level,
                             c.name cat_name, d.name district_name, a.code agency_code
                      FROM reports r JOIN categories c ON c.id = r.category_id
                      LEFT JOIN districts d ON d.id = r.district_id LEFT JOIN agencies a ON a.id = r.agency_id
                      WHERE r.deleted_at IS NULL AND $OPEN
                      ORDER BY ($OVER) DESC, FIELD(r.priority, 'darurat', 'tinggi', 'sedang', 'rendah'), r.created_at LIMIT 10")->fetchAll();

$ag = db()->query("SELECT SUM(TIMESTAMPDIFF(HOUR, created_at, NOW()) < 24) b1,
                          SUM(TIMESTAMPDIFF(HOUR, created_at, NOW()) BETWEEN 24 AND 71) b2,
                          SUM(TIMESTAMPDIFF(HOUR, created_at, NOW()) BETWEEN 72 AND 167) b3,
                          SUM(TIMESTAMPDIFF(HOUR, created_at, NOW()) >= 168) b4
                   FROM reports WHERE deleted_at IS NULL AND status IN ('pending','proses')")->fetch();
$aging = [['< 24 jam', (int)$ag['b1'], 'bg-emerald-500'], ['1-3 hari', (int)$ag['b2'], 'bg-sky-500'], ['3-7 hari', (int)$ag['b3'], 'bg-amber-500'], ['> 7 hari', (int)$ag['b4'], 'bg-red-500']];

$prioOpen = array_fill_keys(PRIORITY_ORDER, 0);
foreach (db()->query("SELECT priority, COUNT(*) n FROM reports WHERE deleted_at IS NULL AND status IN ('pending','proses') GROUP BY priority")->fetchAll() as $x) {
    $prioOpen[$x['priority']] = (int)$x['n'];
}

$grp = function ($table, $fk) use ($base, $OPEN, $OVER) {
    return db()->query("SELECT t.name, COUNT(r.id) total, SUM($OPEN) open_n, SUM($OPEN AND $OVER) overdue,
                               SUM(r.status = 'selesai') done, SUM(r.status = 'selesai' AND r.resolved_at <= r.resolve_due_at) ontime,
                               AVG(CASE WHEN r.status = 'selesai' THEN TIMESTAMPDIFF(HOUR, r.created_at, r.resolved_at) END) avg_h
                        FROM $table t LEFT JOIN reports r ON r.$fk = t.id AND $base
                        GROUP BY t.id, t.name ORDER BY total DESC, overdue DESC, t.name")->fetchAll();
};
$byAgency   = $grp('agencies', 'agency_id');
$byCategory = $grp('categories', 'category_id');
$byDistrict = $grp('districts', 'district_id');

$hot = db()->query("SELECT d.name district, c.name category, COUNT(*) n, SUM(r.status IN ('pending','proses')) open_n
                    FROM reports r JOIN districts d ON d.id = r.district_id JOIN categories c ON c.id = r.category_id
                    WHERE r.deleted_at IS NULL AND r.created_at >= (NOW() - INTERVAL 90 DAY)
                    GROUP BY r.district_id, r.category_id, d.name, c.name HAVING n >= 2 ORDER BY n DESC, open_n DESC LIMIT 8")->fetchAll();

$card = 'bg-surface-container-lowest rounded-xl shadow-sm p-space-lg';

function group_table($title, $icon, $rows)
{
    $max = 1;
    foreach ($rows as $x) {
        $max = max($max, (int)$x['total']);
    }
    ob_start(); ?>
    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg">
      <h3 class="font-headline-sm text-headline-sm flex items-center gap-2 mb-space-sm"><span class="material-symbols-outlined text-primary"><?= $icon ?></span><?= e($title) ?></h3>
      <div class="overflow-x-auto">
      <table class="w-full text-left min-w-[520px]">
        <thead class="font-label-sm text-label-sm text-on-surface-variant uppercase"><tr><th class="py-2 pr-3">Nama</th><th class="py-2 pr-3">Laporan</th><th class="py-2 pr-3">Terbuka</th><th class="py-2 pr-3">Terlambat</th><th class="py-2 pr-3">Tepat waktu</th><th class="py-2">Rata-rata selesai</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $x): $t = (int)$x['total']; $done = (int)$x['done']; $ov = (int)$x['overdue']; $h = $x['avg_h']; ?>
          <tr class="border-t border-surface-container">
            <td class="py-2 pr-3 font-label-md text-label-md"><?= e($x['name']) ?></td>
            <td class="py-2 pr-3 min-w-[120px]"><div class="flex items-center gap-2"><div class="h-2 flex-1 bg-surface-container rounded-full overflow-hidden"><div class="h-full bg-primary rounded-full" style="width:<?= round($t / $max * 100) ?>%"></div></div><span class="font-body-sm text-body-sm w-6 text-right"><?= $t ?></span></div></td>
            <td class="py-2 pr-3 font-body-sm text-body-sm"><?= (int)$x['open_n'] ?></td>
            <td class="py-2 pr-3 font-body-sm text-body-sm <?= $ov ? 'text-error font-bold' : '' ?>"><?= $ov ?></td>
            <td class="py-2 pr-3 font-body-sm text-body-sm"><?= $done ? round((int)$x['ontime'] / $done * 100) . '%' : '-' ?></td>
            <td class="py-2 font-body-sm text-body-sm"><?= $h === null ? '-' : ((float)$h < 48 ? round((float)$h, 1) . ' jam' : round((float)$h / 24, 1) . ' hari') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php
    return ob_get_clean();
}

include __DIR__ . '/includes/app_top.php';
?>
<div class="flex flex-col md:flex-row md:items-end justify-between gap-space-md mb-space-lg">
  <div class="max-w-2xl">
    <div class="flex items-center gap-space-xs text-on-surface-variant font-label-sm text-label-sm mb-space-xs"><span>JagaRaksa</span><span class="material-symbols-outlined text-sm">chevron_right</span><span class="text-primary font-semibold">Analitik &amp; SLA</span></div>
    <h1 class="font-headline-xl text-headline-xl tracking-tight">Analitik &amp; Kepatuhan SLA</h1>
    <p class="font-body-md text-body-md text-on-surface-variant mt-space-2xs">Pantau laporan yang terlambat, kinerja tiap instansi, dan wilayah yang berulang bermasalah. Eskalasi berjalan otomatis; halaman ini menunjukkan hasilnya.</p>
  </div>
  <div class="flex flex-wrap items-center gap-space-xs">
    <form method="get" class="flex items-center gap-space-xs">
      <select name="hari" onchange="this.form.submit()" class="rounded bg-surface-container-low px-space-sm py-space-xs font-body-md text-body-md">
        <?php foreach ($periods as $v => $l): ?><option value="<?= $v ?>" <?= $days === $v ? 'selected' : '' ?>>Periode: <?= e($l) ?></option><?php endforeach; ?>
      </select>
    </form>
    <a href="analitik.php?hari=<?= $days ?>&export=csv" class="inline-flex items-center gap-space-xs px-space-md py-space-xs rounded bg-surface-container-high font-label-md text-label-md hover:bg-surface-container-highest"><span class="material-symbols-outlined text-lg">download</span>Ekspor CSV</a>
  </div>
</div>

<div class="grid grid-cols-2 xl:grid-cols-4 gap-gutter-sm mb-space-lg">
  <?php foreach ([
    ['Laporan Terbuka', $k['open_n'], 'dari ' . number_format($k['total']) . ' pada periode', 'text-primary', 'kelola-laporan.php'],
    ['Terlambat (SLA)', $k['overdue'], $k['open_n'] ? round($k['overdue'] / $k['open_n'] * 100) . '% dari yang terbuka' : '-', $k['overdue'] ? 'text-error' : 'text-emerald-700', 'kelola-laporan.php?sla=overdue'],
    ['Tereskalasi', $k['escalated'], 'masih terbuka', $k['escalated'] ? 'text-orange-700' : 'text-emerald-700', 'kelola-laporan.php?sla=escalated'],
    ['Rata-rata Respons Pertama', $fmtMin($k['avg_resp']), 'sejak laporan masuk', 'text-primary', ''],
    ['Kepatuhan SLA Verifikasi', ($p = $pct($k['resp_ok'], $k['responded'])) === null ? '-' : $p . '%', $k['responded'] . ' laporan ditanggapi', 'text-emerald-700', ''],
    ['Kepatuhan SLA Penyelesaian', ($p = $pct($k['res_ok'], $k['resolved'])) === null ? '-' : $p . '%', $k['resolved'] . ' laporan selesai', 'text-emerald-700', ''],
    ['Rata-rata Waktu Selesai', $fmtMin($k['avg_res']), 'laporan yang selesai', 'text-primary', ''],
    ['Total Laporan', number_format($k['total']), 'periode ' . $periods[$days], 'text-primary', ''],
  ] as [$l, $v, $sub, $c, $href]): ?>
  <<?= $href ? 'a href="' . e($href) . '"' : 'div' ?> class="block <?= $card ?> !p-space-md <?= $href ? 'hover:shadow-md transition-shadow' : '' ?>">
    <div class="font-label-md text-label-md text-on-surface-variant"><?= e($l) ?></div>
    <div class="font-headline-xl text-headline-xl mt-space-2xs <?= $c ?>"><?= e((string)$v) ?></div>
    <div class="font-label-sm text-label-sm text-on-surface-variant"><?= e($sub) ?></div>
  </<?= $href ? 'a' : 'div' ?>>
  <?php endforeach; ?>
</div>

<div class="<?= $card ?> mb-space-lg">
  <div class="flex items-center justify-between mb-space-sm">
    <h3 class="font-headline-md text-headline-md flex items-center gap-2"><span class="material-symbols-outlined text-error">notification_important</span>Perlu Tindakan Segera</h3>
    <a href="kelola-laporan.php" class="font-label-sm text-label-sm text-primary hover:underline">Semua laporan →</a>
  </div>
  <?php if (!$queue): ?><p class="font-body-md text-on-surface-variant">Tidak ada laporan terbuka. Semua sudah tertangani.</p><?php endif; ?>
  <div class="flex flex-col gap-space-xs">
  <?php foreach ($queue as $r): ?>
    <a href="kelola-laporan.php?id=<?= (int)$r['id'] ?>" class="flex flex-col md:flex-row md:items-center gap-space-xs md:gap-space-md p-space-sm rounded-lg bg-surface-container-low hover:bg-surface-container transition-colors">
      <span class="font-mono text-body-sm text-primary font-bold whitespace-nowrap">#<?= e($r['ticket_code']) ?></span>
      <span class="flex-1 min-w-0"><span class="block font-label-lg text-label-lg truncate"><?= e($r['title']) ?></span>
        <span class="block font-body-sm text-body-sm text-on-surface-variant"><?= e($r['cat_name']) ?> • Kec. <?= e($r['district_name'] ?? '-') ?><?= $r['agency_code'] ? ' • Dinas ' . e($r['agency_code']) : ' • Belum disposisi' ?></span></span>
      <span class="flex flex-wrap items-center gap-1"><?= status_badge($r['status']) ?><?= priority_badge($r['priority']) ?><?= sla_badge($r) ?><?php if ((int)$r['escalation_level'] > 0): ?><span class="font-label-sm text-label-sm text-orange-700">Eskalasi <?= (int)$r['escalation_level'] ?></span><?php endif; ?></span>
    </a>
  <?php endforeach; ?>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-gutter-sm mb-space-lg">
  <div class="<?= $card ?>">
    <h3 class="font-headline-sm text-headline-sm mb-space-xs">Usia Laporan Terbuka</h3>
    <p class="font-body-sm text-body-sm text-on-surface-variant mb-space-sm">Sejak laporan masuk, belum selesai atau ditolak.</p>
    <?php $mx = max(1, max(array_column($aging, 1))); foreach ($aging as [$l, $n, $cl]): ?>
      <div class="flex items-center gap-2 mb-space-xs"><span class="w-20 font-label-sm text-label-sm"><?= e($l) ?></span>
        <div class="flex-1 h-3 bg-surface-container rounded-full overflow-hidden"><div class="h-full <?= $cl ?> rounded-full" style="width:<?= round($n / $mx * 100) ?>%"></div></div><span class="w-8 text-right font-label-md text-label-md"><?= $n ?></span></div>
    <?php endforeach; ?>
  </div>
  <div class="<?= $card ?>">
    <h3 class="font-headline-sm text-headline-sm mb-space-xs">Prioritas Laporan Terbuka</h3>
    <p class="font-body-sm text-body-sm text-on-surface-variant mb-space-sm">Prioritas naik otomatis pada eskalasi tingkat 2.</p>
    <?php $mx = max(1, max($prioOpen)); foreach (array_reverse(PRIORITY_ORDER) as $p): ?>
      <div class="flex items-center gap-2 mb-space-xs"><span class="w-20"><?= priority_badge($p) ?></span>
        <div class="flex-1 h-3 bg-surface-container rounded-full overflow-hidden"><div class="h-full rounded-full" style="width:<?= round($prioOpen[$p] / $mx * 100) ?>%;background:<?= PRIORITY_META[$p]['color'] ?>"></div></div><span class="w-8 text-right font-label-md text-label-md"><?= $prioOpen[$p] ?></span></div>
    <?php endforeach; ?>
  </div>
</div>

<div class="flex flex-col gap-gutter-sm mb-space-lg">
  <?= group_table('Kinerja per Instansi', 'account_balance', $byAgency) ?>
  <?= group_table('Per Kategori Masalah', 'category', $byCategory) ?>
  <?= group_table('Sebaran per Kecamatan', 'location_city', $byDistrict) ?>
</div>

<div class="<?= $card ?>">
  <h3 class="font-headline-md text-headline-md flex items-center gap-2 mb-space-xs"><span class="material-symbols-outlined text-primary">repeat</span>Titik Rawan Berulang</h3>
  <p class="font-body-sm text-body-sm text-on-surface-variant mb-space-sm">Kecamatan dengan kategori masalah yang sama dilaporkan 2 kali atau lebih dalam 90 hari terakhir. Pertimbangkan perbaikan permanen, bukan tambal sulam.</p>
  <?php if (!$hot): ?><p class="font-body-md text-on-surface-variant">Belum ada pola berulang.</p><?php endif; ?>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-space-xs">
    <?php foreach ($hot as $x): ?>
      <div class="flex items-center justify-between p-space-sm rounded-lg bg-surface-container-low">
        <span><span class="font-label-lg text-label-lg"><?= e($x['category']) ?></span><br><span class="font-body-sm text-body-sm text-on-surface-variant">Kec. <?= e($x['district']) ?></span></span>
        <span class="text-right"><span class="font-headline-sm text-headline-sm text-primary"><?= (int)$x['n'] ?>x</span><br><span class="font-label-sm text-label-sm text-on-surface-variant"><?= (int)$x['open_n'] ?> masih terbuka</span></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php include __DIR__ . '/includes/app_bottom.php'; ?>
