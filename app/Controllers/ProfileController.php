<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\I18n;
use App\Core\Repo;
use App\Core\Request;
use App\Core\Response;
use App\Services\Users;

final class ProfileController extends Controller
{
    public function edit(): Response
    {
        return $this->view('profile/edit', ['langs' => I18n::enabled()]);
    }

    public function update(): Response
    {
        $u = Auth::user();
        $name = $this->post('user_nm');
        $email = $this->post('email');
        $lang = $this->post('lang');
        try {
            Users::validateProfile((string)$u['login_id'], $name, $email, $lang);
        } catch (\InvalidArgumentException $e) {
            $this->flash('danger', $e->getMessage());
            return $this->redirect('/profile');
        }
        Repo::update('user', ['user_id' => $u['user_id']], ['user_nm' => $name, 'email' => $email, 'lang' => $lang]);
        Audit::log('PROFILE_UPDATE', (string)$u['login_id']);
        $this->flash('success', 'profile.updated');
        return $this->redirect('/profile')->cookie('srms_lang', $lang);
    }

    public function password(): Response
    {
        $u = Auth::user();
        $row = Repo::find('user', ['user_id' => $u['user_id']]);
        $current = Request::post('current_password');
        $new = Request::post('new_password');
        $new2 = Request::post('new_password2');
        if (!is_string($row['pw_hash']) || !password_verify($current, $row['pw_hash'])) {
            $this->flash('danger', 'profile.pw_wrong');
        } elseif ($new !== $new2) {
            $this->flash('danger', 'profile.pw_mismatch');
        } elseif (($err = Auth::passwordError($new, (string)$u['login_id'])) !== null) {
            $this->flash('danger', $err);
        } else {
            Repo::update('user', ['user_id' => $u['user_id']], ['pw_hash' => Auth::hashPassword($new)]);
            Audit::log('PASSWORD_CHANGE', (string)$u['login_id']);
            $this->flash('success', 'profile.pw_changed');
        }
        return $this->redirect('/profile');
    }
}
