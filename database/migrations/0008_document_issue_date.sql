-- Types de documents : date d'émission exigée au dépôt (ex. certificat d'origine, connaissement)
ALTER TABLE document_types ADD COLUMN requires_issue_date TINYINT(1) NOT NULL DEFAULT 0 AFTER requires_file;
