-- ============================================================================
--  POS SaaS Platform - Seed data (plans, roles, demo tenant, catalog)
--  Demo passwords are set by bin/install.php (Demo#12345 / Admin#12345).
-- ============================================================================
SET NAMES utf8mb4;
SET time_zone = '+00:00';

INSERT INTO plans (id, code, name, description, price_monthly, price_yearly, trial_days, sort_order) VALUES
(1,'starter','Starter','POS, katalog, stok dasar, laporan harian',149000,1490000,14,1),
(2,'growth','Growth','Multi outlet, purchasing, CRM, promo, dashboard lanjutan',349000,3490000,14,2),
(3,'restaurant','Restaurant','Meja, KDS, resep/BOM, kitchen routing, split bill',449000,4490000,14,3),
(4,'enterprise','Enterprise','Semua modul, API, AI, multi-outlet tanpa batas',999000,9990000,30,4);

INSERT INTO plan_features (plan_id, feature_code, enabled, limit_value) VALUES
-- Starter
(1,'pos',1,NULL),(1,'catalog',1,NULL),(1,'inventory',1,NULL),(1,'reports',1,NULL),(1,'print',1,NULL),
(1,'limit_outlets',1,1),(1,'limit_users',1,5),(1,'limit_products',1,500),(1,'limit_registers',1,2),(1,'limit_tx_month',1,5000),
-- Growth
(2,'pos',1,NULL),(2,'catalog',1,NULL),(2,'inventory',1,NULL),(2,'purchasing',1,NULL),(2,'crm',1,NULL),
(2,'loyalty',1,NULL),(2,'reports',1,NULL),(2,'reports_advanced',1,NULL),(2,'ai',1,NULL),(2,'print',1,NULL),
(2,'multi_outlet',1,NULL),(2,'api',1,NULL),
(2,'limit_outlets',1,5),(2,'limit_users',1,25),(2,'limit_products',1,5000),(2,'limit_registers',1,10),(2,'limit_tx_month',1,50000),
-- Restaurant
(3,'pos',1,NULL),(3,'catalog',1,NULL),(3,'inventory',1,NULL),(3,'restaurant',1,NULL),(3,'kds',1,NULL),
(3,'crm',1,NULL),(3,'loyalty',1,NULL),(3,'reports',1,NULL),(3,'reports_advanced',1,NULL),(3,'ai',1,NULL),(3,'print',1,NULL),
(3,'limit_outlets',1,3),(3,'limit_users',1,20),(3,'limit_products',1,2000),(3,'limit_registers',1,8),(3,'limit_tx_month',1,30000),
-- Enterprise
(4,'pos',1,NULL),(4,'catalog',1,NULL),(4,'inventory',1,NULL),(4,'purchasing',1,NULL),(4,'restaurant',1,NULL),
(4,'kds',1,NULL),(4,'crm',1,NULL),(4,'loyalty',1,NULL),(4,'reports',1,NULL),(4,'reports_advanced',1,NULL),
(4,'ai',1,NULL),(4,'api',1,NULL),(4,'print',1,NULL),(4,'multi_outlet',1,NULL),
(4,'limit_outlets',1,-1),(4,'limit_users',1,-1),(4,'limit_products',1,-1),(4,'limit_registers',1,-1),(4,'limit_tx_month',1,-1);

INSERT INTO roles (id, tenant_id, code, name, permissions, is_system) VALUES
(1,NULL,'super_admin','SaaS Super Admin','["*"]',1),
(2,NULL,'owner','Owner / Company Admin','["*"]',1),
(3,NULL,'manager','Outlet Manager','["pos.sell","pos.void","pos.refund","catalog.view","catalog.manage","inventory.view","inventory.manage","purchasing.manage","restaurant.manage","kitchen.view","crm.manage","reports.view","ai.view","settings.manage","users.manage"]',1),
(4,NULL,'cashier','Cashier','["pos.sell","catalog.view"]',1),
(5,NULL,'waiter','Waiter','["pos.sell","restaurant.manage"]',1),
(6,NULL,'kitchen','Kitchen Staff','["kitchen.view"]',1),
(7,NULL,'inventory','Inventory Staff','["inventory.view","inventory.manage","purchasing.manage","catalog.view"]',1),
(8,NULL,'finance','Finance','["reports.view","billing.view","pos.refund","audit.view"]',1),
(9,NULL,'auditor','Auditor','["reports.view","audit.view"]',1);

-- Demo tenant (Enterprise trial so every module can be explored)
INSERT INTO tenants (id, public_id, name, slug, domain, email, phone, address, currency, timezone, status, plan_id, trial_ends_at) VALUES
(1,'01JDEMOTENANT0000000000001','Toko Berkah Jaya','berkah-jaya',NULL,'owner@demo.id','021-5550100','Jl. Merdeka No. 88, Jakarta','IDR','Asia/Jakarta','active',4, DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 30 DAY));

