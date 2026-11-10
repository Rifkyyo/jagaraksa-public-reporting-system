<?php
require_once __DIR__ . '/config.php';
$user    = require_role('admin', 'petugas');
$active  = 'kelola';
$pageTitle = 'Kelola Laporan';
$isAdmin = $user['role'] === 'admin';

/* ------------------------ Aksi POST ------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $rs = db()->prepare('SELECT * FROM reports WHERE id = ?');
    $rs->execute([$id]);
    $rep = $rs->fetch();

    // kembali ke daftar dengan filter yang sama (hanya kunci yang diizinkan)
    $bq = [];
    parse_str((string)($_POST['back_qs'] ?? ''), $bq);
    $keep = [];
    foreach (['q', 'status', 'cat', 'dist', 'bulan', 'page', 'sla', 'prio', 'arsip'] as $k) {
        if (isset($bq[$k]) && is_scalar($bq[$k]) && (string)$bq[$k] !== '') {
            $keep[$k] = (string)$bq[$k];
        }
    }
    $back = 'kelola-laporan.php?' . http_build_query(['id' => $id] + $keep);

    if (!$rep) {
        flash('error', 'Laporan tidak ditemukan.');
        redirect('kelola-laporan.php');
    }
    $do = (string)($_POST['do'] ?? 'save');

    /* --- pulihkan dari arsip (admin) --- */
    if ($do === 'restore') {
        if (!$isAdmin || $rep['deleted_at'] === null) {
            flash('error', 'Aksi tidak diizinkan.');
            redirect($back);
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE reports SET deleted_at = NULL, deleted_by = NULL, delete_reason = NULL WHERE id = ?')->execute([$id]);
            log_report($id, (int)$user['id'], 'dipulihkan', 'Laporan dipulihkan dari arsip oleh admin.');
            notify((int)$rep['user_id'], $id, 'Laporan #' . $rep['ticket_code'] . ' dipulihkan oleh admin dan kembali ditampilkan.');
            audit('laporan_dipulihkan', 'report', $id, 'Tiket ' . $rep['ticket_code']);
            $pdo->commit();
            flash('success', 'Laporan #' . $rep['ticket_code'] . ' dipulihkan.');
        } catch (Throwable $ex) {
            $pdo->rollBack();
            flash('error', 'Gagal memulihkan laporan.');
        }
        redirect('kelola-laporan.php?id=' . $id);
    }

    if ($rep['deleted_at'] !== null) {
        flash('error', 'Laporan ini sedang diarsipkan. Pulihkan terlebih dahulu untuk mengubahnya.');
        redirect($back);
    }

    /* --- arsipkan (soft delete, admin): data & riwayat TIDAK dihapus --- */
    if ($do === 'delete') {
        $reason = trim((string)($_POST['reason'] ?? ''));
        if (!$isAdmin) {
            flash('error', 'Hanya admin yang dapat mengarsipkan laporan.');
            redirect($back);
        }
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 200) {
            flash('error', 'Alasan pengarsipan wajib diisi (5-200 karakter).');
            redirect($back);
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE reports SET deleted_at = NOW(), deleted_by = ?, delete_reason = ? WHERE id = ? AND deleted_at IS NULL')
                ->execute([(int)$user['id'], $reason, $id]);
            log_report($id, (int)$user['id'], 'diarsipkan', 'Diarsipkan oleh admin: ' . $reason);
            audit('laporan_diarsipkan', 'report', $id, 'Tiket ' . $rep['ticket_code'] . ': ' . $reason);
            if ((int)$rep['user_id'] !== (int)$user['id']) {
                notify((int)$rep['user_id'], null, 'Laporan #' . $rep['ticket_code'] . ' dihapus oleh admin. Alasan: ' . $reason);
            }
            $pdo->commit();
            flash('success', 'Laporan #' . $rep['ticket_code'] . ' diarsipkan. Data dan riwayatnya tetap tersimpan dan dapat dipulihkan.');
        } catch (Throwable $ex) {
            $pdo->rollBack();
            flash('error', 'Gagal mengarsipkan laporan.');
        }
        redirect('kelola-laporan.php');
    }

    /* --- simpan: status / prioritas / disposisi / tanggapan --- */
    $newStatus = (string)($_POST['status'] ?? '');
    $newPrio   = (string)($_POST['priority'] ?? $rep['priority']);
    $agencyId  = (int)($_POST['agency_id'] ?? 0) ?: null;
    $message   = trim((string)($_POST['message'] ?? ''));

    $agNames = [];
    foreach (agencies() as $a) {
        $agNames[(int)$a['id']] = $a['name'];
    }
    if (!isset(STATUS_META[$newStatus]) || !isset(PRIORITY_META[$newPrio]) || mb_strlen($message) > 300
        || ($agencyId !== null && !isset($agNames[$agencyId]))) {
        flash('error', 'Data tidak valid (status/prioritas/instansi salah atau tanggapan melebihi 300 karakter).');
        redirect($back);
    }
    if (!status_transition_ok($rep['status'], $newStatus)) {
        flash('error', 'Perpindahan status ' . STATUS_META[$rep['status']]['label'] . ' ke ' . STATUS_META[$newStatus]['label'] . ' tidak diizinkan oleh alur penanganan.');
        redirect($back);
    }
    if ($newStatus === 'ditolak' && $rep['status'] !== 'ditolak' && $message === '') {
        flash('error', 'Alasan penolakan wajib ditulis pada kolom tanggapan agar pelapor mengetahuinya.');
        redirect($back);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $now           = date('Y-m-d H:i:s');
        $statusChanged = $newStatus !== $rep['status'];
        $prioChanged   = $newPrio !== $rep['priority'];
        $resolved      = $newStatus === 'selesai' ? ($rep['resolved_at'] ?: $now) : null;
        $assignee      = $newStatus === 'proses' ? ($rep['assigned_to'] ?: $user['id']) : $rep['assigned_to'];

        // tenggat SLA
        $respDue = $rep['respond_due_at'];
        $resDue  = $rep['resolve_due_at'];
        if ($prioChanged) {   // geser tenggat sesuai selisih jam SLA antar prioritas
            $ho = sla_hours($rep['priority']);
            $hn = sla_hours($newPrio);
            $respDue = $respDue ? date('Y-m-d H:i:s', strtotime($respDue) + ($hn['respond'] - $ho['respond']) * 3600) : null;
            $resDue  = $resDue ? date('Y-m-d H:i:s', strtotime($resDue) + ($hn['resolve'] - $ho['resolve']) * 3600) : null;
        }
        if ($statusChanged && $rep['status'] === 'selesai' && $newStatus === 'proses') {   // dibuka kembali oleh petugas
            $resDue = sla_due_dates($newPrio)[1];
        }
        if ($statusChanged && $rep['status'] === 'ditolak' && $newStatus === 'pending') {   // ditinjau ulang
            [$respDue, $resDue] = sla_due_dates($newPrio);
        }
        $firstResp = $rep['first_response_at'] ?: (($newStatus !== 'pending' || $message !== '') ? $now : null);
        $escLevel  = $statusChanged ? 0 : (int)$rep['escalation_level'];
        $escAt     = $statusChanged ? null : $rep['escalated_at'];

        $pdo->prepare('UPDATE reports SET status = ?, agency_id = ?, resolved_at = ?, assigned_to = ?, priority = ?, respond_due_at = ?, resolve_due_at = ?,
                       first_response_at = ?, escalation_level = ?, escalated_at = ? WHERE id = ?')
            ->execute([$newStatus, $agencyId, $resolved, $assignee, $newPrio, $respDue, $resDue, $firstResp, $escLevel, $escAt, $id]);

        $oldAgency = $rep['agency_id'] !== null ? (int)$rep['agency_id'] : null;
        if ($agencyId !== $oldAgency) {
            log_report($id, (int)$user['id'], 'disposisi', 'Didisposisikan ke ' . ($agencyId ? $agNames[$agencyId] : '-'),
                       $oldAgency ? $agNames[$oldAgency] ?? null : null, $agencyId ? $agNames[$agencyId] : null);
            if ($agencyId) {
                notify_staff($agencyId, $id, 'Disposisi baru: #' . $rep['ticket_code'] . ' - ' . $rep['title'], (int)$user['id']);
            }
        }
        if ($prioChanged) {
            log_report($id, (int)$user['id'], 'prioritas', 'Prioritas diubah dari ' . PRIORITY_META[$rep['priority']]['label'] . ' menjadi ' . PRIORITY_META[$newPrio]['label'] . ' oleh ' . $user['name'], $rep['priority'], $newPrio);
        }
        if ($statusChanged) {
            log_report($id, (int)$user['id'], 'status', 'Status diubah menjadi ' . STATUS_META[$newStatus]['label'] . ' oleh ' . $user['name'], $rep['status'], $newStatus);
        }
        if ($message !== '') {
            $pdo->prepare('INSERT INTO report_responses (report_id, user_id, message) VALUES (?,?,?)')->execute([$id, $user['id'], $message]);
            log_report($id, (int)$user['id'], 'tanggapan', 'Tanggapan resmi ditambahkan.');
        }
        if ($newStatus === 'selesai') {
            foreach (save_uploads('done_photos') as $f) {
                $pdo->prepare("INSERT INTO report_attachments (report_id, file_path, file_type, file_size, kind) VALUES (?,?,?,?, 'penyelesaian')")
                    ->execute([$id, $f['path'], $f['type'], $f['size']]);
            }
        }
        if (!empty($_POST['notify']) && ($statusChanged || $message !== '')) {
            notify((int)$rep['user_id'], $id, 'Laporan #' . $rep['ticket_code'] . ' kini berstatus ' . STATUS_META[$newStatus]['label'] . ($message !== '' ? ': ' . $message : '.'));
        }
        $pdo->commit();
        flash('success', 'Perubahan laporan #' . $rep['ticket_code'] . ' berhasil disimpan.');
    } catch (Throwable $ex) {
        $pdo->rollBack();
        flash('error', 'Gagal menyimpan perubahan.');
    }
    redirect($back);
}

