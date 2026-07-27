<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

class LoginPage
{
    public static function render(?string $error, string $email, string $csrfToken): string
    {
        $errorHtml = $error !== null
            ? sprintf('<div class="form-error">%s</div>', Layout::escape($error))
            : '';
        $emailValue = Layout::escape($email);
        $token = Layout::escape($csrfToken);

        $body = <<<HTML
        <section class="auth-panel">
            <h2>Iniciar sesion</h2>
            {$errorHtml}
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
        </section>
HTML;

        return Layout::render('Login - Stock Analyzer', '', $body, null, 'login');
    }
}
