<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DataTable;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Domain\DomainException;
use App\Domain\OrderProgress;
use App\Repositories\InvoiceRepository;
use App\Repositories\OrderRepository;
use App\Services\InvoiceService;

/** Suivi des factures et paiements fournisseurs (à payer) et clients (à encaisser). */
final class InvoiceController extends Controller
{
    public const METHODS = ['transfer', 'cash', 'cheque', 'mobile', 'other'];

    public function index(Request $request): Response
    {
        $this->authorize('finance.view');
        return $this->view('finance/index', [
            'title' => t('nav.finance'),
            'outstanding' => (new InvoiceRepository())->outstanding(),
            'direction' => (string) $request->query('direction', ''),
            'status' => (string) $request->query('status', 'open'),
        ]);
    }

    public function data(Request $request): Response
    {
        $this->authorize('finance.view');
        $dt = new DataTable($request, InvoiceRepository::SORTABLE, 'i.due_date ASC');
        [$total, $filtered, $rows] = (new InvoiceRepository())->datatable($dt, [
            'direction' => (string) $request->query('direction', ''),
            'status' => (string) $request->query('status', ''),
        ]);
        return $dt->response($total, $filtered, $rows);
    }

    /** Fiche finance d'une facture (client ou fournisseur). */
    public function show(Request $request): Response
    {
        $this->authorize('finance.view');
        $inv = $this->orNotFound((new InvoiceRepository())->details($request->intParam('id')));
        return $this->view('finance/show', ['title' => $inv['reference'], 'inv' => $inv]);
    }

    public function store(Request $request): Response
    {
        $this->authorize('finance.manage');
        $order = $this->orNotFound((new OrderRepository())->find($request->intParam('id')));
        $back = '/orders/' . $order['id'] . '#finance';
        $input = $request->all();
        $v = new Validator($input, [
            'reference' => 'max:30', 'direction' => 'required|in:payable,receivable', 'invoice_date' => 'required|date', 'due_date' => 'date',
            'amount' => 'required|numeric|gt:0', 'external_number' => 'max:80', 'notes' => 'max:500', 'po_id' => 'int',
        ]);
        if (($input['direction'] ?? '') === 'payable' && ($input['po_id'] ?? '') === '') {
            $v->addError('po_id', t('invoice.error.po_required'));
        }
        if (($input['direction'] ?? '') === 'payable' && ($input['external_number'] ?? '') === '') {
            $v->addError('external_number', t('validation.required'));
        }
        if ($v->fails()) {
            return $this->backWithErrors($back, $v->errors(), $input, implode(' ', $v->errors()));
        }
        $terms = (int) $order['payment_terms_days'];
        if ($input['direction'] === 'payable') {
            foreach ((new OrderRepository())->purchaseOrders((int) $order['id']) as $po) {
                if ((int) $po['id'] === (int) $input['po_id']) {
                    $terms = (int) $po['payment_terms_days'];
                }
            }
        }
        try {
            (new InvoiceService())->create((int) $order['id'], [
                'direction' => $input['direction'],
                'reference' => (string) ($input['reference'] ?? ''),
                'reference_suggested' => (string) ($input['reference_suggested'] ?? ''),
                'po_id' => self::nul($input['po_id'] ?? null) !== null ? (int) $input['po_id'] : null,
                'external_number' => self::nul($input['external_number'] ?? null),
                'invoice_date' => $input['invoice_date'],
                'due_date' => self::nul($input['due_date'] ?? null) ?? OrderProgress::dueDate($input['invoice_date'], $terms),
                'amount' => round((float) $input['amount'], 2),
                'notes' => self::nul($input['notes'] ?? null),
            ]);
        } catch (DomainException $e) {
            return $this->domainError($back, $e);
        }
        return $this->success($back, 'invoice.created');
    }

    public function pay(Request $request): Response
    {
        $this->authorize('finance.manage');
        $inv = $this->orNotFound((new InvoiceRepository())->find($request->intParam('id')));
        $back = self::backFor($request, $inv);
        $input = $request->all();
        $v = new Validator($input, ['paid_at' => 'required|date', 'amount' => 'required|numeric|gt:0',
            'method' => 'required|in:' . implode(',', self::METHODS), 'bank_ref' => 'max:120', 'notes' => 'max:500']);
        if ($v->fails()) {
            return $this->backWithErrors($back, $v->errors(), $input, implode(' ', $v->errors()));
        }
        try {
            (new InvoiceService())->pay((int) $inv['id'], [
                'paid_at' => $input['paid_at'], 'amount' => round((float) $input['amount'], 2), 'method' => $input['method'],
                'bank_ref' => self::nul($input['bank_ref'] ?? null), 'notes' => self::nul($input['notes'] ?? null),
            ]);
        } catch (DomainException $e) {
            return $this->domainError($back, $e);
        }
        return $this->success($back, 'invoice.payment_recorded');
    }

    /** Pièce jointe de la facture (scan, avis de virement…), rattachée à son dossier. */
    public function attach(Request $request): Response
    {
        $this->authorize('finance.manage');
        $inv = $this->orNotFound((new InvoiceRepository())->find($request->intParam('id')));
        $back = '/invoices/' . $inv['id'] . '#documents';
        try {
            (new \App\Services\AttachmentService())->store((int) $inv['order_id'], 'invoice', (int) $inv['id'], $_FILES['file'] ?? [],
                self::nul(mb_substr(trim((string) $request->input('label', '')), 0, 160)));
        } catch (DomainException $e) {
            return $this->domainError($back, $e);
        }
        return $this->success($back, 'attachment.uploaded');
    }

    /** Retour après action : fiche facture, liste finance ou dossier. */
    private static function backFor(Request $request, array $inv): string
    {
        return match ($request->input('back')) {
            'invoice' => '/invoices/' . $inv['id'],
            'finance' => '/finance',
            default => '/orders/' . $inv['order_id'] . '#finance',
        };
    }

    public function cancel(Request $request): Response
    {
        $this->authorize('finance.manage');
        $inv = $this->orNotFound((new InvoiceRepository())->find($request->intParam('id')));
        $back = self::backFor($request, $inv);
        try {
            $note = trim((string) $request->input('note', ''));
            if ($note === '') {
                throw new DomainException('orders.error.note_required');
            }
            (new InvoiceService())->cancel((int) $inv['id'], mb_substr($note, 0, 300));
        } catch (DomainException $e) {
            return $this->domainError($back, $e);
        }
        return $this->success($back, 'invoice.cancelled');
    }
}
