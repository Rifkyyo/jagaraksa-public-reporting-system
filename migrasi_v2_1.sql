-- =====================================================================
--  JagaRaksa  v2 -> v2.1   (MIGRASI untuk database yang SUDAH berisi data)
--
--  Pakai file ini JIKA Anda sudah mengimpor database.sql versi sebelumnya dan tidak ingin
--  kehilangan data. Jika memulai dari nol, cukup impor database.sql yang baru.
--
--  Jalankan SEKALI SAJA (phpMyAdmin -> pilih database jagaraksa -> Import).
--  Disarankan membuat cadangan (Export) database terlebih dahulu.
-- =====================================================================
SET NAMES utf8mb4;
USE jagaraksa;

-- 1) reports: prioritas, SLA, eskalasi, arsip -------------------------
ALTER TABLE reports
  ADD COLUMN priority          ENUM('rendah','sedang','tinggi','darurat') NOT NULL DEFAULT 'sedang' AFTER resolved_at,
  ADD COLUMN respond_due_at    DATETIME NULL AFTER priority,
  ADD COLUMN resolve_due_at    DATETIME NULL AFTER respond_due_at,
  ADD COLUMN first_response_at DATETIME NULL AFTER resolve_due_at,
  ADD COLUMN escalation_level  TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER first_response_at,
  ADD COLUMN escalated_at      DATETIME NULL AFTER escalation_level,
  ADD COLUMN deleted_at        DATETIME NULL AFTER escalated_at,
  ADD COLUMN deleted_by        BIGINT UNSIGNED NULL AFTER deleted_at,
  ADD COLUMN delete_reason     VARCHAR(255) NULL AFTER deleted_by,
  ADD KEY idx_rep_deleted (deleted_at),
  ADD KEY idx_rep_sla (status, respond_due_at, resolve_due_at),
  ADD KEY idx_rep_priority (priority),
  ADD KEY idx_rep_category (category_id),
  ADD KEY idx_rep_district (district_id),
  ADD CONSTRAINT fk_rep_deleter FOREIGN KEY (deleted_by) REFERENCES users (id) ON DELETE SET NULL;

-- data lama tidak ikut terhapus bila pengguna dihapus
ALTER TABLE reports DROP FOREIGN KEY fk_rep_user;
ALTER TABLE reports ADD CONSTRAINT fk_rep_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT;

-- isi prioritas & tenggat untuk laporan lama (dihitung dari waktu laporan dibuat)
UPDATE reports SET
  priority          = IF(impact_level = 'bahaya', 'darurat', 'sedang'),
  respond_due_at    = created_at + INTERVAL IF(impact_level = 'bahaya', 12, 24)  HOUR,
  resolve_due_at    = created_at + INTERVAL IF(impact_level = 'bahaya', 72, 168) HOUR,
  first_response_at = IF(status = 'pending', NULL, updated_at);

-- 2) report_logs: nilai lama/baru, IP, hash chain ----------------------
ALTER TABLE report_logs
  ADD COLUMN old_value  VARCHAR(100) NULL AFTER note,
  ADD COLUMN new_value  VARCHAR(100) NULL AFTER old_value,
  ADD COLUMN ip         VARCHAR(45)  NULL AFTER new_value,
  ADD COLUMN prev_hash  CHAR(64)     NULL AFTER ip,
  ADD COLUMN entry_hash CHAR(64)     NULL AFTER prev_hash,
  ADD KEY idx_log_action (action);

ALTER TABLE report_logs DROP FOREIGN KEY fk_log_report;
ALTER TABLE report_logs ADD CONSTRAINT fk_log_report FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE RESTRICT;

-- 3) tabel baru ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
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

CREATE TABLE IF NOT EXISTS ticket_counters (
  yr      SMALLINT UNSIGNED NOT NULL,
  last_no INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (yr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- counter dimulai dari nomor tiket tertinggi yang sudah ada (format JR-TAHUN-NNNN)
INSERT INTO ticket_counters (yr, last_no)
  SELECT CAST(SUBSTRING(ticket_code, 4, 4) AS UNSIGNED), MAX(CAST(SUBSTRING(ticket_code, 9) AS UNSIGNED))
  FROM reports GROUP BY CAST(SUBSTRING(ticket_code, 4, 4) AS UNSIGNED)
ON DUPLICATE KEY UPDATE last_no = VALUES(last_no);

-- 4) PROTEKSI APPEND-ONLY (opsional) -------------------------------------
--    Jika hosting menolak CREATE TRIGGER, abaikan error di bawah; aplikasi tetap berjalan
--    dan hash chain tetap mendeteksi pengubahan data.
DROP TRIGGER IF EXISTS trg_report_logs_no_update;
DROP TRIGGER IF EXISTS trg_report_logs_no_delete;
DROP TRIGGER IF EXISTS trg_audit_log_no_update;
DROP TRIGGER IF EXISTS trg_audit_log_no_delete;
CREATE TRIGGER trg_report_logs_no_update BEFORE UPDATE ON report_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'report_logs bersifat append-only';
CREATE TRIGGER trg_report_logs_no_delete BEFORE DELETE ON report_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'report_logs bersifat append-only';
CREATE TRIGGER trg_audit_log_no_update BEFORE UPDATE ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log bersifat append-only';
CREATE TRIGGER trg_audit_log_no_delete BEFORE DELETE ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log bersifat append-only';
