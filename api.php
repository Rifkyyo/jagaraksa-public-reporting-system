<?php
require_once __DIR__ . '/config.php';

$a = (string)($_GET['a'] ?? '');

/* ---------- Endpoint PUBLIK (tanpa login, tanpa data pribadi) ---------- */
if ($a === 'public_stats' || $a === 'track') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    header('X-Content-Type-Options: nosniff');
    if ($a === 'public_stats') {
        echo json_encode(public_stats(), JSON_UNESCAPED_UNICODE);
        exit;
    }
    $d = public_report((string)($_GET['tiket'] ?? ''));
    if (!$d) {
        http_response_code(404);
        echo json_encode(['error' => 'Tiket tidak ditemukan.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(public_report_payload($d), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- Endpoint yang butuh login ---------- */
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'read_notif') {
    csrf_check();
    $nid = (int)($_POST['id'] ?? 0);
    if ($nid > 0) {
        db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id = ?')->execute([$user['id'], $nid]);
    } else {
        db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$user['id']]);
    }
    $to = (string)($_POST['to'] ?? '');
    // hanya izinkan tujuan internal (tanpa skema/host) agar tidak menjadi open redirect
    if ($to === '' || !preg_match('#^[a-z0-9\-]+\.php(\?[A-Za-z0-9=&%_\-\.]*)?$#i', $to)) {
        $to = 'dashboard.php';
    }
    redirect($to);
}

if ($a === 'markers') {
    header('Content-Type: application/json; charset=utf-8');
    if (is_staff($user)) {
        $st = db()->query('SELECT ticket_code, title, address, latitude lat, longitude lng, status, category_id FROM reports WHERE deleted_at IS NULL');
    } else {
        $st = db()->prepare('SELECT ticket_code, title, address, latitude lat, longitude lng, status, category_id FROM reports WHERE deleted_at IS NULL AND user_id = ?');
        $st->execute([(int)$user['id']]);
    }
    echo json_encode($st->fetchAll(), JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(404);
echo 'Not found';
