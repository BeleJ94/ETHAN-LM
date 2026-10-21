-- N° COD de la licence (« COD 2026 0012 ») : racine des n° d'inspection / AV / ARA « COD 2026 0012 / 0001 ».
ALTER TABLE import_licences ADD COLUMN cod_number VARCHAR(30) NULL AFTER number, ADD UNIQUE KEY uq_lic_cod (cod_number);
