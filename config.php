<?php
/**
 * JagaRaksa - konfigurasi inti, koneksi database, sesi, autentikasi & helper.
 * Kompatibel PHP 7.4+ (disarankan 8.1+).
 *
 * Sesuaikan DB_* di bawah (atau set environment variable JR_DB_HOST, JR_DB_NAME, JR_DB_USER, JR_DB_PASS).
 */

/* ------------------------------ Pengaturan ------------------------------ */
define('DB_HOST', getenv('JR_DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('JR_DB_PORT') ?: '3306');
define('DB_NAME', getenv('JR_DB_NAME') ?: 'jagaraksa');
define('DB_USER', getenv('JR_DB_USER') ?: 'root');
define('DB_PASS', getenv('JR_DB_PASS') !== false ? getenv('JR_DB_PASS') : '');

// Mode debug (pesan error asli + halaman diagnosa.php).
// Otomatis AKTIF hanya bila diakses langsung dari komputer ini (127.0.0.1 / ::1, tanpa proxy).
// Di server publik otomatis NONAKTIF. Paksa dengan environment variable JR_DEBUG=1 atau JR_DEBUG=0.
$__jrLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    && empty($_SERVER['HTTP_X_FORWARDED_FOR']) && empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && empty($_SERVER['HTTP_X_REAL_IP']);
define('APP_DEBUG', getenv('JR_DEBUG') !== false ? filter_var(getenv('JR_DEBUG'), FILTER_VALIDATE_BOOLEAN) : $__jrLocal);

const LOGO_URL = 'https://lh3.googleusercontent.com/aida-public/AB6AXuDIz7dEOMCg8kNRnGjMja15hHVgzn9oWHgQMOTNcJddyrBI_r4xS0jnQUK_HWWj833MAyNq62XuURyHkPVQhT9OMa0xSNhe5pSI9aMVvld_5uqA8lqyOiRnIHze6oaYMQWMSrnI6w6mbtD6S-MfP6RZW2Uhsu3ouK6Flh6t7MkQlPLGnJ-hVeaoLRdwCu0kqD7R1CYUfzNih0I6REyFTBr-IbRPQlGdhTHQuorCKuxZKKRc4mrk6fqUEw';
const UPLOAD_DIR = __DIR__ . '/uploads/';
const UPLOAD_URL = 'uploads/';
const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;   // 5 MB per foto
const MAX_UPLOAD_FILES = 3;
const LOGIN_MAX_FAILS  = 8;                 // gagal login maksimum ...
const LOGIN_WINDOW_MIN = 10;                // ... dalam sekian menit (per akun + IP)

date_default_timezone_set('Asia/Jakarta');

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

/* Polyfill jika ekstensi mbstring tidak aktif */
if (!function_exists('mb_strlen'))      { function mb_strlen($s)            { return strlen($s); } }
if (!function_exists('mb_substr'))      { function mb_substr($s, $a, $l = null) { return $l === null ? substr($s, $a) : substr($s, $a, $l); } }
if (!function_exists('mb_strtoupper'))  { function mb_strtoupper($s)        { return strtoupper($s); } }

/* ------------------------------ Session ------------------------------ */

function boot_session()
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    // Pastikan folder penyimpan session dapat ditulis; jika tidak, pakai storage/sessions.
    $path = (string)session_save_path();
    if (strpos($path, ';') !== false) {
        $path = substr($path, strrpos($path, ';') + 1);
    }
    if ($path === '') {
        $path = sys_get_temp_dir();
    }
    if (!is_dir($path) || !is_writable($path)) {
        $alt = __DIR__ . '/storage/sessions';
        if (!is_dir($alt)) {
            @mkdir($alt, 0775, true);
        }
        if (is_dir($alt) && is_writable($alt)) {
            session_save_path($alt);
        }
    }
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('JAGARAKSA_SID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $https,
    ]);
    session_start();
}
boot_session();

/* ------------------------------ Database ------------------------------ */

