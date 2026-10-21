-- ETHAN LM — schéma initial : socle (sites, utilisateurs, devises, journal) + stock

CREATE TABLE sites (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    city VARCHAR(120) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE warehouses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wh_site FOREIGN KEY (site_id) REFERENCES sites(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','manager','storekeeper','buyer','viewer') NOT NULL DEFAULT 'viewer',
    locale CHAR(2) NOT NULL DEFAULT 'fr',
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_sites (
    user_id INT UNSIGNED NOT NULL,
    site_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, site_id),
    CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_us_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE currencies (
    code CHAR(3) PRIMARY KEY,
    name VARCHAR(60) NOT NULL,
    decimals TINYINT UNSIGNED NOT NULL DEFAULT 2
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Taux : 1 unité de la devise = rate unités de la devise de base, à partir de valid_from
CREATE TABLE exchange_rates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    currency_code CHAR(3) NOT NULL,
    rate DECIMAL(18,8) NOT NULL,
    valid_from DATE NOT NULL,
    UNIQUE KEY uq_rate (currency_code, valid_from),
    CONSTRAINT fk_rate_cur FOREIGN KEY (currency_code) REFERENCES currencies(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE units (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(10) NOT NULL UNIQUE,
    label VARCHAR(60) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Article : avg_cost = CMUP global, exprimé dans la devise de base
CREATE TABLE items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(40) NOT NULL UNIQUE,
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    category_id INT UNSIGNED NULL,
    unit_id INT UNSIGNED NOT NULL,
    hs_code VARCHAR(20) NULL,
    min_stock DECIMAL(18,3) NOT NULL DEFAULT 0,
    avg_cost DECIMAL(18,6) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_item_cat FOREIGN KEY (category_id) REFERENCES categories(id),
    CONSTRAINT fk_item_unit FOREIGN KEY (unit_id) REFERENCES units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_levels (
    item_id INT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(18,3) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (item_id, warehouse_id),
    CONSTRAINT fk_sl_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_sl_wh FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal de stock immuable. Un transfert = 2 lignes (sortie + entrée) liées par transfer_ref.
-- source_type/source_id relieront plus tard les mouvements aux PO fournisseurs, PO clients et dossiers d'import.
CREATE TABLE stock_movements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(30) NOT NULL,
    type ENUM('in','out','transfer_out','transfer_in','adjust_in','adjust_out') NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(18,3) NOT NULL,
    unit_cost DECIMAL(18,6) NULL,
    currency_code CHAR(3) NULL,
    exchange_rate DECIMAL(18,8) NULL,
    unit_cost_base DECIMAL(18,6) NOT NULL,
    balance_after DECIMAL(18,3) NOT NULL,
    avg_cost_after DECIMAL(18,6) NOT NULL,
    transfer_ref VARCHAR(30) NULL,
    source_type VARCHAR(30) NULL,
    source_id INT UNSIGNED NULL,
    note VARCHAR(500) NULL,
    user_id INT UNSIGNED NOT NULL,
    moved_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mv_item (item_id, moved_at),
    KEY idx_mv_wh (warehouse_id, moved_at),
    KEY idx_mv_ref (reference),
    CONSTRAINT fk_mv_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_mv_wh FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_mv_cur FOREIGN KEY (currency_code) REFERENCES currencies(code),
    CONSTRAINT fk_mv_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Compteurs de numérotation (MV-2026-000001…)
CREATE TABLE sequences (
    name VARCHAR(40) PRIMARY KEY,
    value INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE activity_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    entity VARCHAR(40) NOT NULL,
    entity_id INT UNSIGNED NULL,
    action VARCHAR(40) NOT NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_log_entity (entity, entity_id),
    KEY idx_log_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO currencies (code, name, decimals) VALUES
    ('USD', 'Dollar américain', 2),
    ('EUR', 'Euro', 2),
    ('CDF', 'Franc congolais', 2),
    ('CNY', 'Yuan renminbi', 2),
    ('ZAR', 'Rand sud-africain', 2),
    ('AED', 'Dirham des EAU', 2);

INSERT INTO exchange_rates (currency_code, rate, valid_from) VALUES ('USD', 1, '2000-01-01');

INSERT INTO units (code, label) VALUES
    ('PCE', 'Pièce'), ('KG', 'Kilogramme'), ('T', 'Tonne'), ('L', 'Litre'),
    ('M', 'Mètre'), ('M3', 'Mètre cube'), ('CTN', 'Carton'), ('PAL', 'Palette'), ('SAC', 'Sac');
