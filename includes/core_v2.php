<?php
/**
 * JagaRaksa v2.1 - inti tambahan (dimuat otomatis dari config.php)
 *
 *  1. Prioritas & SLA        -> tenggat verifikasi/penyelesaian per prioritas
 *  2. Eskalasi otomatis      -> tanpa cron (dipicu saat petugas membuka halaman) atau via cron_eskalasi.php
 *  3. Notifikasi terarah     -> ke petugas instansi terkait + admin, bukan ke semua orang
 *  4. Jejak audit berantai   -> hash chain SHA-256 pada report_logs & audit_log (deteksi pengubahan data)
 *  5. Data publik            -> pelacakan tiket & statistik transparansi TANPA data pribadi
 *  6. Komponen UI            -> stepper progres, badge prioritas, badge SLA
 */

/* ------------------------------ Konstanta ------------------------------ */

const PRIORITY_ORDER = ['rendah', 'sedang', 'tinggi', 'darurat'];

const PRIORITY_META = [
    'rendah'  => ['label' => 'Rendah',  'badge' => 'bg-slate-100 text-slate-700',   'color' => '#64748b'],
    'sedang'  => ['label' => 'Sedang',  'badge' => 'bg-sky-100 text-sky-800',       'color' => '#0284c7'],
    'tinggi'  => ['label' => 'Tinggi',  'badge' => 'bg-orange-100 text-orange-800', 'color' => '#ea580c'],
    'darurat' => ['label' => 'Darurat', 'badge' => 'bg-red-100 text-red-800',       'color' => '#dc2626'],
];

/** Jam. respond = batas verifikasi / respons pertama, resolve = batas penyelesaian. Sesuaikan dengan kebijakan dinas. */
const SLA_HOURS = [
    'darurat' => ['respond' => 12, 'resolve' => 72],
    'tinggi'  => ['respond' => 24, 'resolve' => 120],
    'sedang'  => ['respond' => 24, 'resolve' => 168],
    'rendah'  => ['respond' => 48, 'resolve' => 240],
];

const ESCALATION_INTERVAL_SEC   = 300;  // pemeriksaan eskalasi otomatis paling sering tiap 5 menit
const ESCALATION_L2_AFTER_HOURS = 24;   // terlambat > 24 jam sejak tenggat -> eskalasi tingkat 2 (ke admin)
const REOPEN_WINDOW_DAYS        = 14;   // warga boleh membuka kembali laporan selesai dalam 14 hari

/** Alur status yang diizinkan: dari => [tujuan...] */
const STATUS_FLOW = [
    'pending' => ['proses', 'ditolak'],
    'proses'  => ['selesai', 'ditolak'],
    'selesai' => ['proses'],
    'ditolak' => ['pending'],
];

/* ------------------------------ Prioritas & SLA ------------------------------ */

function priority_from_impact($impact)
{
    return $impact === 'bahaya' ? 'darurat' : 'sedang';
}

function sla_hours($priority)
{
    return SLA_HOURS[$priority] ?? SLA_HOURS['sedang'];
}

/** @return array [respond_due_at, resolve_due_at] sebagai string DATETIME */
function sla_due_dates($priority, $fromTs = null)
{
    $h = sla_hours($priority);
    $t = $fromTs ?: time();
    return [date('Y-m-d H:i:s', $t + $h['respond'] * 3600), date('Y-m-d H:i:s', $t + $h['resolve'] * 3600)];
}

function status_transition_ok($from, $to)
{
    return $from === $to || in_array($to, STATUS_FLOW[$from] ?? [], true);
}

function hours_text($h)
{
    return $h >= 48 ? round($h / 24) . ' hari' : $h . ' jam';
}

function dur_text($sec)
{
    $sec = abs((int)$sec);
    if ($sec < 3600) {
        return max(1, (int)floor($sec / 60)) . ' menit';
    }
    if ($sec < 86400) {
        return (int)floor($sec / 3600) . ' jam';
    }
    $d = (int)floor($sec / 86400);
    $h = (int)floor(($sec % 86400) / 3600);
    return $d . ' hari' . ($h ? ' ' . $h . ' jam' : '');
}

/** Ekspresi SQL "sudah lewat tenggat" untuk laporan terbuka. $a = alias/nama tabel reports. */
function sql_overdue_expr($a = 'r')
{
    return "(CASE WHEN {$a}.status = 'pending' THEN {$a}.respond_due_at ELSE {$a}.resolve_due_at END) < NOW()";
}

