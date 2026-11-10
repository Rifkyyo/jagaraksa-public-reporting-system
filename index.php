<?php
require_once __DIR__ . '/config.php';
$pageTitle = 'Beranda';

$st      = stats_overview();
$thisMon = (int)db()->query("SELECT COUNT(*) FROM reports WHERE deleted_at IS NULL AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetchColumn();
$pct     = $st['total'] ? round($st['selesai'] / $st['total'] * 100, 1) : 0;
$cats    = categories();

$tab   = $_GET['tab'] ?? 'terbaru';
$order = $tab === 'mendesak'
    ? "ORDER BY (r.priority = 'darurat') DESC, (r.impact_level = 'bahaya') DESC, r.created_at DESC"
    : 'ORDER BY r.created_at DESC';
$where = $tab === 'selesai' ? "WHERE r.deleted_at IS NULL AND r.status = 'selesai'" : 'WHERE r.deleted_at IS NULL';
$latest = db()->query("SELECT r.*, c.name AS cat_name FROM reports r
                       JOIN categories c ON c.id = r.category_id $where $order LIMIT 4")->fetchAll();

$markers = db()->query("SELECT r.id, r.ticket_code, r.title, r.address, r.latitude lat, r.longitude lng, r.status,
                               r.created_at, r.category_id
                        FROM reports r WHERE r.deleted_at IS NULL AND r.latitude IS NOT NULL ORDER BY r.created_at DESC LIMIT 300")->fetchAll();
foreach ($markers as &$m) {
    $m['lat'] = (float)$m['lat'];
    $m['lng'] = (float)$m['lng'];
    $m['label'] = STATUS_META[$m['status']]['label'];
    $m['color'] = STATUS_META[$m['status']]['color'];
    $m['ago']   = time_ago($m['created_at']);
}
unset($m);

include __DIR__ . '/includes/public_header.php';
$ctaHref = $user ? 'laporan-saya.php' : 'login.php';

$statCards = [
    ['Total Laporan',    $st['total'],   'folder',        'icon-theme-secondary', 'text-theme-emerald', 'trending_up', '+' . $thisMon . ' laporan bulan ini'],
    ['Menunggu',         $st['pending'], 'hourglass_top', 'icon-theme-amber',     'text-theme-amber',   'schedule',    'Verifikasi dinas terkait'],
    ['Sedang Diproses',  $st['proses'],  'build',         'icon-theme-primary',   'text-theme-primary', 'engineering', 'Dalam pengerjaan lapangan'],
    ['Tuntas Selesai',   $st['selesai'], 'check_circle',  'icon-theme-emerald',   'text-theme-emerald', 'task_alt',    'Tuntas ditangani dinas'],
];
$faqs = [
    ['Bagaimana alur penyampaian dan penanganan laporan di JagaRaksa?', 'steps'],
    ['Berapa lama laporan ditindaklanjuti oleh dinas terkait?', 'Verifikasi awal dilakukan maksimal 1x24 jam kerja. Untuk keadaan darurat (pohon tumbang, kabel putus) respon di bawah 12 jam, sedangkan perbaikan jalan dan drainase berkisar 3 hingga 7 hari kerja tergantung prioritas dinas.'],
    ['Apakah identitas pelapor dapat dirahasiakan (Anonim)?', 'Ya. Centang opsi anonim saat membuat laporan. Nama Anda tidak akan tampil di halaman publik; hanya admin verifikator yang dapat mengakses identitas pelapor.'],
    ['Informasi apa saja yang wajib disertakan saat mengirim laporan?', 'Judul, kategori, deskripsi kejadian, alamat/patokan lokasi, titik koordinat di peta, dan minimal satu foto bukti agar petugas dapat memverifikasi dengan cepat.'],
];
?>
<!-- Hubungkan stylesheet CSS Vanilla (misal: style.css) -->
<link rel="stylesheet" href="assets/index.css">
<meta charset="utf-8">

<main class="page-main">
  <?php if ($flashHtml = flash_render()): ?>
    <div class="flash-container"><?= $flashHtml ?></div>
  <?php endif; ?>

  <!-- HERO SECTION -->
  <div class="hero-wrapper">
    <div class="hero-bg-glow"></div>
    <section class="hero-section">
      <div class="hero-grid">
        <div class="hero-content">
          <div class="badge-official">
            <!-- <span class="flag-icon"></span> -->
            <span class="badge-official-text">Portal Aspirasi &amp; Pengaduan Fasilitas Publik</span>
          </div>
          <h1 class="jr-h1 hero-title">
            <span class="jr-ln" style="--d:0"><span>Dari <i>Kita</i>,</span></span>
            <span class="jr-ln" style="--d:1"><span>Untuk <i>Kita</i>,</span></span>
            <span class="jr-ln" style="--d:2"><span>dan Oleh <i>Kita</i>.</span></span>
          </h1>
          <p class="jr-sub hero-description">Sampaikan laporan dan aspirasi kita bersama JagaRaksa.</p>
          <div class="hero-actions">
            <a class="btn btn-hero-primary" href="<?= $ctaHref ?>">
              <span class="material-symbols-outlined icon-20">add_circle</span>
              <span>Buat Laporan</span>
            </a>
            <a class="btn btn-hero-secondary" href="#peta-laporan">
              <span class="material-symbols-outlined icon-20 text-secondary">explore</span>
              <span>Jelajahi Peta Laporan</span>
            </a>
          </div>
          <form method="get" action="lacak.php" class="flex items-stretch gap-2 mt-4 max-w-md">
            <input name="tiket" placeholder="Lacak tiket, mis. JR-<?= date('Y') ?>-0001" autocomplete="off" autocapitalize="characters" required
                   class="flex-1 min-w-0 px-4 py-2.5 rounded-lg border border-slate-300 bg-white font-mono text-sm focus:outline-none focus:ring-2 focus:ring-primary"/>
            <button class="px-4 py-2.5 rounded-lg bg-slate-900 text-white text-sm font-semibold hover:opacity-90">Lacak</button>
          </form>
          <?php if (!$user): ?>
          <p class="register-hint">Belum punya akun? <a class="register-link" href="login.php?tab=register">Daftar gratis</a> untuk mulai melapor.</p>
          <?php endif; ?>
          <div class="integration-note">
            <span class="material-symbols-outlined icon-18 icon-verified" style="font-variation-settings:'FILL' 1;">verified</span>
            <span>Terhubung dengan dinas terkait* | Rata-rata respons &lt; 48 jam</span>
          </div>
        </div>
        <div class="hero-map-wrapper">
          <div class="hero-map-card">
            <div class="hero-map-container">
              <div id="heroMap" class="full-map"></div>
              <div class="hero-map-badge-top">
                <span class="live-status-pill">
                  <span class="status-pulse-dot"></span>Radar Pemantauan Aktif
                </span>
                <span class="location-pill">Kota Bandung • Live</span>
              </div>
              <div class="hero-map-badge-bottom">
                <div class="stat-badge-icon">
                  <span class="material-symbols-outlined icon-22">verified</span>
                </div>
                <div class="stat-badge-text">
                  <span class="stat-badge-value"><?= $pct ?>% Ditangani</span>
                  <span class="stat-badge-label">Selesai terverifikasi dari seluruh laporan</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>

  <!-- STATISTIK SECTION -->
  <section id="statistik" class="section-statistik">
    <div class="container-grid-4">
      <?php foreach ($statCards as [$lbl, $val, $ico, $icoCls, $subCls, $subIco, $sub]): ?>
      <div class="stat-card">
        <div class="stat-card-header">
          <span class="stat-card-title"><?= e($lbl) ?></span>
          <div class="stat-card-icon-wrap <?= $icoCls ?>">
            <span class="material-symbols-outlined icon-22"><?= $ico ?></span>
          </div>
        </div>
        <div class="stat-card-body">
          <div class="stat-card-value"><?= number_format((int)$val) ?></div>
          <div class="stat-card-sub <?= $subCls ?>">
            <span class="material-symbols-outlined icon-16"><?= $subIco ?></span>
            <span><?= e($sub) ?></span>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- PETA SECTION -->
  <section id="peta-laporan" class="section-peta">
    <div class="container-flex-col">
      <div class="section-header">
        <span class="section-tag font-bold">Pusat Data Spasial</span>
        <h2 class="section-title">Peta Sebaran Laporan Terkini</h2>
        <p class="section-subtitle">Pantau lokasi kerusakan jalan, lampu penerangan padam, dan drainase bermasalah secara transparan dan akuntabel.</p>
      </div>
      <div class="map-controls-card">
        <div class="search-input-wrapper">
          <span class="material-symbols-outlined search-icon">search</span>
          <input id="mapSearch" class="search-input" placeholder="Cari nama jalan atau nomor tiket laporan..." type="text"/>
        </div>
        <div class="category-filters" id="catFilters">
          <button data-cat="" class="btn-cat-filter active">Semua Kategori</button>
          <?php foreach ($cats as $c): ?>
            <button data-cat="<?= $c['id'] ?>" class="btn-cat-filter"><?= e($c['name']) ?></button>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="main-map-card">
        <div id="mainMap" class="full-map"></div>
        <div class="map-legend">
          <span class="legend-label">STATUS:</span>
          <?php foreach (STATUS_META as $m): ?>
            <span class="legend-item">
              <span class="legend-dot" style="background:<?= $m['color'] ?>"></span>
              <?= e($m['label']) ?>
            </span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- LAPORAN TERBARU SECTION -->
  <section class="section-laporan">
    <div class="container-flex-col">
      <div class="laporan-header-wrap">
        <div>
          <span class="section-tag font-bold">Aktivitas Terbaru</span>
          <h2 class="section-title">Laporan Masyarakat Terbaru</h2>
        </div>
        <div class="tab-filters">
          <?php foreach (['semua' => 'Semua', 'terbaru' => 'Terbaru', 'mendesak' => 'Paling Mendesak', 'selesai' => 'Selesai'] as $k => $v):
            $on = ($tab === $k) || ($k === 'semua' && $tab === 'terbaru' && !isset($_GET['tab'])); ?>
            <a href="?tab=<?= $k === 'semua' ? 'terbaru' : $k ?>#laporan-terbaru" class="tab-link <?= ($tab === $k) ? 'active' : '' ?>"><?= $v ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <div id="laporan-terbaru" class="container-grid-4">
        <?php if (!$latest): ?>
          <p class="empty-state">Belum ada laporan.</p>
        <?php endif; ?>
        <?php foreach ($latest as $r): $photo = first_photo((int)$r['id']); $sm = STATUS_META[$r['status']]; ?>
        <div class="report-card group">
          <div class="report-card-media">
            <?php if ($photo): ?>
              <img class="report-card-img" src="<?= e($photo) ?>" alt="<?= e($r['title']) ?>"/>
            <?php else: ?>
              <div class="report-card-placeholder">
                <span class="material-symbols-outlined icon-placeholder">image</span>
              </div>
            <?php endif; ?>
            <span class="status-badge <?= $sm['badge'] ?>"><?= e($sm['label']) ?></span>
            <span class="category-badge"><?= e($r['cat_name']) ?></span>
          </div>
          <div class="report-card-body">
            <div class="report-card-text">
              <h3 class="report-card-title"><?= e($r['title']) ?></h3>
              <p class="report-card-location">
                <span class="material-symbols-outlined icon-16 text-secondary">location_on</span>
                <span class="truncate"><?= e($r['address']) ?></span>
              </p>
            </div>
            <div class="report-card-footer">
              <span class="report-card-date"><?= e(format_tanggal($r['created_at'])) ?></span>
              <a class="report-card-link" href="lacak.php?tiket=<?= urlencode($r['ticket_code']) ?>">
                Lihat Detail <span class="material-symbols-outlined icon-16">arrow_forward</span>
              </a>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- FAQ SECTION -->
  <section id="faq" class="section-faq">
    <div class="faq-container">
      <div class="faq-header">
        <span class="section-tag font-bold">Pusat Informasi</span>
        <h2 class="section-title">Pertanyaan Umum (FAQ)</h2>
        <p class="section-subtitle">Ketahui mekanisme transparansi, hak privasi warga, serta standar operasional penanganan fasilitas publik oleh pemerintah daerah.</p>
      </div>
      <?php foreach ($faqs as $i => [$q, $a]): ?>
      <div class="faq-card">
        <button type="button" aria-expanded="<?= $i === 0 ? 'true' : 'false' ?>" class="faq-toggle">
          <span class="faq-question"><?= e($q) ?></span>
          <span class="material-symbols-outlined faq-icon <?= $i === 0 ? 'rotate-180' : '' ?>">expand_more</span>
        </button>
        <div class="faq-content <?= $i === 0 ? '' : 'is-hidden' ?>">
          <?php if ($a === 'steps'): ?>
          <p class="faq-steps-intro">Setiap laporan warga diproses melalui 4 tahapan audit transparan dengan notifikasi berjenjang:</p>
          <div class="steps-grid">
            <?php foreach ([['1. Pelaporan','Warga mengisi formulir, lokasi GPS, dan bukti foto.','text-theme-primary'],['2. Verifikasi','Admin dinas memvalidasi kelayakan & wewenang instansi.','text-theme-primary'],['3. Lapangan','Petugas teknis diterjunkan untuk perbaikan fisik.','text-theme-primary'],['4. Tuntas','Unggah foto hasil pengerjaan & penutupan tiket aduan.','text-theme-tertiary-container']] as [$t,$d,$c]): ?>
              <div class="step-item">
                <span class="step-title <?= $c ?>"><?= e($t) ?></span>
                <span class="step-desc"><?= e($d) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
            <p class="faq-answer"><?= e($a) ?></p>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- CTA SECTION -->
  <section class="section-cta">
    <div class="cta-banner">
      <div class="cta-content">
        <h2 class="cta-title">Menemukan fasilitas rusak hari ini?</h2>
        <p class="cta-desc">Laporkan sekarang dalam 2 menit tanpa dipungut biaya. Partisipasi Anda adalah langkah nyata pembangunan kota yang aman dan nyaman.</p>
      </div>
      <a class="btn btn-cta" href="<?= $ctaHref ?>">
        <span class="material-symbols-outlined icon-20">send</span>
        <span>Kirim Laporan Sekarang</span>
      </a>
      <div class="cta-bg-circle"></div>
    </div>
  </section>
</main>

<script>
const REPORTS = <?= json_encode($markers, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const BDG = [-6.9147, 107.6098];
function esc(s){return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function makeMap(id, zoom, interactive){
  const m = L.map(id,{zoomControl:interactive,scrollWheelZoom:false,dragging:interactive}).setView(BDG,zoom);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap'}).addTo(m);
  return m;
}
function pin(r){
  return L.circleMarker([r.lat,r.lng],{radius:9,color:'#fff',weight:2,fillColor:r.color,fillOpacity:.95})
    .bindPopup('<b>#'+esc(r.ticket_code)+'</b> <small>('+esc(r.label)+')</small><br>'+esc(r.title)+'<br><small>'+esc(r.address)+' • '+esc(r.ago)+'</small>');
}
const hero = makeMap('heroMap', 12, false);
REPORTS.forEach(r=>pin(r).addTo(hero));

const main = makeMap('mainMap', 13, true);
const layer = L.layerGroup().addTo(main);
let cat = '', q = '';
function render(){
  layer.clearLayers();
  REPORTS.filter(r=>(!cat||String(r.category_id)===cat)&&(!q||(r.title+r.address+r.ticket_code).toLowerCase().includes(q)))
         .forEach(r=>pin(r).addTo(layer));
}
render();
document.getElementById('mapSearch').addEventListener('input',e=>{q=e.target.value.toLowerCase().trim();render();});
document.querySelectorAll('#catFilters button').forEach(b=>b.addEventListener('click',()=>{
  cat=b.dataset.cat; render();
  document.querySelectorAll('#catFilters button').forEach(x=>{
    if(x===b){
      x.className='btn-cat-filter active';
    } else {
      x.className='btn-cat-filter';
    }
  });
}));

document.querySelectorAll('.faq-toggle').forEach(btn=>btn.addEventListener('click',()=>{
  const open = btn.getAttribute('aria-expanded')==='true';
  document.querySelectorAll('.faq-toggle').forEach(o=>{
    o.setAttribute('aria-expanded','false');
    o.nextElementSibling.classList.add('is-hidden');
    o.querySelector('.faq-icon').classList.remove('rotate-180');
  });
  if(!open){
    btn.setAttribute('aria-expanded','true');
    btn.nextElementSibling.classList.remove('is-hidden');
    btn.querySelector('.faq-icon').classList.add('rotate-180');
  }
}));
</script>
<?php include __DIR__ . '/includes/public_footer.php'; ?>