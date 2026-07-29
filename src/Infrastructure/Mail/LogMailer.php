<?php

declare(strict_types=1);

namespace StockAnalyzer\Infrastructure\Mail;

use StockAnalyzer\Interfaces\MailerInterface;

/**
 * Implementacion por defecto de MailerInterface (ver versions.md v2.11).
 * Envia con la funcion mail() nativa de PHP y ademas deja siempre una
 * copia legible en storage/mails/, para poder verificar el flujo de
 * registro aunque el envio real falle.
 *
 * En local, con el proyecto montado en DDEV (ver `.ddev/`), esto ya
 * funciona sin configuracion adicional: DDEV apunta `sendmail_path` del
 * contenedor web al binario `mailpit sendmail`, que entrega el correo a
 * Mailpit en vez de a Internet. Los correos se pueden ver en
 * `ddev describe` (fila "Mailpit") o ejecutando `ddev mailpit`, sin tocar
 * nada de este codigo. En la Raspberry Pi de produccion, sin un MTA
 * configurado, `mail()` simplemente no entrega nada y solo queda la copia
 * en `storage/mails/`; ahi es donde conviene sustituir esta clase por un
 * mailer SMTP real (PHPMailer u otro) detras del mismo MailerInterface.
 */
class LogMailer implements MailerInterface
{
    public function __construct(
        private readonly string $storagePath = __DIR__ . '/../../../storage/mails',
        private readonly string $fromAddress = 'no-reply@stockanalyzer.local'
    ) {
    }

    public function send(string $to, string $subject, string $body): void
    {
        if (function_exists('mail')) {
            $headers = "From: Stock Analyzer <{$this->fromAddress}>\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n";

            @mail($to, $subject, $body, $headers);
        }

        $this->logToFile($to, $subject, $body);
    }

    private function logToFile(string $to, string $subject, string $body): void
    {
        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0775, true);
        }

        if (!is_dir($this->storagePath)) {
            return;
        }

        $filename = sprintf('%s_%s.eml', date('Ymd_His'), preg_replace('/[^a-zA-Z0-9]/', '_', $to));
        $content = "To: {$to}\nSubject: {$subject}\n\n{$body}\n";

        @file_put_contents($this->storagePath . '/' . $filename, $content);
    }
}
