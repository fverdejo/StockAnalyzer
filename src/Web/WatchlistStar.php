<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

use StockAnalyzer\Models\User;

/**
 * Estrella de watchlist reutilizable (ver versions.md v2.16): mismo
 * mecanismo que el boton "Seguir"/"Dejar de seguir" de StockDetailPage
 * (v2.14), pero como icono compacto para usarlo dentro de una celda de
 * tabla. Es un toggle: pulsarla añade si no estaba, quita si ya estaba.
 */
class WatchlistStar
{
    public static function render(string $ticker, ?User $currentUser, bool $isWatched, string $csrfToken, string $redirectTo): string
    {
        if (!$currentUser instanceof User) {
            return '';
        }

        $action = $isWatched ? 'remove' : 'add';
        $symbol = $isWatched ? '&#9733;' : '&#9734;';
        $title = $isWatched ? 'Quitar de mi watchlist' : 'Añadir a mi watchlist';
        $class = $isWatched ? 'watch-star watch-star-active' : 'watch-star';

        return sprintf(
            '<form method="post" action="?page=watchlist" class="inline-form"><input type="hidden" name="csrf_token" value="%s"><input type="hidden" name="ticker" value="%s"><input type="hidden" name="redirect_to" value="%s"><button type="submit" name="watchlist_action" value="%s" class="%s" title="%s" aria-label="%s">%s</button></form>',
            Layout::escape($csrfToken),
            Layout::escape($ticker),
            Layout::escape($redirectTo),
            $action,
            $class,
            Layout::escape($title),
            Layout::escape($title),
            $symbol
        );
    }
}
