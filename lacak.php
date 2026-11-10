<?php
require_once __DIR__ . '/config.php';
$pageTitle = 'Lacak Laporan';

$raw      = trim((string)($_GET['tiket'] ?? ''));
$code     = normalize_ticket($raw);
$data     = $code !== '' ? public_report($code) : null;
$searched = $raw !== '';

include __DIR__ . '/includes/public_header.php';   // mendefinisikan $user

// Pemilik laporan / petugas mendapat pintasan ke halaman kelola.
$myLink = null;
if ($data && $user) {
    $ow = db()->prepare('SELECT user_id FROM reports WHERE id = ?');
    $ow->execute([$data['report']['id']]);
    $ownerId = (int)$ow->fetchColumn();
    if (is_staff($user)) {
        $myLink = ['kelola-laporan.php?id=' . (int)$data['report']['id'], 'Buka di Kelola Laporan'];
    } elseif ($ownerId === (int)$user['id']) {
        $myLink = ['laporan-saya.php?tiket=' . urlencode($data['report']['ticket_code']), 'Buka di Laporan Saya (bukti & buka kembali)'];
    }
}
$card = 'bg-surface-container-lowest rounded-xl shadow-sm p-space-lg';
?>
<main class="pt-28 pb-6 px-[5vw] bg-background">
<div class="max-w-[960px] mx-auto">
  <?= flash_render() ?>

  <div class="mb-space-lg">
    <h1 class="font-headline-xl text-headline-xl tracking-tight" style="font-family:'Bricolage Grotesque',Poppins,sans-serif">Lacak Laporan</h1>
    <p class="font-body-md text-body-md text-on-surface-variant mt-space-2xs">Masukkan nomor tiket untuk melihat posisi terkini laporan, target waktu penanganan, dan riwayatnya. Tidak perlu login.</p>
  </div>

  <form method="get" action="lacak.php" class="<?= $card ?> mb-space-lg flex flex-col sm:flex-row gap-space-sm">
    <label for="tiket" class="sr-only">Nomor tiket</label>
    <input id="tiket" name="tiket" value="<?= e($raw) ?>" placeholder="Contoh: JR-<?= date('Y') ?>-0001" autocomplete="off" autocapitalize="characters" spellcheck="false" required
           class="flex-1 min-w-0 px-space-md py-3 rounded-lg bg-surface-container-low font-mono text-body-lg focus:outline-none focus:ring-2 focus:ring-primary"/>
    <button type="submit" class="inline-flex items-center justify-center gap-space-xs px-space-lg py-3 rounded-lg bg-primary text-on-primary font-label-lg text-label-lg hover:bg-on-primary-fixed-variant">
      <span class="material-symbols-outlined">search</span>Lacak
    </button>
  </form>

<?php if ($searched && !$data): ?>
  <div class="<?= $card ?> flex items-start gap-space-sm">
    <span class="material-symbols-outlined text-error">search_off</span>
    <div>
      <p class="font-label-lg text-label-lg">Tiket tidak ditemukan</p>
      <p class="font-body-sm text-body-sm text-on-surface-variant">Periksa kembali nomor tiketnya. Formatnya <span class="font-mono">JR-TAHUN-NNNN</span> dan tertera pada notifikasi saat laporan dikirim.</p>
    </div>
  </div>

<?php elseif ($data):
    $r  = $data['report'];
    $sm = STATUS_META[$r['status']];
    $open = in_array($r['status'], ['pending', 'proses'], true);
    $ig   = $data['integrity'];
    $igOk = !$ig['broken'] && empty($ig['error']);
