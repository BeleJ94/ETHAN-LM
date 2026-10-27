<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Security\Auth;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

Env::load($root . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'Africa/Lubumbashi'));
$debug = Env::bool('APP_DEBUG');
ini_set('display_errors', $debug ? '1' : '0');
View::setRoot($root . '/views');

// Serveur intégré PHP : laisser servir les fichiers statiques.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

Session::start();
I18n::setLocale((string) Session::get('locale', Env::get('APP_LOCALE', 'fr')));

$request = Request::fromGlobals(rtrim((string) Env::get('APP_BASE_PATH', ''), '/'));
// AJAX : toute sortie parasite (avertissement PHP affiché en mode debug…) corromprait le JSON ; elle est capturée puis journalisée.
if ($request->isAjax()) {
    ob_start();
}
$router = new Router();
(require $root . '/app/routes.php')($router);

try {
    $route = $router->match($request->method, $request->path);
    $request->params = $route['params'];

    if ($request->method !== 'GET' && !Csrf::check($request)) {
        throw new HttpException(419);
    }
    if ($route['permission'] !== null && Auth::user() === null) {
        throw new HttpException(401);
    }

    [$class, $method] = $route['handler'];
    $response = (new $class())->$method($request);
} catch (HttpException $e) {
    if ($e->status === 401) {
        $response = $request->isAjax() ? Response::json(['error' => 'unauthenticated'], 401) : Response::redirect('/login');
    } else {
        // 419 (jeton CSRF) n'est pas un code HTTP standard : Apache le convertirait en 500.
        $httpStatus = $e->status === 419 ? 403 : $e->status;
        if ($request->isAjax()) {
            $response = Response::json(['ok' => false, 'error' => $e->status, 'message' => t('error.' . $e->status . '.hint')], $httpStatus);
        } else {
            $layout = Auth::user() ? 'app' : 'minimal';
            $response = Response::html(View::render('errors/error', ['title' => t('error.' . $e->status), 'status' => $e->status], $layout), $httpStatus);
        }
    }
} catch (\Throwable $e) {
    error_log((string) $e);
    @file_put_contents($root . '/storage/logs/app.log', '[' . date('c') . '] ' . $e . "\n", FILE_APPEND);
    if ($request->isAjax()) {
        $response = Response::json(['ok' => false, 'message' => t('error.500.hint')], 500);
    } else {
        $body = $debug
            ? '<pre>' . e((string) $e) . '</pre>'
            : View::render('errors/error', ['title' => t('error.500'), 'status' => 500], 'minimal');
        $response = Response::html($body, 500);
    }
}

/*
 * Soumission AJAX : la redirection « POST → redirect + flash » des contrôleurs devient une réponse JSON.
 * Les messages flash et erreurs de champ sont consommés ici (le navigateur les affiche lui-même)
 * afin de ne pas réapparaître sur la page suivante. Sans JavaScript, le comportement classique est inchangé.
 */
if ($request->isAjax() && $request->method !== 'GET' && $response->status === 302) {
    $flash = $_SESSION['_flash_next'] ?? [];
    $_SESSION['_flash_next'] = [];
    $error = $flash['error'] ?? null;
    $response = Response::json([
        'ok' => $error === null,
        'message' => $error ?? $flash['success'] ?? $flash['info'] ?? null,
        'errors' => $flash['_errors'] ?? (object) [],
        'redirect' => $response->headers['Location'] ?? null,
        'next' => $error === null ? ($flash['next'] ?? null) : null,
    ], $error === null ? 200 : 422);
}

if ($request->isAjax()) {
    $stray = (string) ob_get_clean();
    if (trim($stray) !== '') {
        @file_put_contents($root . '/storage/logs/app.log', '[' . date('c') . '] Sortie parasite sur ' . $request->method . ' ' . $request->path . ' : ' . mb_substr(strip_tags($stray), 0, 2000) . "\n", FILE_APPEND);
    }
}
$response->send();
