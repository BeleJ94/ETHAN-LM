<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DataTable;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Domain\DomainException;
use App\Repositories\ItemRepository;
use App\Repositories\LookupRepository;
use App\Repositories\MovementRepository;
use App\Repositories\WarehouseRepository;
use App\Services\StockService;

final class MovementController extends Controller
{
    public const KINDS = ['in', 'out', 'transfer', 'adjust'];

    public function index(Request $request): Response
    {
        $this->authorize('stock.view');
        return $this->view('movements/index', [
            'title' => t('nav.movements'),
            'warehouses' => (new WarehouseRepository())->scoped(true),
            'type' => (string) $request->query('type', ''),
            'period' => (string) $request->query('period', ''),
        ]);
    }

    public function data(Request $request): Response
    {
        $this->authorize('stock.view');
        $dt = new DataTable($request, MovementRepository::SORTABLE, 'm.moved_at DESC');
        $filters = [];
        foreach (['warehouse_id', 'item_id', 'type', 'period'] as $key) {
            $filters[$key] = (string) $request->query($key, '');
        }
        [$total, $filtered, $rows] = (new MovementRepository())->datatable($dt, $filters);
        return $dt->response($total, $filtered, $rows);
    }

    public function create(Request $request): Response
    {
        $kind = (string) $request->query('type', 'in');
        if (!in_array($kind, self::KINDS, true)) {
            $kind = 'in';
        }
        $this->authorize($kind === 'adjust' ? 'stock.adjust' : 'stock.move');
        $itemId = (int) $request->query('item_id', 0);
        $lookups = new LookupRepository();
        return $this->view('movements/form', [
            'title' => t('movements.new.' . $kind),
            'kind' => $kind,
            'item' => $itemId ? (new ItemRepository())->find($itemId) : null,
            'warehouses' => (new WarehouseRepository())->scoped(true),
            'currencies' => $lookups->currencies(),
            'rates' => $lookups->latestRates(),
        ]);
    }

    public function store(Request $request): Response
    {
        $input = $request->all();
        $kind = (string) ($input['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) {
            $kind = 'in';
        }
        $this->authorize($kind === 'adjust' ? 'stock.adjust' : 'stock.move');
        $back = '/movements/new?' . http_build_query(['type' => $kind, 'item_id' => $input['item_id'] ?? '']);

        $rules = [
            'item_id' => 'required|int',
            'warehouse_id' => 'required|int',
            'moved_at' => 'required|date',
            'note' => 'max:500',
            'reference' => 'max:30',
        ];
        $rules['quantity'] = $kind === 'adjust' ? 'required|numeric|min:0' : 'required|numeric|gt:0';
        if ($kind === 'in') {
            $rules += ['unit_cost' => 'required|numeric|min:0', 'currency_code' => 'required|max:3', 'exchange_rate' => 'required|numeric|gt:0'];
        }
        if ($kind === 'transfer') {
            $rules['dest_warehouse_id'] = 'required|int';
        }
        if ($kind === 'adjust') {
            $rules['note'] = 'required|max:500';
        }
        $v = new Validator($input, $rules);

        // Périmètre : chaque entrepôt doit appartenir aux sites de l'utilisateur.
        $warehouses = new WarehouseRepository();
        foreach (['warehouse_id', 'dest_warehouse_id'] as $field) {
            if (isset($rules[$field]) && !isset($v->errors()[$field]) && $warehouses->findScoped((int) $input[$field]) === null) {
                $v->addError($field, t('validation.in'));
            }
        }
        if ($kind === 'in' && !isset($v->errors()['currency_code']) && !(new LookupRepository())->currencyExists((string) $input['currency_code'])) {
            $v->addError('currency_code', t('validation.in'));
        }
        if (!isset($v->errors()['item_id']) && (new ItemRepository())->find((int) $input['item_id']) === null) {
            $v->addError('item_id', t('validation.required'));
        }
        if ($v->fails()) {
            return $this->backWithErrors($back, $v->errors(), $input);
        }

        // Date saisie (jour) + heure courante pour garder l'ordre chronologique dans la journée.
        $movedAt = $input['moved_at'] . ' ' . date('H:i:s');
        $itemId = (int) $input['item_id'];
        $whId = (int) $input['warehouse_id'];
        $qty = (float) $input['quantity'];
        $note = ($input['note'] ?? '') ?: null;
        $stock = new StockService();
        [$refIn, $refSug] = [(string) ($input['reference'] ?? ''), (string) ($input['reference_suggested'] ?? '')];

        try {
            $ref = match ($kind) {
                'in' => $stock->receive($itemId, $whId, $qty, (float) $input['unit_cost'], (string) $input['currency_code'], (float) $input['exchange_rate'], $movedAt, $note, [], $refIn, $refSug),
                'out' => $stock->issue($itemId, $whId, $qty, $movedAt, $note, [], $refIn, $refSug),
                'transfer' => $stock->transfer($itemId, $whId, (int) $input['dest_warehouse_id'], $qty, $movedAt, $note, $refIn, $refSug),
                'adjust' => $stock->adjust($itemId, $whId, $qty, $movedAt, (string) $note, $refIn, $refSug),
            };
        } catch (DomainException $e) {
            $field = str_starts_with($e->getMessage(), 'reference.') ? 'reference' : 'quantity';
            return $this->backWithErrors($back, [$field => t($e->getMessage(), $e->params)], $input, t($e->getMessage(), $e->params));
        }

        if ($ref === null) {
            return $this->success("/items/$itemId", 'movements.no_change');
        }
        return $this->success("/items/$itemId", 'movements.recorded', ['ref' => $ref]);
    }
}
