<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

use StockAnalyzer\Models\User;

class AccountPage
{
    public static function render(User $user, string $csrfToken): string
    {
        $token = Layout::escape($csrfToken);

        $body = sprintf(
            '<section class="panel"><h2>Mi cuenta</h2><div class="values-grid">%s%s%s</div><form method="post" action="?page=logout" class="inline-form account-actions"><input type="hidden" name="csrf_token" value="%s"><button type="submit" class="danger-button">Cerrar sesión</button></form></section>',
            self::value('Email', $user->getEmail()),
            self::value('Fecha de alta', $user->getCreatedAt()->format('Y-m-d H:i')),
            self::value('Email verificado', $user->isEmailVerified() ? 'Sí' : 'No'),
            $token
        );

        return Layout::render('Mi cuenta - Stock Analyzer', '', $body, $user, 'account');
    }

    private static function value(string $label, string $value): string
    {
        return sprintf(
            '<div class="value-box"><span class="muted">%s</span><strong>%s</strong></div>',
            Layout::escape($label),
            Layout::escape($value)
        );
    }
}