INSERT INTO subscriptions (id, public_id, tenant_id, plan_id, status, billing_cycle, started_at, current_period_start, current_period_end) VALUES
(1,'01JSUBSCRIPTION00000000001',1,4,'active','monthly', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 1 MONTH));

INSERT INTO outlets (id, public_id, tenant_id, code, name, address, phone, timezone, tax_rate, service_charge_rate, receipt_footer) VALUES
(1,'01JOUTLETPUSAT000000000001',1,'OUT-01','Outlet Pusat - Jakarta','Jl. Merdeka No. 88, Jakarta','021-5550100','Asia/Jakarta',11.00,5.00,'Terima kasih telah berbelanja. Barang yang sudah dibeli dapat ditukar dalam 3 hari dengan nota.'),
(2,'01JOUTLETBANDUNG0000000002',1,'OUT-02','Outlet Bandung','Jl. Asia Afrika No. 12, Bandung','022-5550200','Asia/Jakarta',11.00,0.00,'Terima kasih telah berbelanja.');

INSERT INTO warehouses (tenant_id, outlet_id, code, name, is_default) VALUES
(1,1,'WH-01','Gudang Pusat',1),
(1,2,'WH-02','Gudang Bandung',1);

INSERT INTO registers (id, tenant_id, outlet_id, code, name, device_key) VALUES
(1,1,1,'REG-01','Kasir 1', REPEAT('a',64)),
(2,1,1,'REG-02','Kasir 2', REPEAT('b',64)),
(3,1,2,'REG-03','Kasir Bandung', REPEAT('c',64));

-- Password hash below = Demo#12345 (installer re-hashes to Argon2id)
INSERT INTO users (id, public_id, tenant_id, outlet_id, role_id, name, email, password_hash, status) VALUES
(1,'01JUSERSUPERADMIN000000001',NULL,NULL,1,'SaaS Administrator','admin@possaas.id','$2y$10$pa.h/X82CAOUFhKRaLRREuX/9zdORLMwfnSInTTliUqFxWkpBNC3.','active'),
(2,'01JUSEROWNER0000000000002',1,1,2,'Eddy Wijaya','owner@demo.id','$2y$10$Y6P2sFcKAdJQkB.ObmJCv.I7yLQNCGdHSbTrLZebC6iqjZjgbiiRC','active'),
(3,'01JUSERMANAGER00000000003',1,1,3,'Sari Manager','manager@demo.id','$2y$10$Y6P2sFcKAdJQkB.ObmJCv.I7yLQNCGdHSbTrLZebC6iqjZjgbiiRC','active'),
(4,'01JUSERKASIR0000000000004',1,1,4,'Budi Kasir','kasir@demo.id','$2y$10$Y6P2sFcKAdJQkB.ObmJCv.I7yLQNCGdHSbTrLZebC6iqjZjgbiiRC','active'),
(5,'01JUSERDAPUR0000000000005',1,1,6,'Dapur Staff','dapur@demo.id','$2y$10$Y6P2sFcKAdJQkB.ObmJCv.I7yLQNCGdHSbTrLZebC6iqjZjgbiiRC','active');

INSERT INTO categories (id, tenant_id, name, sort_order) VALUES
(1,1,'Kopi & Minuman',1),(2,1,'Makanan',2),(3,1,'Snack',3),(4,1,'Sembako',4),(5,1,'Perlengkapan',5),(6,1,'Bahan Baku',6);

INSERT INTO units (tenant_id, code, name) VALUES
(1,'pcs','Pcs'),(1,'btl','Botol'),(1,'kg','Kilogram'),(1,'porsi','Porsi'),(1,'cup','Cup');

INSERT INTO taxes (tenant_id, name, rate, is_inclusive) VALUES
(1,'PPN',11.00,0),(1,'Non Pajak',0,0);

