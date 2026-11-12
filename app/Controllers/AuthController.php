<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Providers;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Db;
use App\Core\I18n;
use App\Core\Repo;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class AuthController extends Controller
{
    public function showLogin(): Response
    {
        if (Auth::user() !== null) {
            return $this->redirect('/');
        }
        $companies = Db::all('SELECT company_cd, company_nm FROM company WHERE use_yn = 1 ORDER BY company_cd');
        $fixed = Auth::fixedCompany();
        $selected = (string)($_COOKIE['srms_company'] ?? '');
        if ($selected === '' && count($companies) === 1) {
            $selected = (string)$companies[0]['company_cd'];
        }
        $fixedNm = null;
        if ($fixed !== null) {
            $row = Db::one('SELECT company_nm FROM company WHERE company_cd = :c AND use_yn = 1', ['c' => $fixed]);
            $fixedNm = $row === null ? $fixed : (string)$row['company_nm'];
        }
        return $this->view('auth/login', [
            'companies' => $companies, 'selected' => $selected, 'fixedNm' => $fixedNm,
            'localOn' => Auth::mode() !== 'SSO', 'ssoOn' => Auth::mode() !== 'LOCAL',
        ], 'layouts/plain');
    }

    public function login(): Response
    {
        $company = Auth::fixedCompany() ?? $this->post('company_cd');
        $loginId = $this->post('login_id');
        $password = Request::post('password');
        $err = $loginId === '' || $password === '' || $company === ''
            ? 'auth.invalid'
            : Auth::attempt($company, $loginId, $password, Request::ip());
        if ($err !== null) {
            $this->flash('danger', $err);
            return $this->redirect('/login')->cookie('srms_company', $company);
        }
        $next = $this->safePath((string)Session::get('next', '/'));
        Session::forget('next');
        return $this->redirect($next)->cookie('srms_company', $company)->cookie('srms_theme', theme());
    }

    /** 외부 IdP 로 이동. 어댑터가 연동되지 않았으면 로그인 화면으로 되돌린다. */
    public function ssoLogin(): Response
    {
        if (!in_array(Auth::mode(), ['SSO', 'BOTH'], true)) {
            $this->abort(404);
        }
        $state = bin2hex(random_bytes(16));
        Session::set('sso_state', $state);
        $url = Providers::sso()->loginUrl($state);
        if ($url === null) {
            Session::forget('sso_state');
            $this->flash('danger', 'auth.sso_unavailable');
            return $this->redirect('/login');
        }
        return new Response(302, '', ['Location' => $url]);
    }

    public function ssoCallback(): Response
    {
        if (!in_array(Auth::mode(), ['SSO', 'BOTH'], true)) {
            $this->abort(404);
        }
        $state = (string)Session::get('sso_state', '');
        Session::forget('sso_state');
        $who = $state === '' ? null : Providers::sso()->identify($_GET, $state);
        $err = $who === null ? 'auth.sso_failed' : Auth::attemptSso(Auth::fixedCompany() ?? (string)$who['company_cd'], (string)$who['login_id']);
        if ($err !== null) {
            $this->flash('danger', $err);
            return $this->redirect('/login');
        }
        $next = $this->safePath((string)Session::get('next', '/'));
        Session::forget('next');
        return $this->redirect($next);
    }

    public function logout(): Response
    {
        Audit::log('LOGOUT');
        Auth::logout();
        return $this->redirect('/login');
    }

    /** 화면 테마(light/dark) 저장: 로그인 사용자는 계정에, 나머지는 쿠키에 남긴다. */
    public function setTheme(): Response
    {
        $theme = $this->post('theme') === 'dark' ? 'dark' : 'light';
        $back = $this->safePath($this->post('back', '/'));
        if (Auth::user() !== null) {
            Repo::update('user', ['user_id' => Auth::user()['user_id']], ['theme' => $theme]);
        }
        return $this->redirect($back)->cookie('srms_theme', $theme);
    }

    public function setLang(): Response
    {
        $lang = $this->post('lang');
        $back = $this->safePath($this->post('back', '/'));
        if (!I18n::isEnabled($lang)) {
            return $this->redirect($back);
        }
        if (Auth::user() !== null) {
            Repo::update('user', ['user_id' => Auth::user()['user_id']], ['lang' => $lang]);
        }
        return $this->redirect($back)->cookie('srms_lang', $lang);
    }
}
