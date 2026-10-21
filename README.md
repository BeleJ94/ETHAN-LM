# ETHAN LM — Logistics Management

Gestion des commandes (PO clients et PO fournisseurs), achats, importations et stockage multi-sites.
Stack et conventions UI : voir `STACK-ET-SPECIFICATION-UI.md` (PHP 8.2+ sans framework, MariaDB, jQuery/DataTables/Chart.js/SweetAlert2/Lucide auto-hébergés).

## Installation locale (XAMPP)

```bash
cp .env.example .env                 # APP_BASE_PATH=/ETHAN-LM, DB_*
composer install
bash bin/fetch-vendor-assets.sh      # bibliothèques front, versions épinglées
php bin/migrate.php                  # crée la base et applique database/migrations
php bin/create-admin.php vous@domaine.com "Votre nom"
php bin/seed-demo.php                # optionnel : données de démonstration
```

Puis ouvrir http://localhost/ETHAN-LM. Hors XAMPP : vhost sur `public/` avec `APP_BASE_PATH=` vide, ou `composer serve`.

Tests : `vendor/bin/phpunit` et `npm test`.

## Architecture

```
app/Core          noyau : Router, Request/Response, Session, Csrf, I18n, View, Validator, Migrator, DataTable
app/Security      Auth, CurrentUser, Role (permissions), SiteScope (périmètre de sites)
app/Domain        règles pures : Cmup, MovementType
app/Services      StockService (seul point d'écriture du stock), ActivityLogger, Sequence
app/Repositories  requêtes SQL, toutes filtrées par SiteScope::clause()
app/Controllers   HTTP → validation → service → redirection + flash
views/            gabarits PHP (e() partout), layouts app / minimal
public/assets/js  elm-core / elm-tables / elm-export (purs, testés sous Node) + app.js
```

### Règles de stock
- **Journal immuable** : `stock_movements` n'est jamais modifié ; une correction = un nouveau mouvement.
- **CMUP global par article**, en devise de base (`APP_CURRENCY`). Seules les entrées valorisées le recalculent ;
  sorties, transferts et inventaires sont valorisés au CMUP courant.
- **Multi-devises** : chaque entrée conserve coût, devise et taux d'origine, plus le coût converti.
- **Stock négatif interdit** ; verrous `FOR UPDATE` sur l'article puis le niveau de stock (concurrence).
- Transfert = 2 lignes liées par `transfer_ref`. Inventaire : on saisit la quantité comptée, l'écart est calculé.
- `source_type` / `source_id` relieront les mouvements aux PO et dossiers d'import (lots suivants).

### Processus « achat local » (lot 2)
PO client → sourcing (devis comparés, marge) → PO fournisseur (brouillon → émis, imprimable, adresse de livraison client figée)
→ expéditions partielles (chargé / reçu, écart tracé) → factures et paiements fournisseurs / clients → clôture contrôlée.
- Le fournisseur livre **directement** le site client : aucun mouvement de stock.
- PO fournisseur soldé par les quantités **chargées** ; PO client mesuré par les quantités **reçues** (l'écart exige un motif à la clôture).
- Statuts recalculés par `OrderService::refresh()` ; chronologie métier dans `order_events`.
- Coordonnées imprimées sur les documents : variables `COMPANY_*` du `.env`.

### Check-list documentaire des expéditions (lot 2 bis)
- Référentiel **types de documents** (fourni par, niveau dossier / expédition, n°, fichier, expiration) et **règles**
  (mode d'exécution × famille × mode de transport, vide = tous) : Paramètres → Documents d'expédition.
- À la création d'une expédition (et à l'émission d'un PO pour les documents « dossier »), la check-list est **générée et figée**
  (`ChecklistService`, règles pures dans `App\Domain\Checklist`) ; « Recalculer » applique les nouvelles règles.
- Cycle : à fournir → **déposé** → **validé par une autre personne** (contrôle à 2 niveaux) ; rejet motivé ; **dérogation / non applicable**
  (droit `documents.waive`, motif tracé). Expiré = non satisfait.
- Blocages : avant départ (statut « en préparation » → « en route »), avant réception, avant facturation client, avant clôture.
- Transitaires : fournisseur marqué « transitaire », comptes de rôle `forwarder` → portail `/portal` limité aux expéditions confiées.

### Références automatiques (`App\Services\ReferenceGenerator`)
Tous les champs référence / code sont pré-remplis et modifiables : `CMD-AAAA-000001`, `PO-…`, `LIV-…`, `FAC-…`, `FF-…`,
`ENT/SOR/TRF/INV-…`, article = code famille + n° (`REA-0001`), `CLI-0001`, `FRS-0001`, `SITE-01`, entrepôt = code site + n°, `FAM-01`.
- La proposition affichée ne consomme pas le compteur ; vide = automatique ; bouton ↻ pour régénérer.
- Unicité garantie dans la transaction ; une proposition devenue prise entre-temps passe au numéro suivant.
- Une saisie manuelle au format du compteur fait avancer celui-ci. Nouveau champ : `views/partials/ref_field.php`.

## Feuille de route

| Lot | Contenu | État |
|---|---|---|
| 1 | Socle (auth, rôles, sites, i18n, journal) + Stock (articles, entrepôts, mouvements, inventaire, CMUP) | ✅ |
| 2 | PO clients, sourcing, PO fournisseurs, livraisons directes partielles, factures & paiements, clôture (processus achat local) | ✅ |
| 3 | Processus « importation » : licences (1 par PO fournisseur), inspections BIVAC/OCC partielles, AV / ARA et régularisation, livraisons plafonnées aux quantités libérées (`ImportService`, règles pures `App\Domain\ImportGate`) | ✅ |
| 3 bis | Licence depuis un document PDF : téléversement → extraction (texte natif, `pdftotext` ou OCR si configurés via `PDFTOTEXT_PATH` / `TESSERACT_PATH` + `PDFTOPPM_PATH`) → vérification / correction à côté du PDF → confirmation. Brouillons privés (`storage/uploads/tmp`, 24 h, purge `php bin/purge-licence-imports.php` en tâche planifiée) | ✅ |
| 3 ter | Moteur d’extraction externe (à brancher sur `App\Services\Licence\LicenceExtractor`), frais d’approche dans le coût de revient | |
| 4 | Processus « vente sur stock » : préparation / livraison → sorties de stock | |
| 5 | Alertes centralisées (cloche, pastilles, e-mail), rapports, gestion des taux de change | |
