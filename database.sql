-- =====================================================================
--  JagaRaksa - Skema Database + Data Awal (MySQL 5.7+/8.x, MariaDB 10.3+)
--
--  CARA PAKAI (cukup SATU langkah, tidak perlu install.php):
--    phpMyAdmin : tab "Import" -> pilih file ini -> Go
--    Terminal   : mysql -u root -p < database.sql
--
--  File ini AMAN diimpor ulang: semua tabel dihapus lalu dibuat ulang
--  beserta akun demo yang kata sandinya sudah di-hash (bcrypt, kompatibel
--  dengan password_verify() PHP).
--
--  AKUN DEMO
--    admin    / Admin123!     (Admin Kota)
--    petugas  / Petugas123!   (Petugas Lapangan PUPR)
--    petugas2 / Petugas123!   (Petugas PJU)
--    warga    / Warga123!     (Pelapor)
--    siti     / Warga123!     (Pelapor)
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS jagaraksa
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE jagaraksa;

DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS ticket_counters;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS report_logs;
DROP TABLE IF EXISTS report_responses;
DROP TABLE IF EXISTS report_attachments;
DROP TABLE IF EXISTS reports;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS districts;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS agencies;

-- ---------------------------------------------------------------------
-- Dinas / instansi penanggung jawab (OPD)
-- ---------------------------------------------------------------------
CREATE TABLE agencies (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(20)  NOT NULL,
  name        VARCHAR(150) NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_agencies_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Pengguna: admin | petugas | pelapor (warga)
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(150) NOT NULL,
  username    VARCHAR(50)  NOT NULL,
  email       VARCHAR(190) NOT NULL,
  phone       VARCHAR(25)  NULL,
  password    VARCHAR(255) NOT NULL,                 -- hash bcrypt dari password_hash()
  role        ENUM('admin','petugas','pelapor') NOT NULL DEFAULT 'pelapor',
  jabatan     VARCHAR(120) NULL,
  agency_id   INT UNSIGNED NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  last_login  DATETIME     NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role),
  CONSTRAINT fk_users_agency FOREIGN KEY (agency_id) REFERENCES agencies (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Pembatas percobaan login (anti brute-force, tidak bergantung session)
-- ---------------------------------------------------------------------
CREATE TABLE login_attempts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  identifier   VARCHAR(190) NOT NULL,
  ip           VARCHAR(45)  NOT NULL,
  attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_la_lookup (identifier, ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Wilayah (kecamatan)
-- ---------------------------------------------------------------------
CREATE TABLE districts (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name      VARCHAR(100) NOT NULL,
  city      VARCHAR(100) NOT NULL DEFAULT 'Kota Bandung',
  latitude  DECIMAL(10,8) NULL,
  longitude DECIMAL(11,8) NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Kategori masalah
-- ---------------------------------------------------------------------
CREATE TABLE categories (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(80)  NOT NULL,
  name        VARCHAR(120) NOT NULL,
  icon        VARCHAR(50)  NOT NULL DEFAULT 'report',    -- nama ikon Material Symbols
  agency_id   INT UNSIGNED NULL,                         -- dinas default
  description TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_slug (slug),
  CONSTRAINT fk_cat_agency FOREIGN KEY (agency_id) REFERENCES agencies (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Laporan / pengaduan
-- ---------------------------------------------------------------------
CREATE TABLE reports (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_code   VARCHAR(20) NOT NULL,                    -- contoh: JR-2026-0001
  user_id       BIGINT UNSIGNED NOT NULL,
  category_id   INT UNSIGNED NOT NULL,
  district_id   INT UNSIGNED NULL,
  agency_id     INT UNSIGNED NULL,                       -- hasil disposisi
  assigned_to   BIGINT UNSIGNED NULL,                    -- petugas penanggung jawab
  title         VARCHAR(255) NOT NULL,
  description   TEXT NOT NULL,
  address       VARCHAR(500) NOT NULL,
  latitude      DECIMAL(10,8) NULL,
  longitude     DECIMAL(11,8) NULL,
  impact_level  ENUM('sedang','bahaya') NOT NULL DEFAULT 'sedang',
  is_anonymous  TINYINT(1) NOT NULL DEFAULT 0,
  status        ENUM('pending','proses','selesai','ditolak') NOT NULL DEFAULT 'pending',
  resolved_at   DATETIME NULL,
  -- v2.1: prioritas, SLA & eskalasi
  priority          ENUM('rendah','sedang','tinggi','darurat') NOT NULL DEFAULT 'sedang',
  respond_due_at    DATETIME NULL,                       -- tenggat verifikasi / respons pertama
  resolve_due_at    DATETIME NULL,                       -- tenggat penyelesaian
  first_response_at DATETIME NULL,
  escalation_level  TINYINT UNSIGNED NOT NULL DEFAULT 0, -- 0 normal, 1 melewati tenggat, 2 eskalasi ke admin
  escalated_at      DATETIME NULL,
  -- v2.1: arsip (soft delete) - data tidak pernah dihapus permanen
  deleted_at    DATETIME NULL,
  deleted_by    BIGINT UNSIGNED NULL,
  delete_reason VARCHAR(255) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reports_ticket (ticket_code),
  KEY idx_rep_deleted (deleted_at),
  KEY idx_rep_sla (status, respond_due_at, resolve_due_at),
  KEY idx_rep_priority (priority),
  KEY idx_rep_category (category_id),
  KEY idx_rep_district (district_id),
  KEY idx_rep_status (status),
  KEY idx_rep_created (created_at),
  KEY idx_rep_user (user_id),
  CONSTRAINT fk_rep_user     FOREIGN KEY (user_id)     REFERENCES users (id)      ON DELETE RESTRICT,
  CONSTRAINT fk_rep_cat      FOREIGN KEY (category_id) REFERENCES categories (id),
  CONSTRAINT fk_rep_district FOREIGN KEY (district_id) REFERENCES districts (id)  ON DELETE SET NULL,
  CONSTRAINT fk_rep_agency   FOREIGN KEY (agency_id)   REFERENCES agencies (id)   ON DELETE SET NULL,
  CONSTRAINT fk_rep_assignee FOREIGN KEY (assigned_to) REFERENCES users (id)      ON DELETE SET NULL,
  CONSTRAINT fk_rep_deleter  FOREIGN KEY (deleted_by)  REFERENCES users (id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Lampiran foto
-- ---------------------------------------------------------------------
CREATE TABLE report_attachments (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id  BIGINT UNSIGNED NOT NULL,
  file_path  VARCHAR(255) NOT NULL,
  file_type  VARCHAR(50)  NULL,
  file_size  INT UNSIGNED NULL,
  kind       ENUM('bukti','penyelesaian') NOT NULL DEFAULT 'bukti',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_att_report (report_id),
  CONSTRAINT fk_att_report FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tanggapan resmi petugas
-- ---------------------------------------------------------------------
CREATE TABLE report_responses (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id  BIGINT UNSIGNED NOT NULL,
  user_id    BIGINT UNSIGNED NOT NULL,
  message    TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_resp_report (report_id),
  CONSTRAINT fk_resp_report FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE,
  CONSTRAINT fk_resp_user   FOREIGN KEY (user_id)   REFERENCES users (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Audit trail aktivitas laporan
-- ---------------------------------------------------------------------
CREATE TABLE report_logs (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id  BIGINT UNSIGNED NOT NULL,
  user_id    BIGINT UNSIGNED NULL,
  action     VARCHAR(50)  NOT NULL,              -- dibuat | status | disposisi | tanggapan | prioritas | eskalasi | buka_kembali | diarsipkan | dipulihkan
  note       VARCHAR(500) NULL,
  old_value  VARCHAR(100) NULL,                  -- v2.1: nilai sebelum
  new_value  VARCHAR(100) NULL,                  -- v2.1: nilai sesudah
  ip         VARCHAR(45)  NULL,
  prev_hash  CHAR(64)     NULL,                  -- hash baris sebelumnya pada laporan yang sama
  entry_hash CHAR(64)     NULL,                  -- SHA-256 baris ini (hash chain); NULL = data lama
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_log_report (report_id),
  KEY idx_log_created (created_at),
  KEY idx_log_action (action),
  CONSTRAINT fk_log_report FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE RESTRICT,
  CONSTRAINT fk_log_user   FOREIGN KEY (user_id)   REFERENCES users (id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Notifikasi dalam aplikasi
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  report_id  BIGINT UNSIGNED NULL,
  message    VARCHAR(300) NOT NULL,
  is_read    TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notif_user (user_id, is_read),
  CONSTRAINT fk_notif_user   FOREIGN KEY (user_id)   REFERENCES users (id)   ON DELETE CASCADE,
  CONSTRAINT fk_notif_report FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- v2.1: Audit aksi administratif (hash chain global, append-only)
-- ---------------------------------------------------------------------
CREATE TABLE audit_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NULL,
  action     VARCHAR(50)  NOT NULL,
  entity     VARCHAR(50)  NOT NULL,
  entity_id  VARCHAR(64)  NULL,
  detail     VARCHAR(500) NULL,
  ip         VARCHAR(45)  NULL,
  prev_hash  CHAR(64)     NULL,
  entry_hash CHAR(64)     NOT NULL,
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_audit_created (created_at),
  KEY idx_audit_action (action),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- v2.1: Counter nomor tiket per tahun (atomik, aman untuk kiriman bersamaan)
-- ---------------------------------------------------------------------
CREATE TABLE ticket_counters (
  yr      SMALLINT UNSIGNED NOT NULL,
  last_no INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (yr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
--  DATA MASTER
-- =====================================================================
INSERT INTO agencies (id, code, name) VALUES
 (1, 'PUPR',   'Dinas Bina Marga & Sumber Daya Air'),
 (2, 'PJU',    'Dinas Perhubungan - Seksi Penerangan Jalan Umum'),
 (3, 'DLH',    'Dinas Lingkungan Hidup & Kebersihan'),
 (4, 'DISHUB', 'Dinas Perhubungan - Lalu Lintas'),
 (5, 'DKPP',   'Dinas Pertamanan & Pemakaman');

INSERT INTO districts (id, name, latitude, longitude) VALUES
 (1,  'Coblong',          -6.88470000, 107.61310000),
 (2,  'Lengkong',         -6.92140000, 107.62190000),
 (3,  'Sukajadi',         -6.88960000, 107.59610000),
 (4,  'Sukasari',         -6.86070000, 107.59270000),
 (5,  'Cibeunying Kaler', -6.88000000, 107.63000000),
 (6,  'Astana Anyar',     -6.93110000, 107.60250000),
 (7,  'Bojongloa Kaler',  -6.93500000, 107.58400000),
 (8,  'Cicendo',          -6.90730000, 107.59360000),
 (9,  'Bandung Wetan',    -6.90420000, 107.61970000),
 (10, 'Batununggal',      -6.92480000, 107.63750000);

INSERT INTO categories (id, slug, name, icon, agency_id, description) VALUES
 (1, 'jalan_rusak', 'Jalan Rusak & Trotoar Amblas',          'edit_road',  1, 'Lubang, amblas, aspal mengelupas, trotoar rusak'),
 (2, 'pju_rusak',   'Penerangan Jalan Umum (PJU)',           'lightbulb',  2, 'Lampu jalan mati, tiang roboh, kabel terbuka'),
 (3, 'kebersihan',  'Kebersihan & Sampah Liar',              'delete',     3, 'Tumpukan sampah, TPS liar, kebersihan lingkungan'),
 (4, 'drainase',    'Drainase, Gorong-gorong & Banjir',      'water_drop', 1, 'Saluran tersumbat, genangan, banjir'),
 (5, 'rambu_lalin', 'Rambu Lalu Lintas & Marka Jalan',       'traffic',    4, 'Rambu rusak, marka pudar, lampu lalu lintas'),
 (6, 'taman_rth',   'Fasilitas Taman & Ruang Terbuka Hijau', 'park',       5, 'Taman rusak, pohon tumbang, fasilitas RTH');

-- =====================================================================
--  AKUN DEMO  (hash bcrypt $2y$10 - valid untuk password_verify PHP)
-- =====================================================================
INSERT INTO users (id, name, username, email, phone, password, role, jabatan, agency_id, is_active) VALUES
 (1, 'Budi Santoso, S.T.', 'admin',    'admin@jagaraksa.id',    '081200000001', '$2y$10$WNqGmoygfr0202R2Kn5p.OBqsYVRcW.ehnl5l4F6HnjOoYspKKA1u', 'admin',   'Koordinator Infrastruktur', 1,    1),
 (2, 'Dewi Lestari',       'petugas',  'petugas@jagaraksa.id',  '081200000002', '$2y$10$ib2Uct3i9MbUUe.0JCtp5.SzMJUzKP9zepcLBrq792eGP6eYnjeb.', 'petugas', 'Petugas Lapangan PUPR',     1,    1),
 (3, 'Agus Pratama',       'petugas2', 'petugas2@jagaraksa.id', '081200000003', '$2y$10$ib2Uct3i9MbUUe.0JCtp5.SzMJUzKP9zepcLBrq792eGP6eYnjeb.', 'petugas', 'Petugas Lapangan PJU',      2,    1),
 (4, 'Rian Firmansyah',    'warga',    'warga@jagaraksa.id',    '081200000004', '$2y$10$TTZtCOdIH44kfkBxEDgzFe/k6rd1/lqLiDA4lOAtAAUsfs4JfZPy2', 'pelapor', NULL,                       NULL, 1),
 (5, 'Siti Aminah',        'siti',     'siti@jagaraksa.id',     '081200000005', '$2y$10$TTZtCOdIH44kfkBxEDgzFe/k6rd1/lqLiDA4lOAtAAUsfs4JfZPy2', 'pelapor', NULL,                       NULL, 1);

-- =====================================================================
--  DATA CONTOH LAPORAN
-- =====================================================================
INSERT INTO reports (id, ticket_code, user_id, category_id, district_id, agency_id, assigned_to, title, description, address, latitude, longitude, impact_level, is_anonymous, status, resolved_at, created_at) VALUES
 (1, CONCAT('JR-', YEAR(NOW()), '-', LPAD(1, 4, '0')), 4, 1, 9, 1, 2, 'Lubang Jalan Amblas di Depan Pasar', 'Jalan cor amblas sedalam kurang lebih 35cm setelah dilintasi truk material. Sangat membahayakan pemotor yang lewat.', 'Jl. Asia Afrika No. 18, Bandung Wetan', -6.9211, 107.6097, 'bahaya', 0, 'proses', NULL, NOW() - INTERVAL 50 HOUR),
 (2, CONCAT('JR-', YEAR(NOW()), '-', LPAD(2, 4, '0')), 4, 2, 1, 2, NULL, 'PJU Mati Total 3 Tiang Sepanjang 200m', 'Lampu jalan mati total sejak 3 hari lalu, kondisi gelap pekat dan rawan tindak kejahatan malam hari.', 'Jl. Cihampelas No. 45, Coblong', -6.8868, 107.6041, 'sedang', 0, 'pending', NULL, NOW() - INTERVAL 3 HOUR),
 (3, CONCAT('JR-', YEAR(NOW()), '-', LPAD(3, 4, '0')), 4, 3, 1, 3, NULL, 'Tumpukan Sampah Menutupi Trotoar Pejalan Kaki', 'Sampah liar menumpuk menutup trotoar dan menimbulkan bau menyengat.', 'Simpang Dago Bawah, Coblong', -6.8979, 107.6133, 'sedang', 0, 'pending', NULL, NOW() - INTERVAL 26 HOUR),
 (4, CONCAT('JR-', YEAR(NOW()), '-', LPAD(4, 4, '0')), 5, 4, 2, 1, 2, 'Saluran Drainase Tersumbat Genangan Air', 'Drainase tersumbat sampah sehingga air menggenang setinggi mata kaki saat hujan.', 'Jl. R.E. Martadinata, Lengkong', -6.9064, 107.6232, 'sedang', 0, 'selesai', NOW() - INTERVAL 96 HOUR, NOW() - INTERVAL 144 HOUR),
 (5, CONCAT('JR-', YEAR(NOW()), '-', LPAD(5, 4, '0')), 5, 1, 10, 1, 2, 'Aspal Mengelupas Dekat Jembatan Penyeberangan', 'Permukaan jalan bergelombang dan batu koral berserakan sehingga licin bagi pengendara.', 'Jl. Buah Batu, Batununggal', -6.9369, 107.6329, 'sedang', 0, 'selesai', NOW() - INTERVAL 144 HOUR, NOW() - INTERVAL 216 HOUR),
 (6, CONCAT('JR-', YEAR(NOW()), '-', LPAD(6, 4, '0')), 4, 5, 4, 4, NULL, 'Rambu Lalu Lintas Roboh di Simpang', 'Rambu dilarang parkir roboh tertabrak kendaraan dan menghalangi trotoar.', 'Jl. Setiabudi, Sukasari', -6.8635, 107.5952, 'sedang', 0, 'ditolak', NULL, NOW() - INTERVAL 288 HOUR),
 (7, CONCAT('JR-', YEAR(NOW()), '-', LPAD(7, 4, '0')), 5, 6, 8, 5, 3, 'Pohon Tumbang Menimpa Kabel Tiang Listrik', 'Pohon besar tumbang menimpa kabel dan miring ke badan jalan. Segera butuh penanganan.', 'Jl. Pasir Kaliki, Cicendo', -6.9086, 107.5901, 'bahaya', 0, 'proses', NULL, NOW() - INTERVAL 20 HOUR),
 (8, CONCAT('JR-', YEAR(NOW()), '-', LPAD(8, 4, '0')), 4, 2, 3, 2, 3, 'Lampu PJU Padam di Jl. Pasteur', 'Dua titik lampu PJU padam sejak sepekan lalu.', 'Jl. Dr. Djunjunan, Sukajadi', -6.8924, 107.5947, 'sedang', 0, 'selesai', NOW() - INTERVAL 132 HOUR, NOW() - INTERVAL 192 HOUR),
 (9, CONCAT('JR-', YEAR(NOW()), '-', LPAD(9, 4, '0')), 5, 3, 6, 3, NULL, 'Sampah Liar di Bantaran Sungai Cikapundung', 'Warga membuang sampah rumah tangga sembarangan di bantaran sungai.', 'Bantaran Cikapundung, Astana Anyar', -6.9302, 107.6041, 'sedang', 1, 'pending', NULL, NOW() - INTERVAL 50 HOUR),
 (10, CONCAT('JR-', YEAR(NOW()), '-', LPAD(10, 4, '0')), 4, 4, 7, 1, 2, 'Gorong-gorong Amblas Dekat Sekolah', 'Penutup gorong-gorong amblas di depan gerbang sekolah dasar, anak-anak berisiko terperosok.', 'Jl. Kopo, Bojongloa Kaler', -6.9401, 107.5821, 'bahaya', 0, 'proses', NULL, NOW() - INTERVAL 96 HOUR),
 (11, CONCAT('JR-', YEAR(NOW()), '-', LPAD(11, 4, '0')), 5, 1, 5, 1, NULL, 'Trotoar Rusak Menyulitkan Pengguna Kursi Roda', 'Trotoar retak dan naik turun, sulit dilalui penyandang disabilitas.', 'Jl. Surapati, Cibeunying Kaler', -6.8948, 107.6235, 'sedang', 0, 'pending', NULL, NOW() - INTERVAL 5 HOUR),
 (12, CONCAT('JR-', YEAR(NOW()), '-', LPAD(12, 4, '0')), 4, 6, 2, 5, NULL, 'Taman Kota Tanpa Penerangan', 'Taman tidak memiliki lampu sehingga gelap dan tidak aman setelah magrib.', 'Taman Lalu Lintas, Lengkong', -6.9156, 107.6178, 'sedang', 0, 'pending', NULL, NOW() - INTERVAL 72 HOUR);

INSERT INTO report_logs (report_id, user_id, action, note, created_at) VALUES
 (1, 4, 'dibuat', 'Laporan dikirim oleh pelapor dan menunggu verifikasi.', NOW() - INTERVAL 50 HOUR),
 (1, 1, 'disposisi', 'Didisposisikan ke instansi terkait.', NOW() - INTERVAL 49 HOUR),
 (1, 2, 'status', 'Status diubah menjadi Diproses oleh petugas.', NOW() - INTERVAL 48 HOUR),
 (2, 4, 'dibuat', 'Laporan dikirim oleh pelapor dan menunggu verifikasi.', NOW() - INTERVAL 3 HOUR),
 (3, 4, 'dibuat', 'Laporan dikirim oleh pelapor dan menunggu verifikasi.', NOW() - INTERVAL 26 HOUR),
 (4, 5, 'dibuat', 'Laporan dikirim oleh pelapor dan menunggu verifikasi.', NOW() - INTERVAL 144 HOUR),
 (4, 1, 'disposisi', 'Didisposisikan ke instansi terkait.', NOW() - INTERVAL 143 HOUR),
 (4, 2, 'status', 'Status diubah menjadi Diproses oleh petugas.', NOW() - INTERVAL 142 HOUR),
 (4, 2, 'status', 'Status diubah menjadi Selesai oleh petugas.', NOW() - INTERVAL 96 HOUR),
 (5, 5, 'dibuat', 'Laporan dikirim oleh pelapor dan menunggu verifikasi.', NOW() - INTERVAL 216 HOUR),
 (5, 1, 'disposisi', 'Didisposisikan ke instansi terkait.', NOW() - INTERVAL 215 HOUR),
 (5, 2, 'status', 'Status diubah menjadi Diproses oleh petugas.', NOW() - INTERVAL 214 HOUR),
 (5, 2, 'status', 'Status diubah menjadi Selesai oleh petugas.', NOW() - INTERVAL 144 HOUR),
 (6, 4, 'dibuat', 'Laporan dikirim oleh pelapor dan menunggu verifikasi.', NOW() - INTERVAL 288 HOUR),
 (6, 1, 'disposisi', 'Didisposisikan ke instansi terkait.', NOW() - INTERVAL 287 HOUR),
 (6, 1, 'status', 'Status diubah menjadi Ditolak oleh petugas.', NOW() - INTERVAL 286 HOUR),
 (7, 5, 'dibuat', 'Laporan dikirim oleh pelapor dan menunggu verifikasi.', NOW() - INTERVAL 20 HOUR),
 (7, 1, 'disposisi', 'Didisposisikan ke instansi terkait.', NOW() - INTERVAL 19 HOUR),
 (7, 3, 'status', 'Status diubah menjadi Diproses oleh petugas.', NOW() - INTERVAL 18 HOUR),
 (8, 4, 'dibuat', 'Laporan dikirim oleh pelapor dan menunggu verifikasi.', NOW() - INTERVAL 192 HOUR),
 (8, 1, 'disposisi', 'Didisposisikan ke instansi terkait.', NOW() - INTERVAL 191 HOUR),
 (8, 3, 'status', 'Status diubah menjadi Diproses oleh petugas.', NOW() - INTERVAL 190 HOUR),
 (8, 3, 'status', 'Status diubah menjadi Selesai oleh petugas.', NOW() - INTERVAL 132 HOUR),
 (9, 5, 'dibuat', 'Laporan dikirim oleh pelapor dan menunggu verifikasi.', NOW() - INTERVAL 50 HOUR),
 (10, 4, 'dibuat', 'Laporan dikirim oleh pelapor dan menunggu verifikasi.', NOW() - INTERVAL 96 HOUR),
 (10, 1, 'disposisi', 'Didisposisikan ke instansi terkait.', NOW() - INTERVAL 95 HOUR),
 (10, 2, 'status', 'Status diubah menjadi Diproses oleh petugas.', NOW() - INTERVAL 94 HOUR),
 (11, 5, 'dibuat', 'Laporan dikirim oleh pelapor dan menunggu verifikasi.', NOW() - INTERVAL 5 HOUR),
 (12, 4, 'dibuat', 'Laporan dikirim oleh pelapor dan menunggu verifikasi.', NOW() - INTERVAL 72 HOUR);

INSERT INTO report_responses (report_id, user_id, message, created_at) VALUES
 (1, 2, 'Laporan telah diverifikasi. Regu lapangan dijadwalkan turun untuk penanganan sementara.', NOW() - INTERVAL 48 HOUR),
 (4, 2, 'Penanganan selesai dilakukan oleh tim teknis. Hasil pemantauan aman terkendali.', NOW() - INTERVAL 142 HOUR),
 (5, 2, 'Penanganan selesai dilakukan oleh tim teknis. Hasil pemantauan aman terkendali.', NOW() - INTERVAL 214 HOUR),
 (6, 1, 'Laporan merupakan kewenangan pihak lain / bukan fasilitas publik pemerintah kota.', NOW() - INTERVAL 286 HOUR),
 (7, 3, 'Laporan telah diverifikasi. Regu lapangan dijadwalkan turun untuk penanganan sementara.', NOW() - INTERVAL 18 HOUR),
 (8, 3, 'Penanganan selesai dilakukan oleh tim teknis. Hasil pemantauan aman terkendali.', NOW() - INTERVAL 190 HOUR),
 (10, 2, 'Laporan telah diverifikasi. Regu lapangan dijadwalkan turun untuk penanganan sementara.', NOW() - INTERVAL 94 HOUR);

INSERT INTO notifications (user_id, report_id, message, is_read) VALUES
 (4, 1,  'Laporan #1 kini berstatus Diproses: regu lapangan dijadwalkan turun.', 0),
 (5, 4,  'Laporan Anda telah selesai ditangani. Terima kasih atas partisipasi Anda.', 0),
 (1, 11, 'Laporan baru menunggu verifikasi: Trotoar Rusak Menyulitkan Pengguna Kursi Roda', 0),
 (2, 2,  'Laporan baru menunggu verifikasi: PJU Mati Total 3 Tiang Sepanjang 200m', 0);

-- =====================================================================
--  v2.1: lengkapi data contoh (prioritas, SLA, counter tiket)
-- =====================================================================
UPDATE reports SET
  priority          = IF(impact_level = 'bahaya', 'darurat', 'sedang'),
  respond_due_at    = created_at + INTERVAL IF(impact_level = 'bahaya', 12, 24)  HOUR,
  resolve_due_at    = created_at + INTERVAL IF(impact_level = 'bahaya', 72, 168) HOUR,
  first_response_at = IF(status = 'pending', NULL, created_at + INTERVAL 1 HOUR);

INSERT INTO ticket_counters (yr, last_no) VALUES (YEAR(NOW()), 12);

-- =====================================================================
--  v2.1: PROTEKSI APPEND-ONLY (riwayat tidak dapat diubah/dihapus lewat SQL biasa)
--  Letakkan paling akhir. Jika hosting Anda menolak CREATE TRIGGER (hak akses terbatas),
--  abaikan error di bawah ini: semua tabel & data di atas sudah tersimpan dan aplikasi tetap berjalan.
--  Hash chain tetap mendeteksi pengubahan data walau trigger tidak aktif.
-- =====================================================================
DROP TRIGGER IF EXISTS trg_report_logs_no_update;
DROP TRIGGER IF EXISTS trg_report_logs_no_delete;
DROP TRIGGER IF EXISTS trg_audit_log_no_update;
DROP TRIGGER IF EXISTS trg_audit_log_no_delete;
CREATE TRIGGER trg_report_logs_no_update BEFORE UPDATE ON report_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'report_logs bersifat append-only';
CREATE TRIGGER trg_report_logs_no_delete BEFORE DELETE ON report_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'report_logs bersifat append-only';
CREATE TRIGGER trg_audit_log_no_update BEFORE UPDATE ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log bersifat append-only';
CREATE TRIGGER trg_audit_log_no_delete BEFORE DELETE ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log bersifat append-only';
