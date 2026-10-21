<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Domain\DomainException;
use App\Repositories\BankRepository;
use App\Services\ActivityLogger;
use App\Services\ReferenceGenerator;

/**
 * Référentiel des banques et de leurs agences (Référentiels → Banques).
 * Consultation : tout utilisateur interne ; gestion : banks.manage.
 * Une banque / agence utilisée (licences) ou une banque ayant des agences ne se supprime pas : on la désactive.
 */
final class BankController extends Controller
{
    /** SWIFT / BIC : 8 ou 11 caractères (banque, pays, localité, agence optionnelle). */
    private const SWIFT = '/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/';

    public function index(Request $request): Response
    {
        $this->authorize('orders.view');
        return $this->view('banks/index', ['title' => t('nav.banks'), 'banks' => (new BankRepository())->all()]);
    }

    public function show(Request $request): Response
    {
        $this->authorize('orders.view');
        $repo = new BankRepository();
        $bank = $this->orNotFound($repo->find($request->intParam('id')));
        return $this->view('banks/show', ['title' => $bank['name'], 'bank' => $bank, 'branches' => $repo->branches((int) $bank['id'])]);
    }

    public function store(Request $request): Response
    {
        $this->authorize('banks.manage');
        return $this->saveBank($request, null);
    }

    public function update(Request $request): Response
    {
        $this->authorize('banks.manage');
        return $this->saveBank($request, $this->orNotFound((new BankRepository())->find($request->intParam('id'))));
    }

    public function delete(Request $request): Response
    {
        $this->authorize('banks.manage');
        $repo = new BankRepository();
        $bank = $this->orNotFound($repo->find($request->intParam('id')));
        if ((int) $bank['branch_count'] > 0 || (int) $bank['usage_count'] > 0) {
            return $this->domainError('/banks', new DomainException('banks.error.in_use'));
        }
        Database::transaction(function () use ($repo, $bank) {
            $repo->deleteBank((int) $bank['id']);
            ActivityLogger::log('bank', (int) $bank['id'], 'delete', array_intersect_key($bank, array_flip(['code', 'name', 'swift'])), null);
        });
        return $this->success('/banks', 'banks.deleted');
    }

    public function storeBranch(Request $request): Response
    {
        $this->authorize('banks.manage');
        $bank = $this->orNotFound((new BankRepository())->find($request->intParam('id')));
        return $this->saveBranch($request, $bank, null);
    }

    public function updateBranch(Request $request): Response
    {
        $this->authorize('banks.manage');
        $repo = new BankRepository();
        $branch = $this->orNotFound($repo->findBranch($request->intParam('branch_id')));
        $bank = $this->orNotFound($repo->find($request->intParam('id')));
        if ((int) $branch['bank_id'] !== (int) $bank['id']) {
            throw new \App\Core\HttpException(404);
        }
        return $this->saveBranch($request, $bank, $branch);
    }

    public function deleteBranch(Request $request): Response
    {
        $this->authorize('banks.manage');
        $repo = new BankRepository();
        $branch = $this->orNotFound($repo->findBranch($request->intParam('branch_id')));
        $back = '/banks/' . (int) $branch['bank_id'];
        if ((int) $branch['bank_id'] !== $request->intParam('id')) {
            throw new \App\Core\HttpException(404);
        }
        if ((int) $branch['usage_count'] > 0) {
            return $this->domainError($back, new DomainException('banks.error.branch_in_use', ['n' => (int) $branch['usage_count']]));
        }
        Database::transaction(function () use ($repo, $branch) {
            $repo->deleteBranch((int) $branch['id']);
            ActivityLogger::log('bank_branch', (int) $branch['id'], 'delete', array_intersect_key($branch, array_flip(['bank_id', 'code', 'name', 'city'])), null);
        });
        return $this->success($back, 'banks.branch_deleted');
    }

