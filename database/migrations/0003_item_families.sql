-- ETHAN LM — familles d'articles : la liste figée (ENUM) devient un référentiel administrable.
-- Les articles existants conservent leur famille (reprise reagent → REA, fuel → CAR, other → AUT).

CREATE TABLE item_families (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    color ENUM('blue','green','orange','red','purple','teal','gray') NOT NULL DEFAULT 'gray',
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO item_families (code, name, description, color, sort_order) VALUES
    ('REA', 'Réactifs', 'Acide sulfurique, soufre, chaux…', 'purple', 10),
    ('CAR', 'Carburants', 'Essence, gasoil, mazout…', 'orange', 20),
    ('AUT', 'Autres', NULL, 'gray', 90);

ALTER TABLE items ADD COLUMN family_id INT UNSIGNED NULL AFTER category_id;

UPDATE items i JOIN item_families f
    ON f.code = CASE i.family WHEN 'reagent' THEN 'REA' WHEN 'fuel' THEN 'CAR' ELSE 'AUT' END
SET i.family_id = f.id;

ALTER TABLE items
    MODIFY family_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_item_family FOREIGN KEY (family_id) REFERENCES item_families(id),
    DROP COLUMN family;
