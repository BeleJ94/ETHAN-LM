<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Security\Auth;
use App\Services\ActivityLogger;

final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        if (Auth::user() !== null) {
            return Response::redirect('/');
        }
        return $this->view('auth/login', ['title' => t('auth.title')], 'minimal');
    }

    public function login(Request $request): Response
    {
        $email = (string) $request->input('email', '');
        // Freinage simple contre la force brute (par session).
        $attempts = (int) Session::get('login_attempts', 0);
        if ($attempts >= 5 && time() - (int) Session::get('login_last', 0) < 60) {
            Session::flash('error', t('auth.throttled'));
            return Response::redirect('/login');
        }
        if (!Auth::attempt($email, (string) $request->input('password', ''))) {
            Session::set('login_attempts', $attempts + 1);
            Session::set('login_last', time());
            Session::flash('error', t('auth.failed'));
            Session::flash('_old', ['email' => $email]);
            return Response::redirect('/login');
        }
        Session::forget('login_attempts');
        ActivityLogger::log('user', Auth::user()?->id, 'login');
        return Response::redirect('/');
    }

    public function logout(Request $request): Response
    {
        Auth::logout();
        return Response::redirect('/login');
    }
}
