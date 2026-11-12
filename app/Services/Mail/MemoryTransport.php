<?php
declare(strict_types=1);

namespace App\Services\Mail;

/** 테스트용: 메모리에 쌓고, 지정한 주소는 실패시킨다. */
final class MemoryTransport implements Transport
{
    /** @var list<array{to:string,name:string,subject:string,body:string}> */
    public array $sent = [];
    /** @var list<string> */
    public array $failTo = [];

    public function send(string $toEmail, string $toName, string $subject, string $body): void
    {
        if (in_array($toEmail, $this->failTo, true)) {
            throw new \RuntimeException('simulated failure');
        }
        $this->sent[] = ['to' => $toEmail, 'name' => $toName, 'subject' => $subject, 'body' => $body];
    }
}
