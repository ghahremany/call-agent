-- منشی صوتی — جدول‌های انبار
-- این جدول‌ها در اولین اجرای api.php خودکار ساخته می‌شوند؛
-- این فایل فقط برای مرجع است (اگر خواستی دستی بسازی).

CREATE TABLE IF NOT EXISTS ngy_voice_slots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slot VARCHAR(48) NOT NULL,
  day VARCHAR(32) NOT NULL DEFAULT '',
  status VARCHAR(16) NOT NULL DEFAULT 'free',   -- free | booked | done
  name VARCHAR(120) NOT NULL DEFAULT '',
  phone VARCHAR(32) NOT NULL DEFAULT '',
  sid VARCHAR(64) NOT NULL DEFAULT '',
  note VARCHAR(255) NOT NULL DEFAULT '',
  ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_slot (slot),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ngy_voice_calls (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sid VARCHAR(64) NOT NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  turns INT NOT NULL DEFAULT 0,
  outcome VARCHAR(24) NOT NULL DEFAULT '',
  transcript MEDIUMTEXT,
  ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sid (sid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- نمونهٔ وقت‌های آزاد (اختیاری)
-- INSERT IGNORE INTO ngy_voice_slots (slot, status) VALUES
--   ('شنبه ۲۶ مهر ساعت ۱۰:۰۰', 'free'),
--   ('شنبه ۲۶ مهر ساعت ۱۶:۳۰', 'free'),
--   ('یکشنبه ۲۷ مهر ساعت ۱۱:۰۰', 'free');