/** Status SLA satu laporan. Butuh kolom: status, respond_due_at, resolve_due_at, resolved_at. */
function sla_state($r)
{
    $st = $r['status'];
    if ($st === 'ditolak') {
        return ['key' => 'closed', 'label' => 'Ditutup', 'badge' => 'bg-slate-100 text-slate-700', 'late' => false, 'what' => '', 'left' => 0];
    }
    if ($st === 'selesai') {
        $ok = empty($r['resolve_due_at']) || empty($r['resolved_at'])
            || strtotime($r['resolved_at']) <= strtotime($r['resolve_due_at']);
        return $ok
            ? ['key' => 'done_ok', 'label' => 'Selesai tepat waktu', 'badge' => 'bg-emerald-100 text-emerald-800', 'late' => false, 'what' => '', 'left' => 0]
            : ['key' => 'done_late', 'label' => 'Selesai terlambat', 'badge' => 'bg-amber-100 text-amber-800', 'late' => true, 'what' => '', 'left' => 0];
    }
    $due  = $st === 'pending' ? ($r['respond_due_at'] ?? null) : ($r['resolve_due_at'] ?? null);
    $what = $st === 'pending' ? 'verifikasi' : 'penyelesaian';
    if (!$due) {
        return ['key' => 'none', 'label' => 'Tanpa SLA', 'badge' => 'bg-slate-100 text-slate-700', 'late' => false, 'what' => $what, 'left' => 0];
    }
    $left = strtotime($due) - time();
    if ($left < 0) {
        return ['key' => 'overdue', 'label' => 'Terlambat ' . dur_text($left), 'badge' => 'bg-red-100 text-red-800', 'late' => true, 'what' => $what, 'left' => $left];
    }
    if ($left < 6 * 3600) {
        return ['key' => 'warning', 'label' => 'Sisa ' . dur_text($left), 'badge' => 'bg-amber-100 text-amber-800', 'late' => false, 'what' => $what, 'left' => $left];
    }
    return ['key' => 'ok', 'label' => 'Sisa ' . dur_text($left), 'badge' => 'bg-emerald-100 text-emerald-800', 'late' => false, 'what' => $what, 'left' => $left];
}

