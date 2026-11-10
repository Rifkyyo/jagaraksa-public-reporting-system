<?php
require_once __DIR__ . '/config.php';
$user   = require_role('admin');
$active = 'pengguna';
$pageTitle = 'Pengguna';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($do === 'toggle' && $id !== (int)$user['id']) {
        db()->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        audit('akun_status_diubah', 'user', $id, 'Status aktif akun dibalik (aktif/nonaktif)');
        flash('success', 'Status akun diperbarui.');
    } elseif ($do === 'role' && $id !== (int)$user['id'] && in_array($_POST['role'] ?? '', ['admin', 'petugas', 'pelapor'], true)) {
        $oldRole = db()->prepare('SELECT role FROM users WHERE id = ?'); $oldRole->execute([$id]);
        $oldRole = (string)$oldRole->fetchColumn();
        db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$_POST['role'], $id]);
        audit('peran_diubah', 'user', $id, 'Peran ' . $oldRole . ' menjadi ' . $_POST['role']);
        flash('success', 'Peran pengguna diperbarui.');
    } elseif ($do === 'add') {
        $name = trim($_POST['name'] ?? ''); $email = strtolower(trim($_POST['email'] ?? '')); $un = strtolower(trim($_POST['username'] ?? ''));
        $pw = (string)($_POST['password'] ?? ''); $role = in_array($_POST['role'] ?? '', ['admin', 'petugas'], true) ? $_POST['role'] : 'petugas';
        $aid = (int)($_POST['agency_id'] ?? 0) ?: null;
        if (mb_strlen($name) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-z0-9_]{3,30}$/', $un) || strlen($pw) < 8) {
            flash('error', 'Data tidak valid: nama, email, username (a-z0-9_) dan sandi minimal 8 karakter wajib benar.');
        } else {
            $c = db()->prepare('SELECT 1 FROM users WHERE email = ? OR username = ?'); $c->execute([$email, $un]);
            if ($c->fetchColumn()) flash('error', 'Email atau username sudah terdaftar.');
            else {
                db()->prepare('INSERT INTO users (name, username, email, password, role, jabatan, agency_id) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$name, $un, $email, password_hash($pw, PASSWORD_DEFAULT), $role, trim($_POST['jabatan'] ?? '') ?: null, $aid]);
                audit('akun_dibuat', 'user', (int)db()->lastInsertId(), 'Akun ' . $role . ' dibuat: ' . $un);
                flash('success', 'Akun ' . $role . ' berhasil dibuat.');
            }
        }
    }
    redirect('pengguna.php');
}
$q = trim($_GET['q'] ?? '');
$st = db()->prepare("SELECT u.*, a.code agency, (SELECT COUNT(*) FROM reports r WHERE r.user_id = u.id) n FROM users u LEFT JOIN agencies a ON a.id = u.agency_id
                     WHERE u.name LIKE ? OR u.email LIKE ? OR u.username LIKE ? ORDER BY u.role, u.name");
$st->execute(["%$q%", "%$q%", "%$q%"]);
$users = $st->fetchAll();
$f = 'rounded bg-surface-container-low px-space-sm py-space-xs w-full';
include __DIR__ . '/includes/app_top.php';
?>
<h1 class="font-headline-xl text-headline-xl tracking-tight mb-space-lg">Manajemen Pengguna</h1>
<div class="grid grid-cols-1 xl:grid-cols-3 gap-gutter-sm items-start">
  <div class="xl:col-span-2 bg-surface-container-lowest rounded-xl shadow-sm overflow-x-auto">
    <table class="w-full text-left"><thead class="bg-surface-container-low font-label-sm text-label-sm uppercase text-on-surface-variant"><tr><th class="p-space-md">Pengguna</th><th class="p-space-md">Peran</th><th class="p-space-md">Laporan</th><th class="p-space-md">Status</th></tr></thead><tbody>
    <?php foreach ($users as $u): $self = (int)$u['id'] === (int)$user['id']; ?>
      <tr class="border-b border-surface-container align-top"><td class="p-space-md"><div class="font-label-lg text-label-lg"><?= e($u['name']) ?></div><div class="font-body-sm text-body-sm text-on-surface-variant"><?= e($u['email']) ?> • @<?= e($u['username']) ?><?= $u['agency'] ? ' • ' . e($u['agency']) : '' ?></div></td>
        <td class="p-space-md"><?php if ($self): ?><?= e(role_label($u['role'])) ?><?php else: ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="role"><input type="hidden" name="id" value="<?= $u['id'] ?>">
          <select name="role" onchange="this.form.submit()" class="rounded bg-surface-container-low px-2 py-1"><?php foreach (['admin', 'petugas', 'pelapor'] as $r): ?><option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= e(role_label($r)) ?></option><?php endforeach; ?></select></form><?php endif; ?></td>
        <td class="p-space-md"><?= (int)$u['n'] ?></td>
        <td class="p-space-md"><?php if ($self): ?><span class="text-emerald-700 font-label-sm">Aktif (Anda)</span><?php else: ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="px-3 py-1 rounded-full font-label-sm text-label-sm <?= $u['is_active'] ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' ?>"><?= $u['is_active'] ? 'Aktif' : 'Nonaktif' ?></button></form><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table>
  </div>
  <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg">
    <h2 class="font-headline-md text-headline-md mb-space-sm">Tambah Akun Petugas / Admin</h2>
    <form method="post" class="flex flex-col gap-space-sm"><?= csrf_field() ?><input type="hidden" name="do" value="add">
      <input name="name" placeholder="Nama lengkap" required class="<?= $f ?>"/><input name="username" placeholder="username" required class="<?= $f ?>"/><input name="email" type="email" placeholder="Email dinas" required class="<?= $f ?>"/>
      <input name="jabatan" placeholder="Jabatan" class="<?= $f ?>"/><input name="password" type="password" minlength="8" placeholder="Kata sandi awal (min. 8)" required class="<?= $f ?>"/>
      <select name="role" class="<?= $f ?>"><option value="petugas">Petugas Lapangan</option><option value="admin">Admin Kota</option></select>
      <select name="agency_id" class="<?= $f ?>"><option value="">Instansi (opsional)</option><?php foreach (agencies() as $a): ?><option value="<?= $a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach; ?></select>
      <button class="py-space-xs rounded bg-primary text-on-primary font-label-md text-label-md">Buat Akun</button></form>
  </div>
</div>
<?php include __DIR__ . '/includes/app_bottom.php'; ?>
