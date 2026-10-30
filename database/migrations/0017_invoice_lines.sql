-- Lignes de facture client : reprises des lignes de l'expédition (quantité réceptionnée × prix de vente, modifiables)
-- et lignes libres (frais de transport, manutention…). Le montant de la facture est la somme des lignes.
CREATE TABLE invoice_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    delivery_line_id INT UNSIGNED NULL,
    item_id INT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    unit VARCHAR(20) NULL,
    quantity DECIMAL(18,3) NOT NULL,
    unit_price DECIMAL(18,6) NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    KEY ix_invl_invoice (invoice_id),
    CONSTRAINT fk_invl_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_invl_dline FOREIGN KEY (delivery_line_id) REFERENCES delivery_lines(id),
    CONSTRAINT fk_invl_item FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
