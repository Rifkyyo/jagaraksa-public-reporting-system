<?php
require_once __DIR__ . '/config.php';
$user   = require_login();
$staff  = is_staff($user);
$scope  = $staff ? '' : 'WHERE r.user_id = ' . (int)$user['id'];
$scopeA = 'AND r.deleted_at IS NULL ' . ($staff ? '' : 'AND r.user_id = ' . (int)$user['id']);
$active = 'dashboard';
$pageTitle = 'Dashboard';

$st  = stats_overview($staff ? null : (int)$user['id']);
$pct = $st['total'] ? round($st['selesai'] / $st['total'] * 100, 1) : 0;
$bk  = $st['total'] ? round($st['pending'] / $st['total'] * 100, 1) : 0;
$pr  = $st['total'] ? round($st['proses'] / $st['total'] * 100, 1) : 0;

// Tren 30 hari
$days = [];
for ($i = 29; $i >= 0; $i--) {
    $days[date('Y-m-d', strtotime("-$i day"))] = ['in' => 0, 'done' => 0];
}
foreach (db()->query("SELECT DATE(r.created_at) d, COUNT(*) c FROM reports r
        WHERE r.created_at >= CURDATE() - INTERVAL 29 DAY $scopeA GROUP BY d")->fetchAll() as $row) {
    if (isset($days[$row['d']])) $days[$row['d']]['in'] = (int)$row['c'];
}
foreach (db()->query("SELECT DATE(r.resolved_at) d, COUNT(*) c FROM reports r
        WHERE r.resolved_at >= CURDATE() - INTERVAL 29 DAY $scopeA GROUP BY d")->fetchAll() as $row) {
    if (isset($days[$row['d']])) $days[$row['d']]['done'] = (int)$row['c'];
}
$in30   = array_sum(array_column($days, 'in'));
$done30 = array_sum(array_column($days, 'done'));

// Distribusi kategori
$catRows = db()->query("SELECT c.name, COUNT(r.id) n FROM categories c
    LEFT JOIN reports r ON r.category_id = c.id AND r.deleted_at IS NULL " . ($staff ? '' : 'AND r.user_id = ' . (int)$user['id']) . "
    GROUP BY c.id ORDER BY n DESC")->fetchAll();
$catTotal = array_sum(array_column($catRows, 'n'));

// Rata-rata waktu penyelesaian
$avgH = db()->query("SELECT AVG(TIMESTAMPDIFF(HOUR, r.created_at, r.resolved_at)) FROM reports r
                     WHERE r.resolved_at IS NOT NULL $scopeA")->fetchColumn();
$avgTxt = $avgH === null ? '-' : ($avgH < 48 ? round((float)$avgH, 1) . ' Jam' : round((float)$avgH / 24, 1) . ' Hari');

// Titik peta
$pts = db()->query("SELECT r.ticket_code, r.title, r.address, r.latitude lat, r.longitude lng, r.status
                    FROM reports r WHERE r.latitude IS NOT NULL $scopeA LIMIT 300")->fetchAll();
foreach ($pts as &$p) { $p['lat'] = (float)$p['lat']; $p['lng'] = (float)$p['lng']; $p['color'] = STATUS_META[$p['status']]['color']; $p['label'] = STATUS_META[$p['status']]['label']; }
unset($p);

// Aktivitas terbaru
$acts = db()->query("SELECT l.*, r.ticket_code, r.title, r.status FROM report_logs l
                     JOIN reports r ON r.id = l.report_id WHERE r.deleted_at IS NULL " . ($staff ? '' : 'AND r.user_id = ' . (int)$user['id']) . "
                     ORDER BY l.id DESC LIMIT 6")->fetchAll();
$actIcon = ['dibuat' => ['add_circle', 'bg-primary-fixed text-primary'], 'status' => ['sync', 'bg-cyan-100 text-cyan-700'],
            'disposisi' => ['share', 'bg-secondary-container text-secondary'], 'tanggapan' => ['chat', 'bg-amber-100 text-amber-700'],
            'eskalasi' => ['priority_high', 'bg-red-100 text-red-700'], 'prioritas' => ['flag', 'bg-orange-100 text-orange-700'],
            'buka_kembali' => ['replay', 'bg-amber-100 text-amber-700'], 'dipulihkan' => ['restore', 'bg-slate-100 text-slate-700']];
$slaInfo = $staff ? sla_overview() : null;

$cards = [
    ['Total Laporan Masuk', $st['total'],   'assignment',  'bg-surface-container text-primary', '', ''],
    ['Menunggu Verifikasi', $st['pending'], 'hourglass_top', 'bg-amber-100 text-amber-700', $bk . '% dari total', 'bg-amber-100 text-amber-900'],
    ['Sedang Ditindaklanjuti', $st['proses'], 'build',      'bg-cyan-100 text-primary', $pr . '% dalam pengerjaan', 'bg-cyan-100 text-cyan-900'],
    ['Selesai Dikerjakan',  $st['selesai'], 'check_circle', 'bg-emerald-100 text-emerald-700', $pct . '% rasio tuntas', 'bg-emerald-100 text-emerald-900'],
];
include __DIR__ . '/includes/app_top.php';
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<div class="flex flex-wrap items-center justify-between gap-space-sm mb-space-md">
  <div class="flex items-center gap-space-xs text-on-surface-variant font-label-sm text-label-sm">
    <span>JagaRaksa</span><span class="material-symbols-outlined text-sm">chevron_right</span><span class="text-on-surface font-semibold">Dashboard Pemantauan</span>
  </div>
  <div class="flex items-center gap-space-xs px-space-sm py-space-2xs rounded-full bg-surface-container-high text-on-surface-variant font-label-sm text-label-sm">
    <span class="w-2 h-2 rounded-full bg-tertiary-container animate-pulse"></span><span>Sistem Sinkronisasi Real-Time (GIS Active)</span>
  </div>
</div>

<div class="flex flex-col lg:flex-row lg:items-end justify-between gap-space-lg mb-space-xl">
  <div class="max-w-2xl">
    <h1 class="font-headline-xl text-headline-xl text-on-surface tracking-tight"><?= $staff ? 'Dashboard Pemantauan &amp; Analitik' : 'Dashboard Warga' ?></h1>
    <p class="font-body-md text-body-md text-on-surface-variant mt-space-2xs">
      <?= $staff ? 'Ringkasan data laporan fasilitas umum, efisiensi tindak lanjut dinas, dan sebaran spasial wilayah Kota Bandung.'
                 : 'Halo, ' . e($user['name']) . '. Pantau status seluruh laporan yang Anda kirim dan perkembangan penanganannya.' ?>
    </p>
  </div>
  <div class="flex flex-wrap gap-space-xs self-start">
    <?php if ($staff): ?><a href="analitik.php" class="inline-flex items-center gap-space-xs px-space-lg py-space-sm rounded bg-primary-fixed text-primary font-label-lg text-label-lg hover:opacity-90"><span class="material-symbols-outlined text-xl">insights</span> Analitik &amp; SLA</a><?php endif; ?>
    <a href="laporan-saya.php" class="inline-flex items-center gap-space-xs px-space-lg py-space-sm rounded bg-primary text-on-primary font-label-lg text-label-lg hover:bg-on-primary-fixed-variant shadow-sm">
      <span class="material-symbols-outlined text-xl">add_circle</span> Buat Laporan Baru
    </a>
  </div>
</div>

<?php if ($staff && $slaInfo['overdue'] > 0): ?>
<a href="kelola-laporan.php?sla=overdue" class="flex items-center gap-space-sm p-space-md mb-space-lg rounded-xl bg-red-50 text-red-900 hover:bg-red-100 transition-colors">
  <span class="material-symbols-outlined">alarm</span>
  <span class="font-label-lg text-label-lg flex-1"><?= (int)$slaInfo['overdue'] ?> laporan melewati batas SLA dan perlu tindakan sekarang.</span>
  <span class="font-label-md text-label-md underline">Tinjau</span>
</a>
<?php endif; ?>

<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-gutter-sm mb-space-lg">
  <?php foreach ($cards as [$lbl, $val, $ico, $icoCls, $chip, $chipCls]): ?>
  <div class="bg-surface-container-lowest p-space-lg rounded-xl shadow-sm flex flex-col gap-space-sm">
    <div class="flex items-start justify-between">
      <div><div class="font-label-md text-label-md text-on-surface-variant"><?= e($lbl) ?></div>
        <div class="font-display-lg text-display-lg text-on-surface"><?= number_format((int)$val) ?></div></div>
      <div class="w-10 h-10 rounded flex items-center justify-center <?= $icoCls ?>"><span class="material-symbols-outlined"><?= $ico ?></span></div>
    </div>
    <?php if ($chip): ?><span class="self-start px-2 py-0.5 rounded font-label-sm text-label-sm <?= $chipCls ?>"><?= e($chip) ?></span><?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-gutter-sm mb-space-xl">
  <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex items-center gap-space-md">
    <div class="w-11 h-11 rounded-full bg-primary-fixed text-primary flex items-center justify-center"><span class="material-symbols-outlined">timer</span></div>
    <div><div class="font-label-sm text-label-sm text-on-surface-variant">Rata-rata Waktu Penyelesaian</div><div class="font-headline-md text-headline-md"><?= e($avgTxt) ?></div></div>
  </div>
  <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex items-center gap-space-md">
    <div class="w-11 h-11 rounded-full bg-tertiary-fixed text-tertiary flex items-center justify-center"><span class="material-symbols-outlined">task_alt</span></div>
    <div><div class="font-label-sm text-label-sm text-on-surface-variant">Laporan Ditolak / Tidak Valid</div><div class="font-headline-md text-headline-md"><?= (int)$st['ditolak'] ?></div></div>
  </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-gutter-sm mb-space-xl">
  <div class="xl:col-span-2 bg-surface-container-lowest rounded-xl shadow-sm p-space-lg">
    <h3 class="font-headline-md text-headline-md">Tren Pelaporan &amp; Penyelesaian Harian</h3>
    <p class="font-body-sm text-body-sm text-on-surface-variant mb-space-sm">Volume harian laporan baru dibanding penyelesaian dalam 30 hari terakhir</p>
    <div class="flex gap-space-lg font-label-sm text-label-sm mb-space-sm">
      <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-primary"></span>Diterima (<?= $in30 ?>)</span>
      <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-emerald-600"></span>Diselesaikan (<?= $done30 ?>)</span>
    </div>
    <div class="relative h-64"><canvas id="trend"></canvas></div>
  </div>
  <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg">
    <h3 class="font-headline-md text-headline-md">Distribusi Kategori Masalah</h3>
    <p class="font-body-sm text-body-sm text-on-surface-variant mb-space-md">Proporsi aduan sarana prasarana kota</p>
    <?php $cols = ['#0f172a', '#2BA8C8', '#059669', '#f59e0b', '#7c3aed', '#db2777']; ?>
    <div class="relative h-40 mb-space-md"><canvas id="cats"></canvas></div>
    <div class="space-y-space-sm">
      <?php foreach ($catRows as $i => $c): $p = $catTotal ? round($c['n'] / $catTotal * 100) : 0; ?>
      <div>
        <div class="flex justify-between font-label-sm text-label-sm"><span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full" style="background:<?= $cols[$i % 6] ?>"></span><?= e($c['name']) ?></span><span class="text-on-surface-variant"><?= $p ?>% (<?= (int)$c['n'] ?>)</span></div>
        <div class="h-1.5 bg-surface-container rounded-full overflow-hidden"><div class="h-full rounded-full" style="width:<?= $p ?>%;background:<?= $cols[$i % 6] ?>"></div></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg mb-space-xl">
  <div class="flex flex-wrap items-center justify-between gap-space-sm mb-space-sm">
    <div><h3 class="font-headline-md text-headline-md flex items-center gap-2"><span class="material-symbols-outlined text-primary">public</span>Peta Interaktif Sebaran Laporan</h3>
      <p class="font-body-sm text-body-sm text-on-surface-variant">Visualisasi koordinat GPS laporan berdasarkan status penanganan.</p></div>
    <a href="peta.php" class="px-space-md py-space-xs rounded bg-primary text-on-primary font-label-sm text-label-sm">Buka Peta Penuh</a>
  </div>
  <div id="dMap" class="w-full h-[380px] rounded-lg overflow-hidden"></div>
  <div class="flex gap-space-md mt-space-sm font-label-sm text-label-sm flex-wrap">
    <?php foreach (STATUS_META as $k => $m): ?><span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full" style="background:<?= $m['color'] ?>"></span><?= e($m['label']) ?> (<?= (int)$st[$k] ?>)</span><?php endforeach; ?>
  </div>
</div>

<div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg">
  <div class="flex items-center justify-between mb-space-md">
    <h3 class="font-headline-md text-headline-md flex items-center gap-2"><span class="material-symbols-outlined text-primary">history</span>Aktivitas Tindak Lanjut Terbaru</h3>
    <a href="<?= $staff ? 'kelola-laporan.php' : 'laporan-saya.php?tab=riwayat' ?>" class="font-label-sm text-label-sm text-primary hover:underline">Lihat Semua →</a>
  </div>
  <?php if (!$acts): ?><p class="font-body-md text-on-surface-variant">Belum ada aktivitas. <a class="text-primary font-semibold" href="laporan-saya.php">Buat laporan pertama Anda</a>.</p><?php endif; ?>
  <div class="space-y-space-sm">
    <?php foreach ($acts as $a): [$ai, $ac] = $actIcon[$a['action']] ?? ['info', 'bg-surface-container text-on-surface']; ?>
    <div class="flex items-start gap-space-md p-space-md rounded-lg bg-surface-container-low">
      <div class="w-10 h-10 rounded-full flex items-center justify-center shrink-0 <?= $ac ?>"><span class="material-symbols-outlined"><?= $ai ?></span></div>
      <div class="flex-1 min-w-0">
        <div class="flex flex-wrap items-center gap-2"><span class="font-label-md text-label-md text-primary">#<?= e($a['ticket_code']) ?></span><span class="font-label-md text-label-md truncate"><?= e($a['title']) ?></span><?= status_badge($a['status']) ?></div>
        <p class="font-body-sm text-body-sm text-on-surface-variant"><?= e($a['note'] ?? '') ?></p>
      </div>
      <span class="font-label-sm text-label-sm text-on-surface-variant whitespace-nowrap"><?= e(time_ago($a['created_at'])) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
const DAYS = <?= json_encode(array_keys($days)) ?>, IN = <?= json_encode(array_column($days, 'in')) ?>, DONE = <?= json_encode(array_column($days, 'done')) ?>;
new Chart(document.getElementById('trend'), {type:'line', data:{labels:DAYS.map(d=>d.slice(8)+'/'+d.slice(5,7)), datasets:[
  {label:'Diterima', data:IN, borderColor:'#2BA8C8', backgroundColor:'rgba(43,168,200,.14)', fill:true, tension:.35, pointRadius:0},
  {label:'Diselesaikan', data:DONE, borderColor:'#059669', tension:.35, pointRadius:0}]},
  options:{maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true,ticks:{precision:0}},x:{ticks:{maxTicksLimit:8}}}}});
new Chart(document.getElementById('cats'), {type:'doughnut', data:{labels:<?= json_encode(array_column($catRows, 'name'), JSON_UNESCAPED_UNICODE) ?>,
  datasets:[{data:<?= json_encode(array_map('intval', array_column($catRows, 'n'))) ?>, backgroundColor:<?= json_encode($cols) ?>, borderWidth:0}]},
  options:{maintainAspectRatio:false, cutout:'68%', plugins:{legend:{display:false}}}});

const PTS = <?= json_encode($pts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const map = L.map('dMap',{scrollWheelZoom:false}).setView([-6.9147,107.6098],12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap'}).addTo(map);
const esc = s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
PTS.forEach(r=>L.circleMarker([r.lat,r.lng],{radius:9,color:'#fff',weight:2,fillColor:r.color,fillOpacity:.95})
  .bindPopup('<b>#'+esc(r.ticket_code)+'</b> ('+esc(r.label)+')<br>'+esc(r.title)+'<br><small>'+esc(r.address)+'</small>').addTo(map));
</script>
<?php include __DIR__ . '/includes/app_bottom.php'; ?>
