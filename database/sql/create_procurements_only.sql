-- Target database: dujiaoka_gptplus (MySQL).
-- Creates only the three procurement feature tables; never alters existing tables.
-- Safe to run again: existing tables and their data are left as-is.
-- goods/orders/carmis retain their original schema. No foreign keys are added to them.
CREATE TABLE IF NOT EXISTS `procurement_goods` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `goods_id` INT NOT NULL,
  `procurement_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `procurement_paused` TINYINT(1) NOT NULL DEFAULT 0,
  `supplier_code` VARCHAR(190) NULL,
  `supplier_race` VARCHAR(190) NULL,
  `supplier_sku` TEXT NULL,
  `procurement_max_cost` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `supplier_stock` INT UNSIGNED NULL,
  `supplier_stock_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `procurement_goods_goods_id_unique` (`goods_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `procurement_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT NOT NULL,
  `paid_at` TIMESTAMP NULL DEFAULT NULL,
  `procurement_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `procurement_config` TEXT NULL,
  `carmi_id` BIGINT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `procurement_orders_order_id_unique` (`order_id`),
  UNIQUE KEY `procurement_orders_carmi_id_unique` (`carmi_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `procurements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT NOT NULL,
  `request_no` VARCHAR(16) NULL,
  `supplier_order_no` VARCHAR(190) NULL,
  `state` VARCHAR(24) NOT NULL DEFAULT 'pending',
  `amount` DECIMAL(12,2) NULL,
  `cost_exceeded` TINYINT(1) NOT NULL DEFAULT 0,
  `secret` TEXT NULL,
  `error_code` VARCHAR(100) NULL,
  `submitted_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `procurements_order_id_unique` (`order_id`),
  UNIQUE KEY `procurements_request_no_unique` (`request_no`),
  UNIQUE KEY `procurements_supplier_order_no_unique` (`supplier_order_no`),
  KEY `procurements_state_index` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
