<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\CockpitDrill;
use App\Repositories\CockpitRepository;

final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        // Un transitaire n'a pas de tableau de bord : il arrive sur son portail.
        if (!$this->user()->can('dashboard.view') && $this->user()->can('portal.access')) {
            return Response::redirect('/portal');
        }
        $this->authorize('dashboard.view');
        $cockpit = new CockpitRepository();
        return $this->view('dashboard/index', [
            'title' => t('nav.dashboard'),
            'todo' => $cockpit->todo($this->user()),
            'tiles' => $cockpit->tiles(),
            'pipeline' => $cockpit->pipeline(),
            'charts' => $cockpit->charts(),
            'deliveries' => $cockpit->activeDeliveries(),
            'licences' => $cockpit->licences(),
            'activity' => $cockpit->activity(),
            'missingRates' => $cockpit->missingRates(),
        ]);
    }

    /** Détail d'un indicateur (modale du cockpit) : colonnes + lignes en JSON. */
    public function drill(Request $request): Response
    {
        // Le détail des pastilles du menu est visible de tout utilisateur qui voit ces rubriques.
        $key = (string) $request->query('key');
        $this->authorize(match ($key) { 'nav' => 'orders.view', 'invoices', 'aging' => 'finance.view', default => 'dashboard.view' });
        $data = (new CockpitDrill($this->user()))->get((string) $request->query('key', ''), (string) $request->query('param', ''));
        return $data === null ? Response::json(['error' => 'not_found'], 404) : Response::json($data);
    }
}
