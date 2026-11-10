<?php
require_once __DIR__ . '/config.php';
$user   = require_login();
$active = 'laporan-saya';
$pageTitle = 'Laporan Saya';
$errors = [];

/* ------------------------ Buka kembali laporan selesai ------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'reopen') {
    csrf_check();
    $code   = (string)($_POST['tiket'] ?? '');
    $reason = trim((string)($_POST['reason'] ?? ''));
    $back   = 'laporan-saya.php?tiket=' . urlencode($code);
    $rs = db()->prepare('SELECT * FROM reports WHERE ticket_code = ? AND user_id = ? AND deleted_at IS NULL');
    $rs->execute([$code, (int)$user['id']]);
    $rep = $rs->fetch();
    if (!$rep || $rep['status'] !== 'selesai') {
        flash('error', 'Laporan ini tidak dapat dibuka kembali.');
        redirect($back);
    }
    if (mb_strlen($reason) < 10 || mb_strlen($reason) > 300) {
        flash('error', 'Jelaskan alasan pembukaan kembali (10-300 karakter).');
        redirect($back);
    }
    if ($rep['resolved_at'] && strtotime($rep['resolved_at']) < time() - REOPEN_WINDOW_DAYS * 86400) {
        flash('error', 'Batas ' . REOPEN_WINDOW_DAYS . ' hari untuk membuka kembali laporan telah lewat. Silakan buat laporan baru.');
        redirect($back);
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        [$rd, $sd] = sla_due_dates($rep['priority']);
        $pdo->prepare("UPDATE reports SET status = 'pending', resolved_at = NULL, escalation_level = 0, escalated_at = NULL, respond_due_at = ?, resolve_due_at = ? WHERE id = ?")
            ->execute([$rd, $sd, (int)$rep['id']]);
        log_report((int)$rep['id'], (int)$user['id'], 'buka_kembali', 'Pelapor membuka kembali laporan: ' . $reason, 'selesai', 'pending');
        notify_staff($rep['agency_id'] ? (int)$rep['agency_id'] : null, (int)$rep['id'], 'Laporan #' . $rep['ticket_code'] . ' dibuka kembali oleh pelapor: ' . mb_substr($reason, 0, 120));
        $pdo->commit();
        flash('success', 'Laporan dibuka kembali dan masuk antrean verifikasi dengan target waktu baru.');
    } catch (Throwable $ex) {
        $pdo->rollBack();
        flash('error', 'Gagal membuka kembali laporan. Coba lagi.');
    }
    redirect($back);
}

/* ------------------------ Simpan laporan baru ------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $title   = trim((string)($_POST['report_title'] ?? ''));
    $catId   = (int)($_POST['report_category'] ?? 0);
    $impact  = ($_POST['impact_level'] ?? '') === 'bahaya' ? 'bahaya' : 'sedang';
    $desc    = trim((string)($_POST['report_description'] ?? ''));
    $address = trim((string)($_POST['address_line'] ?? ''));
    $lat     = (float)($_POST['latitude'] ?? 0);
    $lng     = (float)($_POST['longitude'] ?? 0);
    $anon    = !empty($_POST['is_anonymous']) ? 1 : 0;

    if (mb_strlen($title) < 5 || mb_strlen($title) > 255) $errors[] = 'Judul laporan 5-255 karakter.';
    if (mb_strlen($desc) < 10 || mb_strlen($desc) > 500)  $errors[] = 'Deskripsi kejadian 10-500 karakter.';
    if ($address === '')                                   $errors[] = 'Alamat / patokan lokasi wajib diisi.';
    if ($lat < -11 || $lat > 6 || $lng < 95 || $lng > 141) $errors[] = 'Titik koordinat tidak valid. Tentukan lokasi pada peta.';
    $cat = db()->prepare('SELECT * FROM categories WHERE id = ?');
    $cat->execute([$catId]);
    $cat = $cat->fetch();
    if (!$cat) $errors[] = 'Kategori tidak valid.';

    if (!$errors) {
        // kecamatan terdekat dari titik koordinat
        $near = db()->prepare('SELECT id FROM districts WHERE latitude IS NOT NULL
                               ORDER BY (POW(latitude - ?, 2) + POW(longitude - ?, 2)) LIMIT 1');
        $near->execute([$lat, $lng]);
        $districtId = $near->fetchColumn() ?: null;

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $code = next_ticket_code();
            $prio = priority_from_impact($impact);
            [$respondDue, $resolveDue] = sla_due_dates($prio);
            $pdo->prepare('INSERT INTO reports (ticket_code, user_id, category_id, district_id, agency_id, title, description,
                           address, latitude, longitude, impact_level, is_anonymous, priority, respond_due_at, resolve_due_at)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$code, $user['id'], $catId, $districtId, $cat['agency_id'], $title, $desc, $address, $lat, $lng, $impact, $anon, $prio, $respondDue, $resolveDue]);
            $rid = (int)$pdo->lastInsertId();
            foreach (save_uploads('photos') as $f) {
                $pdo->prepare('INSERT INTO report_attachments (report_id, file_path, file_type, file_size) VALUES (?,?,?,?)')
                    ->execute([$rid, $f['path'], $f['type'], $f['size']]);
            }
            log_report($rid, (int)$user['id'], 'dibuat', 'Laporan dikirim oleh pelapor dan menunggu verifikasi.', null, 'pending');
            // notifikasi terarah: admin + petugas instansi terkait (bukan semua petugas)
            notify_staff($cat['agency_id'] ? (int)$cat['agency_id'] : null, $rid, '[' . PRIORITY_META[$prio]['label'] . "] Laporan baru #$code: $title");
            notify((int)$user['id'], $rid, "Laporan #$code diterima. Target verifikasi: " . hours_text(sla_hours($prio)['respond']) . ', target penyelesaian: ' . hours_text(sla_hours($prio)['resolve']) . '.');
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $errors[] = 'Terjadi kesalahan saat menyimpan laporan. Coba lagi.';
        }
        if (!$errors) {
            flash('success', "Laporan berhasil dikirim dengan nomor tiket #$code. Target verifikasi petugas: " . hours_text(sla_hours($prio)['respond']) . '. Simpan nomor tiket ini untuk melacak laporan kapan saja.');
            redirect('laporan-saya.php?tiket=' . urlencode($code));
        }
    }
}

/* ------------------------ Detail laporan ------------------------ */
$detail = null;
if (!empty($_GET['tiket'])) {
    $d = db()->prepare('SELECT r.*, c.name cat_name, c.icon cat_icon, a.name agency_name, dist.name district_name
                        FROM reports r JOIN categories c ON c.id = r.category_id
                        LEFT JOIN agencies a ON a.id = r.agency_id LEFT JOIN districts dist ON dist.id = r.district_id
                        WHERE r.ticket_code = ? AND r.deleted_at IS NULL');
    $d->execute([(string)$_GET['tiket']]);
    $detail = $d->fetch() ?: null;
    if ($detail && !is_staff($user) && (int)$detail['user_id'] !== (int)$user['id']) {
        // laporan milik warga lain hanya boleh dilihat lewat halaman publik (tanpa identitas, tanpa foto pelapor)
        redirect('lacak.php?tiket=' . urlencode($detail['ticket_code']));
    }
}

/* ------------------------ Riwayat ------------------------ */
$filter = $_GET['status'] ?? 'semua';
$q      = trim((string)($_GET['q'] ?? ''));
$sql    = 'SELECT r.*, c.name cat_name FROM reports r JOIN categories c ON c.id = r.category_id WHERE r.user_id = ? AND r.deleted_at IS NULL';
$par    = [$user['id']];
if (isset(STATUS_META[$filter])) { $sql .= ' AND r.status = ?'; $par[] = $filter; }
if ($q !== '') { $sql .= ' AND (r.title LIKE ? OR r.ticket_code LIKE ?)'; $par[] = "%$q%"; $par[] = "%$q%"; }
$sql .= ' ORDER BY r.created_at DESC';
$hist = db()->prepare($sql);
$hist->execute($par);
$hist = $hist->fetchAll();
$my   = stats_overview((int)$user['id']);
$tab  = ($_GET['tab'] ?? '') === 'riwayat' || $q !== '' || isset(STATUS_META[$filter]) ? 'riwayat' : 'baru';
if ($detail) $tab = 'detail';
$cats = categories();
$catAgency = [];
foreach (db()->query('SELECT c.id, a.name FROM categories c LEFT JOIN agencies a ON a.id = c.agency_id')->fetchAll() as $row) $catAgency[$row['id']] = $row['name'] ?? '-';

$fld = 'w-full px-space-md py-space-xs rounded-lg bg-surface-container-low text-on-surface placeholder:text-on-surface-variant focus:outline-none focus:ring-2 focus:ring-primary font-body-md text-body-md shadow-sm';
include __DIR__ . '/includes/app_top.php';
?>
<div class="flex flex-col md:flex-row md:items-end justify-between gap-space-md mb-space-xl">
  <div class="flex flex-col max-w-2xl">
    <div class="flex items-center gap-space-xs text-on-surface-variant font-label-sm text-label-sm mb-space-xs"><span>JagaRaksa</span><span class="material-symbols-outlined text-sm">chevron_right</span><span class="text-primary font-semibold">Laporan Saya</span></div>
    <h1 class="font-headline-xl text-headline-xl tracking-tight">Laporan Saya &amp; Pengajuan Baru</h1>
    <p class="font-body-md text-body-md text-on-surface-variant mt-space-2xs">Laporkan permasalahan di lingkunganmu, unggah bukti foto, dan pantau perkembangan tindak lanjut secara real-time.</p>
  </div>
</div>

<?php if ($errors): ?>
<div class="flex items-start gap-3 p-3.5 rounded-lg bg-error-container text-on-error-container mb-space-md"><span class="material-symbols-outlined text-[20px]">error</span>
  <ul class="font-body-sm text-body-sm"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="flex items-center gap-space-sm p-space-xs bg-surface-container-low rounded-xl w-fit mb-space-xl shadow-sm">
  <a href="laporan-saya.php" class="inline-flex items-center gap-space-xs px-space-lg py-space-sm rounded-lg font-label-lg text-label-lg transition-all <?= $tab === 'baru' ? 'bg-primary-container text-on-primary-container shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' ?>"><span class="material-symbols-outlined text-xl">add_circle</span>Buat Laporan Baru</a>
  <a href="laporan-saya.php?tab=riwayat" class="inline-flex items-center gap-space-xs px-space-lg py-space-sm rounded-lg font-label-lg text-label-lg transition-all <?= $tab !== 'baru' ? 'bg-primary-container text-on-primary-container shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' ?>"><span class="material-symbols-outlined text-xl">history_edu</span>Riwayat Laporan Saya<span class="px-space-xs py-0.5 rounded-full bg-secondary-container text-on-secondary-container font-label-sm text-label-sm"><?= $my['total'] ?></span></a>
</div>

<?php if ($tab === 'baru'): ?>
<form method="post" action="laporan-saya.php" enctype="multipart/form-data" class="bg-surface-container-lowest rounded-xl p-space-xl shadow-md flex flex-col gap-space-xl mb-space-xl">
  <?= csrf_field() ?>
  <div class="flex flex-col gap-space-md">
    <div class="flex items-center justify-between"><div class="flex items-center gap-space-xs"><span class="w-2.5 h-6 rounded-full bg-primary inline-block"></span><h2 class="font-headline-md text-headline-md">Bagian A: Informasi Masalah</h2></div><span class="hidden sm:block font-label-sm text-label-sm text-on-surface-variant">Karakteristik &amp; Detail Pengaduan</span></div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-space-lg">
      <div class="flex flex-col gap-space-2xs md:col-span-2">
        <label class="font-label-md text-label-md" for="report_title">Judul Laporan <span class="text-error">*</span></label>
        <input class="<?= $fld ?>" id="report_title" name="report_title" maxlength="255" value="<?= e($_POST['report_title'] ?? '') ?>" placeholder="Contoh: Lubang jalan berdiameter 50cm di dekat halte bus" required type="text"/>
        <span class="font-body-sm text-body-sm text-on-surface-variant">Buat judul yang singkat dan jelas mengenai masalah yang dihadapi.</span>
      </div>
      <div class="flex flex-col gap-space-2xs">
        <label class="font-label-md text-label-md" for="report_category">Kategori Permasalahan <span class="text-error">*</span></label>
        <select class="<?= $fld ?>" id="report_category" name="report_category" required>
          <?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>" <?= (int)($_POST['report_category'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="flex flex-col gap-space-2xs">
        <label class="font-label-md text-label-md">Estimasi Dampak Bagi Warga</label>
        <div class="flex items-center gap-space-sm h-10">
          <label class="flex-1 flex items-center justify-center gap-space-xs h-full px-space-sm rounded-lg bg-surface-container-low font-label-md text-label-md cursor-pointer"><input checked class="accent-primary w-4 h-4" name="impact_level" type="radio" value="sedang"/><span>Mengganggu</span></label>
          <label class="flex-1 flex items-center justify-center gap-space-xs h-full px-space-sm rounded-lg bg-error-container text-on-error-container font-label-md text-label-md cursor-pointer"><input class="accent-error w-4 h-4" name="impact_level" type="radio" value="bahaya"/><span>Membahayakan Jiwa</span></label>
        </div>
        <span id="slaHint" class="font-body-sm text-body-sm text-on-surface-variant"></span>
      </div>
      <div class="flex flex-col gap-space-2xs md:col-span-2">
        <div class="flex items-center justify-between"><label class="font-label-md text-label-md" for="report_description">Deskripsi Detail Kejadian <span class="text-error">*</span></label><span class="font-label-sm text-label-sm text-on-surface-variant" id="charCount">0/500 Karakter</span></div>
        <textarea class="<?= $fld ?> resize-y" id="report_description" maxlength="500" name="report_description" minlength="10" required rows="4" placeholder="Jelaskan kronologi, kondisi kerusakan, tingkat bahaya bagi warga/pengendara, serta patokan lokasi..."><?= e($_POST['report_description'] ?? '') ?></textarea>
      </div>
      <div class="flex items-center gap-space-sm md:col-span-2 p-space-sm rounded-lg bg-surface-container-low">
        <input class="w-5 h-5 rounded accent-primary" id="is_anonymous" name="is_anonymous" type="checkbox" value="1"/>
        <label class="font-label-md text-label-md cursor-pointer" for="is_anonymous">Laporkan sebagai Rahasia / Tanpa Menampilkan Nama Publik (Anonim)</label>
        <span class="material-symbols-outlined text-secondary text-lg ml-auto" title="Identitas hanya dapat diakses administrator verifikator resmi.">lock</span>
      </div>
    </div>
  </div>

  <div class="flex flex-col gap-space-md">
    <div class="flex items-center justify-between"><div class="flex items-center gap-space-xs"><span class="w-2.5 h-6 rounded-full bg-tertiary inline-block"></span><h2 class="font-headline-md text-headline-md">Bagian B: Titik Lokasi Kejadian</h2></div>
      <span class="font-label-sm text-label-sm text-tertiary font-semibold flex items-center gap-1"><span class="material-symbols-outlined text-sm">my_location</span>Presisi Tinggi GPS</span></div>
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-space-lg items-start">
      <div class="lg:col-span-7 flex flex-col gap-space-xs">
        <div class="relative w-full h-72 rounded-xl overflow-hidden shadow-sm">
          <div id="pickMap" class="w-full h-full"></div>
          <div class="absolute top-space-sm left-space-sm z-[500] bg-inverse-surface/90 px-space-md py-space-xs rounded-lg text-inverse-on-surface flex items-center gap-space-sm shadow-md"><span class="material-symbols-outlined text-tertiary-fixed text-lg">pin_drop</span><span class="font-label-sm text-label-sm">Geser penanda untuk menetapkan titik akurat</span></div>
          <button type="button" onclick="useGPS()" class="absolute bottom-space-sm right-space-sm z-[500] inline-flex items-center gap-space-xs px-space-md py-space-xs rounded-lg bg-surface-container-lowest font-label-sm text-label-sm shadow-md hover:bg-surface-container-high"><span class="material-symbols-outlined text-primary text-base">near_me</span>Gunakan Lokasi GPS Saat Ini</button>
        </div>
        <span class="font-body-sm text-body-sm text-on-surface-variant">Klik pada peta atau geser penanda; sistem otomatis menentukan kecamatan terdekat.</span>
      </div>
      <div class="lg:col-span-5 flex flex-col gap-space-md">
        <div class="flex flex-col gap-space-2xs"><label class="font-label-md text-label-md" for="address_line">Alamat Lengkap / Patokan Lokasi <span class="text-error">*</span></label>
          <textarea class="<?= $fld ?> resize-none" id="address_line" name="address_line" required rows="3" placeholder="Jl. Cihampelas No. 120, depan toko obat, Kelurahan Cipaganti"><?= e($_POST['address_line'] ?? '') ?></textarea></div>
        <div class="grid grid-cols-2 gap-space-sm">
          <div class="flex flex-col gap-space-2xs"><label class="font-label-sm text-label-sm text-on-surface-variant" for="latitude">Latitude (Lintang)</label><input class="w-full px-space-sm py-space-xs rounded bg-surface-container font-mono text-body-sm" id="latitude" name="latitude" readonly value="<?= e($_POST['latitude'] ?? '-6.917464') ?>"/></div>
          <div class="flex flex-col gap-space-2xs"><label class="font-label-sm text-label-sm text-on-surface-variant" for="longitude">Longitude (Bujur)</label><input class="w-full px-space-sm py-space-xs rounded bg-surface-container font-mono text-body-sm" id="longitude" name="longitude" readonly value="<?= e($_POST['longitude'] ?? '107.619123') ?>"/></div>
        </div>
        <div class="flex items-start gap-space-sm p-space-sm rounded-lg bg-secondary-container/50"><span class="material-symbols-outlined text-secondary">account_balance</span>
          <div><div class="font-label-sm text-label-sm text-on-surface-variant">Instansi Penanggung Jawab Terduga:</div><div class="font-label-md text-label-md" id="agencyHint">-</div></div></div>
      </div>
    </div>
  </div>

  <div class="flex flex-col gap-space-md">
    <div class="flex items-center justify-between"><div class="flex items-center gap-space-xs"><span class="w-2.5 h-6 rounded-full bg-secondary inline-block"></span><h2 class="font-headline-md text-headline-md">Bagian C: Bukti Foto Pendukung</h2></div><span class="font-label-sm text-label-sm text-on-surface-variant">Format Foto Lapangan</span></div>
    <label for="photos" class="flex flex-col items-center justify-center gap-space-xs p-space-xl rounded-xl bg-surface-container-low cursor-pointer hover:bg-surface-container transition-colors text-center">
      <div class="w-12 h-12 rounded-full bg-primary-fixed text-primary flex items-center justify-center"><span class="material-symbols-outlined">cloud_upload</span></div>
      <span class="font-label-lg text-label-lg">Klik untuk memilih foto bukti</span>
      <span class="font-body-sm text-body-sm text-on-surface-variant">Maksimal 3 foto (JPG, PNG). Ukuran maks. 5MB per foto.</span>
      <input id="photos" name="photos[]" type="file" accept="image/jpeg,image/png" multiple class="hidden"/>
    </label>
    <div id="previews" class="flex flex-wrap gap-space-sm"></div>
  </div>

  <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-space-md pt-space-md">
    <p class="font-body-sm text-body-sm text-on-surface-variant max-w-md">Setelah dikirim, Anda mendapat nomor tiket untuk melacak laporan. Target waktu verifikasi mengikuti tingkat dampak yang dipilih.</p>
    <button type="submit" class="w-full sm:w-auto justify-center inline-flex items-center gap-space-xs px-space-lg py-space-sm rounded-lg bg-primary text-on-primary font-label-lg text-label-lg hover:bg-on-primary-fixed-variant shadow-sm"><span class="material-symbols-outlined text-xl">send</span>Kirim Laporan</button>
  </div>
</form>
<script>
const AG = <?= json_encode($catAgency, JSON_UNESCAPED_UNICODE) ?>;
const lat0 = parseFloat(document.getElementById('latitude').value), lng0 = parseFloat(document.getElementById('longitude').value);
const pm = L.map('pickMap').setView([lat0,lng0],15);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap'}).addTo(pm);
const mk = L.marker([lat0,lng0],{draggable:true}).addTo(pm);
function setPos(ll){mk.setLatLng(ll);document.getElementById('latitude').value=ll.lat.toFixed(6);document.getElementById('longitude').value=ll.lng.toFixed(6);}
mk.on('dragend',()=>setPos(mk.getLatLng())); pm.on('click',e=>setPos(e.latlng));
function useGPS(){ if(!navigator.geolocation){alert('Peramban tidak mendukung GPS.');return;}
  navigator.geolocation.getCurrentPosition(p=>{const ll=L.latLng(p.coords.latitude,p.coords.longitude);setPos(ll);pm.setView(ll,17);},()=>alert('Gagal mengambil lokasi. Izinkan akses lokasi pada peramban.'));}
const SLA = <?= json_encode(SLA_HOURS) ?>;
const hT = h => h >= 48 ? Math.round(h/24) + ' hari' : h + ' jam';
function slaUpd(){ const hz = document.querySelector('input[name=impact_level]:checked'); const p = hz && hz.value === 'bahaya' ? 'darurat' : 'sedang';
  document.getElementById('slaHint').textContent = 'Target verifikasi ' + hT(SLA[p].respond) + ' · target penyelesaian ' + hT(SLA[p].resolve) + ' (prioritas ' + p + ').'; }
document.querySelectorAll('input[name=impact_level]').forEach(r => r.addEventListener('change', slaUpd)); slaUpd();
const cs=document.getElementById('report_category'), ah=document.getElementById('agencyHint');
const upd=()=>ah.textContent=AG[cs.value]||'-'; cs.addEventListener('change',upd); upd();
const desc=document.getElementById('report_description'), cc=document.getElementById('charCount');
const cnt=()=>cc.textContent=desc.value.length+'/500 Karakter'; desc.addEventListener('input',cnt); cnt();
document.getElementById('photos').addEventListener('change',e=>{
  const box=document.getElementById('previews'); box.innerHTML='';
  const files=[...e.target.files].slice(0,3);
  if(e.target.files.length>3) alert('Hanya 3 foto pertama yang akan diunggah.');
  files.forEach(f=>{ if(f.size>5*1024*1024){alert(f.name+' melebihi 5MB dan akan dilewati.');}
    const d=document.createElement('div'); d.className='flex items-center gap-space-sm p-space-xs rounded-lg bg-surface-container-low';
    const i=document.createElement('img'); i.src=URL.createObjectURL(f); i.className='w-14 h-14 rounded object-cover';
    const t=document.createElement('div'); t.className='font-label-sm text-label-sm'; t.textContent=f.name+' ('+(f.size/1048576).toFixed(1)+' MB)';
    d.append(i,t); box.append(d); });
});
</script>

<?php elseif ($tab === 'detail'): ?>
<?php if (!$detail): ?>
  <div class="bg-surface-container-lowest rounded-xl p-space-xl shadow-sm">Laporan tidak ditemukan. <a class="text-primary font-semibold" href="laporan-saya.php?tab=riwayat">Kembali</a></div>
<?php else:
  $atts = db()->prepare('SELECT * FROM report_attachments WHERE report_id = ? ORDER BY kind, id'); $atts->execute([$detail['id']]); $atts = $atts->fetchAll();
  $resp = db()->prepare('SELECT rr.*, u.name FROM report_responses rr JOIN users u ON u.id = rr.user_id WHERE report_id = ? ORDER BY rr.id DESC'); $resp->execute([$detail['id']]); $resp = $resp->fetchAll();
  $logs = db()->prepare('SELECT * FROM report_logs WHERE report_id = ? ORDER BY id DESC'); $logs->execute([$detail['id']]); $logs = $logs->fetchAll();
  $sm = STATUS_META[$detail['status']]; ?>
  <a href="laporan-saya.php?tab=riwayat" class="inline-flex items-center gap-1 font-label-md text-label-md text-primary mb-space-md"><span class="material-symbols-outlined text-lg">arrow_back</span>Kembali ke riwayat</a>
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-gutter-sm">
    <div class="lg:col-span-2 bg-surface-container-lowest rounded-xl shadow-sm p-space-xl flex flex-col gap-space-md">
      <div class="flex flex-wrap items-center gap-2"><span class="font-mono text-body-sm text-on-surface-variant">Tiket #<?= e($detail['ticket_code']) ?></span><?= status_badge($detail['status']) ?><?php if ($detail['impact_level'] === 'bahaya'): ?><span class="px-2.5 py-0.5 rounded-full bg-error-container text-on-error-container font-label-sm text-label-sm font-bold">Membahayakan Jiwa</span><?php endif; ?></div>
      <h2 class="font-headline-lg text-headline-lg"><?= e($detail['title']) ?></h2>
      <p class="font-body-md text-body-md text-on-surface-variant whitespace-pre-line"><?= e($detail['description']) ?></p>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm font-body-sm text-body-sm">
        <div class="p-space-sm rounded bg-surface-container-low"><b>Kategori:</b> <?= e($detail['cat_name']) ?></div>
        <div class="p-space-sm rounded bg-surface-container-low"><b>Dilaporkan:</b> <?= e(format_tanggal($detail['created_at'], true)) ?></div>
        <div class="p-space-sm rounded bg-surface-container-low"><b>Lokasi:</b> <?= e($detail['address']) ?><?= $detail['district_name'] ? ' (Kec. ' . e($detail['district_name']) . ')' : '' ?></div>
        <div class="p-space-sm rounded bg-surface-container-low"><b>Instansi:</b> <?= e($detail['agency_name'] ?? '-') ?></div>
      </div>
      <?php if ($atts): ?><div class="flex flex-wrap gap-space-sm"><?php foreach ($atts as $a): ?>
        <a href="<?= e(UPLOAD_URL . $a['file_path']) ?>" target="_blank" class="relative"><img src="<?= e(UPLOAD_URL . $a['file_path']) ?>" class="w-40 h-32 object-cover rounded-lg" alt="foto"/><?php if ($a['kind'] === 'penyelesaian'): ?><span class="absolute top-1 left-1 px-2 rounded bg-emerald-600 text-white font-label-sm text-label-sm">Bukti Selesai</span><?php endif; ?></a>
      <?php endforeach; ?></div><?php endif; ?>
      <div id="dMap" class="w-full h-64 rounded-lg overflow-hidden"></div>
      <script>(function(){const m=L.map('dMap',{scrollWheelZoom:false}).setView([<?= (float)$detail['latitude'] ?>,<?= (float)$detail['longitude'] ?>],16);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19}).addTo(m);
        L.circleMarker([<?= (float)$detail['latitude'] ?>,<?= (float)$detail['longitude'] ?>],{radius:10,color:'#fff',weight:2,fillColor:'<?= $sm['color'] ?>',fillOpacity:1}).addTo(m);})();</script>
    </div>
    <div class="flex flex-col gap-gutter-sm">
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg">
        <h3 class="font-headline-sm text-headline-sm mb-space-md">Posisi Laporan</h3>
        <?= render_stepper($detail['status']) ?>
        <div class="flex flex-wrap items-center gap-2 mt-space-xs"><?= priority_badge($detail['priority']) ?><?= sla_badge($detail) ?></div>
        <?php if (in_array($detail['status'], ['pending', 'proses'], true) && (int)$detail['escalation_level'] > 0): ?>
          <p class="mt-space-sm p-space-sm rounded-lg bg-orange-50 text-orange-900 font-body-sm text-body-sm">Laporan ini telah dieskalasikan (tingkat <?= (int)$detail['escalation_level'] ?>) karena melewati batas waktu. Petugas dan admin sudah diberi tahu.</p>
        <?php endif; ?>
        <div class="mt-space-sm font-body-sm text-body-sm text-on-surface-variant">
          Target verifikasi: <?= $detail['respond_due_at'] ? e(format_tanggal($detail['respond_due_at'], true)) : '-' ?><br>
          Target selesai: <?= $detail['resolve_due_at'] ? e(format_tanggal($detail['resolve_due_at'], true)) : '-' ?>
        </div>
        <a href="lacak.php?tiket=<?= urlencode($detail['ticket_code']) ?>" class="inline-flex items-center gap-1 mt-space-sm font-label-md text-label-md text-primary hover:underline"><span class="material-symbols-outlined text-lg">link</span>Halaman pelacakan publik</a>
      </div>
      <?php if ((int)$detail['user_id'] === (int)$user['id'] && $detail['status'] === 'selesai'
                && (!$detail['resolved_at'] || strtotime($detail['resolved_at']) >= time() - REOPEN_WINDOW_DAYS * 86400)): ?>
      <details class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg">
        <summary class="cursor-pointer font-label-lg text-label-lg flex items-center gap-1"><span class="material-symbols-outlined text-lg text-primary">replay</span>Penanganan belum tuntas? Buka kembali</summary>
        <form method="post" action="laporan-saya.php" class="flex flex-col gap-space-sm mt-space-sm"><?= csrf_field() ?>
          <input type="hidden" name="do" value="reopen"><input type="hidden" name="tiket" value="<?= e($detail['ticket_code']) ?>">
          <textarea name="reason" rows="3" minlength="10" maxlength="300" required class="<?= $fld ?> resize-none" placeholder="Jelaskan apa yang belum selesai (10-300 karakter)"></textarea>
          <button class="self-start px-space-md py-space-xs rounded-lg bg-primary text-on-primary font-label-md text-label-md">Buka Kembali Laporan</button>
          <span class="font-body-sm text-body-sm text-on-surface-variant">Tersedia <?= (int)REOPEN_WINDOW_DAYS ?> hari sejak laporan dinyatakan selesai.</span>
        </form>
      </details>
      <?php endif; ?>
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg"><h3 class="font-headline-sm text-headline-sm mb-space-sm">Tanggapan Resmi</h3>
        <?php if (!$resp): ?><p class="font-body-sm text-body-sm text-on-surface-variant">Belum ada tanggapan.</p><?php endif; ?>
        <?php foreach ($resp as $r): ?><div class="p-space-sm rounded bg-surface-container-low mb-space-xs"><p class="font-body-sm text-body-sm">“<?= e($r['message']) ?>”</p><span class="font-label-sm text-label-sm text-on-surface-variant"><?= e($r['name']) ?> • <?= e(format_tanggal($r['created_at'], true)) ?></span></div><?php endforeach; ?></div>
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg"><h3 class="font-headline-sm text-headline-sm mb-space-sm">Riwayat Aktivitas</h3>
        <?php foreach ($logs as $l): ?><div class="border-l-2 border-primary-fixed-dim pl-space-sm pb-space-sm"><div class="font-label-sm text-label-sm text-on-surface-variant"><?= e(format_tanggal($l['created_at'], true)) ?></div><div class="font-body-sm text-body-sm"><?= e($l['note'] ?? $l['action']) ?></div></div><?php endforeach; ?></div>
    </div>
  </div>
