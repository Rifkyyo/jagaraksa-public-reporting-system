<?php
/** Layout area pengguna (sidebar + header). Butuh: $user, $active, $pageTitle */
require_once __DIR__ . '/../config.php';
$user = $user ?? require_login();
$menu = [
    ['beranda',        'index.php',            'home',            'Beranda',             ['admin','petugas','pelapor']],
    ['dashboard',      'dashboard.php',        'dashboard',       'Dashboard',           ['admin','petugas','pelapor']],
    ['peta',           'peta.php',             'map',             'Peta Laporan (GIS)',  ['admin','petugas','pelapor']],
    ['laporan-saya',   'laporan-saya.php',     'assignment_ind',  'Laporan Saya',        ['admin','petugas','pelapor']],
    ['kelola',         'kelola-laporan.php',   'rule_folder',     'Kelola Laporan',      ['admin','petugas']],
    ['analitik',       'analitik.php',         'insights',        'Analitik & SLA',      ['admin','petugas']],
    ['kategori',       'kategori-wilayah.php', 'share_location',  'Kategori & Wilayah',  ['admin']],
    ['pengguna',       'pengguna.php',         'group',           'Pengguna',            ['admin']],
    ['audit',          'audit.php',            'verified_user',   'Audit & Integritas',  ['admin']],
];
if (is_staff($user)) {
    run_escalation();   // eskalasi SLA otomatis (dibatasi tiap 5 menit)
}
$notifs = db()->prepare('SELECT n.*, r.ticket_code FROM notifications n LEFT JOIN reports r ON r.id = n.report_id WHERE n.user_id = ? ORDER BY n.id DESC LIMIT 6');
$notifs->execute([$user['id']]);
$notifs = $notifs->fetchAll();
$unread = (int)db()->query('SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND user_id = ' . (int)$user['id'])->fetchColumn();
$sla = sla_overview();
$slaPct = $sla['compliance'] === null ? '-' : $sla['compliance'] . '%';
$searchAction = is_staff($user) ? 'kelola-laporan.php' : 'laporan-saya.php';
include __DIR__ . '/head.php';
?>
<body class="bg-background font-body-md text-on-surface antialiased">
<aside id="sidebar" class="fixed left-0 top-0 h-full w-72 bg-inverse-surface text-inverse-on-surface z-[1200] flex flex-col justify-between shadow-[0_1px_8px_rgba(16,42,67,0.08)] -translate-x-full lg:translate-x-0 transition-transform">
  <div class="flex flex-col">
    <a href="index.php" class="h-20 px-space-lg flex items-center gap-space-md">
      <div class="flex flex-col">
        <span class="font-extrabold text-2xl text-primary tracking-tight leading-none" style="font-family:'Bricolage Grotesque',Poppins,sans-serif">JagaRaksa.</span>
        <span class="font-label-sm text-label-sm text-tertiary-fixed leading-none mt-space-2xs">KONSOL OPERASIONAL</span>
      </div>
    </a>
    <div class="px-space-lg mb-space-md">
      <div class="flex items-center justify-between px-space-md py-space-xs rounded bg-surface-variant/10">
        <span class="font-label-sm text-label-sm text-inverse-on-surface/80">Status Otoritas</span>
        <span class="font-label-sm text-label-sm px-space-xs rounded bg-tertiary-container text-on-tertiary-container"><?= e(role_label($user['role'])) ?></span>
      </div>
    </div>
    <nav class="flex flex-col gap-space-xs px-space-sm">
      <?php foreach ($menu as [$key, $href, $icon, $label, $roles]): if (!in_array($user['role'], $roles, true)) continue;
        $on = ($active ?? '') === $key; ?>
        <a href="<?= $href ?>" <?= $on ? 'aria-current="page"' : '' ?>
           class="flex items-center gap-space-md px-space-md py-space-sm rounded transition-colors <?= $on ? 'bg-primary-container text-on-primary-container font-bold' : 'text-inverse-on-surface/80 hover:bg-surface-variant/20 hover:text-inverse-on-surface' ?>">
          <span class="material-symbols-outlined text-xl"><?= $icon ?></span>
          <span class="font-label-lg text-label-lg"><?= e($label) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>
  <div class="p-space-lg flex flex-col gap-space-md">
    <div class="p-space-md rounded bg-surface-variant/10 text-inverse-on-surface/80 flex flex-col gap-space-2xs">
      <span class="font-label-sm text-label-sm text-tertiary-fixed">Kepatuhan SLA Kota</span>
      <div class="flex items-center justify-between">
        <span class="font-headline-sm text-headline-sm text-inverse-on-surface"><?= e($slaPct) ?></span>
        <span class="font-label-sm text-label-sm text-tertiary-fixed-dim">Selesai tepat waktu</span>
      </div>
      <?php if (is_staff($user) && $sla['overdue'] > 0): ?>
      <a href="kelola-laporan.php?sla=overdue" class="mt-space-xs inline-flex items-center gap-1 font-label-sm text-label-sm text-orange-300 hover:underline"><span class="material-symbols-outlined text-[14px]">alarm</span><?= (int)$sla['overdue'] ?> laporan terlambat</a>
      <?php endif; ?>
    </div>
    <form method="post" action="logout.php"><?= csrf_field() ?>
      <button type="submit" class="w-full flex items-center justify-center gap-space-xs px-space-md py-space-sm rounded bg-surface-variant/10 hover:bg-surface-variant/25 transition-colors font-label-lg text-label-lg">
        <span class="material-symbols-outlined text-xl">logout</span> Keluar
      </button>
    </form>
  </div>
