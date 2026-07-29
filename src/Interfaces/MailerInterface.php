<?php

declare(strict_types=1);

namespace StockAnalyzer\Interfaces;

/**
 * Abstraccion de envio de correo (ver versions.md v2.11), coherente con la
 * regla de project.md de que toda dependencia externa se abstrae mediante
 * una interfaz. AuthService depende de esto, no de una libreria SMTP
 * concreta, para poder cambiar de implementacion (PHPMailer, un servicio
 * transaccional...) sin tocar la logica de registro/verificacion.
 */
interface MailerInterface
{
    public function send(string $to, string $subject, string $body): void;
}
