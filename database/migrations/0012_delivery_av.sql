-- Traçabilité import : chaque ligne d'expédition est couverte par une ou plusieurs AV (quantité prélevée sur chacune).
-- Une expédition annulée rend ses quantités aux AV (les lignes restent, le statut de l'expédition les exclut des soldes).
CREATE TABLE delivery_line_avs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_line_id INT UNSIGNED NOT NULL,
    inspection_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(18,3) NOT NULL,
    UNIQUE KEY uq_dla (delivery_line_id, inspection_id),
    KEY ix_dla_insp (inspection_id),
    CONSTRAINT fk_dla_line FOREIGN KEY (delivery_line_id) REFERENCES delivery_lines(id) ON DELETE CASCADE,
    CONSTRAINT fk_dla_insp FOREIGN KEY (inspection_id) REFERENCES inspections(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