    private function saveBank(Request $request, ?array $before): Response
    {
        $repo = new BankRepository();
        $input = $request->all();
        $input['swift'] = strtoupper(preg_replace('/\s+/', '', (string) ($input['swift'] ?? '')) ?? '');
        $id = $before ? (int) $before['id'] : null;
        $back = $before ? '/banks/' . $id : '/banks';
        $v = new Validator($input, [
            'code' => 'max:20', 'name' => 'required|max:160', 'short_name' => 'max:40', 'swift' => 'max:11', 'country' => 'max:80',
            'phone' => 'max:60', 'email' => 'email|max:190', 'website' => 'max:190', 'notes' => 'max:500',
        ]);
        if (!isset($v->errors()['name']) && $repo->bankExists('name', trim((string) $input['name']), $id)) {
            $v->addError('name', t('validation.unique'));
        }
        if ($input['swift'] !== '' && !isset($v->errors()['swift']) && !preg_match(self::SWIFT, $input['swift'])) {
            $v->addError('swift', t('banks.error.swift'));
        }
        if ($v->fails()) {
            return $this->backWithErrors($back, $v->errors(), $input);
        }
        $data = [
            'name' => trim((string) $input['name']), 'short_name' => self::nul($input['short_name'] ?? null), 'swift' => self::nul($input['swift']),
            'country' => self::nul($input['country'] ?? null), 'phone' => self::nul($input['phone'] ?? null), 'email' => self::nul($input['email'] ?? null),
            'website' => self::nul($input['website'] ?? null), 'notes' => self::nul($input['notes'] ?? null),
            'active' => isset($input['active']) || $before === null ? 1 : 0,
        ];
        try {
            $newId = Database::transaction(function () use ($repo, $data, $id, $before, $input) {
                $data = ['code' => ReferenceGenerator::resolve('bank', $input['code'] ?? '', $input['code_suggested'] ?? null, [], $id)] + $data;
                $newId = $repo->saveBank($data, $id);
                ActivityLogger::log('bank', $newId, $before ? 'update' : 'create', $before ? array_intersect_key($before, $data) : null, $data);
                return $newId;
            });
        } catch (DomainException $e) {
            return $this->backWithErrors($back, ['code' => t($e->getMessage(), $e->params)], $input);
        }
        // Création : on ouvre la fiche pour saisir ses agences ; modification : retour là où elle a été faite
        $after = $before && ($input['back'] ?? '') === 'list' ? '/banks' : '/banks/' . $newId;
        return $this->success($after, $before ? 'flash.updated' : 'banks.created');
    }

    private function saveBranch(Request $request, array $bank, ?array $before): Response
    {
        $repo = new BankRepository();
        $input = $request->all();
        $bankId = (int) $bank['id'];
        $id = $before ? (int) $before['id'] : null;
        $back = '/banks/' . $bankId;
        $input['code'] = strtoupper(trim((string) ($input['code'] ?? '')));
        $v = new Validator($input, [
            'name' => 'required|max:160', 'code' => 'max:20', 'city' => 'max:120', 'address' => 'max:255',
            'phone' => 'max:60', 'email' => 'email|max:190', 'contact_name' => 'max:120',
        ]);
        if (!isset($v->errors()['name']) && $repo->branchExists($bankId, 'name', trim((string) $input['name']), $id)) {
            $v->addError('name', t('validation.unique'));
        }
        if ($input['code'] !== '' && !isset($v->errors()['code']) && $repo->branchExists($bankId, 'code', $input['code'], $id)) {
            $v->addError('code', t('validation.unique'));
        }
        if ($v->fails()) {
            return $this->backWithErrors($back, $v->errors(), $input);
        }
        $data = [
            'bank_id' => $bankId, 'code' => self::nul($input['code']), 'name' => trim((string) $input['name']),
            'city' => self::nul($input['city'] ?? null), 'address' => self::nul($input['address'] ?? null), 'phone' => self::nul($input['phone'] ?? null),
            'email' => self::nul($input['email'] ?? null), 'contact_name' => self::nul($input['contact_name'] ?? null),
            'active' => isset($input['active']) || $before === null ? 1 : 0,
        ];
        Database::transaction(function () use ($repo, $data, $id, $before) {
            $newId = $repo->saveBranch($data, $id);
            ActivityLogger::log('bank_branch', $newId, $before ? 'update' : 'create', $before ? array_intersect_key($before, $data) : null, $data);
        });
        return $this->success($back, $before ? 'flash.updated' : 'banks.branch_created');
    }
}
