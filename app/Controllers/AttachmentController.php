<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\DomainException;
use App\Repositories\OrderRepository;
use App\Services\AttachmentService;

/** Pièces jointes d'un dossier : envoi et téléchargement après contrôle d'accès (périmètre du PO client). */
final class AttachmentController extends Controller
{
    /** Table de chaque type d'entité, pour vérifier qu'elle appartient bien au dossier. */
    private const OWNERS = [
        'customer_order' => ['customer_orders', 'id'],
        'purchase_order' => ['purchase_orders', 'order_id'],
        'delivery' => ['deliveries', 'order_id'],
        'invoice' => ['invoices', 'order_id'],
    ];

    public function store(Request $request): Response
    {
        $this->authorize('orders.view');
        $order = $this->orNotFound((new OrderRepository())->find($request->intParam('id')));
        $back = '/orders/' . $order['id'] . '#documents';
        $entity = (string) $request->input('entity', 'customer_order');
        $entityId = (int) $request->input('entity_id', $order['id']);
        // Le formulaire envoie « type:id » depuis une seule liste déroulante.
        if (preg_match('/^(\w+):(\d+)$/', (string) $request->input('entity_ref', ''), $m)) {
            [$entity, $entityId] = [$m[1], (int) $m[2]];
        }
        if (!isset(self::OWNERS[$entity])) {
            throw new HttpException(404);
        }
        [$table, $col] = self::OWNERS[$entity];
        $stmt = Database::pdo()->prepare("SELECT 1 FROM $table WHERE id = ? AND $col = ?");
        $stmt->execute([$entityId, $order['id']]);
        if (!$stmt->fetchColumn()) {
            throw new HttpException(404);
        }
        try {
            (new AttachmentService())->store((int) $order['id'], $entity, $entityId, $_FILES['file'] ?? [], self::nul(mb_substr(trim((string) $request->input('label', '')), 0, 160)));
        } catch (DomainException $e) {
            return $this->domainError($back, $e);
        }
        return $this->success($back, 'attachment.uploaded');
    }

    public function download(Request $request): Response
    {
        $this->authorize('orders.view');
        $stmt = Database::pdo()->prepare('SELECT * FROM attachments WHERE id = ?');
        $stmt->execute([$request->intParam('id')]);
        $att = $stmt->fetch();
        // Accès refusé si le dossier est hors périmètre (find() applique SiteScope).
        if (!$att || (new OrderRepository())->find((int) $att['order_id']) === null) {
            throw new HttpException(404);
        }
        $path = AttachmentService::dir() . '/' . $att['stored_name'];
        if (!is_file($path)) {
            throw new HttpException(404);
        }
        $inline = in_array($att['mime'], array_keys(AttachmentService::MIME), true) && !$request->query('download');
        $name = str_replace(['"', "\r", "\n"], '', $att['original_name']);
        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => $att['mime'],
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($att['original_name']),
            'Cache-Control' => 'private, max-age=0',
        ]);
    }
}
