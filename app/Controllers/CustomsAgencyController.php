<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Domain\DomainException;
use App\Repositories\CustomsAgencyRepository;
use App\Services\ActivityLogger;
use App\Services\ReferenceGenerator;

/**
 * Référentiel des agences en douane / transitaires (Référentiels → Agences en douane).
 * Consultation : utilisateurs internes ; gestion : parties.manage. Une agence ne se supprime pas : elle se désactive
 * (elle reste affichée sur ses expéditions, n'est plus proposée, et ses comptes du portail ne voient plus rien de nouveau).
 */
final class CustomsAgencyController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('orders.view');
        return $this->view('customs_agencies/index', ['title' => t('nav.customs_agencies'), 'agencies' => (new CustomsAgencyRepository())->all()]);
    }

    public function show(Request $request): Response
    {
        $this->authorize('orders.view');
        $repo = new CustomsAgencyRepository();
        $a = $this->orNotFound($repo->find($request->intParam('id')));
        return $this->view('customs_agencies/show', [
            'title' => $a['name'],
            'a' => $a,
            'deliveries' => $repo->deliveries((int) $a['id']),
            'users' => $repo->users((int) $a['id']),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->authorize('parties.manage');
        return $this->save($request, null);
    }

    public function update(Request $request): Response
    {
        $this->authorize('parties.manage');
        return $this->save($request, $this->orNotFound((new CustomsAgencyRepository())->find($request->intParam('id'))));
    }

    private function save(Request $request, ?array $before): Response
    {
        $repo = new CustomsAgencyRepository();
        $input = $request->all();
        $id = $before ? (int) $before['id'] : null;
        $back = $before && ($input['back'] ?? '') !== 'list' ? '/customs-agencies/' . $id : '/customs-agencies';
        $v = new Validator($input, [
            'code' => 'max:20', 'name' => 'required|max:190', 'approval_number' => 'max:60', 'nif' => 'max:60', 'rccm' => 'max:60',
            'address' => 'max:255', 'city' => 'max:120', 'country' => 'max:80', 'contact_name' => 'max:120', 'phone' => 'max:60',
            'email' => 'email|max:190', 'customs_posts' => 'max:255', 'notes' => 'max:500',
        ]);
        if (!isset($v->errors()['name']) && $repo->exists('name', trim((string) $input['name']), $id)) {
            $v->addError('name', t('validation.unique'));
        }
        if ($v->fails()) {
            return $this->backWithErrors($back, $v->errors(), $input);
        }
        $data = ['name' => trim((string) $input['name']), 'active' => isset($input['active']) || $before === null ? 1 : 0];
        foreach (['approval_number', 'nif', 'rccm', 'address', 'city', 'country', 'contact_name', 'phone', 'email', 'customs_posts', 'notes'] as $f) {
            $data[$f] = self::nul($input[$f] ?? null);
        }
        if ($data['approval_number']) {
            $data['approval_number'] = mb_strtoupper($data['approval_number']);
        }
        try {
            $newId = Database::transaction(function () use ($repo, $data, $id, $before, $input) {
                $data = ['code' => ReferenceGenerator::resolve('agency', $input['code'] ?? '', $input['code_suggested'] ?? null, [], $id)] + $data;
                $newId = $repo->save($data, $id);
                ActivityLogger::log('customs_agency', $newId, $before ? 'update' : 'create', $before ? array_intersect_key($before, $data) : null, $data);
                return $newId;
            });
        } catch (DomainException $e) {
            return $this->backWithErrors($back, ['code' => t($e->getMessage(), $e->params)], $input);
        }
        return $this->success($before ? $back : '/customs-agencies/' . $newId, $before ? 'flash.updated' : 'agencies.created');
    }
}
