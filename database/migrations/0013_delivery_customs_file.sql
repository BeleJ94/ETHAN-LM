-- Agence en douane / transitaire d'une expédition : n° de dossier attribué par l'agence.
ALTER TABLE deliveries ADD COLUMN forwarder_ref VARCHAR(60) NULL AFTER forwarder_id, ADD KEY ix_dl_fwref (forwarder_ref);
