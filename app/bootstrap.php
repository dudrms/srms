<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) === 0) {
        $file = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    } elseif (strncmp($class, 'PHPMailer\\PHPMailer\\', 20) === 0) {
        $file = APP_ROOT . '/vendor/phpmailer/phpmailer/src/' . substr($class, 20) . '.php';
    } else {
        return;
    }
    if (is_file($file)) {
        require $file;
    }
});

require APP_ROOT . '/app/helpers.php';

App\Core\Env::load(APP_ROOT . '/.env');
date_default_timezone_set(env('APP_TZ', 'Asia/Seoul'));
mb_internal_encoding('UTF-8');
