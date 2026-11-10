# JagaRaksa – Sistem Pengaduan Fasilitas Publik

PHP (7.4+, disarankan 8.1+) + MySQL/MariaDB. Tampilan mengikuti desain Stitch JagaRaksa.

## Instalasi (XAMPP / Laragon / hosting PHP) – hanya 4 langkah
1. Salin folder `jagaraksa` ke `htdocs` (XAMPP) atau `www` (Laragon). Nyalakan Apache & MySQL.
2. Impor database: phpMyAdmin → **Import** → pilih `database.sql` → **Go**
   (atau `mysql -u root -p < database.sql`). File ini membuat database, semua tabel, data master,
   **akun demo (sudah ter-hash)** dan laporan contoh. Aman diimpor ulang kapan saja (mereset semuanya).
3. Cek `config.php` bagian pengaturan DB (default: host `localhost`, user `root`, sandi kosong, db `jagaraksa`).
4. Buka `http://localhost/jagaraksa/`.

**Tidak ada lagi `install.php`.** Akun demo langsung tersedia setelah impor SQL.

## Akun demo
| Peran    | Username   | Kata sandi   |
|----------|------------|--------------|
| Admin    | admin      | Admin123!    |
| Petugas  | petugas    | Petugas123!  |
| Petugas  | petugas2   | Petugas123!  |
| Warga    | warga      | Warga123!    |
| Warga    | siti       | Warga123!    |

Login bisa memakai **username atau email** (huruf besar/kecil tidak berpengaruh).

## Jika login masih gagal
Buka `http://localhost/jagaraksa/diagnosa.php`. Halaman ini memeriksa versi PHP/ekstensi, penyimpanan session,
koneksi database, tabel, daftar akun, menguji login langkah demi langkah, dan punya tombol
**"Perbaiki / buat akun demo"** yang membuat ulang hash kata sandi langsung dari PHP Anda.
Mode debug kini **otomatis aktif hanya bila diakses dari komputer yang sama (127.0.0.1)** dan nonaktif di server publik
(paksa dengan environment variable `JR_DEBUG=1`/`0`). Tetap hapus `diagnosa.php` serta akun demo sebelum go-live.

## Alur akses
- **Tamu:** hanya Beranda dan halaman login/daftar; halaman lain dialihkan ke login.
- **Daftar / login berhasil:** langsung ke **Dashboard**.
- **Publik (tanpa login):** Beranda, **Lacak Laporan**, **Transparansi Kinerja**, API data terbuka.
- **Warga:** Dashboard, Peta, Laporan Saya. **Petugas:** + Kelola Laporan, Analitik & SLA. **Admin:** + Kategori & Wilayah, Pengguna, Audit & Integritas.
- Pendaftaran publik selalu berperan warga; akun petugas/admin dibuat admin di menu Pengguna.

## Keamanan
Prepared statements, `password_hash` bcrypt, token CSRF, escape output, regenerasi ID session saat login,
pembatas percobaan login berbasis database (8 gagal / 10 menit per akun+IP), validasi isi file upload,
`.htaccess` di `uploads/` dan `storage/`.

---

## Pembaruan v2.1 (mengikuti catatan & saran perbaikan)

### 1. Alur pengguna & pelacakan (mobile-first)
- **`lacak.php`** (publik, tanpa login): masukkan nomor tiket, lihat posisi laporan (stepper vertikal), target waktu,
  tanggapan resmi, bukti penyelesaian, riwayat, dan peta. Hanya data publik: tanpa nama pelapor/petugas, tanpa foto pelapor.
  Tautan bisa dibagikan (tombol Bagikan memakai share sheet ponsel).
- Setelah mengirim laporan, warga diarahkan ke halaman laporannya dengan nomor tiket; form menampilkan **estimasi SLA**
  sesuai tingkat dampak. Tombol kirim penuh lebar di ponsel.
- Kartu laporan di beranda dan kotak "Lacak tiket" di hero mengarah ke halaman pelacakan.
- **Perbaikan privasi:** warga tidak lagi bisa membuka detail lengkap laporan milik warga lain lewat `?tiket=`.
- Warga dapat **membuka kembali** laporan selesai (maks. 14 hari) bila penanganan belum tuntas.
- Alur status divalidasi (mis. `selesai` tidak bisa langsung kembali ke `pending`); penolakan wajib disertai alasan.

### 2. Eskalasi, notifikasi, dan dashboard analitik
- **Prioritas & SLA** (`includes/core_v2.php`, konstanta `SLA_HOURS`): darurat 12 jam / 3 hari, tinggi 24 jam / 5 hari,
  sedang 24 jam / 7 hari, rendah 48 jam / 10 hari (verifikasi / penyelesaian). Dampak "membahayakan jiwa" otomatis berprioritas Darurat.
- **Eskalasi otomatis:** melewati tenggat -> tingkat 1 (petugas instansi + admin + pelapor diberi tahu);
  terlambat > 24 jam -> tingkat 2 (seluruh admin, prioritas naik satu tingkat). Berjalan tanpa cron saat petugas
  membuka halaman (maks. tiap 5 menit); opsional `php cron_eskalasi.php` lewat cron.
- **Notifikasi terarah** ke admin + petugas instansi terkait (bukan semua petugas); notifikasi bisa diklik langsung
  ke laporan, ada halaman `notifikasi.php`.
- **Kelola Laporan** diurutkan otomatis: terlambat dulu, lalu prioritas; filter prioritas/SLA/tereskalasi.
- **`analitik.php`** (admin & petugas): KPI SLA, antrean tindakan segera, usia laporan, prioritas, kinerja per instansi/kategori/kecamatan,
  titik rawan berulang, ekspor CSV (tanpa identitas pelapor).

### 3. Penyimpanan data aman & dapat dipertanggungjawabkan
- **Hash chain SHA-256** pada riwayat laporan (`report_logs`) dan aksi admin (`audit_log`), menyimpan nilai lama/baru dan IP.
  Halaman **`audit.php`** (admin) memverifikasi rantai dan menampilkan jejak aksi; `lacak.php` menampilkan status integritas per laporan.
- **Tidak ada penghapusan permanen:** "Hapus" menjadi **Arsip** dengan alasan wajib; admin dapat memulihkan. Relasi database
  diubah ke `RESTRICT`, dan kecamatan/kategori yang dipakai laporan tidak bisa dihapus.
- **Trigger append-only** di database mencegah `UPDATE`/`DELETE` pada tabel riwayat lewat SQL biasa.
- Nomor tiket memakai **counter atomik** (`ticket_counters`), aman untuk kiriman bersamaan.
- Foto diunggah ulang melalui GD: **metadata EXIF/GPS dibuang**, orientasi dikoreksi, sisi terpanjang 1600 px.
- **Data publik:** `transparansi.php` + `api.php?a=public_stats` dan `api.php?a=track&tiket=JR-...` (JSON, hanya data agregat/publik).
- Logout kini lewat POST + CSRF.

### Memperbarui dari versi sebelumnya
- **Mulai dari nol:** impor `database.sql` yang baru (mereset semua data).
- **Sudah punya data:** cadangkan database, lalu impor **`migrasi_v2_1.sql`** sekali saja.
  Jika hosting menolak `CREATE TRIGGER`, abaikan error pada bagian trigger; aplikasi tetap berjalan.
