<?php
require_once __DIR__ . '/../config.php';
$user = current_user();
include __DIR__ . '/head.php';
$ctaHref = $user ? 'laporan-saya.php' : 'login.php';
$navLink = 'px-3.5 py-2 rounded-full text-on-surface font-medium text-sm no-underline transition-colors hover:bg-surface-container hover:text-primary';
$navCur  = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$navOn   = ' !bg-surface-container !text-primary';
?>
<body class="bg-surface font-body-md text-on-surface antialiased">
<header class="fixed top-0 inset-x-0 z-[1100] h-20 bg-white/80 backdrop-blur-xl border-b border-slate-200">
  <div class="h-full w-full px-[5vw] flex items-center">
    <a href="index.php" class="font-headline-xl font-extrabold text-2xl text-primary no-underline" style="font-family:'Bricolage Grotesque',Poppins,sans-serif">JagaRaksa.</a>

    <nav id="jrNav" class="hidden lg:flex items-center gap-1.5 ml-auto max-lg:fixed max-lg:top-20 max-lg:inset-x-0 max-lg:flex-col max-lg:items-stretch max-lg:bg-white max-lg:px-[5vw] max-lg:pt-3 max-lg:pb-5 max-lg:shadow-xl">
      <a class="<?= $navLink . ($navCur === 'index.php' ? $navOn : '') ?>" href="index.php">Beranda</a>
      <a class="<?= $navLink ?>" href="index.php#peta-laporan">Peta Laporan</a>
      <a class="<?= $navLink . ($navCur === 'lacak.php' ? $navOn : '') ?>" href="lacak.php">Lacak Laporan</a>
      <a class="<?= $navLink . ($navCur === 'transparansi.php' ? $navOn : '') ?>" href="transparansi.php">Transparansi</a>
      <a class="<?= $navLink ?>" href="index.php#faq">FAQ</a>
      <?php if ($user): ?>
        <a class="<?= $navLink ?>" href="dashboard.php">Dashboard</a>
      <?php else: ?>
        <a class="<?= $navLink ?>" href="login.php">Masuk</a>
      <?php endif; ?>
      <a class="px-3.5 py-2 rounded-full bg-inverse-surface text-white font-medium text-sm no-underline text-center transition-opacity hover:opacity-90" href="<?= $ctaHref ?>">Buat Laporan</a>
    </nav>

    <div class="flex items-center gap-1 ml-2 max-lg:ml-auto">
      <?php if ($user): ?><a href="dashboard.php" class="pl-1"><?= user_avatar($user) ?></a><?php endif; ?>
      <button type="button" aria-label="Menu" class="lg:hidden bg-transparent border-0 text-2xl cursor-pointer px-2" onclick="var n=document.getElementById('jrNav');n.classList.toggle('hidden');n.classList.toggle('flex')">☰</button>
    </div>
  </div>
</header>
