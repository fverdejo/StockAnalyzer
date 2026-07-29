<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

use StockAnalyzer\Models\Alert;
use StockAnalyzer\Models\User;

/**
 * Historial de alertas del usuario (ver versions.md v2.15): cambios de
 * recomendacion detectados en su cartera o en su watchlist.
 */
class AlertsPage
{
    /**
     * @param list<Alert> $alerts
     */
    public static function render(User $user, array $alerts, string $csrfToken, ?string $message, ?string $error = null): string
    {
        $token = Layout::escape($csrfToken);
        $messageHtml = $message !== null && $message !== '' ? sprintf('<div class="form-success">%s</div>', Layout::escape($message)) : '';
        $errorHtml = $error !== null && $error !== '' ? sprintf('<div class="form-error">%s</div>', Layout::escape($error)) : '';
        $unreadCount = count(array_filter($alerts, static fn (Alert $alert): bool => !$alert->isRead()));
        $markReadButton = $unreadCount > 0 ? sprintf(
            '<form method="post" action="?page=alerts" class="inline-form"><input type="hidden" name="csrf_token" value="%s"><button type="submit" name="alerts_action" value="mark_read">Marcar todas como leidas</button></form>',
            $token
        ) : '';
        $rows = self::renderRows($alerts);

        $body = <<<HTML
        {$messageHtml}
        {$errorHtml}

        <section class="panel">
            <h2>Alertas ({$unreadCount} sin leer)</h2>
            <p class="muted panel-note">Avisos cuando una accion de tu cartera o de tu watchlist cambia de recomendacion (por ejemplo, a STRONG SELL). Solo se muestran aqui, dentro de la aplicacion: no se envian por correo ni notificacion push.</p>
            {$markReadButton}
            {$rows}
        </section>
HTML;

        return Layout::render('Alertas - Stock Analyzer', '', $body, $user, 'alerts');
    }

    /**
     * @param list<Alert> $alerts
     */
    private static function renderRows(array $alerts): string
    {
        if ($alerts === []) {
            return '<div class="muted">Todavia no hay alertas. Se generan cuando la recomendacion de un ticker de tu cartera o watchlist cambia respecto a la ultima vez que la vimos.</div>';
        }

        $items = [];

        foreach ($alerts as $alert) {
            $items[] = sprintf(
                '<div class="signal %s"><strong><a href="?ticker=%s">%s</a> &middot; %s</strong>%s</div>',
                $alert->isRead() ? '' : 'signal-negative',
                urlencode($alert->getTicker()),
                Layout::escape($alert->getTicker()),
                Layout::escape($alert->getCreatedAt()->format('Y-m-d H:i')),
                Layout::escape($alert->getMessage())
            );
        }

        return '<div class="signal-list">' . implode('', $items) . '</div>';
    }
}
