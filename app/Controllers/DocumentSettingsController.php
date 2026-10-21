<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Domain\Checklist;
use App\Domain\DomainException;
use App\Repositories\DocumentRepository;
use App\Repositories\FamilyRepository;
use App\Services\ActivityLogger;
use App\Services\ReferenceGenerator;

/** Paramètres → Documents d'expédition : types de documents et règles de check-list. */
final class DocumentSettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('documents.configure');
        $repo = new DocumentRepository();
        $rules = $repo->rules();
        $families = (new FamilyRepository())->all();
        // Chevauchements par règle active (pastille dans le tableau)
        $overlaps = [];
        foreach ($rules as $r) {
            if ($r['active']) {
                $overlaps[(int) $r['id']] = Checklist::overlaps($r, $rules);
            }
        }
        return $this->view('documents/settings', [
            'title' => t('nav.documents_settings'),
            'types' => $repo->types(),
            'rules' => $rules,
            'families' => $families,
            'overlaps' => $overlaps,
            'sim' => self::simulation($request, $repo, $families),
            'tab' => (string) $request->query('tab', 'rules'),
        ]);
    }

    /** Simulateur rafraîchi en direct (fragment HTML). */
    public function simulate(Request $request): Response
    {
        $this->authorize('documents.configure');
        $repo = new DocumentRepository();
        return Response::html(\App\Core\View::partial('documents/_simulation', ['sim' => self::simulation($request, $repo, (new FamilyRepository())->all())]));
    }

    /**
     * Check-list qu'obtiendrait une expédition (même moteur que les expéditions réelles).
     * @return array{mode:string,family_id:?int,transport:?string,items:list<array<string,mixed>>}
     */
    private static function simulation(Request $request, DocumentRepository $repo, array $families): array
    {
        $mode = (string) $request->query('sim_mode', 'import');
        $mode = in_array($mode, Checklist::MODES, true) ? $mode : 'import';
        $family = (int) $request->query('sim_family', 0);
        $family = in_array($family, array_map(fn ($f) => (int) $f['id'], $families), true) ? $family : null;
        $transport = (string) $request->query('sim_transport', '');
        $transport = in_array($transport, Checklist::TRANSPORTS, true) ? $transport : null;
        $types = [];
        foreach ($repo->types(true) as $tp) {
            $types[(int) $tp['id']] = $tp;
        }
        $explained = Checklist::explain($repo->activeRules(), [
            'mode' => $mode, 'family_ids' => $family ? [$family] : [], 'transport_modes' => $transport ? [$transport] : [],
        ]);
        $items = [];
        foreach ($explained as $typeId => $x) {
            if (isset($types[$typeId])) {
                $items[] = $x + ['type' => $types[$typeId]];
            }
        }
        // Ordre du flux : étape bloquante, puis obligatoires non bloquants, puis facultatifs
        $rank = fn ($i) => $i['blocking_stage'] ? array_search($i['blocking_stage'], Checklist::STAGES, true) : ($i['required'] ? 10 : 20);
        usort($items, fn ($a, $b) => [$rank($a), (int) $a['type']['sort_order']] <=> [$rank($b), (int) $b['type']['sort_order']]);
        return ['mode' => $mode, 'family_id' => $family, 'transport' => $transport, 'items' => $items];
    }

    public function storeType(Request $request): Response
    {
        $this->authorize('documents.configure');
        return $this->saveType($request, null);
    }

    public function updateType(Request $request): Response
    {
        $this->authorize('documents.configure');
        return $this->saveType($request, $this->orNotFound((new DocumentRepository())->findType($request->intParam('id'))));
    }

    public function storeRule(Request $request): Response
    {
        $this->authorize('documents.configure');
        return $this->saveRule($request, null);
    }

    public function updateRule(Request $request): Response
    {
        $this->authorize('documents.configure');
        return $this->saveRule($request, $this->orNotFound((new DocumentRepository())->findRule($request->intParam('id'))));
    }

    /** Une règle supprimée n'affecte pas les check-lists déjà générées (figées). */
    public function deleteRule(Request $request): Response
    {
        $this->authorize('documents.configure');
        $rule = $this->orNotFound((new DocumentRepository())->findRule($request->intParam('id')));
        Database::transaction(function () use ($rule) {
            (new DocumentRepository())->deleteRule((int) $rule['id']);
            ActivityLogger::log('document_rule', (int) $rule['id'], 'delete', $rule, null);
        });
        return $this->success('/documents/settings', 'documents.rule_deleted');
    }

    /** Suppression en lot (cases cochées) : tout ou rien, chaque règle tracée dans le journal. */
    public function bulkDeleteRules(Request $request): Response
    {
        $this->authorize('documents.configure');
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($request->all()['ids'] ?? [])), fn ($i) => $i > 0)));
        if (!$ids) {
            return $this->backWithErrors('/documents/settings', ['ids' => t('documents.bulk_none')], [], t('documents.bulk_none'));
        }
        $repo = new DocumentRepository();
        $rules = array_map(fn ($id) => $repo->findRule($id), $ids);
        if (in_array(null, $rules, true)) {
            return $this->backWithErrors('/documents/settings', ['ids' => t('documents.bulk_stale')], [], t('documents.bulk_stale'));
        }
        Database::transaction(function () use ($repo, $rules) {
            foreach ($rules as $rule) {
                $repo->deleteRule((int) $rule['id']);
                ActivityLogger::log('document_rule', (int) $rule['id'], 'delete', $rule, null);
            }
        });
        return $this->success('/documents/settings', 'documents.bulk_deleted', ['n' => count($rules)]);
    }

    private function saveType(Request $request, ?array $before): Response
    {
        $repo = new DocumentRepository();
        $input = $request->all();
        $input['code'] = ReferenceGenerator::normalize($input['code'] ?? '');
        $id = $before ? (int) $before['id'] : null;
        $v = new Validator($input, [
            'code' => 'required|max:20', 'name' => 'required|max:160', 'description' => 'max:255',
            'provider' => 'required|in:' . implode(',', DocumentRepository::PROVIDERS),
            'level' => 'required|in:' . implode(',', DocumentRepository::LEVELS), 'sort_order' => 'int|min:0',
        ]);
        if (!isset($v->errors()['code']) && (!preg_match(ReferenceGenerator::PATTERN, $input['code']) || $repo->typeCodeExists($input['code'], $id))) {
            $v->addError('code', t('validation.unique'));
        }
        if ($v->fails()) {
            return $this->backWithErrors('/documents/settings?tab=types', $v->errors(), $input, implode(' ', $v->errors()));
        }
        $data = [
            'code' => $input['code'], 'name' => $input['name'], 'description' => self::nul($input['description'] ?? null),
            'provider' => $input['provider'], 'level' => $input['level'],
            'requires_number' => isset($input['requires_number']) ? 1 : 0,
            'requires_file' => isset($input['requires_file']) ? 1 : 0,
            'requires_issue_date' => isset($input['requires_issue_date']) ? 1 : 0,
            'has_expiry' => isset($input['has_expiry']) ? 1 : 0,
            'sort_order' => (int) (($input['sort_order'] ?? '') === '' ? 100 : $input['sort_order']),
            'active' => isset($input['active']) || $before === null ? 1 : 0,
        ];
        Database::transaction(function () use ($repo, $data, $id, $before) {
            $newId = $repo->saveType($data, $id);
            ActivityLogger::log('document_type', $newId, $before ? 'update' : 'create', $before ? array_intersect_key($before, $data) : null, $data);
        });
        return $this->success('/documents/settings?tab=types', $before ? 'flash.updated' : 'flash.created');
    }

    private function saveRule(Request $request, ?array $before): Response
    {
        $repo = new DocumentRepository();
        $input = $request->all();
        // Exigence en un seul choix : facultatif / obligatoire / bloquant à une étape
        if (isset($input['requirement'])) {
            $req = (string) $input['requirement'];
            if ($req === 'optional' || $req === 'required') {
                $input['blocking_stage'] = '';
            }
            if ($req === 'optional') {
                unset($input['required']);
            } else {
                $input['required'] = '1';
            }
        }
        $v = new Validator($input, [
            'requirement' => 'in:optional,required,blocking',
            'document_type_id' => 'required|int',
            'fulfillment_mode' => 'in:' . implode(',', Checklist::MODES),
            'family_id' => 'int',
            'transport_mode' => 'in:' . implode(',', Checklist::TRANSPORTS),
            'blocking_stage' => 'in:' . implode(',', Checklist::STAGES),
            'notes' => 'max:255',
        ]);
        if (!isset($v->errors()['document_type_id']) && !$repo->findType((int) $input['document_type_id'])) {
            $v->addError('document_type_id', t('validation.in'));
        }
        if (($input['requirement'] ?? null) === 'blocking' && self::nul($input['blocking_stage'] ?? null) === null) {
            $v->addError('blocking_stage', t('validation.required'));
        }
        // Doublon exact (même document, mêmes critères, active) : refusé ; un simple chevauchement reste permis
        if (!$v->fails() && (isset($input['active']) || $before === null)) {
            $candidate = [
                'id' => $before['id'] ?? null, 'document_type_id' => (int) $input['document_type_id'],
                'fulfillment_mode' => self::nul($input['fulfillment_mode'] ?? null), 'family_id' => self::nul($input['family_id'] ?? null),
                'transport_mode' => self::nul($input['transport_mode'] ?? null),
            ];
            foreach (Checklist::overlaps($candidate, $repo->rules()) as $o) {
                if ($o['kind'] === 'duplicate') {
                    $v->addError('document_type_id', t('documents.rule_duplicate', ['id' => $o['id']]));
                    break;
                }
            }
        }
        if ($v->fails()) {
            return $this->backWithErrors('/documents/settings', $v->errors(), $input, implode(' ', $v->errors()));
        }
        $data = [
            'document_type_id' => (int) $input['document_type_id'],
            'fulfillment_mode' => self::nul($input['fulfillment_mode'] ?? null),
            'family_id' => self::nul($input['family_id'] ?? null) !== null ? (int) $input['family_id'] : null,
            'transport_mode' => self::nul($input['transport_mode'] ?? null),
            'required' => isset($input['required']) ? 1 : 0,
            'blocking_stage' => self::nul($input['blocking_stage'] ?? null),
            'notes' => self::nul($input['notes'] ?? null),
            'active' => isset($input['active']) || $before === null ? 1 : 0,
        ];
        Database::transaction(function () use ($repo, $data, $before) {
            $newId = $repo->saveRule($data, $before ? (int) $before['id'] : null);
            ActivityLogger::log('document_rule', $newId, $before ? 'update' : 'create', $before ? array_intersect_key($before, $data) : null, $data);
        });
        return $this->success('/documents/settings', $before ? 'flash.updated' : 'flash.created');
    }
}