<?php endif; ?>

<?php else: ?>
<div class="flex flex-col md:flex-row md:items-end justify-between gap-space-md mb-space-md">
  <div><h2 class="font-headline-lg text-headline-lg">Riwayat Pengaduan Terakhir Anda</h2><p class="font-body-md text-body-md text-on-surface-variant">Pantau tiket aduan, tanggapan dinas teknis, dan foto penyelesaian.</p></div>
  <div class="flex flex-wrap items-center gap-space-xs">
    <?php foreach (['semua' => ['Semua', $my['total']], 'pending' => ['Menunggu', $my['pending']], 'proses' => ['Diproses', $my['proses']], 'selesai' => ['Selesai', $my['selesai']], 'ditolak' => ['Ditolak', $my['ditolak']]] as $k => [$lb, $n]): ?>
      <a href="laporan-saya.php?tab=riwayat&status=<?= $k ?>" class="px-space-md py-1 rounded-full font-label-sm text-label-sm <?= $filter === $k ? 'bg-primary text-on-primary' : 'bg-surface-container text-on-surface hover:bg-surface-container-high' ?>"><?= $lb ?> (<?= $n ?>)</a>
    <?php endforeach; ?>
  </div>
</div>
<div class="flex flex-col gap-space-md mb-space-xl">
  <?php if (!$hist): ?><div class="bg-surface-container-lowest rounded-xl p-space-xl shadow-sm text-center text-on-surface-variant">Belum ada laporan yang sesuai. <a class="text-primary font-semibold" href="laporan-saya.php">Buat laporan baru</a>.</div><?php endif; ?>
  <?php foreach ($hist as $r):
    $sm = STATUS_META[$r['status']];
    $lr = db()->prepare('SELECT message FROM report_responses WHERE report_id = ? ORDER BY id DESC LIMIT 1'); $lr->execute([$r['id']]); $lr = $lr->fetchColumn(); ?>
  <div class="bg-surface-container-lowest rounded-xl shadow-sm border-l-4 <?= $sm['bar'] ?> p-space-lg flex flex-col md:flex-row md:items-center justify-between gap-space-md">
    <div class="flex flex-col gap-space-2xs min-w-0">
      <div class="flex flex-wrap items-center gap-2 font-label-sm text-label-sm text-on-surface-variant"><span class="font-mono">Tiket #<?= e($r['ticket_code']) ?></span><?= status_badge($r['status']) ?><?= sla_badge($r) ?><span><?= e(format_tanggal($r['created_at'], true)) ?></span></div>
      <?= mini_progress($r['status']) ?>
      <h3 class="font-headline-sm text-headline-sm"><?= e($r['title']) ?></h3>
      <p class="font-body-sm text-body-sm text-on-surface-variant line-clamp-2"><?= e($r['description']) ?></p>
      <p class="font-body-sm text-body-sm text-on-surface-variant flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">chat</span>Respon: <?= $lr ? '“' . e($lr) . '”' : 'Menunggu verifikasi petugas.' ?></p>
    </div>
    <a href="laporan-saya.php?tiket=<?= urlencode($r['ticket_code']) ?>" class="shrink-0 px-space-md py-space-xs rounded <?= $r['status'] === 'selesai' ? 'bg-tertiary-fixed text-on-tertiary-fixed' : 'bg-surface-container hover:bg-surface-container-high' ?> font-label-md text-label-md text-center"><?= $r['status'] === 'selesai' ? 'Lihat Bukti Selesai' : 'Lacak Laporan' ?></a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/app_bottom.php'; ?>
