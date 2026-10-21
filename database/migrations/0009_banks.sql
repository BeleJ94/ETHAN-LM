-- Référentiel des banques et de leurs agences (branches), utilisé par les licences d'importation.
CREATE TABLE banks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(160) NOT NULL,
    short_name VARCHAR(40) NULL,
    swift VARCHAR(11) NULL,
    country VARCHAR(80) NULL,
    phone VARCHAR(60) NULL,
    email VARCHAR(190) NULL,
    website VARCHAR(190) NULL,
    notes VARCHAR(500) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bank_code (code),
    UNIQUE KEY uq_bank_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bank_branches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bank_id INT UNSIGNED NOT NULL,
    code VARCHAR(20) NULL,
    name VARCHAR(160) NOT NULL,
    city VARCHAR(120) NULL,
    address VARCHAR(255) NULL,
    phone VARCHAR(60) NULL,
    email VARCHAR(190) NULL,
    contact_name VARCHAR(120) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_branch_name (bank_id, name),
    UNIQUE KEY uq_branch_code (bank_id, code),
    CONSTRAINT fk_branch_bank FOREIGN KEY (bank_id) REFERENCES banks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Licences : banque / agence domiciliataires du référentiel (le texte « bank » reste l'affichage et l'historique)
ALTER TABLE import_licences
    ADD COLUMN bank_id INT UNSIGNED NULL AFTER bank,
    ADD COLUMN branch_id INT UNSIGNED NULL AFTER bank_id,
    ADD CONSTRAINT fk_lic_bank FOREIGN KEY (bank_id) REFERENCES banks(id),
    ADD CONSTRAINT fk_lic_branch FOREIGN KEY (branch_id) REFERENCES bank_branches(id);
