<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Security\Auth;
use App\Security\CurrentUser;

abstract class Controller
{
    protected function view(string $template, array $data = [], ?string $layout = 'app'): Response
    {
        return Response::html(View::render($template, $data, $layout));
    }

    protected function user(): CurrentUser
    {
        return Auth::require();
    }

    protected function authorize(string $permission): void
    {
        if (!$this->user()->can($permission)) {
            throw new HttpException(403);
        }
    }

    /** @param array<string,mixed>|null $found */
    protected function orNotFound(?array $found): array
    {
        if ($found === null) {
            throw new HttpException(404);
        }
        return $found;
    }

    protected function success(string $path, string $key, array $params = []): Response
    {
        Session::flash('success', t($key, $params));
        return Response::redirect($path);
    }

    /** Succès + étape suivante du dossier proposée à l'utilisateur ($next = [libellé, chemin]). */
    protected function successNext(string $path, string $key, ?array $next, array $params = []): Response
    {
        if ($next !== null) {
            Session::flash('next', ['label' => $next[0], 'href' => url($next[1])]);
        }
        return $this->success($path, $key, $params);
    }

    /**
     * Page de retour transmise par le lien d'origine (?back=) : chemin interne d'une fiche du dossier uniquement.
     */
    protected static function backTo(mixed $back, string $default): string
    {
        $back = is_string($back) ? $back : '';
        return preg_match('~^/(orders|purchase-orders|deliveries|licences|invoices)/\d+(#[a-z-]+)?$~', $back) ? $back : $default;
    }

    /** Règle métier refusée : message traduit en flash, retour à la page d'origine. */
    protected function domainError(string $path, \App\Domain\DomainException $e, array $old = [], ?string $field = null): Response
    {
        if ($old) {
            unset($old['_csrf']);
            Session::flash('_old', $old);
        }
        // Une référence refusée (doublon, format) est signalée sur son champ.
        $field ??= str_starts_with($e->getMessage(), 'reference.') ? 'reference' : null;
        if ($field !== null) {
            Session::flash('_errors', [$field => t($e->getMessage(), $e->params)]);
        }
        Session::flash('error', t($e->getMessage(), $e->params));
        return Response::redirect($path);
    }

    /** Chaîne vide → null (champs facultatifs). */
    protected static function nul(mixed $value): mixed
    {
        return $value === null || $value === '' ? null : $value;
    }

    /**
     * Redirection après erreur : saisies et messages par champ conservés pour la requête suivante.
     * @param array<string,string> $errors
     */
    protected function backWithErrors(string $path, array $errors, array $old, ?string $message = null): Response
    {
        unset($old['_csrf'], $old['password'], $old['password_confirm']);
        Session::flash('_errors', $errors);
        Session::flash('_old', $old);
        Session::flash('error', $message ?? t('flash.form_errors'));
        return Response::redirect($path);
    }
}
