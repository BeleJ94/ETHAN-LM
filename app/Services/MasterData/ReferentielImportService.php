<?php

declare(strict_types=1);

namespace App\Services\MasterData;

use App\Core\Database;
use App\Domain\DomainException;
use App\Repositories\LookupRepository;
use App\Services\ActivityLogger;
use App\Services\AttachmentService;
use App\Services\CompanySettings;
use App\Services\ReferenceGenerator;
use App\Services\StockService;

/**
 * Import de la fiche de collecte des référentiels (docs/Referentiels-ETHAN-LM.xlsx), en deux modes :
 *
 * - « update » : un élément déjà présent (même code, ou même nom) est mis à jour avec les cellules remplies
 *   (une cellule vide ne remplace rien), les autres sont créés ;
 * - « reset »  : opérations et référentiels sont d'abord vidés (comme bin/reset.php --keep-files non coché), puis le fichier est importé.
 *   Sont TOUJOURS conservés : tous les comptes utilisateurs, leurs sites, l'agence liée à un compte transitaire,
 *   le journal d'activité et la configuration (sites, devises, taux, unités, familles, types et règles de documents),
 *   que le fichier met ensuite à jour.
 *
 * Dans les deux cas, le classeur est d'abord simulé : tout est écrit dans une transaction annulée, ce qui produit le rapport
 * exact de l'import réel. La confirmation rejoue le traitement et ne valide que s'il n'y a aucune erreur (tout ou rien).
 * Le stock initial n'est enregistré que pour un article créé (StockService, entrée valorisée, CMUP).
 * L'onglet « Société » alimente Paramètres → Société (CompanySettings) ; le logo se dépose sur cette page.
 */