-- is_sellable = 0 marks ingredients used by recipes (not shown on POS)
INSERT INTO products (id, public_id, tenant_id, category_id, sku, barcode, name, unit, cost, price, tax_rate, station, track_stock, is_sellable, reorder_point, reorder_qty) VALUES
(1,'01JPROD000000000000000001',1,1,'KP-001','8991001100011','Kopi Susu Gula Aren','cup',8000,22000,11,'bar',0,1,0,0),
(2,'01JPROD000000000000000002',1,1,'KP-002','8991001100028','Americano','cup',6000,20000,11,'bar',0,1,0,0),
(3,'01JPROD000000000000000003',1,1,'KP-003','8991001100035','Matcha Latte','cup',10000,26000,11,'bar',0,1,0,0),
(4,'01JPROD000000000000000004',1,1,'KP-004','8991001100042','Es Teh Manis','cup',2000,8000,11,'bar',0,1,0,0),
(5,'01JPROD000000000000000005',1,1,'KP-005','8991001100059','Air Mineral 600ml','btl',2500,5000,11,NULL,1,1,24,48),
(6,'01JPROD000000000000000006',1,2,'MK-001','8991001100066','Nasi Goreng Spesial','porsi',15000,35000,11,'kitchen',0,1,0,0),
(7,'01JPROD000000000000000007',1,2,'MK-002','8991001100073','Mie Goreng Jawa','porsi',13000,32000,11,'kitchen',0,1,0,0),
(8,'01JPROD000000000000000008',1,2,'MK-003','8991001100080','Ayam Geprek Sambal Ijo','porsi',14000,30000,11,'kitchen',0,1,0,0),
(9,'01JPROD000000000000000009',1,3,'SN-001','8991001100097','Kentang Goreng','porsi',7000,18000,11,'kitchen',0,1,0,0),
(10,'01JPROD000000000000000010',1,3,'SN-002','8991001100103','Roti Bakar Coklat','porsi',8000,20000,11,'kitchen',0,1,0,0),
(11,'01JPROD000000000000000011',1,4,'SM-001','8991001100110','Indomie Goreng','pcs',2900,3500,0,NULL,1,1,48,96),
(12,'01JPROD000000000000000012',1,4,'SM-002','8991001100127','Beras Premium 5kg','pcs',65000,72000,0,NULL,1,1,10,20),
(13,'01JPROD000000000000000013',1,4,'SM-003','8991001100134','Minyak Goreng 1L','pcs',17000,19000,0,NULL,1,1,24,48),
(14,'01JPROD000000000000000014',1,4,'SM-004','8991001100141','Gula Pasir 1kg','kg',14500,16000,0,NULL,1,1,20,40),
(15,'01JPROD000000000000000015',1,4,'SM-005','8991001100158','Telur Ayam 1kg','kg',25000,28000,0,NULL,1,1,15,30),
(16,'01JPROD000000000000000016',1,5,'PL-001','8991001100165','Sabun Mandi Batang','pcs',3500,4500,0,NULL,1,1,30,60),
(17,'01JPROD000000000000000017',1,5,'PL-002','8991001100172','Shampo Sachet','pcs',1000,1500,0,NULL,1,1,50,100),
(18,'01JPROD000000000000000018',1,5,'PL-003','8991001100189','Detergen Bubuk 1kg','pcs',21000,24000,0,NULL,1,1,12,24),
(19,'01JPROD000000000000000019',1,4,'SM-006','8991001100196','Kopi Bubuk 250g','pcs',38000,45000,0,NULL,1,1,8,16),
(20,'01JPROD000000000000000020',1,4,'SM-007','8991001100202','Susu UHT 1L','btl',18500,21000,0,NULL,1,1,12,24),
(21,'01JPROD000000000000000021',1,4,'SM-008','8991001100219','Teh Celup 25s','pcs',9500,12000,0,NULL,1,1,10,20),
(22,'01JPROD000000000000000022',1,5,'PL-004','8991001100226','Tisu 250 Sheet','pcs',15000,18000,0,NULL,1,1,15,30),
(23,'01JPROD000000000000000023',1,5,'PL-005','8991001100233','Baterai AA Isi 2','pcs',9000,12000,0,NULL,1,1,10,20),
(24,'01JPROD000000000000000024',1,3,'SN-003','8991001100240','Cokelat Batang 65g','pcs',11000,15000,0,NULL,1,1,20,40),
(25,'01JPROD000000000000000025',1,6,'BH-001',NULL,'Biji Kopi Arabika','kg',130000,150000,0,NULL,1,0,10,20),
(26,'01JPROD000000000000000026',1,6,'BH-002',NULL,'Susu Segar','btl',16000,18000,0,NULL,1,0,20,40),
(27,'01JPROD000000000000000027',1,6,'BH-003',NULL,'Gula Aren Cair','btl',22000,25000,0,NULL,1,0,8,16),
(28,'01JPROD000000000000000028',1,6,'BH-004',NULL,'Cup Plastik 16oz','pcs',900,1000,0,NULL,1,0,100,500),
(29,'01JPROD000000000000000029',1,6,'BH-005',NULL,'Ayam Fillet','kg',38000,42000,0,NULL,1,0,10,20),
(30,'01JPROD000000000000000030',1,6,'BH-006',NULL,'Beras Medium','kg',12000,13500,0,NULL,1,0,25,50);

