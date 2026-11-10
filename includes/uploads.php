<?php
/**
 * Unggah foto v2: sama seperti save_uploads() di config.php, tetapi
 *  - menerima foto sampai 10 MB (foto kamera HP biasanya 3-8 MB; hasilnya dikecilkan otomatis ke maks. 1600 px),
 *  - TIDAK diam-diam melewati file yang gagal: alasan dikembalikan agar bisa ditampilkan ke pengguna.
 * @return array ['saved' => [...], 'problems' => [pesan, ...]]
 */
function save_uploads_v2($field)
{
    $out = ['saved' => [], 'problems' => []];
    if (empty($_FILES[$field]['name'][0])) {
        return $out;                       // tidak ada foto dipilih
    }
    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0755, true);
    }
    if (!is_dir(UPLOAD_DIR) || !is_writable(UPLOAD_DIR)) {
        $out['problems'][] = 'Folder "uploads" di server tidak dapat ditulis. Atur izin folder uploads menjadi 755 atau 777 di File Manager hosting.';
        return $out;
    }

    $maxBytes = 10 * 1024 * 1024;
    $allowed  = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    $total    = count($_FILES[$field]['name']);
    if ($total > MAX_UPLOAD_FILES) {
        $out['problems'][] = 'Maksimal ' . MAX_UPLOAD_FILES . ' foto per laporan; foto selebihnya diabaikan.';
    }

    for ($i = 0; $i < min($total, MAX_UPLOAD_FILES); $i++) {
        $label = '"' . mb_substr((string)$_FILES[$field]['name'][$i], 0, 40) . '"';
        $err   = (int)$_FILES[$field]['error'][$i];
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            $out['problems'][] = "Foto $label melebihi batas ukuran yang diizinkan server.";
            continue;
        }
        if ($err !== UPLOAD_ERR_OK) {
            $out['problems'][] = "Foto $label gagal diunggah (kode $err). Coba lagi.";
            continue;
        }
        $tmp  = $_FILES[$field]['tmp_name'][$i];
        $size = (int)$_FILES[$field]['size'][$i];
        $info = @getimagesize($tmp);
        $mime = $info ? $info['mime'] : '';
        if (!isset($allowed[$mime])) {
            $out['problems'][] = "Foto $label bukan JPG/PNG (format HEIC/WebP tidak didukung).";
            continue;
        }
        if ($size > $maxBytes) {
            $out['problems'][] = "Foto $label lebih dari 10 MB.";
            continue;
        }
        if (!is_uploaded_file($tmp)) {
            $out['problems'][] = "Foto $label tidak valid.";
            continue;
        }
        $name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
        $dest = UPLOAD_DIR . $name;
        if (strip_and_save_image($tmp, $mime, $dest) || @move_uploaded_file($tmp, $dest)) {
            $out['saved'][] = ['path' => $name, 'type' => $mime, 'size' => is_file($dest) ? (int)filesize($dest) : $size];
        } else {
            $out['problems'][] = "Foto $label gagal disimpan di server.";
        }
    }
    return $out;
}
