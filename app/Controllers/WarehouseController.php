<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\SiteRepository;
use App\Repositories\WarehouseRepository;
use App\Security\SiteScope;
use App\Services\ActivityLogger;
use App\Services\ReferenceGenerator;

final class WarehouseController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('stock.view');
        return $this->view('warehouses/index', [
            'title' => t('nav.warehouses'),
            'warehouses' => (new WarehouseRepository())->scoped(),
            'sites' => (new SiteRepository())->allowed(),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->authorize('warehouses.manage');
        return $this->save($request, null);
    }

    public function update(Request $request): Response
    {
        $this->authorize('warehouses.manage');
        $before = $this->orNotFound((new WarehouseRepository())->findScoped($request->intParam('id')));
        return $this->save($request, $before);
    }

    private function save(Request $request, ?array $before): Response
    {
        $repo = new WarehouseRepository();
        $input = $request->all();
        $v = new Validator($input, ['site_id' => 'required|int', 'code' => 'max:20', 'name' => 'required|max:120']);
        $id = $before ? (int) $before['id'] : null;
        if (!$v->fails() && !SiteScope::allows((int) $input['site_id'])) {
            $v->addError('site_id', t('validation.in'));
        }
        if ($v->fails()) {
            return $this->backWithErrors('/warehouses', $v->errors(), $input, implode(' ', $v->errors()));
        }
        $data = [
            'site_id' => (int) $input['site_id'],
            'name' => $input['name'],
            'active' => isset($input['active']) || $before === null ? 1 : 0,
        ];
        try {
            Database::transaction(function () use ($repo, $data, $id, $before, $input) {
            $data['code'] = ReferenceGenerator::resolve('warehouse', $input['code'] ?? '', $input['code_suggested'] ?? null, ['site_id' => $data['site_id']], $id);
            $newId = $repo->save($data, $id);
            ActivityLogger::log('warehouse', $newId, $before ? 'update' : 'create', $before ? array_intersect_key($before, $data) : null, $data);
            });
        } catch (\App\Domain\DomainException $e) {
            return $this->domainError('/warehouses', $e, [], 'code');
        }
        return $this->success('/warehouses', $before ? 'flash.updated' : 'flash.created');
    }
}
