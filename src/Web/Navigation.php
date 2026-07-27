<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

use StockAnalyzer\Models\User;

class Navigation
{
    public static function render(?User $user, string $active): string
    {
        $items = [
            self::link('dashboard', 'Ranking', '?', $active),
        ];

        if ($user instanceof User) {
            $items[] = self::link('portfolio', 'Mi cartera', '?page=portfolio', $active);
            $items[] = self::link('backtest', 'Backtesting', '?page=backtest', $active);
            $items[] = self::link('provider', 'Configuracion', '?page=provider', $active);
            $items[] = self::link('account', 'Mi cuenta', '?page=account', $active);
        } else {
            $items[] = self::link('login', 'Login', '?page=login', $active);
            $items[] = self::link('register', 'Registro', '?page=register', $active);
        }

        return '<nav class="main-nav">' . implode('', $items) . '</nav>';
    }

    private static function link(string $key, string $label, string $href, string $active): string
    {
        $class = $key === $active ? ' class="active"' : '';

        return sprintf(
            '<a%s href="%s">%s</a>',
            $class,
            Layout::escape($href),
            Layout::escape($label)
        );
    }
}