/* ------------------------ Filter & daftar ------------------------ */
$q      = trim((string)($_GET['q'] ?? ''));
$fStat  = (string)($_GET['status'] ?? '');
$fCat   = (int)($_GET['cat'] ?? 0);
$fDist  = (int)($_GET['dist'] ?? 0);
$fMonth = (string)($_GET['bulan'] ?? '');
$fSla   = (string)($_GET['sla'] ?? '');
$fPrio  = (string)($_GET['prio'] ?? '');
$showArchive = $isAdmin && !empty($_GET['arsip']);

$where = [$showArchive ? 'r.deleted_at IS NOT NULL' : 'r.deleted_at IS NULL'];
$par   = [];
if ($q !== '')                      { $where[] = '(r.ticket_code LIKE ? OR r.title LIKE ? OR r.address LIKE ?)'; array_push($par, "%$q%", "%$q%", "%$q%"); }
if (isset(STATUS_META[$fStat]))     { $where[] = 'r.status = ?'; $par[] = $fStat; }
if (isset(PRIORITY_META[$fPrio]))   { $where[] = 'r.priority = ?'; $par[] = $fPrio; }
if ($fCat)                          { $where[] = 'r.category_id = ?'; $par[] = $fCat; }
if ($fDist)                         { $where[] = 'r.district_id = ?'; $par[] = $fDist; }
if ($fSla === 'overdue')            { $where[] = "r.status IN ('pending','proses')"; $where[] = sql_overdue_expr('r'); }
if ($fSla === 'escalated')          { $where[] = "r.status IN ('pending','proses')"; $where[] = 'r.escalation_level > 0'; }
if (preg_match('/^\d{4}-\d{2}$/', $fMonth)) { $where[] = "DATE_FORMAT(r.created_at, '%Y-%m') = ?"; $par[] = $fMonth; }
$W = implode(' AND ', $where);

