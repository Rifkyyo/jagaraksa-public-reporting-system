<?php
/**
 * Jalankan eskalasi SLA dari terminal / cron (opsional - eskalasi juga berjalan otomatis
 * saat petugas membuka halaman, paling sering tiap 5 menit).
 *
 *   php cron_eskalasi.php
 *   crontab:  */10 * * * * php /path/ke/jagaraksa/cron_eskalasi.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya dapat dijalankan dari command line.');
}
require_once __DIR__ . '/config.php';
$n = run_escalation(true);
echo date('Y-m-d H:i:s') . " - {$n} laporan dieskalasikan\n";
