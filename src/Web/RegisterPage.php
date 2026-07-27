<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

class RegisterPage
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
            <h2>Crear cuenta</h2>
            {$errorHtml}
            <form method="post" action="?page=register" class="stack-form">
                <input type="hidden" name="csrf_token" value="{$token}">
                <div>
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" value="{$emailValue}" autocomplete="email" required>
                </div>
                <div>
                    <label for="password">Contrasena</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required>
                </div>
                <button type="submit">Crear cuenta</button>
            </form>
        </section>
HTML;

        return Layout::render('Registro - Stock Analyzer', '', $body, null, 'register');
    }
}
