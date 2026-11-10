<?php
require_once __DIR__ . '/config.php';
$user   = require_login();
$active = 'peta';
$pageTitle = 'Peta Laporan (GIS)';
$scope  = 'WHERE r.deleted_at IS NULL' . (is_staff($user) ? '' : ' AND r.user_id = ' . (int)$user['id']);
$pts = db()->query("SELECT r.ticket_code, r.title, r.address, r.latitude lat, r.longitude lng, r.status, r.category_id, c.name cat
                    FROM reports r JOIN categories c ON c.id = r.category_id $scope")->fetchAll();
foreach ($pts as &$p) { $p['lat'] = (float)$p['lat']; $p['lng'] = (float)$p['lng']; $p['color'] = STATUS_META[$p['status']]['color']; $p['label'] = STATUS_META[$p['status']]['label']; }
unset($p);
include __DIR__ . '/includes/app_top.php';
?>
<div class="mb-space-md">
  <h1 class="font-headline-xl text-headline-xl tracking-tight">Peta Laporan (GIS)</h1>
  <p class="font-body-md text-body-md text-on-surface-variant"><?= is_staff($user) ? 'Seluruh sebaran laporan warga di wilayah kota.' : 'Sebaran laporan yang Anda kirim.' ?></p>
</div>
<div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md mb-space-md flex flex-wrap gap-space-sm items-center">
  <select id="fs" class="rounded bg-surface-container-low px-space-sm py-space-xs"><option value="">Semua Status</option><?php foreach (STATUS_META as $k => $m): ?><option value="<?= $k ?>"><?= e($m['label']) ?></option><?php endforeach; ?></select>
  <select id="fc" class="rounded bg-surface-container-low px-space-sm py-space-xs"><option value="">Semua Kategori</option><?php foreach (categories() as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select>
  <span id="cnt" class="font-label-md text-label-md text-on-surface-variant ml-auto"></span>
</div>
<div id="gis" class="w-full h-[70vh] rounded-xl overflow-hidden shadow-md"></div>
<script>
const P = <?= json_encode($pts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const map=L.map('gis').setView([-6.9147,107.6098],12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap'}).addTo(map);
const g=L.layerGroup().addTo(map);
function draw(){g.clearLayers();const s=fs.value,c=fc.value;let n=0;
  P.filter(r=>(!s||r.status===s)&&(!c||String(r.category_id)===c)).forEach(r=>{n++;
    L.circleMarker([r.lat,r.lng],{radius:9,color:'#fff',weight:2,fillColor:r.color,fillOpacity:.95})
     .bindPopup('<b>#'+esc(r.ticket_code)+'</b> ('+esc(r.label)+')<br>'+esc(r.title)+'<br><small>'+esc(r.cat)+' • '+esc(r.address)+'</small><br><a href="laporan-saya.php?tiket='+encodeURIComponent(r.ticket_code)+'">Lihat detail</a>').addTo(g);});
  cnt.textContent=n+' titik ditampilkan';}
fs.onchange=fc.onchange=draw;draw();
</script>
<?php include __DIR__ . '/includes/app_bottom.php'; ?>
