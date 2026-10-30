-- Approbation des factures clients : une facture émise est « en attente d'approbation » (draft), modifiable ;
-- une fois approuvée elle devient « à régler » (open) et peut recevoir des encaissements.
ALTER TABLE invoices
    MODIFY status ENUM('draft','open','paid','cancelled') NOT NULL DEFAULT 'open',
    ADD COLUMN approved_by INT UNSIGNED NULL AFTER created_by,
    ADD COLUMN approved_at DATETIME NULL AFTER approved_by,
    ADD COLUMN updated_at DATETIME NULL AFTER approved_at,
    ADD CONSTRAINT fk_inv_approver FOREIGN KEY (approved_by) REFERENCES users(id);
