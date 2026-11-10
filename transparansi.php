<?php
require_once __DIR__ . '/config.php';
$pageTitle = 'Transparansi Kinerja';
$S = public_stats();

include __DIR__ . '/includes/public_header.php';

function hrs($h)
{
    if ($h === null) {
        return '-';
    }
    return $h >= 48 ? round($h / 24, 1) . ' hari' : $h . ' jam';
}

$card = 'bg-surface-container-lowest rounded-xl shadow-sm p-space-lg';
$kpis = [
    ['Total Laporan', number_format($S['total']), 'folder', 'text-primary'],
    ['Telah Selesai', $S['resolved_pct'] . '%', 'check_circle', 'text-emerald-700'],
    ['Kepatuhan SLA', $S['sla_compliance_pct'] === null ? '-' : $S['sla_compliance_pct'] . '%', 'task_alt', 'text-emerald-700'],
    ['Rata-rata Respons Pertama', hrs($S['avg_first_response_hours']), 'bolt', 'text-primary'],
    ['Rata-rata Penyelesaian', hrs($S['avg_resolve_hours']), 'timer', 'text-primary'],
    ['Terbuka & Terlambat', number_format($S['overdue_open']), 'alarm', $S['overdue_open'] > 0 ? 'text-error' : 'text-emerald-700'],
];
?>
<main class="pt-28 pb-6 px-[5vw] bg-background">
<div class="max-w-[1100px] mx-auto">
  <div class="mb-space-lg">
    <h1 class="font-headline-xl text-headline-xl tracking-tight" style="font-family:'Bricolage Grotesque',Poppins,sans-serif">Transparansi Kinerja</h1>
    <p class="font-body-md text-body-md text-on-surface-variant mt-space-2xs max-w-2xl">Ringkasan terbuka bagaimana laporan warga ditangani: seberapa cepat, seberapa tuntas, dan instansi mana yang menanganinya. Hanya data agregat; tidak memuat identitas pelapor maupun petugas.</p>
  </div>

  <div class="grid grid-cols-2 lg:grid-cols-3 gap-space-sm mb-space-lg">
    <?php foreach ($kpis as [$l, $v, $ic, $cl]): ?>
    <div class="<?= $card ?> !p-space-md">
      <div class="flex items-center gap-1 font-label-sm text-label-sm text-on-surface-variant"><span class="material-symbols-outlined text-[18px] <?= $cl ?>"><?= $ic ?></span><?= e($l) ?></div>
      <div class="font-headline-xl text-headline-xl mt-space-2xs <?= $cl ?>"><?= e($v) ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php
  $blocks = [['Kinerja per Instansi', $S['by_agency']], ['Per Kategori Masalah', $S['by_category']], ['Sebaran per Kecamatan', $S['by_district']]];
  foreach ($blocks as [$ttl, $rows]):
      $max = 1;
      foreach ($rows as $x) {
          $max = max($max, $x['total']);
      }
  ?>
  <div class="<?= $card ?> mb-space-md">
    <h2 class="font-headline-md text-headline-md mb-space-sm"><?= e($ttl) ?></h2>
    <div class="overflow-x-auto">
    <table class="w-full text-left min-w-[560px]">
      <thead class="font-label-sm text-label-sm text-on-surface-variant uppercase"><tr><th class="py-2 pr-3">Nama</th><th class="py-2 pr-3">Laporan</th><th class="py-2 pr-3">Selesai</th><th class="py-2 pr-3">Terbuka</th><th class="py-2 pr-3">Tepat waktu</th><th class="py-2">Rata-rata selesai</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $x): ?>
        <tr class="border-t border-surface-container align-middle">
          <td class="py-2 pr-3 font-label-md text-label-md"><?= e($x['name']) ?></td>
          <td class="py-2 pr-3 min-w-[130px]"><div class="flex items-center gap-2"><div class="h-2 flex-1 bg-surface-container rounded-full overflow-hidden"><div class="h-full bg-primary rounded-full" style="width:<?= round($x['total'] / $max * 100) ?>%"></div></div><span class="font-body-sm text-body-sm w-6 text-right"><?= (int)$x['total'] ?></span></div></td>
          <td class="py-2 pr-3 font-body-sm text-body-sm"><?= (int)$x['selesai'] ?></td>
          <td class="py-2 pr-3 font-body-sm text-body-sm"><?= (int)$x['terbuka'] ?></td>
          <td class="py-2 pr-3 font-body-sm text-body-sm"><?= $x['selesai'] ? round($x['tepat_waktu'] / $x['selesai'] * 100) . '%' : '-' ?></td>
          <td class="py-2 font-body-sm text-body-sm"><?= e(hrs($x['rata_jam'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endforeach; ?>

  <div class="<?= $card ?> flex flex-col md:flex-row md:items-center justify-between gap-space-sm">
    <div>
      <h2 class="font-headline-sm text-headline-sm">Data terbuka</h2>
      <p class="font-body-sm text-body-sm text-on-surface-variant">Angka di halaman ini tersedia dalam format JSON untuk peneliti, jurnalis, dan aplikasi lain. Diperbarui <?= e(format_tanggal($S['generated_at'], true)) ?>.</p>
    </div>
    <div class="flex flex-wrap gap-space-sm">
      <a href="api.php?a=public_stats" class="inline-flex items-center gap-1 px-space-md py-space-xs rounded-lg bg-surface-container font-label-md text-label-md hover:bg-surface-container-high"><span class="material-symbols-outlined text-lg">data_object</span>Statistik (JSON)</a>
      <a href="lacak.php" class="inline-flex items-center gap-1 px-space-md py-space-xs rounded-lg bg-primary text-on-primary font-label-md text-label-md"><span class="material-symbols-outlined text-lg">search</span>Lacak laporan</a>
    </div>
  </div>
</div>
</main>
<?php include __DIR__ . '/includes/public_footer.php'; ?>
