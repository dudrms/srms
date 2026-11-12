<?php
declare(strict_types=1);

namespace App\Services\Mail;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

/** PHPMailer 를 통한 SMTP 전송. 접속 정보는 .env 의 SMTP_* / MAIL_FROM*. */
final class SmtpTransport implements Transport
{
    public function send(string $toEmail, string $toName, string $subject, string $body): void
    {
        $host = (string)env('SMTP_HOST', '');
        $from = (string)env('MAIL_FROM', '');
        if ($host === '' || $from === '') {
            throw new \RuntimeException('SMTP_HOST / MAIL_FROM is not configured');
        }
        $secure = strtolower((string)env('SMTP_SECURE', 'tls'));
        $user = (string)env('SMTP_USER', '');
        $m = new PHPMailer(true);
        try {
            $m->isSMTP();
            $m->Host = $host;
            $m->Port = (int)env('SMTP_PORT', '587');
            $m->SMTPAuth = $user !== '';
            $m->Username = $user;
            $m->Password = (string)env('SMTP_PASS', '');
            $m->SMTPSecure = match ($secure) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'tls' => PHPMailer::ENCRYPTION_STARTTLS,
                default => '',
            };
            $m->SMTPAutoTLS = $secure === 'tls';
            $m->Timeout = 15;
            $m->CharSet = PHPMailer::CHARSET_UTF8;
            $m->setFrom($from, (string)env('MAIL_FROM_NAME', 'SRMS'));
            $m->addAddress($toEmail, $toName);
            $m->isHTML(false);
            $m->Subject = $subject;
            $m->Body = $body;
            $m->send();
        } catch (MailException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }
}
