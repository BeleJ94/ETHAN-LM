<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Domain\DomainException;
use App\Repositories\ShipmentDocumentRepository;
use App\Services\AttachmentService;
use App\Services\ChecklistService;

/**
 * Actions sur un document de check-list : dépôt (équipe ou transitaire), validation par une autre personne,
 * rejet, dérogation / non applicable (responsable), réouverture, consultation du fichier.
 */
final class ShipmentDocumentController extends Controller
{
    public function submit(Request $request): Response
    {
        $this->authorize('documents.upload');
        $doc = $this->accessible($request);
        $input = $request->all();
        $v = new Validator($input, ['number' => 'max:80', 'issued_at' => 'date', 'expires_at' => 'date']);
        if ($v->fails()) {
            return $this->backWithErrors($this->back($request, $doc), $v->errors(), $input, implode(' ', $v->errors()));
        }
        try {
            (new ChecklistService())->submit((int) $doc['id'], [
                'number' => self::nul($input['number'] ?? null),
                'issued_at' => self::nul($input['issued_at'] ?? null),
                'expires_at' => self::nul($input['expires_at'] ?? null),
            ], $_FILES['file'] ?? null);
        } catch (DomainException $e) {
            return $this->domainError($this->back($request, $doc), $e);
        }
        return $this->success($this->back($request, $doc), 'documents.submitted');
    }

    public function validate(Request $request): Response
    {
        $this->authorize('documents.validate');
        $doc = $this->accessible($request);
        try {
            (new ChecklistService())->validate((int) $doc['id']);
        } catch (DomainException $e) {
            return $this->domainError($this->back($request, $doc), $e);
        }
        return $this->success($this->back($request, $doc), 'documents.validated');
    }

    public function reject(Request $request): Response
    {
        $this->authorize('documents.validate');
        $doc = $this->accessible($request);
        try {
            $note = trim((string) $request->input('note', ''));
            if ($note === '') {
                throw new DomainException('orders.error.note_required');
            }
            (new ChecklistService())->reject((int) $doc['id'], mb_substr($note, 0, 500));
        } catch (DomainException $e) {
            return $this->domainError($this->back($request, $doc), $e);
        }
        return $this->success($this->back($request, $doc), 'documents.rejected');
    }

    public function waive(Request $request): Response
    {
        $this->authorize('documents.waive');
        $doc = $this->accessible($request);
        try {
            (new ChecklistService())->waive((int) $doc['id'], (string) $request->input('kind', 'waived'), mb_substr(trim((string) $request->input('note', '')), 0, 500));
        } catch (DomainException $e) {
            return $this->domainError($this->back($request, $doc), $e);
        }
        return $this->success($this->back($request, $doc), 'documents.waived');
    }

    public function reset(Request $request): Response
    {
        $this->authorize('documents.waive');
        $doc = $this->accessible($request);
        try {
            (new ChecklistService())->reset((int) $doc['id'], mb_substr(trim((string) $request->input('note', '')), 0, 500));
        } catch (DomainException $e) {
            return $this->domainError($this->back($request, $doc), $e);
        }
        return $this->success($this->back($request, $doc), 'documents.reopened');
    }

    /** Fichier du document : servi après contrôle d'accès (interne ou transitaire concerné). */
    public function file(Request $request): Response
    {
        $doc = $this->accessible($request);
        if (!$doc['attachment_id']) {
            throw new HttpException(404);
        }
        $stmt = \App\Core\Database::pdo()->prepare('SELECT * FROM attachments WHERE id = ?');
        $stmt->execute([$doc['attachment_id']]);
        $att = $stmt->fetch();
        $path = AttachmentService::dir() . '/' . ($att['stored_name'] ?? '');
        if (!$att || !is_file($path)) {
            throw new HttpException(404);
        }
        $name = str_replace(['"', "\r", "\n"], '', $att['original_name']);
        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => $att['mime'],
            'Content-Disposition' => ($request->query('download') ? 'attachment' : 'inline') . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($att['original_name']),
            'Cache-Control' => 'private, max-age=0',
        ]);
    }

    /** @return array<string,mixed> */
    private function accessible(Request $request): array
    {
        $repo = new ShipmentDocumentRepository();
        $doc = $repo->find($request->intParam('id'));
        $user = $this->user();
        if ($doc === null || !$repo->canAccess($user, $doc) || (!$user->isForwarder() && !$user->can('documents.view'))) {
            throw new HttpException(404);
        }
        return $doc;
    }

    /** Retour vers l'écran d'origine (portail, expédition ou dossier). */
    private function back(Request $request, array $doc): string
    {
        // Un document de niveau dossier peut être traité depuis la fiche d'une expédition : on y revient.
        $fromDelivery = preg_match('/^delivery:(\d+)$/', (string) $request->input('back', ''), $m) ? (int) $m[1] : null;
        if ($this->user()->isForwarder()) {
            $deliveryId = $doc['delivery_id'] ?? $fromDelivery;
            return $deliveryId ? '/portal/deliveries/' . (int) $deliveryId : '/portal';
        }
        if ($fromDelivery) {
            return '/deliveries/' . $fromDelivery . '#documents';
        }
        return $request->input('back') === 'order' || !$doc['delivery_id']
            ? '/orders/' . (int) $doc['order_id'] . '#checklist'
            : '/deliveries/' . (int) $doc['delivery_id'] . '#documents';
    }
}
