<?php

declare(strict_types=1);

namespace App\Services\Licence;

use App\Core\Database;
use App\Domain\DomainException;
use App\Domain\LicenceReview;
use App\Domain\LicenceTextParser;
use App\Domain\NameMatcher;
use App\Repositories\ImportRepository;
use App\Repositories\LookupRepository;
use App\Security\Auth;
use App\Services\ActivityLogger;
use App\Services\AttachmentService;
use App\Services\ImportService;

/**
 * Création de licence depuis un document : téléverser → extraire → vérifier / corriger → confirmer.
 *
 * - le document reste dans un espace temporaire privé (storage/uploads/tmp), lisible par son seul auteur ;
 * - rien n'est enregistré côté métier avant la confirmation explicite ;
 * - la confirmation passe par le même service que la saisie manuelle (ImportService::createLicence), dans une transaction ;
 * - un brouillon déjà confirmé renvoie la licence créée (aucun doublon en cas de double envoi) ;
 * - les brouillons abandonnés sont purgés au bout de 24 h.
 */
final class LicenceImportService
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const TTL_HOURS = 24;

    public static function tmpDir(): string
    {
        return AttachmentService::dir() . '/tmp';
    }

    /* ---------- 1. Téléversement + 2. extraction ---------- */

    /** @param array<string,mixed> $file entrée $_FILES @return string jeton du brouillon */
    public function upload(array $file): string
    {
        $this->purgeExpired();
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE) {
            throw new DomainException('licence_import.error.too_big', ['max' => self::MAX_BYTES / 1024 / 1024]);
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new DomainException('licence_import.error.no_file');
        }
        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new DomainException('licence_import.error.too_big', ['max' => self::MAX_BYTES / 1024 / 1024]);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        $head = (string) file_get_contents($file['tmp_name'], false, null, 0, 8);
        if ($mime !== 'application/pdf' || !str_starts_with($head, '%PDF-')) {
            throw new DomainException('licence_import.error.not_pdf');
        }

        $token = bin2hex(random_bytes(16));
        $dir = self::tmpDir();
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Dossier temporaire inaccessible');
        }
        $stored = $token . '.pdf';
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
            throw new DomainException('licence_import.error.no_file');
        }
        $path = $dir . '/' . $stored;

        // Extraction : une erreur de lecture n'empêche pas le brouillon (l'utilisateur bascule en saisie manuelle)
        $extraction = null;
        $method = 'none';
        $pages = null;
        $error = null;
        try {
            $read = (new PdfTextReader())->read($path);
            [$method, $pages] = [$read['method'], $read['pages']];
            $currencies = array_column((new LookupRepository())->currencies(), 'code');
            $extraction = LicenceTextParser::parse($read['text'], $currencies);
            $extraction['text_excerpt'] = mb_substr(trim($read['text']), 0, 4000);
        } catch (DomainException $e) {
            $error = $e->getMessage();
        }

        Database::pdo()->prepare('INSERT INTO licence_imports (token, user_id, stored_name, original_name, mime, size, sha256, method, page_count, error_code, extraction, expires_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?, NOW() + INTERVAL ' . self::TTL_HOURS . ' HOUR)')
            ->execute([$token, Auth::require()->id, $stored, mb_substr(basename((string) $file['name']), 0, 255), $mime, (int) $file['size'],
                hash_file('sha256', $path), $method, $pages, $error, $extraction ? json_encode($extraction, JSON_UNESCAPED_UNICODE) : null]);
        ActivityLogger::log('licence_import', (int) Database::pdo()->lastInsertId(), 'upload', null, ['file' => $file['name'], 'method' => $method, 'error' => $error]);
        return $token;
    }

    /** @return array<string,mixed>|null Brouillon de l'utilisateur courant (isolation stricte par auteur) */
    public function find(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $stmt = Database::pdo()->prepare('SELECT * FROM licence_imports WHERE token = ? AND user_id = ?');
        $stmt->execute([$token, Auth::require()->id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['extraction'] = $row['extraction'] ? json_decode($row['extraction'], true) : null;
        $row['corrections'] = $row['corrections'] ? json_decode($row['corrections'], true) : null;
        return $row;
    }

    public function path(array $draft): string
    {
        return self::tmpDir() . '/' . $draft['stored_name'];
    }

    /* ---------- 3. Vérification : valeurs proposées, correspondances, contrôles ---------- */

    /**
     * Fiche proposée = corrections déjà saisies, sinon valeurs extraites (jamais de valeur inventée).
     * @return array{data:array<string,mixed>,lines:list<array<string,mixed>>,status:array<string,string>,source:array<string,?string>,supplier:array,pos:list<array>}
     */
    public function proposal(array $draft): array
    {
        $ex = $draft['extraction'] ?? ['fields' => [], 'lines' => []];
        $fields = $ex['fields'] ?? [];
        $value = fn (string $k) => $fields[$k]['value'] ?? null;
        $status = array_map(fn ($f) => $f['status'], $fields);
        $source = array_map(fn ($f) => $f['source'] ?? null, $fields);

        // Fournisseur : correspondance dans le référentiel (aucune création)
        $suppliers = array_map(fn ($s) => ['id' => (int) $s['id'], 'name' => $s['name']], array_values(array_filter(
            (new \App\Repositories\PartyRepository('supplier'))->all(true), fn ($s) => !$s['is_forwarder']
        )));
        $supplier = NameMatcher::match($value('supplier_name'), $suppliers);

        // PO fournisseur couvert : référence lue sur le document, sinon PO d'import sans licence du fournisseur reconnu
        $pos = (new ImportRepository())->posWithoutLicence();
        $poId = null;
        $poStatus = 'missing';
        $ref = (string) $value('po_reference');
        foreach ($pos as $p) {
            if ($ref !== '' && strcasecmp($p['reference'], $ref) === 0) {
                [$poId, $poStatus] = [(int) $p['id'], 'extracted'];
            }
        }
        if ($poId === null && $supplier['best'] !== null) {
            $forSupplier = array_values(array_filter($pos, fn ($p) => (int) $p['supplier_id'] === $supplier['best']));
            if (count($forSupplier) === 1) {
                [$poId, $poStatus] = [(int) $forSupplier[0]['id'], 'uncertain'];
            }
        }
        $status['po_id'] = $poStatus;

        // Banque : correspondance dans le référentiel (aucune création) ; agence : ville / nom lus dans « Agence Banque »
        $bankMatch = self::matchBank($value('bank'), $ex['extras']['agency'] ?? null);
        $status['bank_id'] = $bankMatch['status'];
        $status['cod_number'] = isset($ex['extras']['cod']) ? (str_contains($ex['extras']['cod'], ' / ') ? 'uncertain' : 'extracted') : 'missing';
        foreach (['freight' => 'freight', 'insurance' => 'insurance', 'other_costs' => 'other_costs'] as $k => $x) {
            $status[$k] = isset($ex['extras'][$x]) ? 'extracted' : 'missing';
        }

        $data = [
            'po_id' => $poId,
            'number' => $value('number'),
            'issued_at' => $value('issued_at'),
            'expires_at' => $value('expires_at'),
            'origin_country' => $value('origin_country'),
            'currency' => $value('currency'),
            'amount' => $value('amount'),
            'cod_number' => isset($ex['extras']['cod']) && !str_contains($ex['extras']['cod'], ' / ') ? $ex['extras']['cod'] : null,
            'freight' => self::cost($ex['extras']['freight'] ?? null),
            'insurance' => self::cost($ex['extras']['insurance'] ?? null),
            'other_costs' => self::cost($ex['extras']['other_costs'] ?? null),
            'bank' => $value('bank'),
            'bank_id' => $bankMatch['bank_id'],
            'branch_id' => $bankMatch['branch_id'],
            'notes' => self::notesFromExtras($ex['extras'] ?? []),
        ];
        $lines = array_map(fn ($l) => array_intersect_key($l, array_flip(['description', 'hs_code', 'quantity', 'unit', 'unit_price', 'amount', 'status', 'source'])), $ex['lines'] ?? []);

        // Les corrections déjà enregistrées priment (aucune perte en cas de rechargement)
        if (is_array($draft['corrections'])) {
            $data = array_merge($data, array_intersect_key($draft['corrections']['data'] ?? [], $data));
            $lines = $draft['corrections']['lines'] ?? $lines;
        }
        return ['data' => $data, 'lines' => $lines, 'status' => $status, 'source' => $source, 'supplier' => $supplier, 'pos' => $pos,
            'supplier_name' => $value('supplier_name'), 'importer_name' => $value('importer_name'), 'warnings' => $ex['warnings'] ?? [],
            'model' => $ex['model'] ?? null, 'extras' => $ex['extras'] ?? []];
    }

    /** Informations complémentaires lues (modèle reconnu) → note pré-remplie, modifiable avant confirmation. */
    public static function notesFromExtras(array $extras): ?string
    {
        $parts = [];
        foreach (['category', 'cif', 'invoice', 'invoice_date', 'customs_post', 'regime'] as $k) {
            if (!empty($extras[$k])) {
                $parts[] = t('licence_import.extra.' . $k) . ' : ' . ($k === 'invoice_date' ? fmt_date($extras[$k]) : $extras[$k]);
            }
        }
        return $parts ? mb_substr(implode(' · ', $parts), 0, 500) : null;
    }

    /**
     * Banque lue → banque du référentiel (nom puis sigle) ; agence lue (« RAWBANK - Lubumbashi ») → agence de cette banque
     * dont la ville ou le nom correspond. Ambigu ou inconnu : rien n'est présélectionné.
     * @return array{bank_id:?int,branch_id:?int,status:string}
     */
    public static function matchBank(?string $bankName, ?string $agency): array
    {
        $out = ['bank_id' => null, 'branch_id' => null, 'status' => 'missing'];
        if (trim((string) $bankName) === '') {
            return $out;
        }
        $repo = new \App\Repositories\BankRepository();
        $banks = $repo->all(true);
        $m = NameMatcher::match($bankName, array_map(fn ($b) => ['id' => (int) $b['id'], 'name' => $b['name']], $banks));
        if ($m['status'] !== 'matched') {
            $short = array_values(array_filter($banks, fn ($b) => $b['short_name'] && NameMatcher::normalize($b['short_name']) === NameMatcher::normalize($bankName)));
            $m = count($short) === 1 ? ['status' => 'matched', 'best' => (int) $short[0]['id']] : $m;
        }
        if ($m['status'] !== 'matched') {
            return ['status' => 'uncertain'] + $out;
        }
        $out = ['bank_id' => $m['best'], 'branch_id' => null, 'status' => 'extracted'];
        $place = trim((string) preg_replace('/^.*\s[-–—]\s*/u', '', (string) $agency));
        if ($place !== '') {
            $hits = array_values(array_filter($repo->branches($m['best'], true), fn ($br) => in_array(NameMatcher::normalize($place), [NameMatcher::normalize((string) $br['city']), NameMatcher::normalize($br['name'])], true)));
            if (count($hits) === 1) {
                $out['branch_id'] = (int) $hits[0]['id'];
            }
        }
        return $out;
    }

    /** « 16 890,00 USD » → 16890.0 (null si absent ou illisible). */
    private static function cost(?string $raw): ?float
    {
        $raw = trim((string) preg_replace('/[A-Z]{3}.*$/', '', (string) $raw));
        return $raw === '' ? null : \App\Domain\LicenceTextParser::parseNumber($raw);
    }

    /** Contrôles (identiques à la création manuelle) pour une fiche donnée. */
    public function issues(array $draft, array $data, array $lines): array
    {
        $repo = new ImportRepository();
        $po = null;
        foreach ($repo->posWithoutLicence() as $p) {
            if ((int) $p['id'] === (int) ($data['po_id'] ?? 0)) {
                $po = $p;
            }
        }
        $poHasLicence = ($data['po_id'] ?? '') !== '' && $po === null && $repo->licenceForPo((int) $data['po_id']) !== null;
        $same = Database::pdo()->prepare("SELECT l.number FROM licence_imports li JOIN import_licences l ON l.id = li.licence_id
            WHERE li.sha256 = ? AND li.status = 'confirmed' AND li.id <> ? LIMIT 1");
        $same->execute([$draft['sha256'], $draft['id']]);
        return LicenceReview::issues($data, $lines, [
            'today' => date('Y-m-d'),
            'number_taken' => ($data['number'] ?? '') !== '' && $repo->numberExists((string) $data['number']),
            'po_has_licence' => $poHasLicence,
            'po_amount' => $po ? (float) $po['amount'] : null,
            'po_currency' => $po['currency'] ?? null,
            'same_file_licence' => ($n = $same->fetchColumn()) === false ? null : (string) $n,
            'model' => $draft['extraction']['model'] ?? null,
        ]);
    }

    /** Enregistre les corrections en cours (sauvegarde automatique : rien n'est perdu). */
    public function saveCorrections(array $draft, array $data, array $lines): void
    {
        if ($draft['status'] !== 'draft') {
            return;
        }
        Database::pdo()->prepare('UPDATE licence_imports SET corrections = ?, expires_at = GREATEST(expires_at, NOW() + INTERVAL ' . self::TTL_HOURS . ' HOUR) WHERE id = ?')
            ->execute([json_encode(['data' => $data, 'lines' => $lines], JSON_UNESCAPED_UNICODE), $draft['id']]);
    }

    /* ---------- 4. Confirmation et création ---------- */

    /**
     * Crée la licence (même service et mêmes règles que la saisie manuelle), ses lignes, la pièce jointe du document,
     * et trace l'origine — en une transaction. Idempotent : un brouillon déjà confirmé renvoie sa licence.
     * @return array{licence_id:int,already:bool}
     */
    public function confirm(string $token, array $data, array $lines): array
    {
        $result = Database::transaction(function () use ($token, $data, $lines) {
            $pdo = Database::pdo();
            $lock = $pdo->prepare('SELECT * FROM licence_imports WHERE token = ? AND user_id = ? FOR UPDATE');
            $lock->execute([$token, Auth::require()->id]);
            $draft = $lock->fetch() ?: throw new DomainException('licence_import.error.not_found');
            if ($draft['status'] === 'confirmed') {
                return ['licence_id' => (int) $draft['licence_id'], 'already' => true, 'path' => null];
            }
            if ($draft['status'] !== 'draft' || !is_file(self::tmpDir() . '/' . $draft['stored_name'])) {
                throw new DomainException('licence_import.error.expired');
            }
            $draft['extraction'] = $draft['extraction'] ? json_decode($draft['extraction'], true) : null;
            if (LicenceReview::hasErrors($this->issues($draft, $data, $lines))) {
                throw new DomainException('licence_import.error.has_errors');
            }

            $licenceId = (new ImportService())->createLicence((int) $data['po_id'], $data, null, [
                'lines' => $lines, 'source' => 'document', 'import_id' => (int) $draft['id'],
            ]);
            // Document source conservé en pièce jointe du PO fournisseur, rattaché à la licence et à la check-list
            $po = $pdo->prepare('SELECT order_id FROM purchase_orders WHERE id = ?');
            $po->execute([$data['po_id']]);
            $attachmentId = (new AttachmentService())->storeLocal((int) $po->fetchColumn(), 'purchase_order', (int) $data['po_id'],
                self::tmpDir() . '/' . $draft['stored_name'], $draft['original_name'], t('imports.licence') . ' ' . $data['number']);
            $pdo->prepare('UPDATE import_licences SET attachment_id = ? WHERE id = ?')->execute([$attachmentId, $licenceId]);
            $pdo->prepare("UPDATE shipment_documents sd JOIN document_types t ON t.id = sd.document_type_id
                    SET sd.attachment_id = ? WHERE sd.order_id = (SELECT order_id FROM import_licences WHERE id = ?) AND sd.delivery_id IS NULL
                    AND t.code = 'LIC' AND sd.number = ? AND sd.attachment_id IS NULL")
                ->execute([$attachmentId, $licenceId, $data['number']]);

            $pdo->prepare("UPDATE licence_imports SET status = 'confirmed', licence_id = ?, corrections = ? WHERE id = ?")
                ->execute([$licenceId, json_encode(['data' => $data, 'lines' => $lines], JSON_UNESCAPED_UNICODE), $draft['id']]);
            ActivityLogger::log('licence_import', (int) $draft['id'], 'confirm', null, [
                'licence_id' => $licenceId, 'file' => $draft['original_name'], 'sha256' => $draft['sha256'], 'method' => $draft['method'],
                'corrected' => self::corrected($draft['extraction'], $data),
            ]);
            return ['licence_id' => $licenceId, 'already' => false, 'path' => self::tmpDir() . '/' . $draft['stored_name']];
        });
        // Le document est désormais conservé en pièce jointe : le fichier temporaire est supprimé après validation
        if ($result['path'] && is_file($result['path'])) {
            @unlink($result['path']);
        }
        return ['licence_id' => $result['licence_id'], 'already' => $result['already']];
    }

    public function discard(array $draft): void
    {
        if ($draft['status'] !== 'draft') {
            return;
        }
        @unlink($this->path($draft));
        Database::pdo()->prepare("UPDATE licence_imports SET status = 'abandoned' WHERE id = ?")->execute([$draft['id']]);
        ActivityLogger::log('licence_import', (int) $draft['id'], 'discard');
    }

    /** Purge des brouillons abandonnés (fichiers temporaires supprimés). @return int nombre purgé */
    public function purgeExpired(): int
    {
        $pdo = Database::pdo();
        $rows = $pdo->query("SELECT id, stored_name FROM licence_imports WHERE status = 'draft' AND expires_at < NOW()")->fetchAll();
        foreach ($rows as $r) {
            @unlink(self::tmpDir() . '/' . $r['stored_name']);
            $pdo->prepare("UPDATE licence_imports SET status = 'abandoned' WHERE id = ?")->execute([$r['id']]);
        }
        return count($rows);
    }

    /** @return list<string> champs dont la valeur confirmée diffère de la valeur extraite (traçabilité des corrections) */
    private static function corrected(?array $extraction, array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $extracted = $extraction['fields'][$k]['value'] ?? null;
            if ($extracted !== null && (string) $extracted !== (string) $v) {
                $out[] = $k;
            }
        }
        return $out;
    }
}
