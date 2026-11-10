<?php
/**
 * Halaman diagnosa & perbaikan login JagaRaksa (hanya untuk pengembangan lokal).
 * Memeriksa PHP, session, database, tabel, akun, dan menguji login langkah demi langkah.
 * HAPUS file ini (atau set APP_DEBUG=false di config.php) sebelum dipasang di server publik.
 */
require_once __DIR__ . '/config.php';

if (!APP_DEBUG) {
    http_response_code(404);
    exit('Not found');
}
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
    http_response_code(403);
    exit('Diagnosa hanya dapat dibuka dari jaringan lokal.');
}

/* ------------------- Hitung sesi (uji apakah session tersimpan) ------------------- */
$_SESSION['diag_hits'] = ($_SESSION['diag_hits'] ?? 0) + 1;

$rows = [];   // [label, ok(bool|null), detail]
$add = function ($label, $ok, $detail = '') use (&$rows) { $rows[] = [$label, $ok, $detail]; };

/* ------------------- Aksi: reset akun demo ------------------- */
$resetMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'reset_demo') {
    if (!csrf_valid()) {
        $resetMsg = 'ERR: token CSRF tidak valid (session tidak tersimpan?). Muat ulang halaman ini lalu coba lagi.';
    } else {
        try {
            $pdo = db();
            $hasAgency = function ($id) use ($pdo) {
                $s = $pdo->prepare('SELECT 1 FROM agencies WHERE id = ?'); $s->execute([$id]); return (bool)$s->fetchColumn();
            };
            $demo = [
                ['Budi Santoso, S.T.', 'admin',    'admin@jagaraksa.id',    'Admin123!',   'admin',   'Koordinator Infrastruktur', 1],
                ['Dewi Lestari',       'petugas',  'petugas@jagaraksa.id',  'Petugas123!', 'petugas', 'Petugas Lapangan PUPR',     1],
                ['Agus Pratama',       'petugas2', 'petugas2@jagaraksa.id', 'Petugas123!', 'petugas', 'Petugas Lapangan PJU',      2],
                ['Rian Firmansyah',    'warga',    'warga@jagaraksa.id',    'Warga123!',   'pelapor', null,                       null],
                ['Siti Aminah',        'siti',     'siti@jagaraksa.id',     'Warga123!',   'pelapor', null,                       null],
            ];
            $sel = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
            $upd = $pdo->prepare('UPDATE users SET name = ?, username = ?, email = ?, password = ?, role = ?, jabatan = ?, agency_id = ?, is_active = 1 WHERE id = ?');
            $ins = $pdo->prepare('INSERT INTO users (name, username, email, password, role, jabatan, agency_id, is_active) VALUES (?,?,?,?,?,?,?,1)');
            foreach ($demo as $d) {
                [$n, $u, $em, $pw, $role, $jab, $ag] = $d;
                $ag = ($ag && $hasAgency($ag)) ? $ag : null;
                $hash = password_hash($pw, PASSWORD_DEFAULT);
                $sel->execute([$u, $em]);
                $id = $sel->fetchColumn();
                if ($id) {
                    $upd->execute([$n, $u, $em, $hash, $role, $jab, $ag, $id]);
                } else {
                    $ins->execute([$n, $u, $em, $hash, $role, $jab, $ag]);
                }
            }
            try { $pdo->exec('DELETE FROM login_attempts'); } catch (Throwable $ex) {}
            $resetMsg = 'OK: 5 akun demo dibuat/diperbarui dan penguncian login dibersihkan. Silakan login: admin / Admin123!';
        } catch (Throwable $ex) {
            $resetMsg = 'ERR: ' . $ex->getMessage();
        }
    }
}

/* ------------------- 1. PHP & ekstensi ------------------- */
$add('Versi PHP ' . PHP_VERSION, version_compare(PHP_VERSION, '7.4.0', '>='), 'Minimal 7.4, disarankan 8.1+');
foreach (['pdo', 'pdo_mysql', 'mbstring', 'session', 'json'] as $ext) {
    $add("Ekstensi $ext", extension_loaded($ext), extension_loaded($ext) ? '' : 'Aktifkan di php.ini (hapus tanda ; pada extension=' . $ext . ')');
}
$add('Fungsi password_hash/password_verify', function_exists('password_hash') && function_exists('password_verify'));

