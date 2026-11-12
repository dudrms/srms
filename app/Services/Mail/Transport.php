<?php
declare(strict_types=1);

namespace App\Services\Mail;

/** 메일 전송 수단. 실패 시 \RuntimeException 을 던진다. */
interface Transport
{
    public function send(string $toEmail, string $toName, string $subject, string $body): void;
}