function db()
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => true,   // paling kompatibel lintas versi MySQL/MariaDB
                ]
            );
            $pdo->exec("SET time_zone = '+07:00'");
        } catch (PDOException $ex) {
            http_response_code(500);
            $msg = 'Koneksi database gagal. Pastikan MySQL berjalan, database.sql sudah diimpor, dan kredensial di config.php benar.';
            if (APP_DEBUG) {
                $msg .= "\n\nDetail: " . $ex->getMessage() . "\n\nBuka diagnosa.php untuk pemeriksaan lengkap.";
            }
            header('Content-Type: text/plain; charset=utf-8');
            exit($msg);
        }
    }
    return $pdo;
}

/* ------------------------------ Helper umum ------------------------------ */

function e($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect($to)
{
    header('Location: ' . $to);
    exit;
}

function flash($type, $msg)
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function flash_render()
{
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $ok  = $f['type'] === 'success';
    $cls = $ok ? 'bg-tertiary-fixed text-on-tertiary-fixed' : 'bg-error-container text-on-error-container';
    $ico = $ok ? 'check_circle' : 'error';
    return '<div class="flex items-start gap-3 p-3.5 rounded-lg mb-4 ' . $cls . '">'
        . '<span class="material-symbols-outlined text-[20px] shrink-0">' . $ico . '</span>'
        . '<p class="font-body-sm text-body-sm">' . e($f['msg']) . '</p></div>';
}

function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid()
{
    $sent = $_POST['csrf'] ?? '';
    return is_string($sent) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}

function csrf_check()
{
    if (!csrf_valid()) {
        http_response_code(419);
        exit('Sesi formulir kedaluwarsa. Silakan kembali dan muat ulang halaman (pastikan cookie browser aktif).');
    }
}

/* ------------------------------ Autentikasi ------------------------------ */

function current_user()
{
    static $loaded = false, $user = null;
    if (!$loaded) {
        $loaded = true;
        if (!empty($_SESSION['uid'])) {
            $st = db()->prepare('SELECT u.*, a.name AS agency_name FROM users u
                                 LEFT JOIN agencies a ON a.id = u.agency_id
                                 WHERE u.id = ? AND u.is_active = 1');
            $st->execute([(int)$_SESSION['uid']]);
            $user = $st->fetch() ?: null;
            if (!$user) {
                unset($_SESSION['uid']);
            }
        }
    }
    return $user;
}

function is_staff($u = null)
{
    $u = $u ?: current_user();
    return $u && in_array($u['role'], ['admin', 'petugas'], true);
}

/** Halaman wajib login. Tamu diarahkan ke login. */
function require_login()
{
    $u = current_user();
    if (!$u) {
        flash('error', 'Silakan masuk terlebih dahulu untuk mengakses halaman tersebut.');
        redirect('login.php');
    }
    return $u;
}

/** Halaman khusus peran tertentu. */
function require_role(...$roles)
{
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        flash('error', 'Anda tidak memiliki akses ke halaman tersebut.');
        redirect('dashboard.php');
    }
    return $u;
}

function login_user($u)
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    try {
        db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$u['id']]);
    } catch (Throwable $ex) {
        // last_login tidak boleh menggagalkan login
    }
}

