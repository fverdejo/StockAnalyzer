<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

class LoginPage
{
    public static function render(?string $error, string $email, string $csrfToken, ?string $message = null): string
    {
        $errorHtml = $error !== null && $error !== ''
            ? sprintf('<div class="form-error">%s</div>', Layout::escape($error))
            : '';
        $messageHtml = $message !== null && $message !== ''
            ? sprintf('<div class="form-success">%s</div>', Layout::escape($message))
            : '';
        $emailValue = Layout::escape($email);
        $token = Layout::escape($csrfToken);

        $body = <<<HTML
        <section class="auth-panel">
            <h2>Iniciar sesion</h2>
            {$errorHtml}
            {$messageHtml}
            <form method="post" action="?page=login" class="stack-form">
                <input type="hidden" name="csrf_token" value="{$token}">
                <div>
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" value="{$emailValue}" autocomplete="email" required>
                </div>
                <div>
                    <label for="password">Contrasena</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                </div>
                <button type="submit">Entrar</button>
            </form>
            <form method="post" action="?page=resend-verification" class="stack-form">
                <input type="hidden" name="csrf_token" value="{$token}">
                <div>
                    <label for="resend_email">¿No te llego el correo de confirmacion?</label>
                    <input id="resend_email" name="email" type="email" value="{$emailValue}" placeholder="Tu email" autocomplete="email">
                </div>
                <button type="submit" class="secondary-button">Reenviar correo de confirmacion</button>
            </form>
        </section>
HTML;

        return Layout::render('Login - Stock Analyzer', '', $body, null, 'login');
    }
}
