<?php
require_once __DIR__ . '/config.php';
// Keluar hanya lewat POST + token CSRF agar tidak bisa dipicu dari tautan/gambar di situs lain.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(current_user() ? 'dashboard.php' : 'index.php');
}
csrf_check();
// Kosongkan data sesi lalu ganti ID sesi (lebih andal daripada session_destroy + session_start).
$_SESSION = [];
session_regenerate_id(true);
flash('success', 'Anda telah keluar dari sistem.');
redirect('index.php');
