-- ETHAN LM — lot 2 : PO clients, sourcing, PO fournisseurs, livraisons directes, factures et paiements.
-- Processus « achat local » : le fournisseur livre directement le site du client (pas de passage en stock).

ALTER TABLE users MODIFY role ENUM('admin','manager','sales','buyer','storekeeper','accountant','viewer') NOT NULL DEFAULT 'viewer';

ALTER TABLE items ADD COLUMN family ENUM('reagent','fuel','other') NOT NULL DEFAULT 'other' AFTER category_id;

CREATE TABLE customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(190) NOT NULL,
    rccm VARCHAR(60) NULL,
    id_nat VARCHAR(60) NULL,
    nif VARCHAR(60) NULL,
    address VARCHAR(255) NULL,
    city VARCHAR(120) NULL,
    country VARCHAR(80) NULL,
    contact_name VARCHAR(120) NULL,
    phone VARCHAR(60) NULL,
    email VARCHAR(190) NULL,
    payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    notes TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cust_cur FOREIGN KEY (currency) REFERENCES currencies(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE suppliers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(190) NOT NULL,
    rccm VARCHAR(60) NULL,
    id_nat VARCHAR(60) NULL,
    nif VARCHAR(60) NULL,
    address VARCHAR(255) NULL,
    city VARCHAR(120) NULL,
    country VARCHAR(80) NULL,
    contact_name VARCHAR(120) NULL,
    phone VARCHAR(60) NULL,
    email VARCHAR(190) NULL,
    payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    notes TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_supp_cur FOREIGN KEY (currency) REFERENCES currencies(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lieux de livraison d'un client (mine, usine, dépôt…)
CREATE TABLE delivery_sites (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    address VARCHAR(255) NULL,
    city VARCHAR(120) NULL,
    contact_name VARCHAR(120) NULL,
    phone VARCHAR(60) NULL,
    gps VARCHAR(60) NULL,
    instructions VARCHAR(500) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_ds_cust FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PO client : dossier pivot de toute la traçabilité
CREATE TABLE customer_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(30) NOT NULL UNIQUE,
    customer_id INT UNSIGNED NOT NULL,
    customer_po_number VARCHAR(80) NOT NULL,
    po_date DATE NOT NULL,
    site_id INT UNSIGNED NOT NULL,
    fulfillment_mode ENUM('local_purchase','import','stock') NOT NULL DEFAULT 'local_purchase',
    currency CHAR(3) NOT NULL,
    delivery_site_id INT UNSIGNED NULL,
    required_date DATE NULL,
    payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    status ENUM('received','sourcing','ordered','in_delivery','delivered','closed','cancelled') NOT NULL DEFAULT 'received',
    notes TEXT NULL,
    closing_note VARCHAR(500) NULL,
    closed_at DATETIME NULL,
    closed_by INT UNSIGNED NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cust_po (customer_id, customer_po_number),
    KEY idx_co_status (status),
    CONSTRAINT fk_co_cust FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_co_site FOREIGN KEY (site_id) REFERENCES sites(id),
    CONSTRAINT fk_co_cur FOREIGN KEY (currency) REFERENCES currencies(code),
    CONSTRAINT fk_co_ds FOREIGN KEY (delivery_site_id) REFERENCES delivery_sites(id),
    CONSTRAINT fk_co_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_order_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    specification VARCHAR(255) NULL,
    quantity DECIMAL(18,3) NOT NULL,
    unit_price DECIMAL(18,6) NOT NULL,
    CONSTRAINT fk_col_order FOREIGN KEY (order_id) REFERENCES customer_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_col_item FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sourcing : devis fournisseurs comparés pour un PO client
CREATE TABLE supplier_quotes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    quote_ref VARCHAR(80) NULL,
    quote_date DATE NOT NULL,
    currency CHAR(3) NOT NULL,
    lead_time_days SMALLINT UNSIGNED NULL,
    valid_until DATE NULL,
    notes VARCHAR(500) NULL,
    status ENUM('pending','selected','rejected') NOT NULL DEFAULT 'pending',
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sq_order FOREIGN KEY (order_id) REFERENCES customer_orders(id),
    CONSTRAINT fk_sq_supp FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT fk_sq_cur FOREIGN KEY (currency) REFERENCES currencies(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_quote_lines (
    quote_id INT UNSIGNED NOT NULL,
    order_line_id INT UNSIGNED NOT NULL,
    unit_price DECIMAL(18,6) NOT NULL,
    PRIMARY KEY (quote_id, order_line_id),
    CONSTRAINT fk_sql_quote FOREIGN KEY (quote_id) REFERENCES supplier_quotes(id) ON DELETE CASCADE,
    CONSTRAINT fk_sql_line FOREIGN KEY (order_line_id) REFERENCES customer_order_lines(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PO fournisseur, rattaché à un PO client ; lieu de livraison figé au moment de l'émission
CREATE TABLE purchase_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(30) NOT NULL UNIQUE,
    order_id INT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    quote_id INT UNSIGNED NULL,
    currency CHAR(3) NOT NULL,
    order_date DATE NOT NULL,
    expected_date DATE NULL,
    delivery_site_id INT UNSIGNED NULL,
    delivery_address VARCHAR(600) NOT NULL,
    delivery_contact VARCHAR(200) NULL,
    payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    status ENUM('draft','sent','partial','delivered','cancelled') NOT NULL DEFAULT 'draft',
    notes TEXT NULL,
    sent_at DATETIME NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_po_status (status),
    CONSTRAINT fk_po_order FOREIGN KEY (order_id) REFERENCES customer_orders(id),
    CONSTRAINT fk_po_supp FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT fk_po_quote FOREIGN KEY (quote_id) REFERENCES supplier_quotes(id),
    CONSTRAINT fk_po_cur FOREIGN KEY (currency) REFERENCES currencies(code),
    CONSTRAINT fk_po_ds FOREIGN KEY (delivery_site_id) REFERENCES delivery_sites(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_order_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    po_id INT UNSIGNED NOT NULL,
    order_line_id INT UNSIGNED NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(18,3) NOT NULL,
    unit_price DECIMAL(18,6) NOT NULL,
    CONSTRAINT fk_pol_po FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_pol_col FOREIGN KEY (order_line_id) REFERENCES customer_order_lines(id),
    CONSTRAINT fk_pol_item FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Expédition fournisseur → site client. Quantité chargée vs quantité réceptionnée (écart visible, ex. carburant).
CREATE TABLE deliveries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(30) NOT NULL UNIQUE,
    order_id INT UNSIGNED NOT NULL,
    po_id INT UNSIGNED NOT NULL,
    status ENUM('in_transit','delivered','cancelled') NOT NULL DEFAULT 'in_transit',
    shipped_at DATE NOT NULL,
    delivered_at DATE NULL,
    transporter VARCHAR(160) NULL,
    vehicle_plate VARCHAR(60) NULL,
    trailer_plate VARCHAR(60) NULL,
    driver_name VARCHAR(120) NULL,
    driver_phone VARCHAR(60) NULL,
    supplier_waybill VARCHAR(80) NULL,
    received_by VARCHAR(160) NULL,
    notes VARCHAR(500) NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_dl_status (status),
    CONSTRAINT fk_dl_order FOREIGN KEY (order_id) REFERENCES customer_orders(id),
    CONSTRAINT fk_dl_po FOREIGN KEY (po_id) REFERENCES purchase_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE delivery_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_id INT UNSIGNED NOT NULL,
    po_line_id INT UNSIGNED NOT NULL,
    quantity_shipped DECIMAL(18,3) NOT NULL,
    quantity_received DECIMAL(18,3) NULL,
    CONSTRAINT fk_dll_dl FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
    CONSTRAINT fk_dll_pol FOREIGN KEY (po_line_id) REFERENCES purchase_order_lines(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Factures : payable = fournisseur, receivable = client
CREATE TABLE invoices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(30) NOT NULL UNIQUE,
    direction ENUM('payable','receivable') NOT NULL,
    order_id INT UNSIGNED NOT NULL,
    po_id INT UNSIGNED NULL,
    customer_id INT UNSIGNED NULL,
    supplier_id INT UNSIGNED NULL,
    external_number VARCHAR(80) NULL,
    invoice_date DATE NOT NULL,
    due_date DATE NOT NULL,
    currency CHAR(3) NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    status ENUM('open','paid','cancelled') NOT NULL DEFAULT 'open',
    notes VARCHAR(500) NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_inv_dir (direction, status, due_date),
    CONSTRAINT fk_inv_order FOREIGN KEY (order_id) REFERENCES customer_orders(id),
    CONSTRAINT fk_inv_po FOREIGN KEY (po_id) REFERENCES purchase_orders(id),
    CONSTRAINT fk_inv_cust FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_inv_supp FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT fk_inv_cur FOREIGN KEY (currency) REFERENCES currencies(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    paid_at DATE NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    method ENUM('transfer','cash','cheque','mobile','other') NOT NULL DEFAULT 'transfer',
    bank_ref VARCHAR(120) NULL,
    notes VARCHAR(500) NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pay_inv FOREIGN KEY (invoice_id) REFERENCES invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pièces jointes (scan PO client, BL signé, factures…) stockées hors racine web
CREATE TABLE attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    entity VARCHAR(30) NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    label VARCHAR(160) NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(80) NOT NULL,
    mime VARCHAR(100) NOT NULL,
    size INT UNSIGNED NOT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_att_entity (entity, entity_id),
    CONSTRAINT fk_att_order FOREIGN KEY (order_id) REFERENCES customer_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chronologie métier lisible du dossier (complète le journal technique activity_log)
CREATE TABLE order_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    event VARCHAR(40) NOT NULL,
    detail VARCHAR(500) NULL,
    user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_oe_order (order_id, id),
    CONSTRAINT fk_oe_order FOREIGN KEY (order_id) REFERENCES customer_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