</aside>

<div class="lg:pl-72">
  <header class="fixed top-0 left-0 lg:left-72 right-0 h-20 bg-surface-container-lowest/90 backdrop-blur-md shadow-[0_1px_8px_rgba(16,42,67,0.04)] z-[1100] flex items-center justify-between px-margin-mobile lg:px-gutter-lg gap-space-md">
    <button type="button" class="lg:hidden p-space-xs rounded hover:bg-surface-container" onclick="document.getElementById('sidebar').classList.toggle('-translate-x-full')">
      <span class="material-symbols-outlined text-2xl">menu</span>
    </button>
    <form method="get" action="<?= $searchAction ?>" class="flex items-center gap-space-md flex-1 max-w-lg">
      <div class="relative w-full">
        <span class="material-symbols-outlined absolute left-space-md top-1/2 -translate-y-1/2 text-on-surface-variant text-xl">search</span>
        <input name="q" value="<?= e($_GET['q'] ?? '') ?>" class="w-full pl-10 pr-space-md py-space-xs rounded bg-surface-container-low text-on-surface placeholder:text-on-surface-variant focus:outline-none focus:ring-2 focus:ring-primary font-body-md text-body-md" placeholder="Cari kode tiket atau judul laporan..." type="text"/>
      </div>
    </form>
    <div class="flex items-center gap-space-lg">
      <div class="hidden md:flex items-center gap-space-xs px-space-md py-space-xs rounded bg-surface-container-high text-on-surface">
        <span class="material-symbols-outlined text-primary text-xl">location_on</span>
        <span class="font-label-md text-label-md">Kota Bandung / Jawa Barat</span>
      </div>
      <div class="relative">
        <button type="button" onclick="document.getElementById('notifBox').classList.toggle('hidden')" class="relative p-space-xs rounded hover:bg-surface-container text-on-surface-variant hover:text-on-surface transition-colors">
          <span class="material-symbols-outlined text-2xl">notifications</span>
          <?php if ($unread): ?><span class="absolute top-1 right-1 w-2.5 h-2.5 rounded-full bg-error ring-2 ring-surface-container-lowest"></span><?php endif; ?>
        </button>
        <div id="notifBox" class="hidden absolute right-0 mt-2 w-[calc(100vw-2rem)] max-w-sm bg-surface-container-lowest rounded-xl shadow-xl p-space-sm z-[1300]">
          <div class="font-label-md text-label-md text-on-surface-variant px-space-sm py-space-xs">Notifikasi (<?= $unread ?> baru)</div>
          <?php if (!$notifs): ?><p class="px-space-sm py-space-md font-body-sm text-body-sm text-on-surface-variant">Belum ada notifikasi.</p><?php endif; ?>
          <?php foreach ($notifs as $n):
            $to = $n['report_id'] ? (is_staff($user) ? 'kelola-laporan.php?id=' . (int)$n['report_id'] : 'laporan-saya.php?tiket=' . urlencode((string)$n['ticket_code'])) : 'dashboard.php'; ?>
            <form method="post" action="api.php?a=read_notif" class="block"><?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><input type="hidden" name="to" value="<?= e($to) ?>">
              <button type="submit" class="w-full text-left px-space-sm py-space-xs rounded hover:bg-surface-container <?= $n['is_read'] ? '' : 'bg-primary-fixed/40' ?>">
                <p class="font-body-sm text-body-sm text-on-surface"><?= e($n['message']) ?></p>
                <span class="font-label-sm text-label-sm text-on-surface-variant"><?= e(time_ago($n['created_at'])) ?></span>
              </button>
            </form>
          <?php endforeach; ?>
          <div class="flex items-center justify-between pt-space-xs px-space-sm">
            <a href="notifikasi.php" class="font-label-sm text-label-sm text-primary py-space-xs hover:underline">Lihat semua</a>
            <?php if ($unread): ?><form method="post" action="api.php?a=read_notif"><?= csrf_field() ?><button class="font-label-sm text-label-sm text-primary py-space-xs hover:underline">Tandai semua dibaca</button></form><?php endif; ?>
          </div>
        </div>
      </div>
      <div class="flex items-center gap-space-md pl-space-xs">
        <?= user_avatar($user) ?>
        <div class="hidden sm:flex flex-col text-left">
          <span class="font-label-md text-label-md text-on-surface leading-none"><?= e($user['name']) ?></span>
          <span class="font-body-sm text-body-sm text-on-surface-variant leading-none mt-space-2xs"><?= e($user['jabatan'] ?: role_label($user['role'])) ?></span>
        </div>
      </div>
    </div>
  </header>
  <main class="relative pt-20 bg-background min-h-screen px-margin-mobile lg:px-gutter-lg py-space-xl">
    <div class="flex flex-col w-full pt-space-md">
    <?= flash_render() ?>
