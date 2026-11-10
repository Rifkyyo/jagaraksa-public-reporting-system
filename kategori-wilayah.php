<?php
require_once __DIR__ . '/config.php';
$user   = require_role('admin');
$active = 'kategori';
$pageTitle = 'Kategori & Wilayah';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    try {
        if ($do === 'cat_add') {
            $name = trim($_POST['name'] ?? ''); $icon = trim($_POST['icon'] ?? 'report') ?: 'report';
            $aid = (int)($_POST['agency_id'] ?? 0) ?: null;
            if (mb_strlen($name) < 3) throw new RuntimeException('Nama kategori minimal 3 karakter.');
            $slug = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_') . '_' . substr(bin2hex(random_bytes(2)), 0, 4);
            db()->prepare('INSERT INTO categories (slug, name, icon, agency_id) VALUES (?,?,?,?)')->execute([$slug, $name, preg_replace('/[^a-z_]/', '', $icon), $aid]);
            audit('kategori_dibuat', 'category', (int)db()->lastInsertId(), $name);
            flash('success', 'Kategori ditambahkan.');
        } elseif ($do === 'cat_del') {
            $c = db()->prepare('SELECT COUNT(*) FROM reports WHERE category_id = ?'); $c->execute([(int)$_POST['id']]);
            if ($c->fetchColumn()) throw new RuntimeException('Kategori masih dipakai oleh laporan dan tidak dapat dihapus.');
            db()->prepare('DELETE FROM categories WHERE id = ?')->execute([(int)$_POST['id']]);
            audit('kategori_dihapus', 'category', (int)$_POST['id'], 'Kategori tanpa laporan dihapus');
            flash('success', 'Kategori dihapus.');
        } elseif ($do === 'dist_add') {
            $name = trim($_POST['name'] ?? '');
            if (mb_strlen($name) < 3) throw new RuntimeException('Nama kecamatan minimal 3 karakter.');
            db()->prepare('INSERT INTO districts (name, latitude, longitude) VALUES (?,?,?)')
                ->execute([$name, is_numeric($_POST['lat'] ?? '') ? $_POST['lat'] : null, is_numeric($_POST['lng'] ?? '') ? $_POST['lng'] : null]);
            audit('wilayah_dibuat', 'district', (int)db()->lastInsertId(), $name);
            flash('success', 'Kecamatan ditambahkan.');
        } elseif ($do === 'dist_del') {
            $c = db()->prepare('SELECT COUNT(*) FROM reports WHERE district_id = ?'); $c->execute([(int)$_POST['id']]);
            if ($c->fetchColumn()) throw new RuntimeException('Kecamatan masih dipakai oleh laporan dan tidak dapat dihapus (agar riwayat laporan tidak berubah).');
            db()->prepare('DELETE FROM districts WHERE id = ?')->execute([(int)$_POST['id']]);
            audit('wilayah_dihapus', 'district', (int)$_POST['id'], 'Kecamatan tanpa laporan dihapus');
            flash('success', 'Kecamatan dihapus.');
        }
    } catch (Throwable $ex) {
        flash('error', $ex instanceof RuntimeException ? $ex->getMessage() : 'Operasi gagal.');
    }
    redirect('kategori-wilayah.php');
}
$cats = db()->query('SELECT c.*, a.name agency, (SELECT COUNT(*) FROM reports r WHERE r.category_id = c.id) n FROM categories c LEFT JOIN agencies a ON a.id = c.agency_id ORDER BY c.id')->fetchAll();
$dists = db()->query('SELECT d.*, (SELECT COUNT(*) FROM reports r WHERE r.district_id = d.id) n FROM districts d ORDER BY d.name')->fetchAll();
$f = 'rounded bg-surface-container-low px-space-sm py-space-xs w-full';
include __DIR__ . '/includes/app_top.php';
?>
<h1 class="font-headline-xl text-headline-xl tracking-tight mb-space-lg">Kategori &amp; Wilayah</h1>
<div class="grid grid-cols-1 xl:grid-cols-2 gap-gutter-sm">
  <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg">
    <h2 class="font-headline-md text-headline-md mb-space-sm">Kategori Masalah</h2>
    <table class="w-full text-left mb-space-md"><tbody>
      <?php foreach ($cats as $c): ?><tr class="border-b border-surface-container"><td class="py-2"><span class="material-symbols-outlined align-middle text-primary mr-1"><?= e($c['icon']) ?></span><?= e($c['name']) ?><div class="font-label-sm text-label-sm text-on-surface-variant ml-7"><?= e($c['agency'] ?? '-') ?> • <?= (int)$c['n'] ?> laporan</div></td>
        <td class="text-right"><form method="post" onsubmit="return confirm('Hapus kategori ini?')"><?= csrf_field() ?><input type="hidden" name="do" value="cat_del"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="text-error"><span class="material-symbols-outlined">delete</span></button></form></td></tr><?php endforeach; ?>
    </tbody></table>
    <form method="post" class="grid grid-cols-2 gap-space-sm"><?= csrf_field() ?><input type="hidden" name="do" value="cat_add">
      <input name="name" placeholder="Nama kategori baru" required class="<?= $f ?> col-span-2"/>
      <input name="icon" placeholder="Ikon (mis. traffic)" class="<?= $f ?>"/>
      <select name="agency_id" class="<?= $f ?>"><option value="">Dinas default</option><?php foreach (agencies() as $a): ?><option value="<?= $a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach; ?></select>
      <button class="col-span-2 py-space-xs rounded bg-primary text-on-primary font-label-md text-label-md">Tambah Kategori</button></form>
  </div>
  <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg">
    <h2 class="font-headline-md text-headline-md mb-space-sm">Wilayah (Kecamatan)</h2>
    <table class="w-full text-left mb-space-md"><tbody>
      <?php foreach ($dists as $d): ?><tr class="border-b border-surface-container"><td class="py-2"><?= e($d['name']) ?><div class="font-label-sm text-label-sm text-on-surface-variant"><?= (int)$d['n'] ?> laporan</div></td>
        <td class="text-right"><form method="post" onsubmit="return confirm('Hapus kecamatan ini?')"><?= csrf_field() ?><input type="hidden" name="do" value="dist_del"><input type="hidden" name="id" value="<?= $d['id'] ?>"><button class="text-error"><span class="material-symbols-outlined">delete</span></button></form></td></tr><?php endforeach; ?>
    </tbody></table>
    <form method="post" class="grid grid-cols-2 gap-space-sm"><?= csrf_field() ?><input type="hidden" name="do" value="dist_add">
      <input name="name" placeholder="Nama kecamatan" required class="<?= $f ?> col-span-2"/>
      <input name="lat" placeholder="Latitude (-6.9)" class="<?= $f ?>"/><input name="lng" placeholder="Longitude (107.6)" class="<?= $f ?>"/>
      <button class="col-span-2 py-space-xs rounded bg-primary text-on-primary font-label-md text-label-md">Tambah Kecamatan</button></form>
  </div>
</div>
<?php include __DIR__ . '/includes/app_bottom.php'; ?>
