<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\DataTable;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Domain\DomainException;
use App\Repositories\FamilyRepository;
use App\Repositories\ItemRepository;
use App\Repositories\LookupRepository;
use App\Repositories\MovementRepository;
use App\Repositories\WarehouseRepository;
use App\Services\ActivityLogger;
use App\Services\ReferenceGenerator;

final class ItemController extends Controller
{
    private ItemRepository $items;

    public function __construct()
    {
        $this->items = new ItemRepository();
    }

    public function index(Request $request): Response
    {
        $this->authorize('stock.view');
        $lookups = new LookupRepository();
        return $this->view('items/index', [
            'title' => t('nav.items'),
            'categories' => $lookups->categories(),
            'families' => (new FamilyRepository())->all(),
            'familyId' => (string) $request->query('family_id', ''),
            'warehouses' => (new WarehouseRepository())->scoped(true),
            'status' => (string) $request->query('status', ''),
        ]);
    }

    public function data(Request $request): Response
    {
        $this->authorize('stock.view');
        $dt = new DataTable($request, ItemRepository::SORTABLE, 'i.sku ASC');
        [$total, $filtered, $rows] = $this->items->datatable($dt, array_map('strval', array_filter([
            'category_id' => $request->query('category_id'),
            'family_id' => $request->query('family_id'),
            'warehouse_id' => $request->query('warehouse_id'),
            'status' => $request->query('status'),
        ], 'is_string')));
        return $dt->response($total, $filtered, $rows);
    }

    public function search(Request $request): Response
    {
        $this->authorize('stock.view');
        $term = trim((string) $request->query('q', ''));
        return Response::json(mb_strlen($term) < 2 ? [] : $this->items->search($term));
    }

    public function show(Request $request): Response
    {
        $this->authorize('stock.view');
        $item = $this->orNotFound($this->items->find($request->intParam('id')));
        $levels = $this->items->levels((int) $item['id']);
        return $this->view('items/show', [
            'title' => $item['sku'],
            'item' => $item,
            'levels' => $levels,
            'total' => array_sum(array_map(fn ($l) => (float) $l['quantity'], $levels)),
            'movements' => (new MovementRepository())->recent(15, (int) $item['id']),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('items.manage');
        return $this->form(null);
    }

    public function edit(Request $request): Response
    {
        $this->authorize('items.manage');
        return $this->form($this->orNotFound($this->items->find($request->intParam('id'))));
    }

    public function store(Request $request): Response
    {
        $this->authorize('items.manage');
        return $this->save($request, null);
    }

    public function update(Request $request): Response
    {
        $this->authorize('items.manage');
        $before = $this->orNotFound($this->items->find($request->intParam('id')));
        return $this->save($request, $before);
    }

    private function form(?array $item): Response
    {
        $lookups = new LookupRepository();
        return $this->view('items/form', [
            'title' => $item ? t('items.edit') : t('items.new'),
            'item' => $item,
            'units' => $lookups->units(),
            'categories' => $lookups->categories(),
            'families' => (new FamilyRepository())->all(),
        ]);
    }

    /** @param array<string,mixed>|null $before */
    private function save(Request $request, ?array $before): Response
    {
        $input = $request->all();
        $v = new Validator($input, [
            'sku' => 'max:40',
            'name' => 'required|max:190',
            'unit_id' => 'required|int',
            'family_id' => 'required|int',
            'min_stock' => 'numeric|min:0',
            'hs_code' => 'max:20',
            'category_name' => 'max:120',
        ]);
        $id = $before ? (int) $before['id'] : null;
        $families = new FamilyRepository();
        $familyChanged = !$before || (int) $before['family_id'] !== (int) ($input['family_id'] ?? 0);
        if (!isset($v->errors()['family_id']) && $familyChanged && !$families->isActive((int) $input['family_id'])) {
            $v->addError('family_id', t('validation.in'));
        }
        $back = $id ? "/items/$id/edit" : '/items/new';
        if ($v->fails()) {
            return $this->backWithErrors($back, $v->errors(), $input);
        }

        try {
            $id = $this->persist($input, $before, $id);
        } catch (DomainException $e) {
            return $this->backWithErrors($back, ['sku' => t($e->getMessage(), $e->params)], $input);
        }
        return $this->success("/items/$id", $before ? 'flash.updated' : 'flash.created');
    }

    /** @param array<string,mixed>|null $before */
    private function persist(array $input, ?array $before, ?int $id): int
    {
        return Database::transaction(function () use ($input, $before, $id) {
            $lookups = new LookupRepository();
            $categoryId = ($input['category_id'] ?? '') !== '' ? (int) $input['category_id'] : null;
            if (($input['category_name'] ?? '') !== '') {
                $categoryId = $lookups->createCategory($input['category_name']);
            }
            $data = [
                'sku' => ReferenceGenerator::resolve('item', $input['sku'] ?? '', $input['sku_suggested'] ?? null, ['family_id' => (int) $input['family_id']], $id),
                'name' => $input['name'],
                'description' => ($input['description'] ?? '') ?: null,
                'category_id' => $categoryId,
                'family_id' => (int) $input['family_id'],
                'unit_id' => (int) $input['unit_id'],
                'hs_code' => ($input['hs_code'] ?? '') ?: null,
                'min_stock' => (float) (($input['min_stock'] ?? '') ?: 0),
                'active' => isset($input['active']) || $before === null ? 1 : 0,
            ];
            $newId = $this->items->save($data, $id);
            ActivityLogger::log('item', $newId, $before ? 'update' : 'create', $before ? array_intersect_key($before, $data) : null, $data);
            return $newId;
        });
    }
}
