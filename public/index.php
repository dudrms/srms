<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\I18n;
use App\Core\Request;
use App\Core\Router;
use App\Core\Session;

Session::start();

$cookieLang = (string)($_COOKIE['srms_lang'] ?? '');
I18n::set(I18n::isEnabled($cookieLang) ? $cookieLang : I18n::negotiate((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')));

Router::fromFile(APP_ROOT . '/app/routes.php')
    ->dispatch(Request::method(), Request::path())
    ->send();

App\Services\MailService::flushAfterResponse();
