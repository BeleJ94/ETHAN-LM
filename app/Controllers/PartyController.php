<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\LookupRepository;
use App\Repositories\PartyRepository;
use App\Services\ActivityLogger;
use App\Services\ReferenceGenerator;

/** Clients et fournisseurs : même écran, même règles (voir CustomerController / SupplierController). */
abstract class PartyController extends Controller
{
    abstract protected function type(): string;

    protected function base(): string
    {
        return $this->type() === 'customer' ? '/customers' : '/suppliers';
    }

    protected function repo(): PartyRepository
    {
        return new PartyRepository($this->type());
    }

    public function index(Request $request): Response
    {
        $this->authorize('orders.view');
        return $this->view('parties/index', [
            'title' => t('nav.' . $this->type() . 's'),
            'type' => $this->type(),
            'base' => $this->base(),
            'parties' => $this->repo()->all(),
        ]);
    }

    public function show(Request $request): Response
    {
        $this->authorize('orders.view');
        $party = $this->orNotFound($this->repo()->find($request->intParam('id')));
        return $this->view('parties/show', [
            'title' => $party['name'],
            'type' => $this->type(),
            'base' => $this->base(),
            'party' => $party,
            'sites' => $this->type() === 'customer' ? $this->repo()->sites((int) $party['id']) : [],
            'orders' => $this->orders((int) $party['id']),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('parties.manage');
        return $this->form(null);
    }

    public function edit(Request $request): Response
    {
        $this->authorize('parties.manage');
        return $this->form($this->orNotFound($this->repo()->find($request->intParam('id'))));
    }

    public function store(Request $request): Response
    {
        $this->authorize('parties.manage');
        return $this->save($request, null);
    }

    public function update(Request $request): Response
    {
        $this->authorize('parties.manage');
        return $this->save($request, $this->orNotFound($this->repo()->find($request->intParam('id'))));
    }

    /** Lieu de livraison (clients uniquement), saisi en modale depuis la fiche client. */
    public function saveSite(Request $request): Response
    {
        $this->authorize('parties.manage');
        $customer = $this->orNotFound($this->repo()->find($request->intParam('id')));
        $siteId = $request->intParam('site_id') ?: null;
        if ($siteId !== null) {
            $site = $this->orNotFound($this->repo()->findSite($siteId));
            if ((int) $site['customer_id'] !== (int) $customer['id']) {
                throw new \App\Core\HttpException(404);
            }
        }
        $input = $request->all();
        $back = $this->base() . '/' . $customer['id'] . '#sites';
        $v = new Validator($input, ['name' => 'required|max:160', 'address' => 'max:255', 'city' => 'max:120', 'contact_name' => 'max:120',
            'phone' => 'max:60', 'gps' => 'max:60', 'instructions' => 'max:500']);
        if ($v->fails()) {
            return $this->backWithErrors($back, $v->errors(), $input, implode(' ', $v->errors()));
        }
        $data = ['customer_id' => (int) $customer['id'], 'active' => isset($input['active']) || $siteId === null ? 1 : 0];
        foreach (['name', 'address', 'city', 'contact_name', 'phone', 'gps', 'instructions'] as $f) {
            $data[$f] = self::nul($input[$f] ?? null);
        }
        Database::transaction(function () use ($data, $siteId) {
            $id = $this->repo()->saveSite($data, $siteId);
            ActivityLogger::log('delivery_site', $id, $siteId ? 'update' : 'create', null, $data);
        });
        return $this->success($back, $siteId ? 'flash.updated' : 'flash.created');
    }

    private function form(?array $party): Response
    {
        return $this->view('parties/form', [
            'title' => t($party ? 'parties.edit.' . $this->type() : 'parties.new.' . $this->type()),
            'type' => $this->type(),
            'base' => $this->base(),
            'party' => $party,
            'currencies' => (new LookupRepository())->currencies(),
        ]);
    }

    private function save(Request $request, ?array $before): Response
    {
        $input = $request->all();
        $id = $before ? (int) $before['id'] : null;
        $v = new Validator($input, [
            'code' => 'max:20', 'name' => 'required|max:190', 'rccm' => 'max:60', 'id_nat' => 'max:60', 'nif' => 'max:60',
            'address' => 'max:255', 'city' => 'max:120', 'country' => 'max:80', 'contact_name' => 'max:120', 'phone' => 'max:60',
            'email' => 'email|max:190', 'payment_terms_days' => 'required|int|min:0', 'currency' => 'required|max:3',
        ]);
        if (!isset($v->errors()['currency']) && !(new LookupRepository())->currencyExists((string) $input['currency'])) {
            $v->addError('currency', t('validation.in'));
        }
        $back = $id ? $this->base() . "/$id/edit" : $this->base() . '/new';
        if ($v->fails()) {
            return $this->backWithErrors($back, $v->errors(), $input);
        }
        $data = [];
        foreach (PartyRepository::FIELDS as $f) {
            $data[$f] = self::nul($input[$f] ?? null);
        }
        $data['payment_terms_days'] = (int) $input['payment_terms_days'];
        $data['active'] = isset($input['active']) || $before === null ? 1 : 0;
        if ($this->type() === 'supplier') {
            $data['is_forwarder'] = isset($input['is_forwarder']) ? 1 : 0;
        }

        try {
            $newId = Database::transaction(function () use ($data, $id, $before, $input) {
            $data['code'] = ReferenceGenerator::resolve($this->type(), $input['code'] ?? '', $input['code_suggested'] ?? null, [], $id);
            $newId = $this->repo()->save($data, $id);
            ActivityLogger::log($this->type(), $newId, $before ? 'update' : 'create', $before ? array_intersect_key($before, $data) : null, $data);
            return $newId;
            });
        } catch (\App\Domain\DomainException $e) {
            return $this->backWithErrors($back, ['code' => t($e->getMessage(), $e->params)], $input);
        }
        return $this->success($this->base() . '/' . $newId, $before ? 'flash.updated' : 'flash.created');
    }

    /** @return list<array<string,mixed>> */
    private function orders(int $partyId): array
    {
        [$scope, $params] = \App\Security\SiteScope::clause('o.site_id');
        if ($this->type() === 'customer') {
            $sql = 'SELECT o.id, o.reference, o.customer_po_number AS number, o.po_date AS date, o.status, o.currency,
                    (SELECT COALESCE(SUM(l.quantity * l.unit_price), 0) FROM customer_order_lines l WHERE l.order_id = o.id) AS amount, o.id AS order_id
                FROM customer_orders o WHERE o.customer_id = ?' . $scope . ' ORDER BY o.id DESC LIMIT 100';
        } else {
            $sql = 'SELECT p.id, p.reference, o.reference AS number, p.order_date AS date, p.status, p.currency,
                    (SELECT COALESCE(SUM(pl.quantity * pl.unit_price), 0) FROM purchase_order_lines pl WHERE pl.po_id = p.id) AS amount, o.id AS order_id
                FROM purchase_orders p JOIN customer_orders o ON o.id = p.order_id WHERE p.supplier_id = ?' . $scope . ' ORDER BY p.id DESC LIMIT 100';
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$partyId, ...$params]);
        return $stmt->fetchAll();
    }
}
