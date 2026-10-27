-- Paramètres de la société utilisatrice (en-tête des documents imprimés, logo).
-- Une seule ligne (id = 1). Les variables COMPANY_* du .env ne servent plus que de valeurs de repli.
-- Le logo est conservé en base : il survit aux déploiements et à la réinitialisation des données.
CREATE TABLE company_settings (
    id TINYINT UNSIGNED PRIMARY KEY,
    name VARCHAR(190) NULL,
    legal_form VARCHAR(60) NULL,
    address VARCHAR(255) NULL,
    city VARCHAR(120) NULL,
    country VARCHAR(80) NULL,
    phone VARCHAR(60) NULL,
    email VARCHAR(190) NULL,
    website VARCHAR(190) NULL,
    rccm VARCHAR(60) NULL,
    id_nat VARCHAR(60) NULL,
    nif VARCHAR(60) NULL,
    vat_number VARCHAR(60) NULL,
    importer_number VARCHAR(60) NULL,
    share_capital VARCHAR(60) NULL,
    logo MEDIUMBLOB NULL,
    logo_mime VARCHAR(40) NULL,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NULL,
    CONSTRAINT fk_company_user FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
