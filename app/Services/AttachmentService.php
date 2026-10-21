<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\DomainException;
use App\Security\Auth;

/** Fichiers hors racine web : type déterminé par le contenu, taille plafonnée, nom aléatoire. */
final class AttachmentService
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MIME = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    public const ENTITIES = ['customer_order', 'purchase_order', 'delivery', 'invoice'];

    public static function dir(): string
    {
        return dirname(__DIR__, 2) . '/storage/uploads';
    }

    /**
     * Copie un fichier serveur (ex. document d'un brouillon d'import) dans le stockage des pièces jointes,
     * avec les mêmes contrôles (type par le contenu, taille).
     */
    public function storeLocal(int $orderId, string $entity, int $entityId, string $path, string $originalName, ?string $label): int
    {
        if (!in_array($entity, self::ENTITIES, true) || !is_file($path)) {
            throw new DomainException('attachment.error.upload');
        }
        $size = (int) filesize($path);
        if ($size > self::MAX_BYTES) {
            throw new DomainException('attachment.error.size');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
        $ext = self::MIME[$mime] ?? throw new DomainException('attachment.error.type');
        $sub = date('Y/m');
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $target = self::dir() . '/' . $sub;
        if (!is_dir($target) && !mkdir($target, 0750, true) && !is_dir($target)) {
            throw new \RuntimeException('Dossier de stockage inaccessible');
        }
        if (!copy($path, $target . '/' . $name)) {
            throw new DomainException('attachment.error.upload');
        }
        return Database::transaction(function () use ($orderId, $entity, $entityId, $originalName, $label, $sub, $name, $mime, $size) {
            $pdo = Database::pdo();
            $pdo->prepare('INSERT INTO attachments (order_id, entity, entity_id, label, original_name, stored_name, mime, size, uploaded_by)
                VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$orderId, $entity, $entityId, $label ?: null, mb_substr(basename($originalName), 0, 255), $sub . '/' . $name, $mime, $size, Auth::require()->id]);
            $id = (int) $pdo->lastInsertId();
            ActivityLogger::log('attachment', $id, 'create', null, ['entity' => $entity, 'entity_id' => $entityId, 'name' => $originalName]);
            return $id;
        });
    }

    /** @param array{tmp_name:string,name:string,size:int,error:int} $file */
    public function store(int $orderId, string $entity, int $entityId, array $file, ?string $label): int
    {
        if (!in_array($entity, self::ENTITIES, true)) {
            throw new DomainException('attachment.error.entity');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new DomainException('attachment.error.upload');
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw new DomainException('attachment.error.size');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        $ext = self::MIME[$mime] ?? throw new DomainException('attachment.error.type');

        $sub = date('Y/m');
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $target = self::dir() . '/' . $sub;
        if (!is_dir($target) && !mkdir($target, 0750, true) && !is_dir($target)) {
            throw new \RuntimeException('Dossier de stockage inaccessible');
        }
        if (!move_uploaded_file($file['tmp_name'], $target . '/' . $name)) {
            throw new DomainException('attachment.error.upload');
        }

        return Database::transaction(function () use ($orderId, $entity, $entityId, $file, $label, $sub, $name, $mime) {
            $pdo = Database::pdo();
            $pdo->prepare('INSERT INTO attachments (order_id, entity, entity_id, label, original_name, stored_name, mime, size, uploaded_by)
                VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$orderId, $entity, $entityId, $label ?: null, mb_substr(basename($file['name']), 0, 255), $sub . '/' . $name,
                    $mime, $file['size'], Auth::require()->id]);
            $id = (int) $pdo->lastInsertId();
            ActivityLogger::log('attachment', $id, 'create', null, ['entity' => $entity, 'entity_id' => $entityId, 'name' => $file['name']]);
            OrderService::event($orderId, 'attachment', $label ?: basename($file['name']));
            return $id;
        });
    }
}