/** Ringkasan SLA seluruh kota (dipakai sidebar & dashboard). */
function sla_overview()
{
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    $c = ['overdue' => 0, 'compliance' => null];
    try {
        $c['overdue'] = (int)db()->query("SELECT COUNT(*) FROM reports WHERE deleted_at IS NULL AND status IN ('pending','proses') AND " . sql_overdue_expr('reports'))->fetchColumn();
        $row = db()->query("SELECT COUNT(*) n, SUM(resolved_at <= resolve_due_at) ok FROM reports
                            WHERE deleted_at IS NULL AND status = 'selesai' AND resolved_at IS NOT NULL AND resolve_due_at IS NOT NULL")->fetch();
        $c['compliance'] = ($row && (int)$row['n'] > 0) ? round((int)$row['ok'] / (int)$row['n'] * 100, 1) : null;
    } catch (Throwable $ex) {
    }
    return $c;
}

/* ------------------------------ Notifikasi terarah ------------------------------ */

/** Kirim ke admin + petugas pada instansi terkait (jika instansi kosong: semua petugas). */
function notify_staff($agencyId, $reportId, $msg, $exceptUserId = null)
{
    $sql = "SELECT id FROM users WHERE is_active = 1 AND (role = 'admin' OR "
         . ($agencyId ? "(role = 'petugas' AND agency_id = ?)" : "role = 'petugas'") . ')';
    $st = db()->prepare($sql);
    $st->execute($agencyId ? [(int)$agencyId] : []);
    $n = 0;
    foreach ($st->fetchAll() as $u) {
        if ($exceptUserId !== null && (int)$u['id'] === (int)$exceptUserId) {
            continue;
        }
        notify((int)$u['id'], $reportId, $msg);
        $n++;
    }
    return $n;
}

function notify_admins($reportId, $msg)
{
    $st = db()->query("SELECT id FROM users WHERE is_active = 1 AND role = 'admin'");
    foreach ($st->fetchAll() as $u) {
        notify((int)$u['id'], $reportId, $msg);
    }
}

/* ------------------------------ Eskalasi otomatis ------------------------------ */

/**
 * Tingkat 1: melewati tenggat tahap berjalan -> beri tahu petugas instansi + admin + pelapor.
 * Tingkat 2: terlambat > ESCALATION_L2_AFTER_HOURS -> beri tahu seluruh admin & naikkan prioritas satu tingkat.
 * Aman dipanggil berulang (idempoten, dibatasi ESCALATION_INTERVAL_SEC kecuali $force).
 * @return int jumlah laporan yang dieskalasikan
 */
function run_escalation($force = false)
{
    $stamp = __DIR__ . '/../storage/escalation.stamp';
    if (!$force && is_file($stamp) && (time() - (int)filemtime($stamp)) < ESCALATION_INTERVAL_SEC) {
        return 0;
    }
    @touch($stamp);
    try {
        $rows = db()->query("SELECT id, ticket_code, title, status, priority, agency_id, user_id, respond_due_at, resolve_due_at, escalation_level
                             FROM reports WHERE deleted_at IS NULL AND status IN ('pending','proses') AND escalation_level < 2")->fetchAll();
    } catch (Throwable $ex) {
        return 0;
    }
    $count = 0;
    foreach ($rows as $r) {
        try {
            $due = $r['status'] === 'pending' ? $r['respond_due_at'] : $r['resolve_due_at'];
            if (!$due) {
                continue;
            }
            $late = time() - strtotime($due);
            if ($late <= 0) {
                continue;
            }
            $cur    = (int)$r['escalation_level'];
            $target = $late > ESCALATION_L2_AFTER_HOURS * 3600 ? 2 : 1;
            if ($target <= $cur) {
                continue;
            }
            $newPrio = $r['priority'];
            if ($target === 2) {
                $i       = array_search($r['priority'], PRIORITY_ORDER, true);
                $newPrio = PRIORITY_ORDER[min(count(PRIORITY_ORDER) - 1, ($i === false ? 1 : $i) + 1)];
            }
            $u = db()->prepare('UPDATE reports SET escalation_level = ?, escalated_at = NOW(), priority = ? WHERE id = ? AND escalation_level < ?');
            $u->execute([$target, $newPrio, (int)$r['id'], $target]);
            if ($u->rowCount() < 1) {
                continue;   // sudah diproses permintaan lain
            }
            $stage = $r['status'] === 'pending' ? 'batas verifikasi' : 'batas penyelesaian';
            $code  = $r['ticket_code'];
            if ($target === 1) {
                $note = 'Melewati ' . $stage . ' (terlambat ' . dur_text($late) . '). Eskalasi tingkat 1 ke instansi penanggung jawab.';
            } else {
                $note = 'Masih terlambat ' . dur_text($late) . ' dari ' . $stage . '. Eskalasi tingkat 2 ke admin; prioritas ' . ($newPrio !== $r['priority'] ? 'dinaikkan menjadi ' . PRIORITY_META[$newPrio]['label'] : 'dipertahankan Darurat') . '.';
            }
            log_report((int)$r['id'], null, 'eskalasi', $note, (string)$cur, (string)$target);
            notify_staff($r['agency_id'] ? (int)$r['agency_id'] : null, (int)$r['id'], '[SLA] #' . $code . ' melewati ' . $stage . ': ' . $r['title']);
            if ($target === 2) {
                notify_admins((int)$r['id'], '[ESKALASI 2] #' . $code . ' terlambat ' . dur_text($late) . ' dan butuh keputusan admin.');
            }
            notify((int)$r['user_id'], (int)$r['id'], 'Laporan #' . $code . ' melewati ' . $stage . ' dan telah dieskalasikan agar segera ditindaklanjuti.');
            $count++;
        } catch (Throwable $ex) {
            // satu laporan gagal tidak boleh menghentikan yang lain
        }
    }
    return $count;
}

/* ------------------------------ Jejak audit berantai (hash chain) ------------------------------ */

function chain_hash($prev, array $fields)
{
    $f = array_map(function ($v) {
        return $v === null ? '' : (string)$v;
    }, $fields);
    return hash('sha256', ($prev === null ? '' : $prev) . '|' . json_encode($f, JSON_UNESCAPED_UNICODE));
}

/** Catat aksi administratif/sistem (pengguna, kategori, arsip, dll.) dalam rantai hash global. Tidak pernah menggagalkan proses utama. */
function audit($action, $entity, $entityId = null, $detail = null)
{
    try {
        $pdo    = db();
        $u      = current_user();
        $uid    = $u ? (int)$u['id'] : null;
        $ts     = date('Y-m-d H:i:s');
        $ip     = client_ip();
        $eid    = $entityId === null ? null : mb_substr((string)$entityId, 0, 64);
        $detail = $detail === null ? null : mb_substr($detail, 0, 500);
        $prev   = $pdo->query('SELECT entry_hash FROM audit_log ORDER BY id DESC LIMIT 1')->fetchColumn() ?: null;
        $hash   = chain_hash($prev, [$uid, $action, $entity, $eid, $detail, $ip, $ts]);
        $pdo->prepare('INSERT INTO audit_log (user_id, action, entity, entity_id, detail, ip, prev_hash, entry_hash, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$uid, $action, $entity, $eid, $detail, $ip, $prev, $hash, $ts]);
    } catch (Throwable $ex) {
        // tabel audit_log belum ada (migrasi belum dijalankan) -> abaikan
    }
}

/* ------------------------------ Log aktivitas laporan (berantai) ------------------------------ */

/**
 * Catat aktivitas laporan. Setiap baris menyimpan nilai lama/baru, IP, dan hash yang
 * mengait ke baris sebelumnya pada laporan yang sama (hash chain) sehingga pengubahan diam-diam
 * pada riwayat dapat dideteksi (lihat verify_report_chain & halaman Audit).
 */
function log_report($reportId, $userId, $action, $note = null, $old = null, $new = null)
{
    $pdo  = db();
    $uid  = $userId === null ? null : (int)$userId;
    $ts   = date('Y-m-d H:i:s');
    $ip   = client_ip();
    $note = $note === null ? null : mb_substr((string)$note, 0, 500);
    $old  = $old === null ? null : mb_substr((string)$old, 0, 100);
    $new  = $new === null ? null : mb_substr((string)$new, 0, 100);
    $p    = $pdo->prepare('SELECT entry_hash FROM report_logs WHERE report_id = ? AND entry_hash IS NOT NULL ORDER BY id DESC LIMIT 1');
    $p->execute([(int)$reportId]);
    $prev = $p->fetchColumn() ?: null;
    $hash = chain_hash($prev, [(int)$reportId, $uid, $action, $note, $old, $new, $ip, $ts]);
    $pdo->prepare('INSERT INTO report_logs (report_id, user_id, action, note, old_value, new_value, ip, prev_hash, entry_hash, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([(int)$reportId, $uid, $action, $note, $old, $new, $ip, $prev, $hash, $ts]);
}

/** Verifikasi rantai report_logs (satu laporan atau semua). Baris lama tanpa hash dihitung sebagai 'legacy'. */
function verify_report_chain($reportId = null)
{
    $res  = ['checked' => 0, 'legacy' => 0, 'broken' => []];
    $prev = [];
    $sql  = 'SELECT * FROM report_logs' . ($reportId ? ' WHERE report_id = ' . (int)$reportId : '') . ' ORDER BY report_id, id';
    try {
        foreach (db()->query($sql)->fetchAll() as $r) {
            if ($r['entry_hash'] === null) {
                $res['legacy']++;
                continue;
            }
            $rid = (int)$r['report_id'];
            $p   = $prev[$rid] ?? null;
            $exp = chain_hash($p, [$r['report_id'], $r['user_id'], $r['action'], $r['note'], $r['old_value'], $r['new_value'], $r['ip'], $r['created_at']]);
            $res['checked']++;
            if (!hash_equals($exp, (string)$r['entry_hash']) || ($r['prev_hash'] ?? null) !== $p) {
                $res['broken'][] = ['id' => (int)$r['id'], 'report_id' => $rid];
            }
            $prev[$rid] = $r['entry_hash'];
        }
    } catch (Throwable $ex) {
        $res['error'] = true;
    }
    return $res;
}

function verify_audit_chain()
{
    $res  = ['checked' => 0, 'broken' => []];
    $prev = null;
    try {
        foreach (db()->query('SELECT * FROM audit_log ORDER BY id')->fetchAll() as $r) {
            $exp = chain_hash($prev, [$r['user_id'], $r['action'], $r['entity'], $r['entity_id'], $r['detail'], $r['ip'], $r['created_at']]);
            $res['checked']++;
            if (!hash_equals($exp, (string)$r['entry_hash']) || ($r['prev_hash'] ?? null) !== $prev) {
                $res['broken'][] = ['id' => (int)$r['id']];
            }
            $prev = $r['entry_hash'];
        }
    } catch (Throwable $ex) {
        $res['error'] = true;
    }
    return $res;
}

/* ------------------------------ Data publik (tanpa data pribadi) ------------------------------ */

function normalize_ticket($s)
{
    $s = ltrim(strtoupper(trim((string)$s)), '#');
    return preg_match('/^JR-\d{4}-\d{4,6}$/', $s) ? $s : '';
}

function public_log_text($l)
{
    switch ($l['action']) {
        case 'dibuat':
            return 'Laporan diterima dan menunggu verifikasi petugas.';
        case 'status':
            $s = (string)($l['new_value'] ?? '');
            if (isset(STATUS_META[$s])) {
                return 'Status berubah menjadi ' . STATUS_META[$s]['label'] . '.';
            }
            break;
        case 'disposisi':
            return !empty($l['new_value']) ? 'Didisposisikan ke ' . $l['new_value'] . '.' : 'Didisposisikan ke instansi terkait.';
        case 'tanggapan':
            return 'Petugas menambahkan tanggapan resmi.';
        case 'prioritas':
            return 'Prioritas penanganan diperbarui menjadi ' . (PRIORITY_META[(string)($l['new_value'] ?? '')]['label'] ?? '-') . '.';
        case 'eskalasi':
            return 'Melewati batas waktu layanan dan dieskalasikan ke tingkat ' . (($l['new_value'] ?? '') !== '' ? $l['new_value'] : '1') . '.';
        case 'buka_kembali':
            return 'Pelapor membuka kembali laporan karena penanganan dinilai belum tuntas.';
        case 'dipulihkan':
            return 'Laporan dipulihkan dan kembali ditinjau.';
    }
    // data lama: buang nama petugas ("... oleh Nama") agar tidak tampil di halaman publik
    $n = trim((string)preg_replace('/\s+oleh\s+.+$/u', '', (string)($l['note'] ?? '')));
    if ($n === '') {
        return 'Pembaruan laporan.';
    }
    return substr($n, -1) === '.' ? $n : $n . '.';
}

/** Data laporan yang aman ditampilkan ke publik berdasarkan kode tiket. null jika tidak ada / diarsipkan. */
function public_report($code)
{
    $code = normalize_ticket($code);
    if ($code === '') {
        return null;
    }
    $st = db()->prepare('SELECT r.id, r.ticket_code, r.title, r.description, r.address, r.latitude, r.longitude, r.impact_level, r.priority,
                                r.status, r.created_at, r.resolved_at, r.respond_due_at, r.resolve_due_at, r.first_response_at, r.escalation_level,
                                c.name cat_name, a.name agency_name, a.code agency_code, d.name district_name
                         FROM reports r JOIN categories c ON c.id = r.category_id
                         LEFT JOIN agencies a ON a.id = r.agency_id LEFT JOIN districts d ON d.id = r.district_id
                         WHERE r.ticket_code = ? AND r.deleted_at IS NULL');
    $st->execute([$code]);
    $r = $st->fetch();
    if (!$r) {
        return null;
    }
    $lg = db()->prepare('SELECT action, note, old_value, new_value, created_at FROM report_logs WHERE report_id = ? ORDER BY id');
    $lg->execute([$r['id']]);
    $timeline = [];
    foreach ($lg->fetchAll() as $l) {
        $timeline[] = ['time' => $l['created_at'], 'action' => $l['action'], 'text' => public_log_text($l)];
    }
    $rs = db()->prepare('SELECT rr.message, rr.created_at, u.role, ag.code agency_code
                         FROM report_responses rr JOIN users u ON u.id = rr.user_id LEFT JOIN agencies ag ON ag.id = u.agency_id
                         WHERE rr.report_id = ? ORDER BY rr.id');
    $rs->execute([$r['id']]);
    $responses = [];
    foreach ($rs->fetchAll() as $x) {
        $responses[] = ['message' => $x['message'], 'time' => $x['created_at'],
                        'by' => $x['role'] === 'admin' ? 'Admin Kota' : 'Petugas ' . ($x['agency_code'] ?: 'Lapangan')];
    }
    $ph = db()->prepare("SELECT file_path FROM report_attachments WHERE report_id = ? AND kind = 'penyelesaian' ORDER BY id");
    $ph->execute([$r['id']]);
    return [
        'report'      => $r,
        'timeline'    => $timeline,
        'responses'   => $responses,
        'done_photos' => array_map(function ($x) {
            return UPLOAD_URL . $x['file_path'];
        }, $ph->fetchAll()),
        'integrity'   => verify_report_chain((int)$r['id']),
    ];
}

/** Statistik agregat untuk halaman transparansi & API publik. Tidak memuat identitas siapa pun. */
function public_stats()
{
    $s   = stats_overview();
    $sla = sla_overview();
    $o   = [
        'total' => $s['total'], 'pending' => $s['pending'], 'proses' => $s['proses'], 'selesai' => $s['selesai'], 'ditolak' => $s['ditolak'],
        'resolved_pct' => $s['total'] ? round($s['selesai'] / $s['total'] * 100, 1) : 0,
        'sla_compliance_pct' => $sla['compliance'], 'overdue_open' => $sla['overdue'],
        'avg_first_response_hours' => null, 'avg_resolve_hours' => null,
        'by_agency' => [], 'by_category' => [], 'by_district' => [],
        'generated_at' => date('c'),
    ];
    try {
        $a = db()->query("SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at)) m1,
                                 AVG(CASE WHEN status = 'selesai' THEN TIMESTAMPDIFF(MINUTE, created_at, resolved_at) END) m2
                          FROM reports WHERE deleted_at IS NULL")->fetch();
        $o['avg_first_response_hours'] = $a['m1'] !== null ? round((float)$a['m1'] / 60, 1) : null;
        $o['avg_resolve_hours']        = $a['m2'] !== null ? round((float)$a['m2'] / 60, 1) : null;

        $grp = function ($table, $fk, $label, $limit) {
            $sql = "SELECT t.name, COUNT(r.id) total, SUM(r.status = 'selesai') selesai, SUM(r.status IN ('pending','proses')) terbuka,
                           SUM(r.status = 'selesai' AND r.resolved_at <= r.resolve_due_at) tepat_waktu,
                           AVG(CASE WHEN r.status = 'selesai' THEN TIMESTAMPDIFF(HOUR, r.created_at, r.resolved_at) END) rata_jam
                    FROM $table t LEFT JOIN reports r ON r.$fk = t.id AND r.deleted_at IS NULL
                    GROUP BY t.id, t.name ORDER BY total DESC, t.name LIMIT $limit";
            $out = [];
            foreach (db()->query($sql)->fetchAll() as $x) {
                $out[] = ['name' => $x['name'], 'total' => (int)$x['total'], 'selesai' => (int)$x['selesai'], 'terbuka' => (int)$x['terbuka'],
                          'tepat_waktu' => (int)$x['tepat_waktu'], 'rata_jam' => $x['rata_jam'] !== null ? round((float)$x['rata_jam'], 1) : null];
            }
            return $out;
        };
        $o['by_agency']   = $grp('agencies', 'agency_id', 'name', 20);
        $o['by_category'] = $grp('categories', 'category_id', 'name', 20);
        $o['by_district'] = $grp('districts', 'district_id', 'name', 12);
    } catch (Throwable $ex) {
    }
    return $o;
}

/** Bentuk JSON untuk api.php?a=track (tanpa koordinat pelapor, nama petugas, foto bukti pelapor). */
function public_report_payload($d)
{
    $r = $d['report'];
    return [
        'ticket'    => $r['ticket_code'],
        'title'     => $r['title'],
        'category'  => $r['cat_name'],
        'district'  => $r['district_name'],
        'agency'    => $r['agency_name'],
        'status'    => $r['status'],
        'status_label'   => STATUS_META[$r['status']]['label'],
        'priority'  => $r['priority'],
        'created_at'     => $r['created_at'],
        'resolved_at'    => $r['resolved_at'],
        'respond_due_at' => $r['respond_due_at'],
        'resolve_due_at' => $r['resolve_due_at'],
        'escalation_level' => (int)$r['escalation_level'],
        'timeline'  => $d['timeline'],
        'responses' => $d['responses'],
        'history_integrity' => ['checked' => $d['integrity']['checked'], 'legacy' => $d['integrity']['legacy'], 'valid' => !$d['integrity']['broken'] && empty($d['integrity']['error'])],
    ];
}

/* ------------------------------ Komponen UI ------------------------------ */

function priority_badge($p)
{
    $m = PRIORITY_META[$p] ?? PRIORITY_META['sedang'];
    return '<span class="px-2 py-0.5 rounded-full font-label-sm text-label-sm font-bold ' . $m['badge'] . '">' . e($m['label']) . '</span>';
}

function sla_badge($r)
{
    $s   = sla_state($r);
    $ico = ['overdue' => 'alarm', 'warning' => 'hourglass_top', 'ok' => 'schedule', 'done_ok' => 'task_alt', 'done_late' => 'alarm', 'closed' => 'block', 'none' => 'schedule'][$s['key']] ?? 'schedule';
    return '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full font-label-sm text-label-sm font-bold ' . $s['badge'] . '">'
        . '<span class="material-symbols-outlined text-[14px]">' . $ico . '</span>' . e($s['label']) . '</span>';
}

/** Progres vertikal (nyaman di ponsel): Diterima -> Diproses -> Selesai, atau Ditolak. */
function render_stepper($status)
{
    if ($status === 'ditolak') {
        $steps = [['Diterima', 'Laporan masuk ke sistem', 'done'], ['Ditolak', 'Tidak dapat ditindaklanjuti. Lihat alasannya pada tanggapan resmi.', 'bad']];
    } else {
        $idx    = ['pending' => 0, 'proses' => 1, 'selesai' => 2][$status] ?? 0;
        $labels = [['Diterima', 'Menunggu verifikasi petugas'], ['Diproses', 'Ditangani petugas lapangan'], ['Selesai', 'Penanganan tuntas dan bukti diunggah']];
        $steps  = [];
        foreach ($labels as $i => $l) {
            $state   = $status === 'selesai' ? 'done' : ($i < $idx ? 'done' : ($i === $idx ? 'current' : 'todo'));
            $steps[] = [$l[0], $l[1], $state];
        }
    }
    $cls = ['done' => 'bg-emerald-600 text-white', 'current' => 'bg-primary text-white ring-4 ring-primary-fixed', 'todo' => 'bg-surface-container text-on-surface-variant', 'bad' => 'bg-error text-white'];
    $ico = ['done' => 'check', 'current' => 'more_horiz', 'todo' => 'radio_button_unchecked', 'bad' => 'close'];
    $n   = count($steps);
    $o   = '<ol class="flex flex-col">';
    foreach ($steps as $i => $s) {
        $o .= '<li class="flex gap-3">'
            . '<div class="flex flex-col items-center"><span class="w-8 h-8 rounded-full flex items-center justify-center shrink-0 ' . $cls[$s[2]] . '">'
            . '<span class="material-symbols-outlined text-[18px]">' . $ico[$s[2]] . '</span></span>'
            . ($i === $n - 1 ? '' : '<span class="w-0.5 flex-1 min-h-[28px] ' . ($s[2] === 'done' ? 'bg-emerald-600' : 'bg-outline-variant') . '"></span>')
            . '</div>'
            . '<div class="pb-5"><div class="font-label-lg text-label-lg ' . ($s[2] === 'todo' ? 'text-on-surface-variant' : '') . '">' . e($s[0]) . '</div>'
            . '<div class="font-body-sm text-body-sm text-on-surface-variant">' . e($s[1]) . '</div></div></li>';
    }
    return $o . '</ol>';
}

/** Tiga garis kecil penanda progres untuk daftar laporan. */
function mini_progress($status)
{
    $n   = ['pending' => 1, 'proses' => 2, 'selesai' => 3, 'ditolak' => 1][$status] ?? 1;
    $bad = $status === 'ditolak';
    $o   = '<div class="flex gap-1 w-24" aria-hidden="true">';
    for ($i = 1; $i <= 3; $i++) {
        $o .= '<span class="h-1.5 flex-1 rounded-full ' . ($i <= $n ? ($bad ? 'bg-error' : 'bg-primary') : 'bg-surface-container-high') . '"></span>';
    }
    return $o . '</div>';
}

/** Awalan aman agar sel CSV tidak dieksekusi sebagai rumus di Excel/Sheets. */
function csv_safe($v)
{
    $v = (string)$v;
    return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
}