/* ------------------- 2. Session ------------------- */
$sp = (string)session_save_path();
$add('Folder session: ' . ($sp === '' ? '(default sistem)' : $sp), true, 'Jika tidak dapat ditulis, otomatis memakai storage/sessions');
$add('Session tersimpan antar-halaman (muat ulang halaman ini: angka harus naik)', $_SESSION['diag_hits'] > 1 ? true : null,
    'Kunjungan ke-' . $_SESSION['diag_hits'] . ($_SESSION['diag_hits'] > 1 ? ' → session & cookie berfungsi.' : ' → tekan F5 untuk memastikan.'));
$add('Cookie session diterima browser', isset($_COOKIE[session_name()]), isset($_COOKIE[session_name()]) ? '' : 'Belum ada cookie. Muat ulang halaman; pastikan cookie tidak diblokir.');

/* ------------------- 3. Database ------------------- */
$dbOk = false; $pdo = null;
try {
    $pdo = db();
    $dbOk = true;
    $add('Koneksi MySQL (' . DB_HOST . ':' . DB_PORT . ', db=' . DB_NAME . ', user=' . DB_USER . ')', true);
} catch (Throwable $ex) {
    $add('Koneksi MySQL', false, $ex->getMessage());
}

$users = [];
if ($dbOk) {
    foreach (['agencies', 'users', 'districts', 'categories', 'reports', 'report_attachments', 'report_responses', 'report_logs', 'notifications', 'login_attempts'] as $t) {
        try {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            $add("Tabel $t", true, "$n baris");
        } catch (Throwable $ex) {
            $add("Tabel $t", false, 'Tidak ada. Impor ulang database.sql (versi terbaru).');
        }
    }
    try {
        $users = $pdo->query('SELECT id, name, username, email, role, is_active, password FROM users ORDER BY id')->fetchAll();
        $add('Jumlah akun terdaftar', count($users) > 0, count($users) ? count($users) . ' akun' : 'Kosong → klik "Perbaiki / buat akun demo" di bawah.');
    } catch (Throwable $ex) {}
}

/* ------------------- 4. Uji login langkah demi langkah ------------------- */
$testOut = [];
if ($dbOk && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'test_login') {
    $id = trim((string)($_POST['identifier'] ?? ''));
    $pw = (string)($_POST['password'] ?? '');
    $u = $id !== '' ? find_user_by_identifier($id) : null;
    $testOut[] = [$u ? true : false, $u ? 'Akun ditemukan (id ' . $u['id'] . ', username "' . $u['username'] . '", email "' . $u['email'] . '")' : 'Akun TIDAK ditemukan untuk "' . $id . '". Cek ejaan atau buat akun lewat form Daftar.'];
    if ($u) {
        $testOut[] = [(bool)$u['is_active'], 'Status akun: ' . ($u['is_active'] ? 'aktif' : 'NONAKTIF (login ditolak)')];
        $len = strlen($u['password']);
        $testOut[] = [$len === 60 && strpos($u['password'], '$2') === 0, "Format hash: panjang $len, awalan \"" . substr($u['password'], 0, 7) . '" (bcrypt normal = 60 karakter diawali $2y$)'];
        $okpw = password_verify($pw, $u['password']);
        $testOut[] = [$okpw, 'password_verify(): ' . ($okpw ? 'COCOK' : 'TIDAK COCOK. Kata sandi salah atau hash di database rusak → gunakan tombol perbaiki.')];
    }
    $lock = $id !== '' ? login_lock_seconds($id) : 0;
    $testOut[] = [$lock === 0, $lock ? "Akun TERKUNCI sementara $lock detik (terlalu banyak gagal). Tombol perbaiki akan membersihkannya." : 'Tidak sedang terkunci oleh pembatas percobaan login.'];
}