final class ReferentielImportService
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const TTL_HOURS = 24;
    public const MODES = ['update', 'reset'];
    private const EXAMPLE_MARK = "ligne d'exemple";

    /** Vidées par la réinitialisation (les tables absentes de la base sont ignorées). */
    private const RESET_TABLES = [
        'attachments', 'order_events', 'sequences',
        'customer_orders', 'customer_order_lines', 'supplier_quotes', 'supplier_quote_lines',
        'purchase_orders', 'purchase_order_lines', 'import_licences', 'import_licence_lines', 'licence_imports',
        'inspections', 'inspection_lines', 'deliveries', 'delivery_lines', 'delivery_line_avs',
        'shipment_documents', 'invoices', 'payments', 'stock_levels', 'stock_movements',
        'customers', 'delivery_sites', 'suppliers', 'items', 'categories', 'banks', 'bank_branches', 'customs_agencies', 'warehouses',
    ];

    /** @var list<array{sheet:string,row:int,message:string}> */
    private array $errors = [];
    /** @var list<string> */
    private array $warnings = [];
    /** @var array<string,array{label:string,created:int,updated:list<string>,unchanged:int}> */
    private array $sheets = [];
    /** @var array<string,int> table → lignes supprimées */
    private array $wiped = [];
    /** @var array<string,string> */
    private array $company = [];
    private string $current = '';
    private int $row = 0;

    public static function tmpDir(): string
    {
        return AttachmentService::dir() . '/tmp';
    }

    /* ---------- Dépôt ---------- */

    /** @param array<string,mixed> $file entrée $_FILES @return string jeton */
    public function upload(array $file): string
    {
        $this->purgeExpired();
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || (int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new DomainException('masterdata.error.too_big', ['max' => self::MAX_BYTES / 1024 / 1024]);
        }
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new DomainException('masterdata.error.no_file');
        }
        if (strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new DomainException('masterdata.error.not_xlsx');
        }
        new XlsxReader((string) $file['tmp_name']); // contrôle de structure avant conservation

        $dir = self::tmpDir();
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Dossier temporaire inaccessible : ' . $dir);
        }
        $token = bin2hex(random_bytes(16));
        if (!move_uploaded_file((string) $file['tmp_name'], self::path($token))) {
            throw new \RuntimeException('Dépôt du fichier impossible');
        }
        return $token;
    }

    public static function path(string $token): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new DomainException('masterdata.error.expired');
        }
        return self::tmpDir() . '/referentiels-' . $token . '.xlsx';
    }

    public static function discard(string $token): void
    {
        $path = self::path($token);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function purgeExpired(): void
    {
        foreach (glob(self::tmpDir() . '/referentiels-*.xlsx') ?: [] as $f) {
            if (filemtime($f) < time() - self::TTL_HOURS * 3600) {
                @unlink($f);
            }
        }
    }

    /* ---------- Traitement ---------- */

    /**
     * Simulation ($commit = false) ou import réel ; l'import n'est validé que sans aucune erreur.
     * @return array{mode:string,committed:bool,wiped:array<string,int>,sheets:array,errors:array,warnings:array,company:array<string,string>}
     */
    public function run(string $token, string $mode, bool $commit): array
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException($mode);
        }
        $path = self::path($token);
        if (!is_file($path)) {
            throw new DomainException('masterdata.error.expired');
        }
        $reader = new XlsxReader($path);
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if ($mode === 'reset') {
                $this->reset();
            }
            $this->company($reader);
            $steps = [
                2 => 'sites', 3 => 'warehouses', 4 => 'currencies', 5 => 'units', 6 => 'families', 7 => 'categories',
                8 => 'items', 9 => 'customers', 10 => 'deliverySites', 11 => 'suppliers', 12 => 'agencies',
                13 => 'banks', 14 => 'branches', 15 => 'documentTypes', 16 => 'documentRules',
            ];
            foreach ($steps as $n => $method) {
                $this->sheet($reader, $n, $method);
            }
            $committed = $commit && !$this->errors;
            if ($committed) {
                ActivityLogger::log('masterdata_import', null, $mode, null, [
                    'created' => array_sum(array_column($this->sheets, 'created')),
                    'updated' => array_sum(array_map(fn ($s) => count($s['updated']), $this->sheets)),
                    'wiped' => array_sum($this->wiped),
                ]);
                $pdo->commit();
            } else {
                $pdo->rollBack();
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        if ($committed && $mode === 'reset') {
            $this->afterReset();
        }
        return [
            'mode' => $mode,
            'committed' => $committed,
            'wiped' => $this->wiped,
            'sheets' => array_values($this->sheets),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'company' => $this->company,
        ];
    }

    /** Vidage transactionnel (DELETE, pas TRUNCATE qui validerait la transaction) ; comptes et sites intacts. */
    private function reset(): void
    {
        $pdo = Database::pdo();
        $existing = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (array_intersect(self::RESET_TABLES, $existing) as $table) {
                // Une agence en douane liée à un compte transitaire est conservée (le compte n'est pas modifié).
                $where = $table === 'customs_agencies' ? ' WHERE id NOT IN (SELECT forwarder_id FROM users WHERE forwarder_id IS NOT NULL)' : '';
                $this->wiped[$table] = $pdo->exec("DELETE FROM `$table`$where") ?: 0;
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        $this->wiped = array_filter($this->wiped);
    }

    /** Après validation : fichiers joints supprimés (sauf l'espace temporaire) et identifiants recalés. */
    private function afterReset(): void
    {
        $pdo = Database::pdo();
        foreach (array_keys($this->wiped) as $table) {
            $pdo->exec("ALTER TABLE `$table` AUTO_INCREMENT = 1"); // repart au plus petit libre
        }
        $dir = AttachmentService::dir();
        if (!is_dir($dir)) {
            return;
        }
        $tmp = realpath(self::tmpDir()) ?: '';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $p = $f->getRealPath() ?: $f->getPathname();
            if ($f->getFilename() === '.gitkeep' || ($tmp !== '' && str_starts_with($p, $tmp))) {
                continue;
            }
            $f->isDir() ? @rmdir($p) : @unlink($p);
        }
    }

    /** Onglet « N. … » : lignes de données (après l'en-tête, hors ligne d'exemple et lignes vides). */
    private function sheet(XlsxReader $reader, int $n, string $method): void
    {
        $name = $this->sheetName($reader, $n);
        if ($name === null) {
            $this->warnings[] = t('masterdata.warn.missing_sheet', ['n' => $n]);
            return;
        }
        $this->current = $name;
        $this->sheets[$method] = ['label' => $name, 'created' => 0, 'updated' => [], 'unchanged' => 0];
        foreach ($reader->rows($name) as $rowNo => $cells) {
            if ($rowNo < 5 || !$cells || $this->isExample($cells)) {
                continue;
            }
            $this->row = $rowNo;
            try {
                $this->$method($cells);
            } catch (DomainException $e) {
                $this->error(t($e->getMessage(), $e->params));
            } catch (\PDOException $e) {
                $this->error(t('masterdata.err.db', ['msg' => $e->errorInfo[2] ?? $e->getMessage()]));
            }
        }
    }

    private function sheetName(XlsxReader $reader, int $n): ?string
    {
        foreach ($reader->sheetNames() as $name) {
            if (preg_match('/^' . $n . '\.\s/u', $name)) {
                return $name;
            }
        }
        return null;
    }

    /** @param array<string,string> $cells */
    private function isExample(array $cells): bool
    {
        foreach ($cells as $v) {
            if (str_contains(mb_strtolower($v), self::EXAMPLE_MARK)) {
                return true;
            }
        }
        return false;
    }

    /* ---------- Onglets ---------- */

    private function company(XlsxReader $reader): void
    {
        $name = $this->sheetName($reader, 1);
        if ($name === null) {
            return;
        }
        $keys = ['A' => 'name', 'B' => 'address', 'C' => 'city', 'D' => 'phone', 'E' => 'email', 'F' => 'rccm', 'G' => 'id_nat', 'H' => 'nif'];
        foreach ($reader->rows($name) as $rowNo => $cells) {
            if ($rowNo < 5 || !$cells || $this->isExample($cells)) {
                continue;
            }
            $this->current = $name;
            $this->row = $rowNo;
            foreach ($keys as $col => $key) {
                if (($cells[$col] ?? '') !== '') {
                    $this->company[$key] = $cells[$col];
                }
            }
            if (isset($this->company['email']) && filter_var($this->company['email'], FILTER_VALIDATE_EMAIL) === false) {
                $this->error(t('masterdata.err.email', ['value' => $this->company['email']]));
                unset($this->company['email']);
            }
            // Paramètres → Société : seules les cellules remplies remplacent les valeurs existantes.
            CompanySettings::save($this->company);
            $cur = strtoupper($cells['I'] ?? '');
            if ($cur !== '' && $cur !== base_currency()) {
                $this->warnings[] = t('masterdata.warn.base_currency', ['file' => $cur, 'app' => base_currency()]);
            }
            return;
        }
    }

    /** @param array<string,string> $c */
    private function sites(array $c): void
    {
        $name = $this->req($c, 'B', 'Nom du site');
        $code = ReferenceGenerator::normalize($c['A'] ?? '');
        $id = $this->id('SELECT id FROM sites WHERE ' . ($code !== '' ? 'code = ?' : 'name = ?'), [$code !== '' ? $code : $name]);
        $this->save('sites', $id, $name, ['name' => $name, 'city' => $this->opt($c, 'C')],
            fn () => ['code' => ReferenceGenerator::resolve('site', $code), 'active' => 1]);
    }

    /** @param array<string,string> $c */
    private function warehouses(array $c): void
    {
        $name = $this->req($c, 'B', "Nom de l'entrepôt");
        $site = $this->req($c, 'C', 'Site de rattachement');
        $siteId = $this->id('SELECT id FROM sites WHERE name = ? OR code = ?', [$site, $site])
            ?? throw new DomainException('masterdata.err.not_found', ['what' => 'Site', 'value' => $site]);
        $code = ReferenceGenerator::normalize($c['A'] ?? '');
        $id = $code !== ''
            ? $this->id('SELECT id FROM warehouses WHERE code = ?', [$code])
            : $this->id('SELECT id FROM warehouses WHERE site_id = ? AND name = ?', [$siteId, $name]);
        $this->save('warehouses', $id, $name, ['site_id' => $siteId, 'name' => $name],
            fn () => ['code' => ReferenceGenerator::resolve('warehouse', $code, null, ['site_id' => $siteId]), 'active' => 1]);
    }

    /** Devise (libellé) + taux de départ ajouté s'il n'existe pas à cette date. @param array<string,string> $c */
    private function currencies(array $c): void
    {
        $code = strtoupper($this->req($c, 'A', 'Code ISO'));
        if (!preg_match('/^[A-Z]{3}$/', $code)) {
            throw new DomainException('masterdata.err.invalid', ['field' => 'Code ISO', 'value' => $code]);
        }
        $created = $this->id('SELECT 1 FROM currencies WHERE code = ?', [$code]) === null;
        $updated = false;
        if ($created) {
            $this->insert('currencies', ['code' => $code, 'name' => $this->req($c, 'B', 'Libellé'), 'decimals' => 2]);
        } elseif (($c['B'] ?? '') !== '') {
            $updated = $this->changes('currencies', 'code', $code, ['name' => $c['B']]);
        }
        if (($c['C'] ?? '') !== '' && $code !== base_currency()) {
            $rate = $this->num($c, 'C', 'Taux');
            if ($rate <= 0) {
                throw new DomainException('masterdata.err.invalid', ['field' => 'Taux', 'value' => $c['C']]);
            }
            $from = $this->date($c, 'D', 'Taux valable à partir du');
            $rateId = $this->id('SELECT id FROM exchange_rates WHERE currency_code = ? AND valid_from = ?', [$code, $from]);
            if ($rateId === null) {
                $this->insert('exchange_rates', ['currency_code' => $code, 'rate' => $rate, 'valid_from' => $from]);
                $updated = true;
            } else {
                $updated = $this->changes('exchange_rates', 'id', $rateId, ['rate' => $rate]) || $updated;
            }
        }
        match (true) {
            $created => $this->sheets['currencies']['created']++,
            $updated => $this->sheets['currencies']['updated'][] = $code,
            default => $this->sheets['currencies']['unchanged']++,
        };
    }

    /** @param array<string,string> $c */
    private function units(array $c): void
    {
        $code = strtoupper($this->req($c, 'A', 'Code'));
        $this->save('units', $this->id('SELECT id FROM units WHERE code = ?', [$code]), $code,
            ['label' => $this->req($c, 'B', 'Libellé')], fn () => ['code' => $code]);
    }

    /** @param array<string,string> $c */
    private function families(array $c): void
    {
        $code = ReferenceGenerator::normalize($this->req($c, 'A', 'Code'));
        $name = $this->req($c, 'B', 'Nom');
        $color = $this->opt($c, 'D') !== null ? mb_strtolower($c['D']) : null;
        if ($color !== null && !in_array($color, ['blue', 'green', 'orange', 'red', 'purple', 'teal', 'gray'], true)) {
            throw new DomainException('masterdata.err.invalid', ['field' => 'Couleur', 'value' => $c['D']]);
        }
        $id = $this->id('SELECT id FROM item_families WHERE code = ?', [$code]) ?? $this->id('SELECT id FROM item_families WHERE name = ?', [$name]);
        $this->save('item_families', $id, $name, ['name' => $name, 'description' => $this->opt($c, 'C'), 'color' => $color],
            fn () => ['code' => ReferenceGenerator::resolve('family', $code), 'color' => $color ?? 'gray', 'sort_order' => 100, 'active' => 1]);
    }

    /** @param array<string,string> $c */
    private function categories(array $c): void
    {
        $name = $this->req($c, 'A', 'Nom de la catégorie');
        $this->save('categories', $this->id('SELECT id FROM categories WHERE name = ?', [$name]), $name, ['name' => $name]);
    }

    /** @param array<string,string> $c */
    private function items(array $c): void
    {
        $name = $this->req($c, 'B', 'Désignation');
        $family = $this->req($c, 'D', 'Famille');
        $familyId = $this->id('SELECT id FROM item_families WHERE code = ? OR name = ?', [$family, $family])
            ?? throw new DomainException('masterdata.err.not_found', ['what' => 'Famille', 'value' => $family]);
        $unit = $this->req($c, 'F', 'Unité');
        $unitId = $this->id('SELECT id FROM units WHERE code = ? OR label = ?', [$unit, $unit])
            ?? throw new DomainException('masterdata.err.not_found', ['what' => 'Unité', 'value' => $unit]);
        $categoryId = null;
        if (($c['E'] ?? '') !== '') {
            $categoryId = $this->id('SELECT id FROM categories WHERE name = ?', [$c['E']]) ?? $this->insert('categories', ['name' => $c['E']]);
        }
        $sku = ReferenceGenerator::normalize($c['A'] ?? '');
        $id = $this->id('SELECT id FROM items WHERE ' . ($sku !== '' ? 'sku = ?' : 'name = ?'), [$sku !== '' ? $sku : $name]);

        $qty = ($c['I'] ?? '') !== '' ? $this->num($c, 'I', 'Stock initial') : 0.0;
        if ($qty < 0) {
            throw new DomainException('masterdata.err.invalid', ['field' => 'Stock initial', 'value' => $c['I']]);
        }
        $stock = null;
        if ($qty > 0 && $id === null) {
            $wh = $c['J'] ?? '';
            if ($wh === '' || ($c['K'] ?? '') === '') {
                throw new DomainException('masterdata.err.stock_incomplete');
            }
            $whId = $this->id('SELECT id FROM warehouses WHERE name = ? OR code = ?', [$wh, $wh])
                ?? throw new DomainException('masterdata.err.not_found', ['what' => 'Entrepôt', 'value' => $wh]);
            $currency = strtoupper($c['L'] ?? '') ?: base_currency();
            $rate = (new LookupRepository())->rateAt($currency, date('Y-m-d'))
                ?? throw new DomainException('masterdata.err.no_rate', ['cur' => $currency]);
            $stock = [$whId, $qty, $this->num($c, 'K', 'Coût unitaire initial'), $currency, $rate];
        } elseif ($qty > 0) {
            $this->warnings[] = t('masterdata.warn.stock_ignored', ['item' => $name]);
        }

        $itemId = $this->save('items', $id, $name, [
            'name' => $name, 'description' => $this->opt($c, 'C'), 'category_id' => $categoryId, 'family_id' => $familyId,
            'unit_id' => $unitId, 'hs_code' => $this->opt($c, 'G'),
            'min_stock' => ($c['H'] ?? '') !== '' ? $this->num($c, 'H', 'Stock minimum') : null,
        ], fn () => [
            'sku' => ReferenceGenerator::resolve('item', $sku, null, ['family_id' => $familyId]),
            'min_stock' => ($c['H'] ?? '') !== '' ? $this->num($c, 'H', 'Stock minimum') : 0, 'active' => 1,
        ]);
        if ($stock !== null) {
            [$whId, $qty, $cost, $currency, $rate] = $stock;
            (new StockService())->receive($itemId, $whId, $qty, $cost, $currency, $rate, date('Y-m-d H:i:s'),
                t('masterdata.stock_note'), ['source_type' => 'masterdata_import']);
        }
    }

    /** @param array<string,string> $c */
    private function customers(array $c): void
    {
        $this->party('customers', 'customer', $c);
    }

    /** @param array<string,string> $c */
    private function suppliers(array $c): void
    {
        $this->party('suppliers', 'supplier', $c);
    }

    /** Clients et fournisseurs : même gabarit de colonnes. @param array<string,string> $c */
    private function party(string $table, string $refType, array $c): void
    {
        $name = $this->req($c, 'B', 'Raison sociale');
        $code = ReferenceGenerator::normalize($c['A'] ?? '');
        $id = ($code !== '' ? $this->id("SELECT id FROM $table WHERE code = ?", [$code]) : null) ?? $this->id("SELECT id FROM $table WHERE name = ?", [$name]);
        $this->save($table, $id, $name, [
            'name' => $name, 'rccm' => $this->opt($c, 'C'), 'id_nat' => $this->opt($c, 'D'), 'nif' => $this->opt($c, 'E'),
            'address' => $this->opt($c, 'F'), 'city' => $this->opt($c, 'G'), 'country' => $this->opt($c, 'H'),
            'contact_name' => $this->opt($c, 'I'), 'phone' => $this->opt($c, 'J'), 'email' => $this->email($c, 'K'),
            'payment_terms_days' => ($c['L'] ?? '') !== '' ? (int) $this->num($c, 'L', 'Délai de paiement') : null,
            'currency' => ($c['M'] ?? '') !== '' ? $this->currency($c, 'M') : null, 'notes' => $this->opt($c, 'N'),
        ], fn () => [
            'code' => ReferenceGenerator::resolve($refType, $code),
            'payment_terms_days' => ($c['L'] ?? '') !== '' ? (int) $this->num($c, 'L', 'Délai de paiement') : 30,
            'currency' => $this->currency($c, 'M'), 'active' => 1,
        ]);
    }

    /** @param array<string,string> $c */
    private function deliverySites(array $c): void
    {
        $customer = $this->req($c, 'A', 'Client');
        $customerId = $this->id('SELECT id FROM customers WHERE name = ? OR code = ?', [$customer, $customer])
            ?? throw new DomainException('masterdata.err.not_found', ['what' => 'Client', 'value' => $customer]);
        $name = $this->req($c, 'B', 'Nom du lieu');
        $this->save('delivery_sites', $this->id('SELECT id FROM delivery_sites WHERE customer_id = ? AND name = ?', [$customerId, $name]), $customer . ' — ' . $name, [
            'customer_id' => $customerId, 'name' => $name, 'address' => $this->opt($c, 'C'), 'city' => $this->opt($c, 'D'),
            'contact_name' => $this->opt($c, 'E'), 'phone' => $this->opt($c, 'F'), 'gps' => $this->opt($c, 'G'),
            'instructions' => $this->opt($c, 'H'),
        ], fn () => ['active' => 1]);
    }

    /** @param array<string,string> $c */
    private function agencies(array $c): void
    {
        $name = $this->req($c, 'B', 'Raison sociale');
        $code = ReferenceGenerator::normalize($c['A'] ?? '');
        $id = ($code !== '' ? $this->id('SELECT id FROM customs_agencies WHERE code = ?', [$code]) : null)
            ?? $this->id('SELECT id FROM customs_agencies WHERE name = ?', [$name]);
        $this->save('customs_agencies', $id, $name, [
            'name' => $name, 'approval_number' => $this->opt($c, 'C'), 'nif' => $this->opt($c, 'D'), 'rccm' => $this->opt($c, 'E'),
            'address' => $this->opt($c, 'F'), 'city' => $this->opt($c, 'G'), 'country' => $this->opt($c, 'H'),
            'contact_name' => $this->opt($c, 'I'), 'phone' => $this->opt($c, 'J'), 'email' => $this->email($c, 'K'),
            'customs_posts' => $this->opt($c, 'L'), 'notes' => $this->opt($c, 'M'),
        ], fn () => ['code' => ReferenceGenerator::resolve('agency', $code), 'active' => 1]);
    }

    /** @param array<string,string> $c */
    private function banks(array $c): void
    {
        $code = ReferenceGenerator::normalize($this->req($c, 'A', 'Code'));
        $name = $this->req($c, 'B', 'Nom de la banque');
        $swift = $this->opt($c, 'D') !== null ? strtoupper($c['D']) : null;
        if ($swift !== null && !preg_match('/^[A-Z0-9]{8}([A-Z0-9]{3})?$/', $swift)) {
            throw new DomainException('masterdata.err.invalid', ['field' => 'Code SWIFT', 'value' => $swift]);
        }
        $id = $this->id('SELECT id FROM banks WHERE code = ?', [$code]) ?? $this->id('SELECT id FROM banks WHERE name = ?', [$name]);
        $this->save('banks', $id, $name, [
            'name' => $name, 'short_name' => $this->opt($c, 'C'), 'swift' => $swift, 'country' => $this->opt($c, 'E'),
            'phone' => $this->opt($c, 'F'), 'email' => $this->email($c, 'G'), 'website' => $this->opt($c, 'H'),
        ], fn () => ['code' => ReferenceGenerator::resolve('bank', $code), 'active' => 1]);
    }

    /** @param array<string,string> $c */
    private function branches(array $c): void
    {
        $bank = $this->req($c, 'A', 'Banque');
        $bankId = $this->id('SELECT id FROM banks WHERE code = ? OR name = ? OR short_name = ?', [$bank, $bank, $bank])
            ?? throw new DomainException('masterdata.err.not_found', ['what' => 'Banque', 'value' => $bank]);
        $name = $this->req($c, 'C', "Nom de l'agence");
        $code = ReferenceGenerator::normalize($c['B'] ?? '');
        $id = ($code !== '' ? $this->id('SELECT id FROM bank_branches WHERE bank_id = ? AND code = ?', [$bankId, $code]) : null)
            ?? $this->id('SELECT id FROM bank_branches WHERE bank_id = ? AND name = ?', [$bankId, $name]);
        $this->save('bank_branches', $id, $bank . ' — ' . $name, [
            'name' => $name, 'code' => $code !== '' ? $code : null, 'city' => $this->opt($c, 'D'), 'address' => $this->opt($c, 'E'),
            'phone' => $this->opt($c, 'F'), 'email' => $this->email($c, 'G'), 'contact_name' => $this->opt($c, 'H'),
        ], fn () => ['bank_id' => $bankId, 'active' => 1]);
    }

    /** @param array<string,string> $c */
    private function documentTypes(array $c): void
    {
        $code = ReferenceGenerator::normalize($this->req($c, 'A', 'Code'));
        $id = $this->id('SELECT id FROM document_types WHERE code = ?', [$code]);
        $data = [
            'name' => $this->opt($c, 'B'), 'description' => $this->opt($c, 'C'),
            'provider' => $this->choice($c, 'D', 'Fourni par', [
                'fournisseur' => 'supplier', 'transitaire / agence en douane' => 'forwarder', 'transitaire' => 'forwarder',
                'douane' => 'customs', 'client' => 'customer', 'interne' => 'internal',
            ]),
            'level' => $this->choice($c, 'E', 'Niveau', [
                'dossier (une fois par commande)' => 'order', 'dossier' => 'order',
                'expédition (à chaque livraison)' => 'delivery', 'expédition' => 'delivery',
            ]),
            'requires_number' => $this->yesNo($c, 'F', 'N° obligatoire'),
            'requires_file' => $this->yesNo($c, 'G', 'Fichier obligatoire'),
            'requires_issue_date' => $this->yesNo($c, 'H', "Date d'émission obligatoire"),
            'has_expiry' => $this->yesNo($c, 'I', "Date d'expiration"),
        ];
        $this->save('document_types', $id, $code, $data, fn () => [
            'code' => $code, 'name' => $this->req($c, 'B', 'Nom du document'),
            'provider' => $data['provider'] ?? throw new DomainException('masterdata.err.required', ['field' => 'Fourni par']),
            'level' => $data['level'] ?? throw new DomainException('masterdata.err.required', ['field' => 'Niveau']),
            'requires_number' => $data['requires_number'] ?? 1, 'requires_file' => $data['requires_file'] ?? 1,
            'requires_issue_date' => $data['requires_issue_date'] ?? 0, 'has_expiry' => $data['has_expiry'] ?? 0,
            'sort_order' => 100, 'active' => 1,
        ]);
    }

    /** Règle identifiée par (document, mode, famille, transport) ; « Obligatoire », « Bloque à l'étape » et remarques sont mis à jour. @param array<string,string> $c */
    private function documentRules(array $c): void
    {
        $code = ReferenceGenerator::normalize($this->req($c, 'A', 'Code document'));
        $typeId = $this->id('SELECT id FROM document_types WHERE code = ?', [$code])
            ?? throw new DomainException('masterdata.err.not_found', ['what' => 'Type de document', 'value' => $code]);
        $mode = $this->choice($c, 'B', "Mode d'exécution", [
            'tous' => null, 'achat local' => 'local_purchase', 'importation' => 'import', 'vente sur stock' => 'stock',
        ]);
        $familyId = null;
        if (($c['C'] ?? '') !== '' && mb_strtolower($c['C']) !== 'tous') {
            $familyId = $this->id('SELECT id FROM item_families WHERE code = ? OR name = ?', [$c['C'], $c['C']])
                ?? throw new DomainException('masterdata.err.not_found', ['what' => 'Famille', 'value' => $c['C']]);
        }
        $transport = $this->choice($c, 'D', 'Mode de transport', [
            'tous' => null, 'route' => 'road', 'rail' => 'rail', 'mer' => 'sea', 'air' => 'air', 'multimodal' => 'multimodal',
        ]);
        $id = $this->id('SELECT id FROM document_rules WHERE document_type_id = ? AND fulfillment_mode <=> ? AND family_id <=> ? AND transport_mode <=> ?',
            [$typeId, $mode, $familyId, $transport]);
        $blocking = $this->choice($c, 'F', "Bloque à l'étape", [
            'aucun' => '', 'départ' => 'departure', 'réception' => 'reception', 'facturation' => 'invoicing', 'clôture' => 'closure',
        ]);
        $this->save('document_rules', $id, $code, [
            'required' => $this->yesNo($c, 'E', 'Obligatoire'),
            'blocking_stage' => $blocking, // '' = « Aucun » explicite
            'notes' => $this->opt($c, 'G'),
        ], fn () => [
            'document_type_id' => $typeId, 'fulfillment_mode' => $mode, 'family_id' => $familyId, 'transport_mode' => $transport,
            'required' => $this->yesNo($c, 'E', 'Obligatoire') ?? 1, 'active' => 1,
        ]);
    }

    /* ---------- Écriture ---------- */

    /**
     * Crée (valeurs + $onCreate()) ou met à jour l'enregistrement. En mise à jour, seules les valeurs non nulles sont appliquées
     * (cellule vide = inchangé) ; la chaîne vide vaut NULL explicite (« Aucun »). Les codes ne sont jamais modifiés.
     * @param array<string,mixed> $data @param (callable(): array<string,mixed>)|null $onCreate @return int id
     */
    private function save(string $table, ?int $id, string $label, array $data, ?callable $onCreate = null): int
    {
        $sheet = array_key_last($this->sheets);
        if ($id === null) {
            $row = ($onCreate ? $onCreate() : []) + $data;
            $row = array_map(fn ($v) => $v === '' ? null : $v, $row);
            $id = $this->insert($table, $row);
            $this->sheets[$sheet]['created']++;
            return $id;
        }
        if ($this->changes($table, 'id', $id, $data)) {
            $this->sheets[$sheet]['updated'][] = $label;
        } else {
            $this->sheets[$sheet]['unchanged']++;
        }
        return $id;
    }

    /** Applique les valeurs non nulles qui diffèrent de la base. @param array<string,mixed> $data @return bool modifié */
    private function changes(string $table, string $key, int|string $id, array $data): bool
    {
        $pdo = Database::pdo();
        $data = array_filter($data, fn ($v) => $v !== null);
        if (!$data) {
            return false;
        }
        $stmt = $pdo->prepare('SELECT ' . implode(',', array_keys($data)) . " FROM $table WHERE $key = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetch() ?: [];
        $set = [];
        foreach ($data as $col => $v) {
            $v = $v === '' ? null : $v;
            $old = $current[$col] ?? null;
            $same = is_numeric($v) && is_numeric($old) ? (float) $v === (float) $old : (string) $v === (string) $old && ($v === null) === ($old === null);
            if (!$same) {
                $set[$col] = $v;
            }
        }
        if (!$set) {
            return false;
        }
        $pdo->prepare("UPDATE $table SET " . implode(',', array_map(fn ($c) => "$c = ?", array_keys($set))) . " WHERE $key = ?")
            ->execute([...array_values($set), $id]);
        return true;
    }

    /** @param array<string,mixed> $data @return int id créé */
    private function insert(string $table, array $data): int
    {
        $pdo = Database::pdo();
        $pdo->prepare("INSERT INTO $table (" . implode(',', array_keys($data)) . ') VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')')
            ->execute(array_values($data));
        return (int) $pdo->lastInsertId();
    }

    /* ---------- Lecture des cellules ---------- */

    /** @param list<mixed> $params */
    private function id(string $sql, array $params): ?int
    {
        $stmt = Database::pdo()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    /** @param array<string,string> $c */
    private function req(array $c, string $col, string $field): string
    {
        return ($c[$col] ?? '') !== '' ? $c[$col] : throw new DomainException('masterdata.err.required', ['field' => $field]);
    }

    /** @param array<string,string> $c */
    private function opt(array $c, string $col): ?string
    {
        return ($c[$col] ?? '') !== '' ? $c[$col] : null;
    }

    /** @param array<string,string> $c */
    private function num(array $c, string $col, string $field): float
    {
        $v = str_replace([' ', "\u{202F}", ','], ['', '', '.'], $c[$col] ?? '');
        return is_numeric($v) ? (float) $v : throw new DomainException('masterdata.err.number', ['field' => $field, 'value' => $c[$col] ?? '']);
    }

    /** Date Excel (numéro de série) ou JJ/MM/AAAA ; vide = aujourd'hui. @param array<string,string> $c */
    private function date(array $c, string $col, string $field): string
    {
        $v = $c[$col] ?? '';
        if ($v === '') {
            return date('Y-m-d');
        }
        if (is_numeric($v)) {
            return gmdate('Y-m-d', (int) round(((float) $v - 25569) * 86400));
        }
        foreach (['!d/m/Y', '!Y-m-d', '!d-m-Y'] as $format) {
            $d = \DateTimeImmutable::createFromFormat($format, $v);
            if ($d !== false && $d->format(substr($format, 1)) === $v) {
                return $d->format('Y-m-d');
            }
        }
        throw new DomainException('masterdata.err.date', ['field' => $field, 'value' => $v]);
    }

    /** @param array<string,string> $c */
    private function email(array $c, string $col): ?string
    {
        $v = $this->opt($c, $col);
        if ($v !== null && filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('masterdata.err.email', ['value' => $v]);
        }
        return $v;
    }

    /** Devise existante ; vide = devise de base. @param array<string,string> $c */
    private function currency(array $c, string $col): string
    {
        $code = strtoupper($c[$col] ?? '') ?: base_currency();
        return $this->id('SELECT 1 FROM currencies WHERE code = ?', [$code]) !== null
            ? $code
            : throw new DomainException('masterdata.err.not_found', ['what' => 'Devise', 'value' => $code]);
    }

    /** Oui / Non ; vide = null (valeur par défaut à la création, inchangé en mise à jour). @param array<string,string> $c */
    private function yesNo(array $c, string $col, string $field): ?int
    {
        return match (mb_strtolower($c[$col] ?? '')) {
            '' => null,
            'oui', 'yes', '1', 'x' => 1,
            'non', 'no', '0' => 0,
            default => throw new DomainException('masterdata.err.invalid', ['field' => $field, 'value' => $c[$col]]),
        };
    }

    /**
     * Valeur de liste déroulante → valeur interne ; vide = null.
     * @param array<string,string|null> $map libellé en minuscules → valeur
     * @param array<string,string> $c
     */
    private function choice(array $c, string $col, string $field, array $map): ?string
    {
        $v = mb_strtolower($c[$col] ?? '');
        if ($v === '') {
            return null;
        }
        if (array_key_exists($v, $map)) {
            return $map[$v];
        }
        if (in_array($v, $map, true)) {
            return $v;
        }
        throw new DomainException('masterdata.err.invalid', ['field' => $field, 'value' => $c[$col]]);
    }

    private function error(string $message): void
    {
        $this->errors[] = ['sheet' => $this->current, 'row' => $this->row, 'message' => $message];
    }
}