function client_ip()
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/** Sisa detik penguncian login (0 = tidak terkunci). Aman jika tabel login_attempts belum ada. */
function login_lock_seconds($identifier)
{
    try {
        $st = db()->prepare('SELECT COUNT(*) c, MIN(attempted_at) first_at FROM login_attempts
                             WHERE identifier = ? AND ip = ? AND attempted_at > (NOW() - INTERVAL ' . (int)LOGIN_WINDOW_MIN . ' MINUTE)');
        $st->execute([mb_substr(strtolower($identifier), 0, 190), client_ip()]);
        $r = $st->fetch();
        if ($r && (int)$r['c'] >= LOGIN_MAX_FAILS) {
            $left = strtotime($r['first_at']) + LOGIN_WINDOW_MIN * 60 - time();
            return max(1, $left);
        }
    } catch (Throwable $ex) {
    }
    return 0;
}

function login_record_fail($identifier)
{
    try {
        db()->prepare('INSERT INTO login_attempts (identifier, ip) VALUES (?, ?)')
            ->execute([mb_substr(strtolower($identifier), 0, 190), client_ip()]);
    } catch (Throwable $ex) {
    }
}

function login_clear_fails($identifier)
{
    try {
        db()->prepare('DELETE FROM login_attempts WHERE identifier = ? AND ip = ?')
            ->execute([mb_substr(strtolower($identifier), 0, 190), client_ip()]);
        db()->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
    } catch (Throwable $ex) {
    }
}

/** Cari pengguna berdasarkan email ATAU username (tidak peka huruf besar/kecil). */
function find_user_by_identifier($identifier)
{
    $st = db()->prepare('SELECT * FROM users WHERE LOWER(email) = LOWER(?) OR LOWER(username) = LOWER(?) LIMIT 1');
    $st->execute([$identifier, $identifier]);
    return $st->fetch() ?: null;
}

function role_label($role)
{
    $m = ['admin' => 'Admin Kota', 'petugas' => 'Petugas Lapangan', 'pelapor' => 'Warga Pelapor'];
    return $m[$role] ?? $role;
}

function user_avatar($u)
{
    $initial = mb_strtoupper(mb_substr($u['name'], 0, 1));
    return '<div class="w-8 h-8 rounded-full bg-primary-container text-on-primary-container flex items-center justify-center font-label-md text-label-md">'
        . e($initial) . '</div>';
}

/* ------------------------------ Domain laporan ------------------------------ */

const STATUS_META = [
    'pending'  => ['label' => 'Menunggu', 'badge' => 'bg-amber-100 text-amber-800',     'bar' => 'border-amber-500',   'color' => '#f59e0b'],
    'proses'   => ['label' => 'Diproses', 'badge' => 'bg-cyan-100 text-cyan-800',       'bar' => 'border-cyan-500',    'color' => '#2BA8C8'],
    'selesai'  => ['label' => 'Selesai',  'badge' => 'bg-emerald-100 text-emerald-800', 'bar' => 'border-emerald-600', 'color' => '#059669'],
    'ditolak'  => ['label' => 'Ditolak',  'badge' => 'bg-red-100 text-red-800',         'bar' => 'border-red-500',     'color' => '#dc2626'],
];

function status_badge($s, $extra = '')
{
    $m = STATUS_META[$s] ?? STATUS_META['pending'];
    return '<span class="px-2.5 py-0.5 rounded-full font-label-sm text-label-sm font-bold ' . $m['badge'] . ' ' . $extra . '">'
        . e($m['label']) . '</span>';
}

/** Nomor tiket berurutan yang aman terhadap pengiriman bersamaan (counter atomik per tahun). Panggil di dalam transaksi. */
function next_ticket_code()
{
    $year = (int)date('Y');
    $pdo  = db();
    $pdo->prepare('INSERT INTO ticket_counters (yr, last_no) VALUES (?, 0) ON DUPLICATE KEY UPDATE yr = yr')->execute([$year]);
    $pdo->prepare('UPDATE ticket_counters SET last_no = LAST_INSERT_ID(last_no + 1) WHERE yr = ?')->execute([$year]);
    $n = (int)$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    return sprintf('JR-%d-%04d', $year, $n);
}

function notify($userId, $reportId, $msg)
{
    db()->prepare('INSERT INTO notifications (user_id, report_id, message) VALUES (?,?,?)')
        ->execute([(int)$userId, $reportId === null ? null : (int)$reportId, mb_substr($msg, 0, 300)]);
}

function first_photo($reportId, $kind = 'bukti')
{
    $st = db()->prepare('SELECT file_path FROM report_attachments WHERE report_id = ? AND kind = ? ORDER BY id LIMIT 1');
    $st->execute([(int)$reportId, $kind]);
    $p = $st->fetchColumn();
    return $p ? UPLOAD_URL . $p : null;
}

function format_tanggal($dt, $withTime = false)
{
    $bulan = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $t = strtotime($dt);
    $s = date('j', $t) . ' ' . $bulan[(int)date('n', $t)] . ' ' . date('Y', $t);
    return $withTime ? $s . ', ' . date('H:i', $t) . ' WIB' : $s;
}

function time_ago($dt)
{
    $d = time() - strtotime($dt);
    if ($d < 60)    return 'baru saja';
    if ($d < 3600)  return floor($d / 60) . ' menit lalu';
    if ($d < 86400) return floor($d / 3600) . ' jam lalu';
    return floor($d / 86400) . ' hari lalu';
}

function categories()
{
    return db()->query('SELECT * FROM categories ORDER BY id')->fetchAll();
}

function agencies()
{
    return db()->query('SELECT * FROM agencies ORDER BY name')->fetchAll();
}

function districts()
{
    return db()->query('SELECT * FROM districts ORDER BY name')->fetchAll();
}

/**
 * Simpan foto unggahan; tipe file diperiksa dari isi gambar sebenarnya (bukan ekstensi).
 * @return array
 */
function save_uploads($field)
{
    $saved = [];
    if (empty($_FILES[$field]['name'][0])) {
        return $saved;
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    $count = min(count($_FILES[$field]['name']), MAX_UPLOAD_FILES);
    for ($i = 0; $i < $count; $i++) {
        if ($_FILES[$field]['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }
        $tmp  = $_FILES[$field]['tmp_name'][$i];
        $size = (int)$_FILES[$field]['size'][$i];
        $info = @getimagesize($tmp);
        $mime = $info ? $info['mime'] : '';
        if ($size > MAX_UPLOAD_BYTES || !isset($allowed[$mime]) || !is_uploaded_file($tmp)) {
            continue;
        }
        $name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
        $dest = UPLOAD_DIR . $name;
        if (strip_and_save_image($tmp, $mime, $dest) || move_uploaded_file($tmp, $dest)) {
            $saved[] = ['path' => $name, 'type' => $mime, 'size' => is_file($dest) ? (int)filesize($dest) : $size];
        }
    }
    return $saved;
}

/**
 * Simpan ulang gambar lewat GD: membuang metadata EXIF (termasuk koordinat GPS asli kamera),
 * memutar sesuai orientasi, dan membatasi sisi terpanjang 1600 px. Mengembalikan false bila GD tidak tersedia
 * (pemanggil lalu menyimpan file apa adanya).
 */
function strip_and_save_image($tmp, $mime, $dest)
{
    if (!function_exists('imagecreatefromjpeg') || !function_exists('imagecreatefrompng')) {
        return false;
    }
    $img = $mime === 'image/png' ? @imagecreatefrompng($tmp) : @imagecreatefromjpeg($tmp);
    if (!$img) {
        return false;
    }
    if ($mime === 'image/jpeg' && function_exists('exif_read_data') && function_exists('imagerotate')) {
        $ex  = @exif_read_data($tmp);
        $deg = [3 => 180, 6 => -90, 8 => 90][(int)($ex['Orientation'] ?? 1)] ?? 0;
        if ($deg) {
            $rot = imagerotate($img, $deg, 0);
            if ($rot) {
                imagedestroy($img);
                $img = $rot;
            }
        }
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $max = 1600;
    if (max($w, $h) > $max && function_exists('imagescale')) {
        $nw = $w >= $h ? $max : (int)round($w * $max / $h);
        $nh = $w >= $h ? (int)round($h * $max / $w) : $max;
        $sc = imagescale($img, $nw, $nh);
        if ($sc) {
            imagedestroy($img);
            $img = $sc;
        }
    }
    if ($mime === 'image/png') {
        imagesavealpha($img, true);
        $ok = imagepng($img, $dest, 6);
    } else {
        $ok = imagejpeg($img, $dest, 85);
    }
    imagedestroy($img);
    return (bool)$ok;
}

function stats_overview($userId = null)
{
    $where = 'WHERE deleted_at IS NULL' . ($userId ? ' AND user_id = ' . (int)$userId : '');
    $rows  = db()->query("SELECT status, COUNT(*) c FROM reports $where GROUP BY status")->fetchAll();
    $out   = ['total' => 0, 'pending' => 0, 'proses' => 0, 'selesai' => 0, 'ditolak' => 0];
    foreach ($rows as $r) {
        $out[$r['status']] = (int)$r['c'];
        $out['total']     += (int)$r['c'];
    }
    return $out;
}

/* ------------------------------ Modul v2.1 (SLA, eskalasi, audit, data publik) ------------------------------ */
require_once __DIR__ . '/includes/core_v2.php';
