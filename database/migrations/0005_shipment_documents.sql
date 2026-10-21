-- ETHAN LM — check-list documentaire des expéditions.
-- Référentiel de types de documents + règles (mode d'exécution × famille × transport) → check-list figée par expédition / dossier.
-- Contrôle à deux niveaux (déposé → validé par une autre personne), dérogation tracée, accès transitaire.

-- Transitaires : fournisseurs de service signalés comme tels, comptes utilisateurs rattachés
ALTER TABLE suppliers ADD COLUMN is_forwarder TINYINT(1) NOT NULL DEFAULT 0 AFTER currency;
ALTER TABLE users
    MODIFY role ENUM('admin','manager','sales','buyer','storekeeper','accountant','viewer','forwarder') NOT NULL DEFAULT 'viewer',
    ADD COLUMN forwarder_id INT UNSIGNED NULL AFTER role,
    ADD CONSTRAINT fk_user_forwarder FOREIGN KEY (forwarder_id) REFERENCES suppliers(id);

-- Mode de transport et transitaire (PO = valeur par défaut des expéditions)
ALTER TABLE purchase_orders
    ADD COLUMN transport_mode ENUM('road','rail','sea','air','multimodal') NOT NULL DEFAULT 'road' AFTER delivery_contact,
    ADD COLUMN forwarder_id INT UNSIGNED NULL AFTER transport_mode,
    ADD CONSTRAINT fk_po_forwarder FOREIGN KEY (forwarder_id) REFERENCES suppliers(id);

-- Expédition : statut « en préparation » avant le départ
ALTER TABLE deliveries
    MODIFY status ENUM('planned','in_transit','delivered','cancelled') NOT NULL DEFAULT 'planned',
    ADD COLUMN transport_mode ENUM('road','rail','sea','air','multimodal') NOT NULL DEFAULT 'road' AFTER status,
    ADD COLUMN forwarder_id INT UNSIGNED NULL AFTER transport_mode,
    ADD COLUMN departed_at DATETIME NULL AFTER shipped_at,
    ADD CONSTRAINT fk_dl_forwarder FOREIGN KEY (forwarder_id) REFERENCES suppliers(id);

CREATE TABLE document_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL,
    description VARCHAR(255) NULL,
    provider ENUM('supplier','forwarder','customs','customer','internal') NOT NULL DEFAULT 'supplier',
    level ENUM('order','delivery') NOT NULL DEFAULT 'delivery',
    requires_number TINYINT(1) NOT NULL DEFAULT 1,
    requires_file TINYINT(1) NOT NULL DEFAULT 1,
    has_expiry TINYINT(1) NOT NULL DEFAULT 0,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Critère NULL = toutes les valeurs. Les règles se cumulent.
CREATE TABLE document_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_type_id INT UNSIGNED NOT NULL,
    fulfillment_mode ENUM('local_purchase','import','stock') NULL,
    family_id INT UNSIGNED NULL,
    transport_mode ENUM('road','rail','sea','air','multimodal') NULL,
    required TINYINT(1) NOT NULL DEFAULT 1,
    blocking_stage ENUM('departure','reception','invoicing','closure') NULL,
    notes VARCHAR(255) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rule_type FOREIGN KEY (document_type_id) REFERENCES document_types(id),
    CONSTRAINT fk_rule_family FOREIGN KEY (family_id) REFERENCES item_families(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Check-list figée : delivery_id NULL = document de niveau dossier (licence, assurance, FERI…)
CREATE TABLE shipment_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    delivery_id INT UNSIGNED NULL,
    delivery_key INT UNSIGNED AS (COALESCE(delivery_id, 0)) PERSISTENT,
    document_type_id INT UNSIGNED NOT NULL,
    required TINYINT(1) NOT NULL DEFAULT 1,
    blocking_stage ENUM('departure','reception','invoicing','closure') NULL,
    status ENUM('pending','submitted','validated','rejected','waived','not_applicable') NOT NULL DEFAULT 'pending',
    number VARCHAR(80) NULL,
    issued_at DATE NULL,
    expires_at DATE NULL,
    attachment_id INT UNSIGNED NULL,
    submitted_by INT UNSIGNED NULL,
    submitted_at DATETIME NULL,
    decided_by INT UNSIGNED NULL,
    decided_at DATETIME NULL,
    decision_note VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sd (order_id, delivery_key, document_type_id),
    KEY idx_sd_status (status),
    CONSTRAINT fk_sd_order FOREIGN KEY (order_id) REFERENCES customer_orders(id),
    CONSTRAINT fk_sd_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id),
    CONSTRAINT fk_sd_type FOREIGN KEY (document_type_id) REFERENCES document_types(id),
    CONSTRAINT fk_sd_att FOREIGN KEY (attachment_id) REFERENCES attachments(id),
    CONSTRAINT fk_sd_sub FOREIGN KEY (submitted_by) REFERENCES users(id),
    CONSTRAINT fk_sd_dec FOREIGN KEY (decided_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Référentiel de départ (validé avec le client)
INSERT INTO document_types (code, name, provider, level, requires_number, requires_file, has_expiry, sort_order) VALUES
    ('LIC', 'Licence d''importation', 'internal', 'order', 1, 1, 1, 10),
    ('ASS', 'Assurance (police / certificat)', 'internal', 'order', 1, 1, 1, 20),
    ('FERI', 'FERI — Fiche électronique de renseignement à l''importation', 'forwarder', 'order', 1, 1, 0, 30),
    ('FAC-COM', 'Facture commerciale', 'supplier', 'order', 1, 1, 0, 40),
    ('PL', 'Packing list', 'supplier', 'delivery', 0, 1, 0, 50),
    ('CO', 'Certificat d''origine', 'supplier', 'order', 1, 1, 0, 60),
    ('OCC', 'Certificat OCC', 'forwarder', 'delivery', 1, 1, 0, 70),
    ('LV', 'Connaissement / lettre de voiture', 'forwarder', 'delivery', 1, 1, 0, 80),
    ('IM4', 'Déclaration IM4', 'forwarder', 'delivery', 1, 1, 0, 90),
    ('LIQ', 'Note de liquidation', 'customs', 'delivery', 1, 1, 0, 100),
    ('QUIT', 'Quittance', 'customs', 'delivery', 1, 1, 0, 110),
    ('BL-FRS', 'Bon de livraison fournisseur', 'supplier', 'delivery', 1, 1, 0, 120),
    ('CA', 'Certificat d''analyse', 'supplier', 'delivery', 1, 1, 0, 130),
    ('FDS', 'Fiche de données de sécurité (FDS)', 'supplier', 'order', 0, 1, 0, 140),
    ('BC-DEP', 'Bon de chargement dépôt', 'supplier', 'delivery', 1, 1, 0, 150),
    ('CQ', 'Certificat de qualité / densité', 'supplier', 'delivery', 1, 1, 0, 160),
    ('SCEL', 'Relevé des scellés', 'internal', 'delivery', 1, 0, 0, 170),
    ('BL-SIG', 'Bon de livraison signé par le client', 'customer', 'delivery', 0, 1, 0, 180);

INSERT INTO document_rules (document_type_id, fulfillment_mode, family_id, transport_mode, required, blocking_stage)
SELECT t.id, r.mode, f.id, r.transport, r.required, r.stage
FROM (
    SELECT 'LIC' code, 'import' mode, NULL fam, NULL transport, 1 required, 'departure' stage UNION ALL
    SELECT 'ASS', 'import', NULL, NULL, 1, 'departure' UNION ALL
    SELECT 'FERI', 'import', NULL, NULL, 1, 'departure' UNION ALL
    SELECT 'FAC-COM', 'import', NULL, NULL, 1, 'departure' UNION ALL
    SELECT 'PL', 'import', NULL, NULL, 1, 'departure' UNION ALL
    SELECT 'CO', 'import', NULL, NULL, 1, 'reception' UNION ALL
    SELECT 'OCC', 'import', NULL, NULL, 1, 'reception' UNION ALL
    SELECT 'LV', 'import', NULL, NULL, 1, 'departure' UNION ALL
    SELECT 'IM4', 'import', NULL, NULL, 1, 'reception' UNION ALL
    SELECT 'LIQ', 'import', NULL, NULL, 1, 'reception' UNION ALL
    SELECT 'QUIT', 'import', NULL, NULL, 1, 'reception' UNION ALL
    SELECT 'BL-FRS', 'local_purchase', NULL, NULL, 1, 'reception' UNION ALL
    SELECT 'CA', NULL, 'REA', NULL, 1, 'reception' UNION ALL
    SELECT 'FDS', NULL, 'REA', NULL, 1, 'departure' UNION ALL
    SELECT 'BC-DEP', NULL, 'CAR', 'road', 1, 'departure' UNION ALL
    SELECT 'CQ', NULL, 'CAR', NULL, 1, 'reception' UNION ALL
    SELECT 'SCEL', NULL, 'CAR', 'road', 1, 'departure' UNION ALL
    SELECT 'BL-SIG', NULL, NULL, NULL, 1, 'invoicing'
) r
JOIN document_types t ON t.code = r.code
LEFT JOIN item_families f ON f.code = r.fam;
