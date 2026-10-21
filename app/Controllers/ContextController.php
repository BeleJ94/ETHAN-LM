<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Security\SiteScope;

/** Préférences de session : langue et site de travail. */
final class ContextController extends Controller
{
    public function locale(Request $request): Response
    {
        $locale = (string) $request->input('locale');
        if (in_array($locale, I18n::LOCALES, true)) {
            Session::set('locale', $locale);
        }
        return Response::redirect($this->safeBack($request));
    }

    public function site(Request $request): Response
    {
        $siteId = (int) $request->input('site_id');
        if ($siteId === 0) {
            Session::forget('working_site_id');
        } elseif (SiteScope::allows($siteId)) {
            Session::set('working_site_id', $siteId);
        }
        return Response::redirect($this->safeBack($request));
    }

    /** Retour sur la page d'origine, limité aux chemins internes. */
    private function safeBack(Request $request): string
    {
        $back = (string) $request->input('back', '/');
        return preg_match('#^/[\w\-/]*$#', $back) ? $back : '/';
    }
}
