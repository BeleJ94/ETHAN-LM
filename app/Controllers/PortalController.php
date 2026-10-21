<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Checklist;
use App\Repositories\PurchaseOrderRepository;
use App\Repositories\ShipmentDocumentRepository;

/**
 * Portail transitaire (accès externe limité) : uniquement les expéditions et dossiers confiés à son entreprise,
 * consultation de la check-list et dépôt de documents. Aucune donnée commerciale (prix, marges, factures) n'est exposée.
 */
final class PortalController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->forwarder();
        $repo = new ShipmentDocumentRepository();
        $ids = $repo->forwarderDeliveryIds((int) $user->forwarderId);
        $deliveries = [];
        if ($ids) {
            $deliveries = Database::pdo()->query('SELECT d.id, d.reference, d.status, d.shipped_at, d.transport_mode, d.vehicle_plate,
                    o.reference AS order_reference, c.name AS customer_name, p.reference AS po_reference
                FROM deliveries d JOIN purchase_orders p ON p.id = d.po_id JOIN customer_orders o ON o.id = d.order_id
                JOIN customers c ON c.id = o.customer_id
                WHERE d.id IN (' . implode(',', $ids) . ') ORDER BY d.status = \'delivered\', d.id DESC')->fetchAll();
        }
        return $this->view('portal/index', [
            'title' => t('nav.portal'),
            'deliveries' => $deliveries,
            'summaries' => $repo->summaries($ids),
        ]);
    }

    public function delivery(Request $request): Response
    {
        $user = $this->forwarder();
        $repo = new ShipmentDocumentRepository();
        $id = $request->intParam('id');
        if (!in_array($id, $repo->forwarderDeliveryIds((int) $user->forwarderId), true)) {
            throw new HttpException(404);
        }
        $d = $this->orNotFound((new PurchaseOrderRepository())->findDelivery($id, false));
        $docs = $repo->forDelivery($id, (int) $d['order_id']);
        return $this->view('portal/delivery', [
            'title' => $d['reference'],
            'd' => $d,
            'docs' => $docs,
            'progress' => Checklist::progress($docs, date('Y-m-d')),
        ]);
    }

    /** L'agence (transitaire) renseigne son n° de dossier sur une expédition qui lui est confiée. */
    public function fileNumber(Request $request): Response
    {
        $user = $this->forwarder();
        $id = $request->intParam('id');
        if (!in_array($id, (new ShipmentDocumentRepository())->forwarderDeliveryIds((int) $user->forwarderId), true)) {
            throw new HttpException(404);
        }
        $ref = trim((string) $request->input('forwarder_ref', ''));
        if (mb_strlen($ref) > 60) {
            return $this->backWithErrors('/portal/deliveries/' . $id, ['forwarder_ref' => t('validation.max', ['max' => 60])], $request->all());
        }
        try {
            (new \App\Services\DeliveryService())->updateCustoms($id, false, $ref === '' ? null : mb_strtoupper($ref));
        } catch (\App\Domain\DomainException $e) {
            return $this->domainError('/portal/deliveries/' . $id, $e);
        }
        return $this->success('/portal/deliveries/' . $id, 'delivery.customs.saved');
    }

    private function forwarder(): \App\Security\CurrentUser
    {
        $user = $this->user();
        if (!$user->can('portal.access') || $user->forwarderId === null) {
            throw new HttpException(403);
        }
        return $user;
    }
}