INSERT INTO stock_balances (tenant_id, outlet_id, product_id, qty) VALUES
(1,1,5,120),(1,1,11,90),(1,1,12,24),(1,1,13,60),(1,1,14,45),(1,1,15,30),
(1,1,16,80),(1,1,17,150),(1,1,18,36),(1,1,19,20),(1,1,20,40),(1,1,21,25),
(1,1,22,40),(1,1,23,28),(1,1,24,50),
(1,1,25,18),(1,1,26,35),(1,1,27,14),(1,1,28,600),(1,1,29,22),(1,1,30,60),
(1,2,5,40),(1,2,11,30),(1,2,12,10),(1,2,13,20),(1,2,14,15),(1,2,15,10),(1,2,19,8),(1,2,20,12);

INSERT INTO suppliers (id, tenant_id, name, contact, phone, email, address, terms_days) VALUES
(1,1,'PT Sumber Pangan Nusantara','Andi','021-7771234','sales@sumberpangan.co.id','Kawasan Industri Pulogadung, Jakarta',14),
(2,1,'CV Bijian Kopi','Rina','0812-3456-7890','order@bijiankopi.id','Jl. Braga No. 5, Bandung',7);

INSERT INTO customers (id, public_id, tenant_id, name, phone, email, points, total_spent, tier) VALUES
(1,'01JCUST0000000000000000001',1,'Dewi Lestari','0812-1111-2222','dewi@example.com',120,1850000,'silver'),
(2,'01JCUST0000000000000000002',1,'Agus Pratama','0813-3333-4444','agus@example.com',45,620000,'basic'),
(3,'01JCUST0000000000000000003',1,'Rina Marlina','0815-5555-6666','rina@example.com',310,4200000,'gold'),
(4,'01JCUST0000000000000000004',1,'Hendra Gunawan','0817-7777-8888',NULL,15,210000,'basic'),
(5,'01JCUST0000000000000000005',1,'Siti Aminah','0819-9999-0000','siti@example.com',0,0,'basic');

INSERT INTO dining_tables (tenant_id, outlet_id, name, area, capacity) VALUES
(1,1,'T01','Indoor',4),(1,1,'T02','Indoor',4),(1,1,'T03','Indoor',2),
(1,1,'T04','Outdoor',6),(1,1,'T05','Outdoor',4),(1,1,'VIP-1','VIP',8);

INSERT INTO recipes (id, tenant_id, product_id, yield_qty, note) VALUES
(1,1,1,1,'1 cup kopi susu'),(2,1,2,1,'1 cup americano'),(3,1,6,1,'1 porsi nasi goreng');

INSERT INTO recipe_items (recipe_id, ingredient_id, qty) VALUES
(1,25,0.018),(1,26,0.15),(1,27,0.02),(1,28,1),
(2,25,0.018),(2,28,1),
(3,30,0.12),(3,29,0.09);

INSERT INTO settings (tenant_id, skey, svalue) VALUES
(1,'receipt_footer','Terima kasih telah berbelanja di Toko Berkah Jaya'),
(1,'loyalty_points_per_1000','1'),
(1,'negative_stock_policy','allow'),
(1,'ai_auto_reorder','1');
