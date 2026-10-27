<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\SiteRepository;
use App\Repositories\UserRepository;
use App\Security\Role;
use App\Services\ActivityLogger;

final class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('users.manage');
        return $this->view('users/index', ['title' => t('nav.users'), 'users' => (new UserRepository())->all()]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('users.manage');
        $prefill = null;
        if ($request->query('role') === 'forwarder' && (int) $request->query('forwarder_id', 0) > 0) {
            $prefill = ['role' => 'forwarder', 'forwarder_id' => (int) $request->query('forwarder_id')];
        }
        return $this->form(null, $prefill ?? []);
    }

    public function edit(Request $request): Response
    {
        $this->authorize('users.manage');
        return $this->form($this->orNotFound((new UserRepository())->find($request->intParam('id'))));
    }

    public function store(Request $request): Response
    {
        $this->authorize('users.manage');
        return $this->save($request, null);
    }

    public function update(Request $request): Response
    {
        $this->authorize('users.manage');
        return $this->save($request, $this->orNotFound((new UserRepository())->find($request->intParam('id'))));
    }

    /** @param array{role?:string,forwarder_id?:int} $prefill création pré-remplie (accès portail depuis la fiche d'une agence) */
    private function form(?array $user, array $prefill = []): Response
    {
        return $this->view('users/form', [
            'title' => $user ? t('users.edit') : t('users.new'),
            'editUser' => $user,
            'sites' => (new SiteRepository())->allowed(false),
            'roles' => Role::cases(),
            'forwarders' => DeliveryController::forwarders(),
            'prefill' => $prefill,
        ]);
    }

    private function save(Request $request, ?array $before): Response
    {
        $repo = new UserRepository();
        $input = $request->all();
        $input['email'] = mb_strtolower((string) ($input['email'] ?? ''));
        $id = $before ? (int) $before['id'] : null;
        $roles = implode(',', array_map(fn (Role $r) => $r->value, Role::cases()));
        $v = new Validator($input, [
            'name' => 'required|max:120',
            'email' => 'required|email|max:190',
            'role' => 'required|in:' . $roles,
            'password' => ($before ? '' : 'required|') . 'max:200',
        ]);
        $password = (string) ($input['password'] ?? '');
        if ($password !== '' && mb_strlen($password) < 10) {
            $v->addError('password', t('users.error.password_short', ['n' => mb_strlen($password)]));
        }
        if ($password !== ($input['password_confirm'] ?? '')) {
            $v->addError('password_confirm', t($password === '' ? 'users.error.confirm_without_password' : 'users.error.password_mismatch'));
        }
        // Un compte transitaire est rattaché à son entreprise (fournisseur marqué « transitaire ») : c'est son périmètre.
        $forwarderId = self::nul($input['forwarder_id'] ?? null) !== null ? (int) $input['forwarder_id'] : null;
        if (($input['role'] ?? '') === 'forwarder' && ($forwarderId === null || !in_array($forwarderId, array_map('intval', array_column(DeliveryController::forwarders(), 'id')), true))) {
            $v->addError('forwarder_id', t('users.forwarder_required'));
        }
        if (!isset($v->errors()['email']) && $repo->emailExists($input['email'], $id)) {
            $stmt = Database::pdo()->prepare('SELECT name FROM users WHERE email = ? AND id <> ?');
            $stmt->execute([$input['email'], $id ?? 0]);
            $v->addError('email', t('users.error.email_taken', ['email' => $input['email'], 'name' => (string) $stmt->fetchColumn()]));
        }
        // Empêche l'administrateur de se retirer lui-même ses droits ou de se désactiver.
        if ($id === $this->user()->id) {
            if (($input['role'] ?? '') !== 'admin') {
                $v->addError('role', t('users.error.self_demote'));
            }
            if (!isset($input['active'])) {
                $v->addError('active', t('users.error.self_deactivate'));
            }
        }
        if ($v->fails()) {
            return $this->backWithErrors($id ? "/users/$id/edit" : '/users/new', $v->errors(), $input, $this->summary($v->errors(), $before));
        }

        $siteIds = array_map('intval', (array) ($input['site_ids'] ?? []));
        $data = [
            'name' => $input['name'],
            'email' => $input['email'],
            'role' => $input['role'],
            'forwarder_id' => $input['role'] === 'forwarder' ? $forwarderId : null,
            'active' => isset($input['active']) || $before === null ? 1 : 0,
            'password' => ($input['password'] ?? '') ?: null,
        ];
        Database::transaction(function () use ($repo, $data, $siteIds, $id, $before) {
            $newId = $repo->save($data, $siteIds, $id);
            // Mot de passe : seul le fait qu'il change est journalisé, jamais sa valeur.
            $logged = array_diff_key($data, ['password' => 1]) + ['site_ids' => implode(',', $siteIds)]
                + ($data['password'] !== null ? ['password' => $before ? 'changed' : 'set'] : []);
            // site_ids « avant » en texte (et non le tableau de find()) : à placer en premier, « + » ne remplace pas une clé existante.
            $old = $before ? ['site_ids' => implode(',', $before['site_ids']), 'password' => ''] + array_intersect_key($before, $logged) : null;
            ActivityLogger::log('user', $newId, $before ? 'update' : 'create', $old, $logged);
        });
        return $this->success('/users', $before ? 'flash.updated' : 'flash.created');
    }

    /**
     * Message d'ensemble : compte concerné, nombre de points à corriger, puis « Champ : problème » pour chacun.
     * @param array<string,string> $errors
     */
    private function summary(array $errors, ?array $before): string
    {
        $labels = [
            'name' => 'users.col.name', 'email' => 'auth.email', 'role' => 'users.col.role', 'forwarder_id' => 'users.forwarder',
            'password' => 'auth.password', 'password_confirm' => 'users.password_confirm', 'active' => 'common.status',
        ];
        $lines = [];
        foreach ($errors as $field => $message) {
            $lines[] = (isset($labels[$field]) ? t($labels[$field]) . ' : ' : '') . $message;
        }
        $head = $before
            ? t('users.error.summary_update', ['name' => $before['name'], 'n' => count($errors)])
            : t('users.error.summary_create', ['n' => count($errors)]);
        return $head . ' ' . implode(' · ', $lines);
    }
}
