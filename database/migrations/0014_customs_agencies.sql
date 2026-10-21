-- Référentiel des agences en douane / transitaires (auparavant : fournisseurs marqués « transitaire »).
CREATE TABLE customs_agencies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(190) NOT NULL,
    approval_number VARCHAR(60) NULL,
    nif VARCHAR(60) NULL,
    rccm VARCHAR(60) NULL,
    address VARCHAR(255) NULL,
    city VARCHAR(120) NULL,
    country VARCHAR(80) NULL,
    contact_name VARCHAR(120) NULL,
    phone VARCHAR(60) NULL,
    email VARCHAR(190) NULL,
    customs_posts VARCHAR(255) NULL,
    notes VARCHAR(500) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_agency_code (code),
    UNIQUE KEY uq_agency_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Les agences désignées sur les expéditions, les PO fournisseurs et les comptes du portail pointent désormais vers ce référentiel
ALTER TABLE deliveries DROP FOREIGN KEY fk_dl_forwarder;
ALTER TABLE deliveries ADD CONSTRAINT fk_dl_agency FOREIGN KEY (forwarder_id) REFERENCES customs_agencies(id);
ALTER TABLE purchase_orders DROP FOREIGN KEY fk_po_forwarder;
ALTER TABLE purchase_orders ADD CONSTRAINT fk_po_agency FOREIGN KEY (forwarder_id) REFERENCES customs_agencies(id);
ALTER TABLE users DROP FOREIGN KEY fk_user_forwarder;
ALTER TABLE users ADD CONSTRAINT fk_user_agency FOREIGN KEY (forwarder_id) REFERENCES customs_agencies(id);
