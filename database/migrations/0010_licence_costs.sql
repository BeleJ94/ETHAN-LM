-- Licence : décomposition de la valeur. « amount » = valeur FOB ; CIF = FOB + fret + assurance + autres frais (calculé).
ALTER TABLE import_licences
    ADD COLUMN freight DECIMAL(18,2) NULL AFTER amount,
    ADD COLUMN insurance DECIMAL(18,2) NULL AFTER freight,
    ADD COLUMN other_costs DECIMAL(18,2) NULL AFTER insurance;
