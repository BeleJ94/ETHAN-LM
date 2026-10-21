<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\SiteRepository;
use App\Services\ActivityLogger;
use App\Services\ReferenceGenerator;

final class SiteController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('sites.manage');
        return $this->view('sites/index', ['title' => t('nav.sites'), 'sites' => (new SiteRepository())->allowed(false)]);
    }

    public function store(Request $request): Response
    {
        $this->authorize('sites.manage');
        return $this->save($request, null);
    }

    public function update(Request $request): Response
    {
        $this->authorize('sites.manage');
        return $this->save($request, $this->orNotFound((new SiteRepository())->find($request->intParam('id'))));
    }

    private function save(Request $request, ?array $before): Response
    {
        $repo = new SiteRepository();
        $input = $request->all();
        $v = new Validator($input, ['code' => 'max:20', 'name' => 'required|max:120', 'city' => 'max:120']);
        $id = $before ? (int) $before['id'] : null;
        if ($v->fails()) {
            return $this->backWithErrors('/sites', $v->errors(), $input, implode(' ', $v->errors()));
        }
        $data = [
            'name' => $input['name'],
            'city' => ($input['city'] ?? '') ?: null,
            'active' => isset($input['active']) || $before === null ? 1 : 0,
        ];
        try {
            Database::transaction(function () use ($repo, $data, $id, $before, $input) {
            $data = ['code' => ReferenceGenerator::resolve('site', $input['code'] ?? '', $input['code_suggested'] ?? null, [], $id)] + $data;
            $newId = $repo->save($data, $id);
            ActivityLogger::log('site', $newId, $before ? 'update' : 'create', $before ? array_intersect_key($before, $data) : null, $data);
            });
        } catch (\App\Domain\DomainException $e) {
            return $this->domainError('/sites', $e, [], 'code');
        }
        return $this->success('/sites', $before ? 'flash.updated' : 'flash.created');
    }
}
