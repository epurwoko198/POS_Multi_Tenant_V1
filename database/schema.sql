-- ============================================================================
--  POS SaaS Platform - Schema (MySQL 8.x / MariaDB 10.11+)
--  Multi-tenant, append-only ledger, auditable. All money = DECIMAL.
--  All timestamps stored in UTC (session time_zone is forced to +00:00).
-- ============================================================================
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------- SaaS core
CREATE TABLE IF NOT EXISTS plans (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(32)  NOT NULL,
  name          VARCHAR(80)  NOT NULL,
  description   VARCHAR(255) NULL,
  price_monthly DECIMAL(18,2) NOT NULL DEFAULT 0,
  price_yearly  DECIMAL(18,2) NOT NULL DEFAULT 0,
  trial_days    INT NOT NULL DEFAULT 14,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  sort_order    INT NOT NULL DEFAULT 0,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_plan_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tenants (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26) NOT NULL,
  name          VARCHAR(160) NOT NULL,
  slug          VARCHAR(80)  NOT NULL,
  domain        VARCHAR(160) NULL,
  email         VARCHAR(160) NULL,
  phone         VARCHAR(40)  NULL,
  address       TEXT NULL,
  currency      CHAR(3) NOT NULL DEFAULT 'IDR',
  timezone      VARCHAR(64) NOT NULL DEFAULT 'Asia/Jakarta',
  status        ENUM('trial','active','past_due','grace','suspended','cancelled') NOT NULL DEFAULT 'trial',
  plan_id       BIGINT UNSIGNED NULL,
  trial_ends_at DATETIME(3) NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_tenant_public (public_id),
  UNIQUE KEY uq_tenant_slug (slug),
  UNIQUE KEY uq_tenant_domain (domain),
  KEY ix_tenant_status (status),
  CONSTRAINT fk_tenant_plan FOREIGN KEY (plan_id) REFERENCES plans (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS plan_features (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plan_id      BIGINT UNSIGNED NOT NULL,
  feature_code VARCHAR(40) NOT NULL,
  enabled      TINYINT(1) NOT NULL DEFAULT 1,
  limit_value  INT NULL,
  UNIQUE KEY uq_plan_feature (plan_id, feature_code),
  CONSTRAINT fk_pf_plan FOREIGN KEY (plan_id) REFERENCES plans (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tenant_features (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id    BIGINT UNSIGNED NOT NULL,
  feature_code VARCHAR(40) NOT NULL,
  enabled      TINYINT(1) NOT NULL DEFAULT 1,
  updated_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_tenant_feature (tenant_id, feature_code),
  CONSTRAINT fk_tf_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subscriptions (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id            CHAR(26) NOT NULL,
  tenant_id            BIGINT UNSIGNED NOT NULL,
  plan_id              BIGINT UNSIGNED NOT NULL,
  status               ENUM('trial','active','past_due','grace','suspended','cancelled') NOT NULL DEFAULT 'trial',
  billing_cycle        ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
  started_at           DATETIME(3) NOT NULL,
  current_period_start DATETIME(3) NOT NULL,
  current_period_end   DATETIME(3) NOT NULL,
  grace_until          DATETIME(3) NULL,
  cancelled_at         DATETIME(3) NULL,
  created_at           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_sub_public (public_id),
  KEY ix_sub_tenant (tenant_id, status),
  CONSTRAINT fk_sub_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_sub_plan FOREIGN KEY (plan_id) REFERENCES plans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subscription_invoices (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id       CHAR(26) NOT NULL,
  tenant_id       BIGINT UNSIGNED NOT NULL,
  subscription_id BIGINT UNSIGNED NULL,
  number          VARCHAR(40) NOT NULL,
  amount          DECIMAL(18,2) NOT NULL,
  status          ENUM('open','paid','overdue','void') NOT NULL DEFAULT 'open',
  period_start    DATETIME(3) NOT NULL,
  period_end      DATETIME(3) NOT NULL,
  due_date        DATE NOT NULL,
  paid_at         DATETIME(3) NULL,
  notes           VARCHAR(255) NULL,
  created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_inv_number (number),
  UNIQUE KEY uq_inv_public (public_id),
  KEY ix_inv_tenant (tenant_id, status, due_date),
  CONSTRAINT fk_inv_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS licenses (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26) NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  key_id        VARCHAR(40) NOT NULL,
  token         TEXT NOT NULL,
  plan_code     VARCHAR(32) NOT NULL,
  features_json JSON NOT NULL,
  limits_json   JSON NOT NULL,
  domain        VARCHAR(160) NULL,
  issued_at     DATETIME(3) NOT NULL,
  expires_at    DATETIME(3) NOT NULL,
  status        ENUM('active','revoked','expired') NOT NULL DEFAULT 'active',
  revoked_at    DATETIME(3) NULL,
  revoked_reason VARCHAR(255) NULL,
  created_by    BIGINT UNSIGNED NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_license_public (public_id),
  KEY ix_license_tenant (tenant_id, status, expires_at),
  CONSTRAINT fk_license_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS license_events (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  license_id BIGINT UNSIGNED NULL,
  event      VARCHAR(40) NOT NULL,
  meta_json  JSON NULL,
  actor_id   BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_le_tenant (tenant_id, created_at),
  CONSTRAINT fk_le_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- Identity
CREATE TABLE IF NOT EXISTS roles (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id   BIGINT UNSIGNED NULL,
  code        VARCHAR(32) NOT NULL,
  name        VARCHAR(60) NOT NULL,
  permissions JSON NOT NULL,
  is_system   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_role_code (code),
  CONSTRAINT fk_role_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS outlets (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id           CHAR(26) NOT NULL,
  tenant_id           BIGINT UNSIGNED NOT NULL,
  code                VARCHAR(20) NOT NULL,
  name                VARCHAR(120) NOT NULL,
  address             TEXT NULL,
  phone               VARCHAR(40) NULL,
  timezone            VARCHAR(64) NOT NULL DEFAULT 'Asia/Jakarta',
  tax_rate            DECIMAL(5,2) NOT NULL DEFAULT 0,
  service_charge_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  receipt_footer      VARCHAR(255) NULL,
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_outlet_code (tenant_id, code),
  UNIQUE KEY uq_outlet_public (public_id),
  CONSTRAINT fk_outlet_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26) NOT NULL,
  tenant_id     BIGINT UNSIGNED NULL,
  outlet_id     BIGINT UNSIGNED NULL,
  role_id       BIGINT UNSIGNED NOT NULL,
  name          VARCHAR(120) NOT NULL,
  email         VARCHAR(160) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  pin_hash      VARCHAR(255) NULL,
  phone         VARCHAR(40) NULL,
  status        ENUM('active','inactive','locked') NOT NULL DEFAULT 'active',
  last_login_at DATETIME(3) NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_user_email (email),
  UNIQUE KEY uq_user_public (public_id),
  KEY ix_user_tenant (tenant_id, status),
  CONSTRAINT fk_user_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_user_outlet FOREIGN KEY (outlet_id) REFERENCES outlets (id) ON DELETE SET NULL,
  CONSTRAINT fk_user_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  identifier VARCHAR(160) NOT NULL,
  ip         VARCHAR(64) NOT NULL,
  attempts   INT NOT NULL DEFAULT 0,
  locked_until DATETIME(3) NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_attempt (identifier, ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- Org
CREATE TABLE IF NOT EXISTS warehouses (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  outlet_id  BIGINT UNSIGNED NULL,
  code       VARCHAR(20) NOT NULL,
  name       VARCHAR(120) NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_wh_code (tenant_id, code),
  CONSTRAINT fk_wh_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_wh_outlet FOREIGN KEY (outlet_id) REFERENCES outlets (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS registers (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  outlet_id  BIGINT UNSIGNED NOT NULL,
  code       VARCHAR(20) NOT NULL,
  name       VARCHAR(80) NOT NULL,
  device_key CHAR(64) NOT NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_reg_code (tenant_id, code),
  CONSTRAINT fk_reg_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_reg_outlet FOREIGN KEY (outlet_id) REFERENCES outlets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- Catalog
CREATE TABLE IF NOT EXISTS categories (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  name       VARCHAR(80) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_cat_tenant (tenant_id, is_active),
  CONSTRAINT fk_cat_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS units (
  id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  code      VARCHAR(12) NOT NULL,
  name      VARCHAR(40) NOT NULL,
  UNIQUE KEY uq_unit (tenant_id, code),
  CONSTRAINT fk_unit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS taxes (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id    BIGINT UNSIGNED NOT NULL,
  name         VARCHAR(60) NOT NULL,
  rate         DECIMAL(5,2) NOT NULL DEFAULT 0,
  is_inclusive TINYINT(1) NOT NULL DEFAULT 0,
  created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT fk_tax_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id   CHAR(26) NOT NULL,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NULL,
  sku         VARCHAR(40) NOT NULL,
  barcode     VARCHAR(64) NULL,
  name        VARCHAR(160) NOT NULL,
  unit        VARCHAR(12) NOT NULL DEFAULT 'pcs',
  cost        DECIMAL(18,2) NOT NULL DEFAULT 0,
  price       DECIMAL(18,2) NOT NULL DEFAULT 0,
  tax_rate    DECIMAL(5,2) NOT NULL DEFAULT 0,
  station     VARCHAR(40) NULL,
  track_stock TINYINT(1) NOT NULL DEFAULT 1,
  is_sellable TINYINT(1) NOT NULL DEFAULT 1,
  reorder_point DECIMAL(18,3) NOT NULL DEFAULT 0,
  reorder_qty   DECIMAL(18,3) NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_prod_public (public_id),
  UNIQUE KEY uq_prod_sku (tenant_id, sku),
  KEY ix_prod_barcode (tenant_id, barcode),
  KEY ix_prod_name (tenant_id, name),
  CONSTRAINT fk_prod_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_prod_cat FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- Inventory
CREATE TABLE IF NOT EXISTS stock_balances (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  outlet_id  BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  qty        DECIMAL(18,3) NOT NULL DEFAULT 0,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_stock (tenant_id, outlet_id, product_id),
  KEY ix_stock_product (tenant_id, product_id),
  CONSTRAINT fk_sb_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_sb_outlet FOREIGN KEY (outlet_id) REFERENCES outlets (id) ON DELETE CASCADE,
  CONSTRAINT fk_sb_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_ledger (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  outlet_id     BIGINT UNSIGNED NOT NULL,
  product_id    BIGINT UNSIGNED NOT NULL,
  qty_delta     DECIMAL(18,3) NOT NULL,
  unit_cost     DECIMAL(18,2) NOT NULL DEFAULT 0,
  balance_after DECIMAL(18,3) NOT NULL DEFAULT 0,
  source_type   VARCHAR(30) NOT NULL,
  source_id     BIGINT UNSIGNED NULL,
  note          VARCHAR(255) NULL,
  created_by    BIGINT UNSIGNED NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_ledger (tenant_id, outlet_id, product_id, created_at),
  KEY ix_ledger_source (tenant_id, source_type, source_id),
  CONSTRAINT fk_sl_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_sl_outlet FOREIGN KEY (outlet_id) REFERENCES outlets (id) ON DELETE CASCADE,
  CONSTRAINT fk_sl_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_transfers (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26) NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  from_outlet_id BIGINT UNSIGNED NOT NULL,
  to_outlet_id  BIGINT UNSIGNED NOT NULL,
  number        VARCHAR(40) NOT NULL,
  status        ENUM('draft','completed','cancelled') NOT NULL DEFAULT 'completed',
  note          VARCHAR(255) NULL,
  created_by    BIGINT UNSIGNED NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_transfer_number (tenant_id, number),
  UNIQUE KEY uq_transfer_public (public_id),
  CONSTRAINT fk_tr_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_tr_from FOREIGN KEY (from_outlet_id) REFERENCES outlets (id),
  CONSTRAINT fk_tr_to FOREIGN KEY (to_outlet_id) REFERENCES outlets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_transfer_items (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transfer_id BIGINT UNSIGNED NOT NULL,
  product_id  BIGINT UNSIGNED NOT NULL,
  qty         DECIMAL(18,3) NOT NULL,
  CONSTRAINT fk_tri_transfer FOREIGN KEY (transfer_id) REFERENCES stock_transfers (id) ON DELETE CASCADE,
  CONSTRAINT fk_tri_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- Purchasing
CREATE TABLE IF NOT EXISTS suppliers (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  name       VARCHAR(120) NOT NULL,
  contact    VARCHAR(80) NULL,
  phone      VARCHAR(40) NULL,
  email      VARCHAR(160) NULL,
  address    TEXT NULL,
  terms_days INT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_sup_tenant (tenant_id, is_active),
  CONSTRAINT fk_sup_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_orders (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id   CHAR(26) NOT NULL,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  outlet_id   BIGINT UNSIGNED NOT NULL,
  supplier_id BIGINT UNSIGNED NOT NULL,
  number      VARCHAR(40) NOT NULL,
  status      ENUM('draft','ordered','partial','received','cancelled') NOT NULL DEFAULT 'ordered',
  subtotal    DECIMAL(18,2) NOT NULL DEFAULT 0,
  tax_total   DECIMAL(18,2) NOT NULL DEFAULT 0,
  total       DECIMAL(18,2) NOT NULL DEFAULT 0,
  notes       VARCHAR(255) NULL,
  created_by  BIGINT UNSIGNED NULL,
  created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  received_at DATETIME(3) NULL,
  UNIQUE KEY uq_po_public (public_id),
  UNIQUE KEY uq_po_number (tenant_id, number),
  KEY ix_po_tenant (tenant_id, status, created_at),
  CONSTRAINT fk_po_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_po_outlet FOREIGN KEY (outlet_id) REFERENCES outlets (id),
  CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_order_items (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_order_id BIGINT UNSIGNED NOT NULL,
  product_id        BIGINT UNSIGNED NOT NULL,
  qty               DECIMAL(18,3) NOT NULL,
  received_qty      DECIMAL(18,3) NOT NULL DEFAULT 0,
  unit_cost         DECIMAL(18,2) NOT NULL,
  line_total        DECIMAL(18,2) NOT NULL,
  CONSTRAINT fk_poi_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_poi_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- CRM
CREATE TABLE IF NOT EXISTS customers (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id   CHAR(26) NOT NULL,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  name        VARCHAR(120) NOT NULL,
  phone       VARCHAR(40) NULL,
  email       VARCHAR(160) NULL,
  points      INT NOT NULL DEFAULT 0,
  total_spent DECIMAL(18,2) NOT NULL DEFAULT 0,
  tier        VARCHAR(20) NOT NULL DEFAULT 'basic',
  notes       VARCHAR(255) NULL,
  created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_cust_public (public_id),
  KEY ix_cust_phone (tenant_id, phone),
  CONSTRAINT fk_cust_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS loyalty_transactions (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  order_id    BIGINT UNSIGNED NULL,
  points_delta INT NOT NULL,
  reason      VARCHAR(80) NOT NULL,
  created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_loyal_tenant (tenant_id, customer_id, created_at),
  CONSTRAINT fk_loyal_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_loyal_cust FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- Restaurant
CREATE TABLE IF NOT EXISTS dining_tables (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  outlet_id  BIGINT UNSIGNED NOT NULL,
  name       VARCHAR(40) NOT NULL,
  area       VARCHAR(40) NOT NULL DEFAULT 'Main',
  capacity   INT NOT NULL DEFAULT 4,
  status     ENUM('free','occupied','reserved') NOT NULL DEFAULT 'free',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_table_outlet (tenant_id, outlet_id, status),
  CONSTRAINT fk_table_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_table_outlet FOREIGN KEY (outlet_id) REFERENCES outlets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recipes (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  yield_qty  DECIMAL(18,3) NOT NULL DEFAULT 1,
  note       VARCHAR(255) NULL,
  UNIQUE KEY uq_recipe_product (tenant_id, product_id),
  CONSTRAINT fk_recipe_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_recipe_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recipe_items (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recipe_id     BIGINT UNSIGNED NOT NULL,
  ingredient_id BIGINT UNSIGNED NOT NULL,
  qty           DECIMAL(18,4) NOT NULL,
  CONSTRAINT fk_ri_recipe FOREIGN KEY (recipe_id) REFERENCES recipes (id) ON DELETE CASCADE,
  CONSTRAINT fk_ri_ingredient FOREIGN KEY (ingredient_id) REFERENCES products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- Sales
CREATE TABLE IF NOT EXISTS cash_shifts (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  outlet_id     BIGINT UNSIGNED NOT NULL,
  register_id   BIGINT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,
  opening_float DECIMAL(18,2) NOT NULL DEFAULT 0,
  closing_count DECIMAL(18,2) NULL,
  expected_cash DECIMAL(18,2) NULL,
  variance      DECIMAL(18,2) NULL,
  status        ENUM('open','closed') NOT NULL DEFAULT 'open',
  opened_at     DATETIME(3) NOT NULL,
  closed_at     DATETIME(3) NULL,
  notes         VARCHAR(255) NULL,
  KEY ix_shift (tenant_id, outlet_id, status),
  CONSTRAINT fk_shift_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_shift_outlet FOREIGN KEY (outlet_id) REFERENCES outlets (id),
  CONSTRAINT fk_shift_reg FOREIGN KEY (register_id) REFERENCES registers (id),
  CONSTRAINT fk_shift_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cash_movements (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  shift_id   BIGINT UNSIGNED NOT NULL,
  type       ENUM('in','out','safe_drop') NOT NULL,
  amount     DECIMAL(18,2) NOT NULL,
  reason     VARCHAR(160) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_cm_shift (shift_id, created_at),
  CONSTRAINT fk_cm_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_cm_shift FOREIGN KEY (shift_id) REFERENCES cash_shifts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_orders (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26) NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  outlet_id     BIGINT UNSIGNED NOT NULL,
  register_id   BIGINT UNSIGNED NULL,
  cashier_id    BIGINT UNSIGNED NOT NULL,
  shift_id      BIGINT UNSIGNED NULL,
  customer_id   BIGINT UNSIGNED NULL,
  table_id      BIGINT UNSIGNED NULL,
  order_no      VARCHAR(40) NOT NULL,
  order_type    ENUM('retail','dine_in','takeaway','delivery') NOT NULL DEFAULT 'retail',
  status        ENUM('draft','awaiting_payment','paid','completed','cancelled','partially_refunded','refunded') NOT NULL DEFAULT 'draft',
  currency      CHAR(3) NOT NULL DEFAULT 'IDR',
  subtotal      DECIMAL(18,2) NOT NULL DEFAULT 0,
  discount_total DECIMAL(18,2) NOT NULL DEFAULT 0,
  tax_total     DECIMAL(18,2) NOT NULL DEFAULT 0,
  service_charge DECIMAL(18,2) NOT NULL DEFAULT 0,
  grand_total   DECIMAL(18,2) NOT NULL DEFAULT 0,
  paid_total    DECIMAL(18,2) NOT NULL DEFAULT 0,
  notes         VARCHAR(255) NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  paid_at       DATETIME(3) NULL,
  completed_at  DATETIME(3) NULL,
  UNIQUE KEY uq_order_public (public_id),
  UNIQUE KEY uq_order_no (tenant_id, outlet_id, order_no),
  KEY ix_order_report (tenant_id, outlet_id, created_at, status),
  KEY ix_order_customer (tenant_id, customer_id),
  CONSTRAINT fk_order_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_order_outlet FOREIGN KEY (outlet_id) REFERENCES outlets (id),
  CONSTRAINT fk_order_register FOREIGN KEY (register_id) REFERENCES registers (id) ON DELETE SET NULL,
  CONSTRAINT fk_order_cashier FOREIGN KEY (cashier_id) REFERENCES users (id),
  CONSTRAINT fk_order_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_order_items (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id      BIGINT UNSIGNED NOT NULL,
  order_id       BIGINT UNSIGNED NOT NULL,
  product_id     BIGINT UNSIGNED NULL,
  name_snapshot  VARCHAR(160) NOT NULL,
  sku_snapshot   VARCHAR(40) NOT NULL,
  qty            DECIMAL(18,3) NOT NULL,
  unit_price     DECIMAL(18,2) NOT NULL,
  unit_cost      DECIMAL(18,2) NOT NULL DEFAULT 0,
  discount       DECIMAL(18,2) NOT NULL DEFAULT 0,
  tax_rate       DECIMAL(5,2) NOT NULL DEFAULT 0,
  tax_amount     DECIMAL(18,2) NOT NULL DEFAULT 0,
  line_total     DECIMAL(18,2) NOT NULL,
  station        VARCHAR(40) NULL,
  notes          VARCHAR(160) NULL,
  KEY ix_item_order (order_id),
  KEY ix_item_product (tenant_id, product_id, order_id),
  CONSTRAINT fk_item_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_item_order FOREIGN KEY (order_id) REFERENCES sales_orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_item_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payments (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id          CHAR(26) NOT NULL,
  tenant_id          BIGINT UNSIGNED NOT NULL,
  order_id           BIGINT UNSIGNED NOT NULL,
  method             ENUM('cash','qris','edc','transfer','ewallet') NOT NULL,
  provider           VARCHAR(30) NOT NULL DEFAULT 'manual',
  provider_ref       VARCHAR(120) NULL,
  amount             DECIMAL(18,2) NOT NULL,
  tendered           DECIMAL(18,2) NOT NULL DEFAULT 0,
  change_due         DECIMAL(18,2) NOT NULL DEFAULT 0,
  status             ENUM('created','pending','paid','settled','expired','failed','cancelled','partially_refunded','refunded') NOT NULL DEFAULT 'created',
  qr_string          TEXT NULL,
  expires_at         DATETIME(3) NULL,
  paid_at            DATETIME(3) NULL,
  signature_verified TINYINT(1) NOT NULL DEFAULT 0,
  idempotency_key    VARCHAR(100) NULL,
  raw_json           JSON NULL,
  created_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_pay_public (public_id),
  UNIQUE KEY uq_pay_idem (tenant_id, idempotency_key),
  KEY ix_pay_order (order_id, status),
  KEY ix_pay_provider (provider, provider_ref),
  CONSTRAINT fk_pay_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_order FOREIGN KEY (order_id) REFERENCES sales_orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payment_events (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  payment_id  BIGINT UNSIGNED NOT NULL,
  event       VARCHAR(60) NOT NULL,
  status      VARCHAR(30) NOT NULL,
  payload_json JSON NULL,
  verified    TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_pe_payment (payment_id, created_at),
  CONSTRAINT fk_pe_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_pe_payment FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS refunds (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id   CHAR(26) NOT NULL,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  order_id    BIGINT UNSIGNED NOT NULL,
  payment_id  BIGINT UNSIGNED NULL,
  amount      DECIMAL(18,2) NOT NULL,
  reason      VARCHAR(255) NOT NULL,
  status      ENUM('pending','approved','rejected','processed') NOT NULL DEFAULT 'pending',
  approved_by BIGINT UNSIGNED NULL,
  created_by  BIGINT UNSIGNED NOT NULL,
  created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  processed_at DATETIME(3) NULL,
  UNIQUE KEY uq_refund_public (public_id),
  KEY ix_refund_tenant (tenant_id, status),
  CONSTRAINT fk_refund_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_refund_order FOREIGN KEY (order_id) REFERENCES sales_orders (id),
  CONSTRAINT fk_refund_payment FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- Kitchen
CREATE TABLE IF NOT EXISTS kitchen_tickets (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id  CHAR(26) NOT NULL,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  order_id   BIGINT UNSIGNED NOT NULL,
  station    VARCHAR(40) NOT NULL DEFAULT 'kitchen',
  status     ENUM('queued','preparing','ready','served') NOT NULL DEFAULT 'queued',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_ticket_public (public_id),
  KEY ix_ticket_status (tenant_id, status, created_at),
  CONSTRAINT fk_ticket_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_ticket_order FOREIGN KEY (order_id) REFERENCES sales_orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS kitchen_ticket_items (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id     BIGINT UNSIGNED NOT NULL,
  order_item_id BIGINT UNSIGNED NULL,
  name          VARCHAR(160) NOT NULL,
  qty           DECIMAL(18,3) NOT NULL,
  notes         VARCHAR(160) NULL,
  CONSTRAINT fk_kti_ticket FOREIGN KEY (ticket_id) REFERENCES kitchen_tickets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- Print
CREATE TABLE IF NOT EXISTS print_jobs (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id       CHAR(26) NOT NULL,
  tenant_id       BIGINT UNSIGNED NOT NULL,
  order_id        BIGINT UNSIGNED NOT NULL,
  printer         VARCHAR(60) NOT NULL DEFAULT 'default',
  copy_type       ENUM('original','copy','reprint') NOT NULL DEFAULT 'original',
  status          ENUM('queued','printing','printed','failed') NOT NULL DEFAULT 'queued',
  payload         LONGTEXT NULL,
  attempts        INT NOT NULL DEFAULT 0,
  idempotency_key VARCHAR(100) NOT NULL,
  requested_by    BIGINT UNSIGNED NULL,
  created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  printed_at      DATETIME(3) NULL,
  UNIQUE KEY uq_print_public (public_id),
  UNIQUE KEY uq_print_idem (idempotency_key),
  KEY ix_print_queue (tenant_id, status, created_at),
  CONSTRAINT fk_print_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_print_order FOREIGN KEY (order_id) REFERENCES sales_orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- Platform
CREATE TABLE IF NOT EXISTS audit_logs (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id  BIGINT UNSIGNED NULL,
  user_id    BIGINT UNSIGNED NULL,
  action     VARCHAR(60) NOT NULL,
  entity     VARCHAR(40) NOT NULL,
  entity_id  VARCHAR(64) NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  ip         VARCHAR(64) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_audit_tenant (tenant_id, created_at),
  KEY ix_audit_action (tenant_id, action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS webhook_inbox (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider   VARCHAR(30) NOT NULL,
  event_id   VARCHAR(120) NOT NULL,
  payload    LONGTEXT NOT NULL,
  signature_verified TINYINT(1) NOT NULL DEFAULT 0,
  processed_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_webhook_event (provider, event_id),
  KEY ix_webhook_created (provider, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS outbox_events (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  type        VARCHAR(60) NOT NULL,
  payload_json JSON NOT NULL,
  status      ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
  attempts    INT NOT NULL DEFAULT 0,
  available_at DATETIME(3) NOT NULL,
  processed_at DATETIME(3) NULL,
  created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_outbox (status, available_at),
  CONSTRAINT fk_outbox_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS api_tokens (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  name       VARCHAR(80) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  scopes     VARCHAR(255) NOT NULL DEFAULT '*',
  last_used_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_api_hash (token_hash),
  KEY ix_api_tenant (tenant_id),
  CONSTRAINT fk_api_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  skey      VARCHAR(60) NOT NULL,
  svalue    TEXT NULL,
  UNIQUE KEY uq_setting (tenant_id, skey),
  CONSTRAINT fk_set_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

-- ---------------------------------------------------------------- Reporting views
CREATE OR REPLACE VIEW v_daily_sales AS
SELECT o.tenant_id, o.outlet_id, DATE(o.created_at) AS sales_date,
       COUNT(*) AS orders, SUM(o.grand_total) AS revenue,
       SUM(o.discount_total) AS discounts, SUM(o.tax_total) AS taxes
FROM sales_orders o
WHERE o.status IN ('paid','completed','partially_refunded')
GROUP BY o.tenant_id, o.outlet_id, DATE(o.created_at);
