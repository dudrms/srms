<?php
declare(strict_types=1);

namespace App\Services\Mail;

/** 개발/검증용: SMTP 없이 `storage/mail/*.eml` 파일로 남긴다. */
final class FileTransport implements Transport
{
    public function __construct(private readonly string $dir)
    {
    }

    public function send(string $toEmail, string $toName, string $subject, string $body): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('mail directory is not writable');
        }
        $name = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';
        $text = 'To: ' . $toName . ' <' . $toEmail . ">\r\n"
            . 'Subject: ' . $subject . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n\r\n" . $body . "\r\n";
        if (file_put_contents($this->dir . '/' . $name, $text) === false) {
            throw new \RuntimeException('failed to write mail file');
        }
    }
}
