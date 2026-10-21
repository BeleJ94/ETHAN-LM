<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Domain\DomainException;
use App\Repositories\FamilyRepository;
use App\Services\ActivityLogger;
use App\Services\ReferenceGenerator;

/** CRUD des familles d'articles (Paramètres → Familles d'articles). */
final class FamilyController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('stock.view');
        return $this->view('families/index', [
            'title' => t('nav.families'),
            'families' => (new FamilyRepository())->all(),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->authorize('items.manage');
        return $this->save($request, null);
    }

    public function update(Request $request): Response
    {
        $this->authorize('items.manage');
        return $this->save($request, $this->orNotFound((new FamilyRepository())->find($request->intParam('id'))));
    }

    /** Suppression réservée aux familles jamais utilisées ; sinon on désactive (l'historique reste lisible). */
    public function delete(Request $request): Response
    {
        $this->authorize('items.manage');
        $repo = new FamilyRepository();
        $family = $this->orNotFound($repo->find($request->intParam('id')));
        if ((int) $family['item_count'] > 0) {
            return $this->domainError('/families', new DomainException('families.error.in_use', ['n' => (int) $family['item_count']]));
        }
        Database::transaction(function () use ($repo, $family) {
            $repo->delete((int) $family['id']);
            ActivityLogger::log('item_family', (int) $family['id'], 'delete', array_intersect_key($family, array_flip(['code', 'name', 'color'])), null);
        });
        return $this->success('/families', 'families.deleted');
    }

    private function save(Request $request, ?array $before): Response
    {
        $repo = new FamilyRepository();
        $input = $request->all();
        $id = $before ? (int) $before['id'] : null;
        $v = new Validator($input, [
            'code' => 'max:20',
            'name' => 'required|max:120',
            'description' => 'max:255',
            'color' => 'required|in:' . implode(',', FamilyRepository::COLORS),
            'sort_order' => 'int|min:0',
        ]);
        if (!isset($v->errors()['name']) && $repo->exists('name', (string) $input['name'], $id)) {
            $v->addError('name', t('validation.unique'));
        }
        // Désactiver une famille utilisée est permis : elle disparaît des choix mais reste affichée sur ses articles.
        $active = isset($input['active']) || $before === null ? 1 : 0;
        if ($v->fails()) {
            return $this->backWithErrors('/families', $v->errors(), $input);
        }
        $data = [
            'name' => $input['name'],
            'description' => self::nul($input['description'] ?? null),
            'color' => $input['color'],
            'sort_order' => (int) (($input['sort_order'] ?? '') === '' ? 100 : $input['sort_order']),
            'active' => $active,
        ];
        try {
            Database::transaction(function () use ($repo, $data, $id, $before, $input) {
            $data = ['code' => ReferenceGenerator::resolve('family', $input['code'] ?? '', $input['code_suggested'] ?? null, [], $id)] + $data;
            $newId = $repo->save($data, $id);
            ActivityLogger::log('item_family', $newId, $before ? 'update' : 'create', $before ? array_intersect_key($before, $data) : null, $data);
            });
        } catch (DomainException $e) {
            return $this->backWithErrors('/families', ['code' => t($e->getMessage(), $e->params)], $input);
        }
        return $this->success('/families', $before ? 'flash.updated' : 'flash.created');
    }
}
