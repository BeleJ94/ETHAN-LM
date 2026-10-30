-- Approbateurs des factures clients : habilitation donnée par utilisateur (Paramètres → Utilisateurs).
-- Contrôle à 2 niveaux : un approbateur ne peut pas approuver une facture qu'il a lui-même émise.
ALTER TABLE users ADD COLUMN invoice_approver TINYINT(1) NOT NULL DEFAULT 0 AFTER forwarder_id;
