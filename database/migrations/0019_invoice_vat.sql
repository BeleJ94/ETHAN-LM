-- TVA facultative sur les factures clients : montant HT (somme des lignes), taux et montant de TVA ; amount = TTC.
-- Factures antérieures : subtotal NULL (= amount, sans TVA).
ALTER TABLE invoices
    ADD COLUMN subtotal DECIMAL(18,2) NULL AFTER currency,
    ADD COLUMN vat_rate DECIMAL(5,2) NULL AFTER subtotal,
    ADD COLUMN vat_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER vat_rate;

-- Taux de TVA proposé par défaut dans le formulaire de facturation (vide = TVA non proposée cochée)
ALTER TABLE company_settings ADD COLUMN vat_rate DECIMAL(5,2) NULL AFTER invoice_terms;
