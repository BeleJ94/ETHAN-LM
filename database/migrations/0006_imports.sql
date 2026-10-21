-- ETHAN LM — processus « importation » : licences (1 licence = 1 PO fournisseur), inspections BIVAC/OCC,
-- décisions AV (Attestation de Vérification) / ARA (Avis de Refus d'Attestation), régularisation.

-- Étapes propres à l'import dans le statut du PO client
ALTER TABLE customer_orders
    MODIFY status ENUM('received','sourcing','awaiting_licence','inspection','blocked','ordered','in_delivery','delivered','closed','cancelled')
    NOT NULL DEFAULT 'received';

-- Répertoire des licences d'importation
CREATE TABLE import_licences (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(60) NOT NULL UNIQUE,
    po_id INT UNSIGNED NOT NULL UNIQUE,
    order_id INT UNSIGNED NOT NULL,
    issued_at DATE NOT NULL,
    expires_at DATE NOT NULL,
    origin_country VARCHAR(80) NULL,
    currency CHAR(3) NOT NULL,
    amount DECIMAL(18,2) NULL,
    bank VARCHAR(120) NULL,
    notes VARCHAR(500) NULL,
    status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
    attachment_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_lic_exp (expires_at),
    CONSTRAINT fk_lic_po FOREIGN KEY (po_id) REFERENCES purchase_orders(id),
    CONSTRAINT fk_lic_order FOREIGN KEY (order_id) REFERENCES customer_orders(id),
    CONSTRAINT fk_lic_cur FOREIGN KEY (currency) REFERENCES currencies(code),
    CONSTRAINT fk_lic_att FOREIGN KEY (attachment_id) REFERENCES attachments(id),
    CONSTRAINT fk_lic_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inspections (partielles possibles) et leur rapport ; une AV peut régulariser un ARA
CREATE TABLE inspections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(30) NOT NULL UNIQUE,
    po_id INT UNSIGNED NOT NULL,
    licence_id INT UNSIGNED NOT NULL,
    inspector VARCHAR(120) NOT NULL,
    inspection_date DATE NOT NULL,
    location VARCHAR(160) NULL,
    report_number VARCHAR(80) NULL,
    report_date DATE NULL,
    decision ENUM('pending','av','ara') NOT NULL DEFAULT 'pending',
    decision_number VARCHAR(80) NULL,
    decision_date DATE NULL,
    reason VARCHAR(500) NULL,
    regularizes_id INT UNSIGNED NULL,
    attachment_id INT UNSIGNED NULL,
    notes VARCHAR(500) NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_insp_po (po_id, decision),
    CONSTRAINT fk_insp_po FOREIGN KEY (po_id) REFERENCES purchase_orders(id),
    CONSTRAINT fk_insp_lic FOREIGN KEY (licence_id) REFERENCES import_licences(id),
    CONSTRAINT fk_insp_reg FOREIGN KEY (regularizes_id) REFERENCES inspections(id),
    CONSTRAINT fk_insp_att FOREIGN KEY (attachment_id) REFERENCES attachments(id),
    CONSTRAINT fk_insp_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inspection_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspection_id INT UNSIGNED NOT NULL,
    po_line_id INT UNSIGNED NOT NULL,
    quantity_inspected DECIMAL(18,3) NOT NULL,
    quantity_conform DECIMAL(18,3) NOT NULL,
    UNIQUE KEY uq_il (inspection_id, po_line_id),
    CONSTRAINT fk_il_insp FOREIGN KEY (inspection_id) REFERENCES inspections(id) ON DELETE CASCADE,
    CONSTRAINT fk_il_pol FOREIGN KEY (po_line_id) REFERENCES purchase_order_lines(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
