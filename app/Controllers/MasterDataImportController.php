<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Domain\DomainException;
use App\Services\MasterData\ReferentielImportService;

/**
 * Administration → Import des référentiels : déposer la fiche de collecte remplie en mode « mise à jour » ou « réinitialisation »
 * → simulation (rapport) → confirmation. Les comptes utilisateurs ne sont jamais modifiés.
 * Le fichier déposé et son rapport sont rattachés à la session de l'administrateur qui l'a déposé.
 */
final class MasterDataImportController extends Controller
{
    private const TEMPLATE = __DIR__ . '/../../docs/Referentiels-ETHAN-LM.xlsx';

    public function index(Request $request): Response
    {
        $this->authorize('settings.import');
        return $this->view('masterdata/import', [
            'title' => t('masterdata.title'),
            'maxMb' => ReferentielImportService::MAX_BYTES / 1024 / 1024,
            'pending' => Session::get('masterdata_import'),
            'report' => Session::get('masterdata_report'),
            'hasTemplate' => is_file(self::TEMPLATE),
        ]);
    }

    public function upload(Request $request): Response
    {
        $this->authorize('settings.import');
        $this->discardPending();
        $mode = (string) ($request->all()['mode'] ?? 'update');
        if (!in_array($mode, ReferentielImportService::MODES, true)) {
            $mode = 'update';
        }
        try {
            $service = new ReferentielImportService();
            $token = $service->upload($_FILES['file'] ?? []);
            Session::set('masterdata_import', ['token' => $token, 'mode' => $mode]);
            Session::set('masterdata_report', $service->run($token, $mode, false));
        } catch (DomainException $e) {
            return $this->domainError('/settings/import', $e);
        }
        return Response::redirect('/settings/import');
    }

    public function confirm(Request $request): Response
    {
        $this->authorize('settings.import');
        $pending = Session::get('masterdata_import');
        if (!is_array($pending)) {
            return $this->domainError('/settings/import', new DomainException('masterdata.error.expired'));
        }
        ['token' => $token, 'mode' => $mode] = $pending;
        // Réinitialisation : confirmation tapée, comme bin/reset.php
        if ($mode === 'reset' && trim((string) ($request->all()['confirm_word'] ?? '')) !== 'REINITIALISER') {
            return $this->domainError('/settings/import', new DomainException('masterdata.error.confirm_word'));
        }
        try {
            $report = (new ReferentielImportService())->run($token, $mode, true);
        } catch (DomainException $e) {
            $this->discardPending();
            return $this->domainError('/settings/import', $e);
        }
        Session::set('masterdata_report', $report);
        if (!$report['committed']) {
            Session::flash('error', t('masterdata.not_committed'));
            return Response::redirect('/settings/import');
        }
        ReferentielImportService::discard($token);
        Session::forget('masterdata_import');
        return $this->success('/settings/import', 'masterdata.done', [
            'created' => array_sum(array_column($report['sheets'], 'created')),
            'updated' => array_sum(array_map(fn ($s) => count($s['updated']), $report['sheets'])),
        ]);
    }

    public function cancel(Request $request): Response
    {
        $this->authorize('settings.import');
        $this->discardPending();
        Session::forget('masterdata_report');
        return Response::redirect('/settings/import');
    }

    /** Fiche de collecte vierge à faire remplir. */
    public function template(Request $request): Response
    {
        $this->authorize('settings.import');
        if (!is_file(self::TEMPLATE)) {
            throw new HttpException(404);
        }
        return new Response((string) file_get_contents(self::TEMPLATE), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Referentiels-ETHAN-LM.xlsx"',
            'Cache-Control' => 'private, max-age=0',
        ]);
    }

    private function discardPending(): void
    {
        $pending = Session::get('masterdata_import');
        if (is_array($pending)) {
            try {
                ReferentielImportService::discard((string) $pending['token']);
            } catch (DomainException) {
            }
        }
        Session::forget('masterdata_import');
    }
}
