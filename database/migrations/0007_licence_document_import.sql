-- ETHAN LM — création de licence depuis un document (PDF) : brouillons d'import, lignes de licence, origine tracée.

-- Brouillon d'import : document temporaire privé + résultat d'extraction + corrections, jusqu'à confirmation
CREATE TABLE licence_imports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token CHAR(32) NOT NULL UNIQUE,
    user_id INT UNSIGNED NOT NULL,
    status ENUM('draft','confirmed','abandoned') NOT NULL DEFAULT 'draft',
    stored_name VARCHAR(80) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime VARCHAR(100) NOT NULL,
    size INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    method ENUM('native','pdftotext','ocr','none') NOT NULL DEFAULT 'none',
    page_count SMALLINT UNSIGNED NULL,
    error_code VARCHAR(60) NULL,
    extraction JSON NULL,
    corrections JSON NULL,
    licence_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    KEY idx_li_user (user_id, status),
    KEY idx_li_sha (sha256),
    KEY idx_li_exp (status, expires_at),
    CONSTRAINT fk_li_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Origine de la licence (saisie manuelle ou import de document)
ALTER TABLE import_licences
    ADD COLUMN source ENUM('manual','document') NOT NULL DEFAULT 'manual' AFTER status,
    ADD COLUMN import_id INT UNSIGNED NULL AFTER source,
    ADD CONSTRAINT fk_lic_import FOREIGN KEY (import_id) REFERENCES licence_imports(id);

ALTER TABLE licence_imports ADD CONSTRAINT fk_li_licence FOREIGN KEY (licence_id) REFERENCES import_licences(id);

-- Marchandises couvertes par la licence (lues sur le document ou saisies), rapprochables des lignes du PO fournisseur
CREATE TABLE import_licence_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    licence_id INT UNSIGNED NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    description VARCHAR(255) NOT NULL,
    hs_code VARCHAR(20) NULL,
    quantity DECIMAL(18,3) NULL,
    unit VARCHAR(20) NULL,
    unit_price DECIMAL(18,6) NULL,
    amount DECIMAL(18,2) NULL,
    po_line_id INT UNSIGNED NULL,
    CONSTRAINT fk_ill_lic FOREIGN KEY (licence_id) REFERENCES import_licences(id) ON DELETE CASCADE,
    CONSTRAINT fk_ill_pol FOREIGN KEY (po_line_id) REFERENCES purchase_order_lines(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