?>
  <div class="flex flex-col gap-space-md">

    <div class="<?= $card ?>">
      <div class="flex flex-wrap items-center gap-2 mb-space-xs">
        <span class="font-mono text-body-sm text-on-surface-variant">Tiket #<?= e($r['ticket_code']) ?></span>
        <?= status_badge($r['status']) ?>
        <?= priority_badge($r['priority']) ?>
        <?php if ($r['impact_level'] === 'bahaya'): ?><span class="px-2.5 py-0.5 rounded-full bg-error-container text-on-error-container font-label-sm text-label-sm font-bold">Membahayakan Jiwa</span><?php endif; ?>
      </div>
      <h2 class="font-headline-lg text-headline-lg"><?= e($r['title']) ?></h2>
      <p class="font-body-sm text-body-sm text-on-surface-variant mt-space-2xs">
        <?= e($r['cat_name']) ?> &bull; <?= e($r['address']) ?><?= $r['district_name'] ? ' (Kec. ' . e($r['district_name']) . ')' : '' ?>
      </p>
      <p class="font-body-sm text-body-sm text-on-surface-variant">Dilaporkan <?= e(format_tanggal($r['created_at'], true)) ?><?= $r['agency_name'] ? ' &bull; Instansi: ' . e($r['agency_name']) : ' &bull; Belum didisposisikan' ?></p>
      <div class="flex flex-wrap gap-space-sm mt-space-md">
        <button type="button" id="shareBtn" class="inline-flex items-center gap-1 px-space-md py-space-xs rounded-lg bg-surface-container font-label-md text-label-md hover:bg-surface-container-high"><span class="material-symbols-outlined text-lg">share</span><span id="shareTxt">Bagikan tautan</span></button>
        <?php if ($myLink): ?><a href="<?= e($myLink[0]) ?>" class="inline-flex items-center gap-1 px-space-md py-space-xs rounded-lg bg-primary-fixed text-primary font-label-md text-label-md"><span class="material-symbols-outlined text-lg">open_in_new</span><?= e($myLink[1]) ?></a><?php endif; ?>
      </div>
    </div>

    <?php if ($open && (int)$r['escalation_level'] > 0): ?>
    <div class="flex items-start gap-space-sm p-space-md rounded-xl bg-orange-50 text-orange-900">
      <span class="material-symbols-outlined shrink-0">priority_high</span>
      <p class="font-body-sm text-body-sm"><b>Laporan ini telah dieskalasikan (tingkat <?= (int)$r['escalation_level'] ?>)</b> karena melewati batas waktu layanan. Instansi terkait<?= (int)$r['escalation_level'] >= 2 ? ' dan admin kota' : '' ?> telah diberi tahu dan prioritasnya dinaikkan.</p>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-space-md">
      <div class="<?= $card ?>">
        <h3 class="font-headline-sm text-headline-sm mb-space-md">Posisi Laporan</h3>
        <?= render_stepper($r['status']) ?>
      </div>

      <div class="<?= $card ?> flex flex-col gap-space-sm">
        <h3 class="font-headline-sm text-headline-sm">Target Waktu Penanganan</h3>
        <div class="p-space-sm rounded-lg bg-surface-container-low">
          <div class="font-label-sm text-label-sm text-on-surface-variant uppercase">Verifikasi / respons pertama</div>
          <div class="font-body-md text-body-md"><?= $r['respond_due_at'] ? 'Paling lambat ' . e(format_tanggal($r['respond_due_at'], true)) : '-' ?></div>
          <div class="font-body-sm text-body-sm text-on-surface-variant">
            <?php if ($r['first_response_at']): $ontime = !$r['respond_due_at'] || strtotime($r['first_response_at']) <= strtotime($r['respond_due_at']); ?>
              Ditanggapi <?= e(format_tanggal($r['first_response_at'], true)) ?> (<?= $ontime ? 'tepat waktu' : 'terlambat' ?>)
            <?php elseif ($r['status'] === 'pending'): ?>Menunggu tanggapan petugas<?php else: ?>-<?php endif; ?>
          </div>
        </div>
        <div class="p-space-sm rounded-lg bg-surface-container-low">
          <div class="font-label-sm text-label-sm text-on-surface-variant uppercase">Penyelesaian</div>
          <div class="font-body-md text-body-md"><?= $r['resolve_due_at'] ? 'Paling lambat ' . e(format_tanggal($r['resolve_due_at'], true)) : '-' ?></div>
          <div class="font-body-sm text-body-sm text-on-surface-variant"><?= $r['resolved_at'] ? 'Selesai ' . e(format_tanggal($r['resolved_at'], true)) : ($r['status'] === 'ditolak' ? 'Laporan ditutup' : 'Belum selesai') ?></div>
        </div>
        <div><?= sla_badge($r) ?></div>
      </div>
    </div>

    <?php if ($data['responses']): ?>
    <div class="<?= $card ?>">
      <h3 class="font-headline-sm text-headline-sm mb-space-sm">Tanggapan Resmi</h3>
      <?php foreach (array_reverse($data['responses']) as $x): ?>
        <div class="p-space-sm rounded-lg bg-surface-container-low mb-space-xs">
          <p class="font-body-md text-body-md">&ldquo;<?= e($x['message']) ?>&rdquo;</p>
          <span class="font-label-sm text-label-sm text-on-surface-variant"><?= e($x['by']) ?> &bull; <?= e(format_tanggal($x['time'], true)) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($data['done_photos']): ?>
    <div class="<?= $card ?>">
      <h3 class="font-headline-sm text-headline-sm mb-space-sm">Bukti Penyelesaian</h3>
      <div class="flex flex-wrap gap-space-sm">
        <?php foreach ($data['done_photos'] as $u): ?>
          <a href="<?= e($u) ?>" target="_blank" rel="noopener"><img src="<?= e($u) ?>" alt="Foto bukti penyelesaian" loading="lazy" class="w-40 h-32 object-cover rounded-lg"/></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="<?= $card ?>">
      <h3 class="font-headline-sm text-headline-sm mb-space-sm">Riwayat Penanganan</h3>
      <?php foreach (array_reverse($data['timeline']) as $t): ?>
        <div class="border-l-2 border-primary-fixed-dim pl-space-sm pb-space-sm">
          <div class="font-label-sm text-label-sm text-on-surface-variant"><?= e(format_tanggal($t['time'], true)) ?></div>
          <div class="font-body-md text-body-md"><?= e($t['text']) ?></div>
        </div>
      <?php endforeach; ?>
      <div class="mt-space-xs flex items-start gap-space-xs font-body-sm text-body-sm <?= $igOk ? 'text-emerald-700' : 'text-error' ?>">
        <span class="material-symbols-outlined text-lg shrink-0"><?= $igOk ? 'verified_user' : 'gpp_maybe' ?></span>
        <span>
          <?php if (!$igOk): ?>
            Peringatan: terdeteksi catatan riwayat yang tidak sesuai dengan sidik jarinya. Admin telah dapat melihatnya pada halaman Audit.
          <?php elseif ($ig['checked'] > 0): ?>
            Integritas riwayat terverifikasi: <?= (int)$ig['checked'] ?> catatan berantai (SHA-256) utuh<?= $ig['legacy'] ? ', ' . (int)$ig['legacy'] . ' catatan lama dibuat sebelum fitur ini' : '' ?>.
          <?php else: ?>
            Riwayat ini dibuat sebelum fitur verifikasi integritas, sehingga belum berantai.
          <?php endif; ?>
        </span>
      </div>
    </div>

    <div class="<?= $card ?>">
      <h3 class="font-headline-sm text-headline-sm mb-space-sm">Lokasi</h3>
      <div id="dMap" class="w-full h-56 md:h-72 rounded-lg overflow-hidden"></div>
    </div>
  </div>

  <script>
  (function(){
    const m=L.map('dMap',{scrollWheelZoom:false}).setView([<?= (float)$r['latitude'] ?>,<?= (float)$r['longitude'] ?>],16);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap'}).addTo(m);
    L.circleMarker([<?= (float)$r['latitude'] ?>,<?= (float)$r['longitude'] ?>],{radius:10,color:'#fff',weight:2,fillColor:'<?= e($sm['color']) ?>',fillOpacity:1}).addTo(m);
    const b=document.getElementById('shareBtn'),t=document.getElementById('shareTxt');
    b.addEventListener('click',async()=>{
      const url=location.href.split('#')[0];
      try{
        if(navigator.share){await navigator.share({title:'Laporan <?= e($r['ticket_code']) ?>',url});return;}
        await navigator.clipboard.writeText(url); t.textContent='Tautan disalin';
      }catch(e){ t.textContent='Salin dari bilah alamat'; }
      setTimeout(()=>t.textContent='Bagikan tautan',2500);
    });
  })();
  </script>
<?php endif; ?>

  <p class="mt-space-lg font-body-sm text-body-sm text-on-surface-variant">Ingin melihat kinerja penanganan secara keseluruhan? <a class="text-primary font-semibold" href="transparansi.php">Buka halaman Transparansi Kinerja</a>.</p>
</div>
</main>
<?php include __DIR__ . '/includes/public_footer.php'; ?>
