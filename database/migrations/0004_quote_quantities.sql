-- ETHAN LM — devis fournisseur : quantité proposée par ligne (peut être inférieure à la quantité commandée).
-- NULL = quantité commandée (devis antérieurs).
ALTER TABLE supplier_quote_lines ADD COLUMN quantity DECIMAL(18,3) NULL AFTER order_line_id;
