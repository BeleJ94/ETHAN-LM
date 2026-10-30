-- Mentions des factures clients : coordonnées bancaires de règlement et conditions / mentions de pied de facture.
ALTER TABLE company_settings
    ADD COLUMN bank_details VARCHAR(1000) NULL AFTER share_capital,
    ADD COLUMN invoice_terms VARCHAR(1000) NULL AFTER bank_details;
