-- Email reminders + platform SMTP settings (idempotent upgrade)

CREATE TABLE IF NOT EXISTS platform_settings (
  skey       VARCHAR(80) NOT NULL PRIMARY KEY,
  svalue     TEXT NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS license_reminders (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id      BIGINT UNSIGNED NOT NULL,
  license_id     BIGINT UNSIGNED NOT NULL,
  threshold_days INT NOT NULL,
  recipient      VARCHAR(190) NOT NULL,
  status         ENUM('sending','sent','failed') NOT NULL DEFAULT 'sending',
  attempts       INT NOT NULL DEFAULT 1,
  last_error     VARCHAR(500) NULL,
  sent_at        DATETIME(3) NULL,
  created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_license_reminder (license_id, threshold_days),
  KEY ix_reminder_status (status, updated_at),
  KEY ix_reminder_tenant (tenant_id, created_at),
  CONSTRAINT fk_reminder_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_reminder_license FOREIGN KEY (license_id) REFERENCES licenses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
