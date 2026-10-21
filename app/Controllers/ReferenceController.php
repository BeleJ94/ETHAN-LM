<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ReferenceGenerator;

/** Prochaine référence proposée (bouton ↻ et changement de famille / site). Lecture seule. */
final class ReferenceController extends Controller
{
    public function next(Request $request): Response
    {
        $this->user();
        $type = (string) $request->query('type', '');
        if (!isset(ReferenceGenerator::TYPES[$type])) {
            throw new HttpException(404);
        }
        $ctx = array_filter([
            'family_id' => (int) $request->query('family_id', 0),
            'site_id' => (int) $request->query('site_id', 0),
        ]);
        return Response::json(['value' => ReferenceGenerator::suggest($type, $ctx)]);
    }
}