$orderBy = $showArchive
    ? 'r.deleted_at DESC'
    : "(r.status IN ('pending','proses') AND " . sql_overdue_expr('r') . ") DESC, (r.status IN ('pending','proses')) DESC,
       FIELD(r.priority, 'darurat', 'tinggi', 'sedang', 'rendah'), (r.status = 'pending') DESC, r.created_at DESC";

$perPage = 8;
$cnt = db()->prepare("SELECT COUNT(*) FROM reports r WHERE $W"); $cnt->execute($par);
$totalFiltered = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($totalFiltered / $perPage));
$page  = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$off   = ($page - 1) * $perPage;
$ls = db()->prepare("SELECT r.*, c.name cat_name, c.icon cat_icon, d.name district_name, u.name reporter, a.code agency_code
    FROM reports r JOIN categories c ON c.id = r.category_id JOIN users u ON u.id = r.user_id
    LEFT JOIN districts d ON d.id = r.district_id LEFT JOIN agencies a ON a.id = r.agency_id
    WHERE $W ORDER BY $orderBy LIMIT $perPage OFFSET $off");
$ls->execute($par);
$rows = $ls->fetchAll();

$st       = stats_overview();
$slaInfo  = sla_overview();
$todayNew = (int)db()->query('SELECT COUNT(*) FROM reports WHERE deleted_at IS NULL AND created_at >= CURDATE() - INTERVAL 1 DAY')->fetchColumn();
$doneWeek = (int)db()->query("SELECT COUNT(*) FROM reports WHERE deleted_at IS NULL AND status='selesai' AND resolved_at >= CURDATE() - INTERVAL 7 DAY")->fetchColumn();
$urgent   = (int)db()->query("SELECT COUNT(*) FROM reports WHERE deleted_at IS NULL AND status='pending' AND priority IN ('darurat','tinggi')")->fetchColumn();
$archived = $isAdmin ? (int)db()->query('SELECT COUNT(*) FROM reports WHERE deleted_at IS NOT NULL')->fetchColumn() : 0;

// Detail terpilih
$selId = (int)($_GET['id'] ?? ($rows[0]['id'] ?? 0));
$sel = null;
if ($selId) {
    $s = db()->prepare("SELECT r.*, c.name cat_name, d.name district_name, u.name reporter, u.phone reporter_phone
        FROM reports r JOIN categories c ON c.id = r.category_id JOIN users u ON u.id = r.user_id
        LEFT JOIN districts d ON d.id = r.district_id WHERE r.id = ?" . ($isAdmin ? '' : ' AND r.deleted_at IS NULL'));
    $s->execute([$selId]);
    $sel = $s->fetch() ?: null;
}
$selAtts = [];
$selLogs = [];
if ($sel) {
    $a = db()->prepare('SELECT * FROM report_attachments WHERE report_id = ? ORDER BY id'); $a->execute([$sel['id']]); $selAtts = $a->fetchAll();
    $lg = db()->prepare('SELECT l.*, u.name uname FROM report_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.report_id = ? ORDER BY l.id DESC LIMIT 15');
    $lg->execute([$sel['id']]); $selLogs = $lg->fetchAll();
}
$qs = fn(array $over = []) => http_build_query(array_filter(array_merge(
    ['q' => $q, 'status' => $fStat, 'cat' => $fCat, 'dist' => $fDist, 'bulan' => $fMonth, 'sla' => $fSla, 'prio' => $fPrio, 'arsip' => $showArchive ? 1 : '', 'page' => $page], $over),
    fn($v) => $v !== '' && $v !== 0 && $v !== null));
$sel_cls = 'rounded bg-surface-container-low px-space-sm py-space-xs font-body-md text-body-md focus:outline-none focus:ring-2 focus:ring-primary';

include __DIR__ . '/includes/app_top.php';
?>
<div class="flex flex-col md:flex-row md:items-end justify-between gap-space-md mb-space-lg">
  <div class="max-w-2xl">
    <div class="flex items-center gap-space-xs text-on-surface-variant font-label-sm text-label-sm mb-space-xs"><span>JagaRaksa</span><span class="material-symbols-outlined text-sm">chevron_right</span><span>Manajemen</span><span class="material-symbols-outlined text-sm">chevron_right</span><span class="text-primary font-semibold">Kelola Laporan</span></div>
    <h1 class="font-headline-xl text-headline-xl tracking-tight">Kelola Laporan Masyarakat</h1>
    <p class="font-body-md text-body-md text-on-surface-variant mt-space-2xs">Daftar diurutkan otomatis: laporan terlambat lebih dulu, lalu prioritas tertinggi. Verifikasi, disposisikan ke dinas, dan perbarui status sebelum tenggat SLA berakhir.</p>
  </div>
  <div class="flex flex-wrap gap-space-xs">
    <a href="analitik.php" class="inline-flex items-center gap-space-xs px-space-md py-space-sm rounded bg-primary-fixed text-primary font-label-lg text-label-lg hover:opacity-90"><span class="material-symbols-outlined">insights</span>Analitik &amp; SLA</a>
    <button onclick="window.print()" class="inline-flex items-center gap-space-xs px-space-md py-space-sm rounded bg-surface-container-high font-label-lg text-label-lg hover:bg-surface-container-highest"><span class="material-symbols-outlined">print</span>Cetak Rekap</button>
  </div>
</div>

<div class="grid grid-cols-2 xl:grid-cols-5 gap-gutter-sm mb-space-lg">
  <?php foreach ([
    ['Total Laporan', $st['total'], '+' . $todayNew . ' hari ini · ' . $st['ditolak'] . ' ditolak', '', 'text-primary', ''],
    ['Verifikasi Segera', $st['pending'], $urgent . ' prioritas tinggi/darurat', 'border-l-4 border-amber-500', 'text-amber-800', 'kelola-laporan.php?status=pending'],
    ['Dalam Pengerjaan', $st['proses'], 'Ditangani petugas', '', 'text-primary', 'kelola-laporan.php?status=proses'],
    ['Terlambat (SLA)', $slaInfo['overdue'], 'Klik untuk menyaring', 'border-l-4 border-red-500', 'text-error', 'kelola-laporan.php?sla=overdue'],
    ['Selesai Minggu Ini', $doneWeek, 'Kepatuhan SLA ' . ($slaInfo['compliance'] === null ? '-' : $slaInfo['compliance'] . '%'), '', 'text-emerald-700', 'kelola-laporan.php?status=selesai'],
  ] as [$l, $v, $sub, $b, $c, $href]): ?>
  <<?= $href ? 'a href="' . e($href) . '"' : 'div' ?> class="block bg-surface-container-lowest rounded-xl shadow-sm p-space-md <?= $b ?> <?= $href ? 'hover:shadow-md transition-shadow' : '' ?>"><div class="font-label-md text-label-md text-on-surface-variant"><?= e($l) ?></div>
    <div class="flex items-end justify-between mt-space-xs gap-1"><span class="font-display-lg text-headline-xl"><?= number_format((int)$v) ?></span><span class="font-label-sm text-label-sm text-right <?= $c ?>"><?= e($sub) ?></span></div></<?= $href ? 'a' : 'div' ?>>
  <?php endforeach; ?>
</div>

<form method="get" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md mb-space-lg flex flex-col gap-space-sm">
  <div class="flex flex-wrap gap-space-sm">
    <div class="relative flex-1 min-w-[220px]"><span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant">search</span>
      <input name="q" value="<?= e($q) ?>" placeholder="Cari nomor tiket (JR-...), judul, lokasi" class="w-full pl-10 <?= $sel_cls ?> py-2"/></div>
    <select name="cat" class="<?= $sel_cls ?>"><option value="">Semua Kategori</option><?php foreach (categories() as $c): ?><option value="<?= $c['id'] ?>" <?= $fCat === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
    <select name="status" class="<?= $sel_cls ?>"><option value="">Semua Status</option><?php foreach (STATUS_META as $k => $m): ?><option value="<?= $k ?>" <?= $fStat === $k ? 'selected' : '' ?>><?= e($m['label']) ?></option><?php endforeach; ?></select>
    <select name="prio" class="<?= $sel_cls ?>"><option value="">Semua Prioritas</option><?php foreach (PRIORITY_META as $k => $m): ?><option value="<?= $k ?>" <?= $fPrio === $k ? 'selected' : '' ?>><?= e($m['label']) ?></option><?php endforeach; ?></select>
    <select name="sla" class="<?= $sel_cls ?>"><option value="">Semua SLA</option><option value="overdue" <?= $fSla === 'overdue' ? 'selected' : '' ?>>Terlambat</option><option value="escalated" <?= $fSla === 'escalated' ? 'selected' : '' ?>>Tereskalasi</option></select>
    <select name="dist" class="<?= $sel_cls ?>"><option value="">Semua Wilayah</option><?php foreach (districts() as $d): ?><option value="<?= $d['id'] ?>" <?= $fDist === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select>
    <input type="month" name="bulan" value="<?= e($fMonth) ?>" class="<?= $sel_cls ?>"/>
  </div>
  <div class="flex flex-wrap items-center justify-between gap-space-sm">
    <span class="font-label-sm text-label-sm text-on-surface-variant">Menampilkan <?= count($rows) ?> dari <?= number_format($totalFiltered) ?> laporan<?= $showArchive ? ' (ARSIP)' : '' ?> • <a href="kelola-laporan.php" class="text-primary font-semibold">Reset Filter</a>
      <?php if ($isAdmin): ?> • <?php if ($showArchive): ?><a href="kelola-laporan.php" class="text-primary font-semibold">Kembali ke laporan aktif</a><?php else: ?><a href="kelola-laporan.php?arsip=1" class="text-primary font-semibold">Arsip (<?= $archived ?>)</a><?php endif; ?><?php endif; ?></span>
    <button class="px-space-lg py-space-xs rounded bg-primary text-on-primary font-label-md text-label-md">Terapkan Filter</button>
  </div>
</form>

<div class="grid grid-cols-1 2xl:grid-cols-12 gap-gutter-sm items-start">
  <div class="2xl:col-span-7 bg-surface-container-lowest rounded-xl shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-left">
      <thead class="bg-surface-container-low font-label-sm text-label-sm text-on-surface-variant uppercase tracking-wide"><tr><th class="p-space-md">Tiket &amp; Tgl</th><th class="p-space-md">Uraian Kasus</th><th class="p-space-md">Lokasi / Wilayah</th><th class="p-space-md">Status &amp; SLA</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="4" class="p-space-xl text-center text-on-surface-variant">Tidak ada laporan yang cocok dengan filter.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): $isSel = $sel && (int)$sel['id'] === (int)$r['id']; $isNew = strtotime($r['created_at']) > time() - 86400; ?>
        <tr class="<?= $isSel ? 'bg-primary-fixed/40' : 'hover:bg-surface-container-low' ?> cursor-pointer align-top" onclick="location='kelola-laporan.php?<?= $qs(['id' => $r['id']]) ?>'">
          <td class="p-space-md font-body-sm text-body-sm whitespace-nowrap"><a class="font-bold text-primary" href="kelola-laporan.php?<?= $qs(['id' => $r['id']]) ?>">#<?= e($r['ticket_code']) ?></a><br><span class="text-on-surface-variant"><?= e(format_tanggal($r['created_at'], true)) ?></span><?php if ($isNew): ?><br><span class="inline-block mt-1 px-1.5 rounded bg-error text-on-error font-label-sm text-label-sm">Baru</span><?php endif; ?></td>
          <td class="p-space-md"><div class="font-label-lg text-label-lg line-clamp-1"><?= e($r['title']) ?></div><div class="font-label-sm text-label-sm text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[14px]"><?= e($r['cat_icon'] ?? 'report') ?></span><?= e($r['cat_name']) ?></div>
            <div class="font-body-sm text-body-sm text-on-surface-variant">Pelapor: <?= $r['is_anonymous'] ? 'Anonim (#USR-' . (int)$r['user_id'] . ')' : e($r['reporter']) ?></div></td>
          <td class="p-space-md font-body-sm text-body-sm"><?= e($r['address']) ?><br><span class="text-on-surface-variant">Kec. <?= e($r['district_name'] ?? '-') ?></span></td>
          <td class="p-space-md"><div class="flex flex-col items-start gap-1"><?= status_badge($r['status']) ?><?= priority_badge($r['priority']) ?><?= sla_badge($r) ?>
            <?php if ((int)$r['escalation_level'] > 0 && in_array($r['status'], ['pending', 'proses'], true)): ?><span class="font-label-sm text-label-sm text-orange-700 flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">priority_high</span>Eskalasi <?= (int)$r['escalation_level'] ?></span><?php endif; ?>
            <?php if ($r['agency_code']): ?><span class="font-label-sm text-label-sm text-on-surface-variant">Dinas <?= e($r['agency_code']) ?></span><?php endif; ?></div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="flex items-center justify-between p-space-md bg-surface-container-low/50">
      <span class="font-body-sm text-body-sm text-on-surface-variant">Halaman <?= $page ?> dari <?= $pages ?></span>
      <div class="flex items-center gap-1">
        <?php if ($page > 1): ?><a class="px-3 py-1 rounded hover:bg-surface-container" href="?<?= $qs(['page' => $page - 1, 'id' => '']) ?>">‹</a><?php endif; ?>
        <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?><a class="px-3 py-1 rounded <?= $p === $page ? 'bg-primary text-on-primary' : 'hover:bg-surface-container' ?>" href="?<?= $qs(['page' => $p, 'id' => '']) ?>"><?= $p ?></a><?php endfor; ?>
        <?php if ($page < $pages): ?><a class="px-3 py-1 rounded hover:bg-surface-container" href="?<?= $qs(['page' => $page + 1, 'id' => '']) ?>">›</a><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="2xl:col-span-5 bg-surface-container-lowest rounded-xl shadow-md p-space-lg 2xl:sticky 2xl:top-24">
  <?php if (!$sel): ?><p class="text-on-surface-variant">Pilih laporan pada tabel untuk melihat detail.</p>
  <?php else:
    $isArchived = $sel['deleted_at'] !== null;
    $allowed = array_merge([$sel['status']], STATUS_FLOW[$sel['status']] ?? []);
    $slaS = sla_state($sel);
  ?>
    <div class="flex items-start justify-between gap-2 mb-space-xs"><h3 class="font-headline-md text-headline-md">Detail Laporan #<?= e($sel['ticket_code']) ?></h3><?= status_badge($sel['status']) ?></div>
    <p class="font-body-sm text-body-sm text-on-surface-variant mb-space-xs">Diterima: <?= e(format_tanggal($sel['created_at'], true)) ?></p>
    <div class="flex flex-wrap items-center gap-2 mb-space-sm"><?= priority_badge($sel['priority']) ?><?= sla_badge($sel) ?>
      <a href="lacak.php?tiket=<?= urlencode($sel['ticket_code']) ?>" target="_blank" class="font-label-sm text-label-sm text-primary hover:underline inline-flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">open_in_new</span>Tampilan publik</a></div>
    <?php if ($isArchived): ?>
      <div class="p-space-sm rounded-lg bg-slate-100 text-slate-800 mb-space-sm font-body-sm text-body-sm">
        <b>Diarsipkan</b> <?= e(format_tanggal($sel['deleted_at'], true)) ?>. Alasan: <?= e($sel['delete_reason'] ?? '-') ?>
        <?php if ($isAdmin): ?>
        <form method="post" class="mt-space-xs"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$sel['id'] ?>"><input type="hidden" name="back_qs" value="<?= e($qs()) ?>">
          <button name="do" value="restore" class="px-space-md py-space-xs rounded bg-primary text-on-primary font-label-md text-label-md">Pulihkan Laporan</button></form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if (!$isArchived && (int)$sel['escalation_level'] > 0 && in_array($sel['status'], ['pending', 'proses'], true)): ?>
      <div class="p-space-sm rounded-lg bg-orange-50 text-orange-900 mb-space-sm font-body-sm text-body-sm"><b>Eskalasi tingkat <?= (int)$sel['escalation_level'] ?></b> sejak <?= e(format_tanggal($sel['escalated_at'], true)) ?>. Segera tindak lanjuti atau perbarui status.</div>
    <?php endif; ?>
    <div class="grid grid-cols-2 gap-space-sm mb-space-sm">
      <?php $ph = array_values(array_filter($selAtts, fn($x) => $x['kind'] === 'bukti'))[0] ?? null; ?>
      <div class="relative h-32 rounded-lg overflow-hidden bg-surface-container"><?php if ($ph): ?><a href="<?= e(UPLOAD_URL . $ph['file_path']) ?>" target="_blank"><img class="w-full h-full object-cover" src="<?= e(UPLOAD_URL . $ph['file_path']) ?>" alt="Foto"/></a><?php else: ?><div class="w-full h-full flex items-center justify-center text-outline"><span class="material-symbols-outlined text-4xl">image</span></div><?php endif; ?>
        <span class="absolute bottom-1 left-1 px-2 rounded bg-black/60 text-white font-label-sm text-label-sm">Foto Pelapor (<?= count($selAtts) ?>)</span></div>
      <div id="selMap" class="h-32 rounded-lg overflow-hidden"></div>
    </div>
    <div class="p-space-sm rounded-lg bg-surface-container-low mb-space-sm">
      <div class="font-label-sm text-label-sm text-on-surface-variant uppercase mb-1">Uraian Aduan Warga:</div>
      <div class="font-label-lg text-label-lg mb-1"><?= e($sel['title']) ?></div>
      <p class="font-body-sm text-body-sm whitespace-pre-line">“<?= e($sel['description']) ?>”</p>
      <div class="flex justify-between mt-space-xs font-body-sm text-body-sm text-on-surface-variant"><span class="flex items-center gap-1"><span class="material-symbols-outlined text-base">person</span><?= e($sel['reporter']) ?><?= $sel['reporter_phone'] ? ' (' . e($sel['reporter_phone']) . ')' : '' ?><?= $sel['is_anonymous'] ? ' • anonim publik' : '' ?></span></div>
    </div>
    <div class="grid grid-cols-2 gap-space-sm mb-space-sm font-body-sm text-body-sm text-on-surface-variant">
      <div class="p-space-sm rounded bg-surface-container-low">Target verifikasi<br><b class="text-on-surface"><?= $sel['respond_due_at'] ? e(format_tanggal($sel['respond_due_at'], true)) : '-' ?></b></div>
      <div class="p-space-sm rounded bg-surface-container-low">Target selesai<br><b class="text-on-surface"><?= $sel['resolve_due_at'] ? e(format_tanggal($sel['resolve_due_at'], true)) : '-' ?></b></div>
    </div>
    <?php if (!$isArchived): ?>
    <form method="post" enctype="multipart/form-data" class="flex flex-col gap-space-sm">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$sel['id'] ?>"><input type="hidden" name="back_qs" value="<?= e($qs()) ?>">
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-space-sm">
        <label class="flex flex-col gap-1 font-label-sm text-label-sm">Dinas Penanggung Jawab
          <select name="agency_id" class="<?= $sel_cls ?>"><option value="">- Belum disposisi -</option><?php foreach (agencies() as $a): ?><option value="<?= $a['id'] ?>" <?= (int)$sel['agency_id'] === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></label>
        <label class="flex flex-col gap-1 font-label-sm text-label-sm">Prioritas
          <select name="priority" class="<?= $sel_cls ?>"><?php foreach (PRIORITY_META as $k => $m): ?><option value="<?= $k ?>" <?= $sel['priority'] === $k ? 'selected' : '' ?>><?= e($m['label']) ?> (<?= e(hours_text(SLA_HOURS[$k]['respond'])) ?> / <?= e(hours_text(SLA_HOURS[$k]['resolve'])) ?>)</option><?php endforeach; ?></select></label>
        <label class="flex flex-col gap-1 font-label-sm text-label-sm">Ubah Status Tiket
          <select name="status" id="statusSel" class="<?= $sel_cls ?>"><?php foreach ($allowed as $k): ?><option value="<?= $k ?>" <?= $sel['status'] === $k ? 'selected' : '' ?>><?= e(STATUS_META[$k]['label']) ?></option><?php endforeach; ?></select></label>
      </div>
      <label class="flex flex-col gap-1 font-label-sm text-label-sm"><span class="flex justify-between"><span id="msgLbl">Tanggapan Resmi untuk Pelapor</span><span id="mc">0/300</span></span>
        <textarea name="message" id="msgBox" maxlength="300" rows="3" oninput="document.getElementById('mc').textContent=this.value.length+'/300'" class="<?= $sel_cls ?> resize-none" placeholder="Tim teknis akan meninjau lokasi besok pukul 13.00 WIB..."></textarea></label>
      <label id="doneBox" class="<?= $sel['status'] === 'selesai' ? '' : 'hidden' ?> flex flex-col gap-1 font-label-sm text-label-sm">Foto bukti penyelesaian (opsional, JPG/PNG)
        <input type="file" name="done_photos[]" accept="image/jpeg,image/png" multiple class="font-body-sm"/></label>
      <label class="flex items-center gap-2 font-body-sm text-body-sm"><input type="checkbox" name="notify" value="1" checked class="accent-primary w-4 h-4"/>Kirim notifikasi otomatis ke pelapor</label>
      <div class="flex items-center justify-end gap-2 pt-1">
        <a href="kelola-laporan.php?<?= $qs(['id' => '']) ?>" class="px-space-md py-space-xs rounded bg-surface-container font-label-md text-label-md">Batal</a>
        <button name="do" value="save" class="inline-flex items-center gap-1 px-space-md py-space-xs rounded bg-primary text-on-primary font-label-md text-label-md"><span class="material-symbols-outlined text-lg">done_all</span>Simpan &amp; Kirim</button>
      </div>
    </form>
    <?php if ($isAdmin): ?>
    <?php if ($isAdmin): ?>
    <details class="mt-space-sm">
      <summary class="cursor-pointer text-error font-label-md text-label-md inline-flex items-center gap-1"><span class="material-symbols-outlined text-lg">inventory_2</span>Arsipkan laporan</summary>
      <form method="post" class="flex flex-col gap-space-xs mt-space-xs"><?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$sel['id'] ?>"><input type="hidden" name="back_qs" value="<?= e($qs()) ?>">
        <textarea name="reason" rows="2" minlength="5" maxlength="255" required class="<?= $sel_cls ?> resize-none" placeholder="Alasan pengarsipan (wajib, mis. spam / duplikat #JR-...)"></textarea>
        <span class="font-body-sm text-body-sm text-on-surface-variant">Laporan disembunyikan dari daftar ...</span>
        <button name="do" value="delete" onclick="return confirm('Arsipkan laporan ini?')" class="self-start px-space-md py-space-xs rounded bg-error text-on-error font-label-md text-label-md">Arsipkan</button>
      </form>
    </details>
    <?php endif; ?>
    <?php endif; ?>
    <details class="mt-space-md">
      <summary class="cursor-pointer font-label-md text-label-md inline-flex items-center gap-1"><span class="material-symbols-outlined text-lg text-primary">history</span>Riwayat aktivitas (<?= count($selLogs) ?> terbaru)</summary>
      <div class="mt-space-xs">
        <?php foreach ($selLogs as $l): ?>
          <div class="border-l-2 border-primary-fixed-dim pl-space-sm pb-space-xs">
            <div class="font-label-sm text-label-sm text-on-surface-variant"><?= e(format_tanggal($l['created_at'], true)) ?> • <?= e($l['uname'] ?? 'Sistem') ?><?= $l['entry_hash'] ? ' • #' . e(substr($l['entry_hash'], 0, 8)) : '' ?></div>
            <div class="font-body-sm text-body-sm"><?= e($l['note'] ?? $l['action']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </details>
    <script>
      (function(){
        const sel=document.getElementById('statusSel'); if(sel){
          const upd=()=>{ document.getElementById('doneBox').classList.toggle('hidden',sel.value!=='selesai');
            const rej=sel.value==='ditolak'&&<?= json_encode($sel['status'] !== 'ditolak') ?>;
            document.getElementById('msgBox').required=rej;
            document.getElementById('msgLbl').textContent=rej?'Alasan penolakan (wajib, akan dibaca pelapor)':'Tanggapan Resmi untuk Pelapor'; };
          sel.addEventListener('change',upd); upd(); }
        const m=L.map('selMap',{zoomControl:false,scrollWheelZoom:false,dragging:false}).setView([<?= (float)$sel['latitude'] ?>,<?= (float)$sel['longitude'] ?>],15);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19}).addTo(m);
        L.circleMarker([<?= (float)$sel['latitude'] ?>,<?= (float)$sel['longitude'] ?>],{radius:9,color:'#fff',weight:2,fillColor:'<?= STATUS_META[$sel['status']]['color'] ?>',fillOpacity:1}).addTo(m);
      })();
    </script>
  <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/includes/app_bottom.php'; ?>
