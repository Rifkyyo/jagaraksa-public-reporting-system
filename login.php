<?php
require_once __DIR__ . '/config.php';

// Sudah login -> langsung ke dashboard
if (current_user()) {
    redirect('dashboard.php');
}

$tab    = (($_GET['tab'] ?? '') === 'register') ? 'register' : 'login';
$errors = [];
$old    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'login';

    if (!csrf_valid()) {
        // Token tidak cocok (biasanya cookie/sesi hilang). Tampilkan pesan, jangan layar kosong.
        $errors[] = 'Sesi formulir kedaluwarsa. Silakan coba kirim ulang. Jika terus berulang, pastikan cookie browser aktif.';
        $tab = $action === 'register' ? 'register' : 'login';
    } elseif ($action === 'login') {
        /* ------------------------------ LOGIN ------------------------------ */
        $tab = 'login';
        $id  = trim((string)($_POST['identifier'] ?? ''));
        $pw  = (string)($_POST['password'] ?? '');       // kata sandi TIDAK di-trim
        $old = ['identifier' => $id];

        if ($id === '' || $pw === '') {
            $errors[] = 'Email/nama pengguna dan kata sandi wajib diisi.';
        } elseif (($lock = login_lock_seconds($id)) > 0) {
            $errors[] = 'Terlalu banyak percobaan gagal. Coba lagi dalam ' . ceil($lock / 60) . ' menit.';
        } else {
            $u = find_user_by_identifier($id);
            if ($u && password_verify($pw, $u['password'])) {
                if (!(int)$u['is_active']) {
                    $errors[] = 'Akun Anda dinonaktifkan. Hubungi admin kota.';
                } else {
                    // perbarui hash bila algoritma default PHP berubah
                    if (password_needs_rehash($u['password'], PASSWORD_DEFAULT)) {
                        db()->prepare('UPDATE users SET password = ? WHERE id = ?')
                            ->execute([password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
                    }
                    login_clear_fails($id);
                    login_user($u);
                    flash('success', 'Selamat datang kembali, ' . $u['name'] . '.');
                    redirect('dashboard.php');
                }
            } else {
                login_record_fail($id);
                $errors[] = 'Email/nama pengguna atau kata sandi salah.';
            }
        }
    } elseif ($action === 'register') {
        /* ------------------------------ DAFTAR ------------------------------ */
        $tab      = 'register';
        $name     = trim((string)($_POST['name'] ?? ''));
        $email    = strtolower(trim((string)($_POST['email'] ?? '')));
        $username = strtolower(trim((string)($_POST['username'] ?? '')));
        $phone    = trim((string)($_POST['phone'] ?? ''));
        $pw       = (string)($_POST['password'] ?? '');
        $pw2      = (string)($_POST['password2'] ?? '');
        $old      = compact('name', 'email', 'username', 'phone');

        if (mb_strlen($name) < 3)                                         $errors[] = 'Nama lengkap minimal 3 karakter.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))                   $errors[] = 'Format email tidak valid.';
        if (!preg_match('/^[a-z0-9_]{3,30}$/', $username))                $errors[] = 'Nama pengguna 3-30 karakter: huruf kecil, angka, garis bawah.';
        if ($phone !== '' && !preg_match('/^[0-9+\-\s]{8,20}$/', $phone)) $errors[] = 'Nomor HP tidak valid.';
        if (strlen($pw) < 8)                                              $errors[] = 'Kata sandi minimal 8 karakter.';
        if ($pw !== $pw2)                                                 $errors[] = 'Konfirmasi kata sandi tidak cocok.';

        if (!$errors) {
            $c = db()->prepare('SELECT 1 FROM users WHERE LOWER(email) = ? OR LOWER(username) = ? LIMIT 1');
            $c->execute([$email, $username]);
            if ($c->fetchColumn()) {
                $errors[] = 'Email atau nama pengguna sudah terdaftar. Silakan masuk, atau gunakan data lain.';
            }
        }
        if (!$errors) {
            try {
                db()->prepare("INSERT INTO users (name, username, email, phone, password, role) VALUES (?,?,?,?,?, 'pelapor')")
                    ->execute([$name, $username, $email, $phone !== '' ? $phone : null, password_hash($pw, PASSWORD_DEFAULT)]);
                $newId = (int)db()->lastInsertId();
                $st = db()->prepare('SELECT * FROM users WHERE id = ?');
                $st->execute([$newId]);
                $u = $st->fetch();
                if (!$u) {
                    throw new RuntimeException('Akun tidak ditemukan setelah dibuat.');
                }
                login_user($u);
                flash('success', 'Akun berhasil dibuat. Selamat datang di JagaRaksa! Gunakan "' . $username . '" atau email Anda untuk masuk kembali.');
                redirect('dashboard.php');
            } catch (PDOException $ex) {
                $errors[] = ($ex->getCode() === '23000')
                    ? 'Email atau nama pengguna sudah terdaftar.'
                    : 'Gagal membuat akun' . (APP_DEBUG ? ': ' . $ex->getMessage() : '. Coba lagi.');
            }
        }
    }
}

$pageTitle = 'Akses Akun';
include __DIR__ . '/includes/head.php';
?>
<!-- Link file stylesheet CSS Vanilla jika ditaruh di file terpisah -->
<link rel="stylesheet" href="assets/login.css">

<body class="auth-body">
<main class="auth-main">
<div class="auth-card">

  <!-- Panel Informasi Kiri -->
  <div class="auth-info-panel">
    <div class="glow-effect"></div>
    <div class="info-content-top">
      <a href="index.php" class="brand-link">
        <div>
          <div class="brand-header">
            <span class="brand-title">JagaRaksa.</span>
            <span class="badge-v24">SIPIL v2.4</span>
          </div>
          <p class="brand-subtitle">Sistem Pengawasan &amp; Aspirasi Kota</p>
        </div>
      </a>
      <div class="hero-section">
        <span class="section-tag font-semibold">Infrastruktur Digital Warga</span>
        <h1 class="hero-title">Satu Platform untuk Pelayanan Kota yang Responsif &amp; Transparan</h1>
        <p class="hero-desc">Menghubungkan suara masyarakat secara langsung ke meja verifikasi dinas teknis pemerintah daerah tanpa birokrasi berbelit.</p>
      </div>
      <ul class="feature-list">
        <?php foreach (['Pemantauan status pengaduan secara real-time dan transparan','Integrasi langsung dengan dinas terkait (PUPR, Dishub, DLH)','Dukungan geolokasi presisi dengan peta interaktif','Keamanan data warga dengan privasi terlindungi'] as $f): ?>
        <li class="feature-item">
          <div class="feature-icon"><span class="material-symbols-outlined icon-small">check</span></div>
          <span class="feature-text"><?= e($f) ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div class="info-content-bottom">
      <div class="testimonial-card">
        <div class="star-rating"><?= str_repeat('<span class="material-symbols-outlined icon-star">star</span>', 5) ?></div>
        <p class="testimonial-text">“Sistem JagaRaksa mempercepat perbaikan lampu jalan di lingkungan kami hanya dalam 2 hari.”</p>
        <div class="testimonial-author">
          <span class="author-name">— Warga Sukajadi, Kota Bandung</span>
          <span class="ticket-badge">Tiket #JR-8491</span>
        </div>
      </div>
      <div class="security-note">
        <!-- <span class="material-symbols-outlined icon-security">verified_user</span>
        <span> -->
      </div>
    </div>
  </div>

  <!-- Panel Form Kanan -->
  <div class="auth-form-panel">
    <div>
      <div class="admin-banner">
        <span class="material-symbols-outlined icon-admin">admin_panel_settings</span>
        <span class="admin-banner-text"><strong>Petugas &amp; Administrator:</strong> Silakan masuk menggunakan akun kedinasan terdaftar.</span>
      </div>
      <div class="form-header">
        <h2 class="form-title">Selamat Datang di JagaRaksa</h2>
        <p class="form-subtitle">Masuk untuk menyampaikan laporan dan memantau perkembangan pengaduanmu.</p>
      </div>
      <div class="tab-container">
        <button class="tab-btn active" id="tab-login-btn" onclick="switchAuthTab('login')" type="button">Masuk (Login)</button>
        <button class="tab-btn" id="tab-register-btn" onclick="switchAuthTab('register')" type="button">Daftar Akun Baru</button>
      </div>

      <?php if ($errors): ?>
        <div class="alert-error">
          <span class="material-symbols-outlined icon-error">error</span>
          <ul class="error-list"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>
      <?= flash_render() ?>

      <!-- Tab View Login -->
      <div id="view-login">
        <form method="post" action="login.php" class="form-space">
          <?= csrf_field() ?><input type="hidden" name="action" value="login">
          <div class="form-group">
            <label class="form-label" for="identifier">Email atau Nama Pengguna</label>
            <div class="input-relative">
              <span class="material-symbols-outlined input-icon">mail</span>
              <input class="form-input input-with-icon" id="identifier" name="identifier" value="<?= e($old['identifier'] ?? '') ?>" placeholder="contoh: warga@domain.id atau budi_santoso" required type="text" autocomplete="username"/>
            </div>
            <p class="input-help"><span class="material-symbols-outlined icon-help">shield</span> Gunakan akun yang telah terdaftar di sistem JagaRaksa</p>
          </div>
          <div class="form-group">
            <label class="form-label" for="password">Kata Sandi</label>
            <div class="input-relative">
              <span class="material-symbols-outlined input-icon">lock</span>
              <input class="form-input input-with-icon input-password" id="password" name="password" placeholder="Masukkan kata sandi" required type="password" autocomplete="current-password"/>
              <button type="button" aria-label="Tampilkan kata sandi" class="toggle-password-btn" onclick="togglePw('password',this)">
                <span class="material-symbols-outlined icon-small">visibility</span>
              </button>
            </div>
          </div>
          <div class="forgot-pw-wrap">
            <span class="forgot-pw-text">Lupa kata sandi? Hubungi admin kota.</span>
          </div>
          <button class="btn btn-primary" type="submit">
            <span>Masuk ke Sistem</span>
            <span class="material-symbols-outlined icon-btn">arrow_forward</span>
          </button>
        </form>
      </div>

      <!-- Tab View Register -->
      <div id="view-register" class="is-hidden">
        <div class="reg-banner">
          <span class="reg-banner-title">Registrasi Akun Warga</span>
          <p class="reg-banner-text">Lengkapi data berikut untuk aktivasi akun dan akses pembuatan laporan. Akun petugas dibuat oleh admin kota.</p>
        </div>
        <form method="post" action="login.php?tab=register" class="form-space-sm">
          <?= csrf_field() ?><input type="hidden" name="action" value="register">
          <div class="form-group">
            <label class="form-label">Nama Lengkap (Sesuai KTP)</label>
            <input class="form-input-sm" name="name" value="<?= e($old['name'] ?? '') ?>" placeholder="contoh: Raden Budi Santoso" required type="text"/>
          </div>
          <div class="grid-2-cols">
            <div class="form-group">
              <label class="form-label">Nama Pengguna</label>
              <input class="form-input-sm" name="username" value="<?= e($old['username'] ?? '') ?>" placeholder="budi_santoso" required type="text" pattern="[a-z0-9_]{3,30}"/>
            </div>
            <div class="form-group">
              <label class="form-label">Nomor WhatsApp / HP</label>
              <input class="form-input-sm" name="phone" value="<?= e($old['phone'] ?? '') ?>" placeholder="081234567xxx" type="tel"/>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Alamat Email Aktif</label>
            <input class="form-input-sm" name="email" value="<?= e($old['email'] ?? '') ?>" placeholder="budi@contoh.id" required type="email"/>
          </div>
          <div class="grid-2-cols">
            <div class="form-group">
              <label class="form-label">Kata Sandi (min. 8)</label>
              <input class="form-input-sm" name="password" required minlength="8" type="password" autocomplete="new-password"/>
            </div>
            <div class="form-group">
              <label class="form-label">Ulangi Kata Sandi</label>
              <input class="form-input-sm" name="password2" required minlength="8" type="password" autocomplete="new-password"/>
            </div>
          </div>
          <button class="btn btn-tertiary" type="submit">
            <span>Buat Akun JagaRaksa</span>
            <span class="material-symbols-outlined icon-btn">how_to_reg</span>
          </button>
        </form>
      </div>

      <?php if (APP_DEBUG): ?>
      <div class="debug-card">
        <div class="debug-title">Akun demo (mode pengembangan)</div>
        <div><b>admin</b> / Admin123! &nbsp;•&nbsp; <b>petugas</b> / Petugas123! &nbsp;•&nbsp; <b>warga</b> / Warga123!</div>
        <div class="debug-footer">Tidak bisa masuk? Buka <a class="debug-link" href="diagnosa.php">diagnosa.php</a>.</div>
      </div>
      <?php endif; ?>

      <div class="register-prompt-card">
        <div class="register-prompt-text">
          <p class="prompt-title">Belum punya akun JagaRaksa?</p>
          <p class="prompt-subtitle">Daftar sebagai warga masyarakat untuk mulai melapor.</p>
        </div>
        <button class="btn-secondary" onclick="switchAuthTab('register')" type="button">Buat Akun Baru (Gratis)</button>
      </div>
    </div>
    
    <div class="auth-footer">
      <a href="index.php" class="footer-link">← Kembali ke Beranda</a>
      <span>•</span>
      <span>UU PDP No. 27/2022</span>
    </div>
  </div>
</div>
</main>

<script>
function switchAuthTab(m){
  const viewLogin = document.getElementById('view-login');
  const viewRegister = document.getElementById('view-register');
  const btnLogin = document.getElementById('tab-login-btn');
  const btnRegister = document.getElementById('tab-register-btn');

  if(m === 'login') {
    viewLogin.classList.remove('is-hidden');
    viewRegister.classList.add('is-hidden');
    btnLogin.classList.add('active');
    btnRegister.classList.remove('active');
  } else {
    viewLogin.classList.add('is-hidden');
    viewRegister.classList.remove('is-hidden');
    btnLogin.classList.remove('active');
    btnRegister.classList.add('active');
  }
}

function togglePw(id, btn){
  const i = document.getElementById(id);
  const s = btn.firstElementChild;
  if(i.type === 'password'){
    i.type = 'text';
    s.textContent = 'visibility_off';
  } else {
    i.type = 'password';
    s.textContent = 'visibility';
  }
}

switchAuthTab('<?= $tab ?>');
</script>
</body>
</html>