$ico = function ($ok) { return $ok === true ? '✅' : ($ok === false ? '❌' : '⚠️'); };
?>
<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diagnosa JagaRaksa</title>
<style>
 body{font-family:system-ui,Segoe UI,Arial,sans-serif;max-width:860px;margin:2rem auto;padding:0 1rem;color:#0f172a;background:#f8fafc}
 h1{margin-bottom:.2rem} h2{margin-top:2rem;border-bottom:2px solid #d6f0f7;padding-bottom:.3rem}
 table{border-collapse:collapse;width:100%;background:#fff} td,th{padding:.5rem .6rem;border-bottom:1px solid #e6f5f9;text-align:left;font-size:14px;vertical-align:top}
 .box{background:#fff;padding:1rem;border-radius:10px;box-shadow:0 1px 4px #0001} input{padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;margin:.2rem .3rem .2rem 0}
 button{padding:.55rem 1rem;border:0;border-radius:6px;background:#2BA8C8;color:#fff;cursor:pointer;font-weight:600} .msg{padding:.7rem 1rem;border-radius:8px;margin:1rem 0;background:#e8f7ee}
 .err{background:#ffdad6} code{background:#e6f5f9;padding:1px 5px;border-radius:4px}
</style></head><body>
<h1>🩺 Diagnosa JagaRaksa</h1>
<p>Halaman ini memeriksa penyebab umum gagal login. <a href="index.php">← Beranda</a> • <a href="login.php">Halaman login</a></p>

<?php if ($resetMsg): ?><div class="msg <?= strpos($resetMsg, 'ERR') === 0 ? 'err' : '' ?>"><?= e($resetMsg) ?></div><?php endif; ?>

<h2>1. Pemeriksaan sistem</h2>
<table><?php foreach ($rows as [$l, $ok, $d]): ?>
  <tr><td style="width:2rem"><?= $ico($ok) ?></td><td><?= e($l) ?><?php if ($d): ?><br><small><?= e($d) ?></small><?php endif; ?></td></tr>
<?php endforeach; ?></table>

<?php if ($dbOk): ?>
<h2>2. Akun di database</h2>
<?php if (!$users): ?><p>Belum ada akun.</p><?php else: ?>
<table><tr><th>ID</th><th>Username</th><th>Email</th><th>Peran</th><th>Aktif</th><th>Hash</th></tr>
<?php foreach ($users as $u): ?><tr><td><?= (int)$u['id'] ?></td><td><?= e($u['username']) ?></td><td><?= e($u['email']) ?></td><td><?= e($u['role']) ?></td>
 <td><?= $u['is_active'] ? 'ya' : 'TIDAK' ?></td><td><code><?= e(substr($u['password'], 0, 10)) ?>…</code> (<?= strlen($u['password']) ?>)</td></tr><?php endforeach; ?></table>
<?php endif; ?>

<h2>3. Perbaiki / buat akun demo</h2>
<div class="box">
  <p>Membuat ulang 5 akun demo dengan hash yang dibuat langsung oleh PHP di komputer Anda, mengaktifkannya, dan menghapus penguncian login.
     Akun lain (yang Anda daftarkan sendiri) tidak diubah.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="reset_demo"><button>Perbaiki / buat akun demo</button></form>
  <p><small>admin / Admin123! • petugas / Petugas123! • petugas2 / Petugas123! • warga / Warga123! • siti / Warga123!</small></p>
</div>

<h2>4. Uji login langkah demi langkah</h2>
<div class="box">
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="test_login">
    <input name="identifier" placeholder="email atau username" value="<?= e($_POST['identifier'] ?? '') ?>" required>
    <input name="password" type="password" placeholder="kata sandi" required>
    <button>Uji</button></form>
  <?php if ($testOut): ?><table style="margin-top:1rem"><?php foreach ($testOut as [$ok, $t]): ?><tr><td style="width:2rem"><?= $ico($ok) ?></td><td><?= e($t) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
</div>
<?php endif; ?>

<p style="margin-top:2rem;color:#a00"><b>Setelah selesai, hapus diagnosa.php</b> atau set <code>APP_DEBUG</code> menjadi <code>false</code> di config.php.</p>
</body></html>
