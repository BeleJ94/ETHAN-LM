-- Facturation client par expédition : chaque facture client porte l'expédition livrée qu'elle facture.
-- Les factures client antérieures (facturation au dossier) restent sans expédition.
ALTER TABLE invoices
    ADD COLUMN delivery_id INT UNSIGNED NULL AFTER po_id,
    ADD KEY ix_inv_delivery (delivery_id),
    ADD CONSTRAINT fk_inv_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id);
