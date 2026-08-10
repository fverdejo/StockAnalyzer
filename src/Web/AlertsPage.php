<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

use DateTimeInterface;
use StockAnalyzer\Models\Alert;
use StockAnalyzer\Models\User;

/**
 * Historial de alertas del usuario (ver versions.md v2.15): cambios de
 * recomendacion detectados en su cartera o en su watchlist, con acciones
 * por alerta (marcar leida / no leida / borrar).
 *
 * El estado leido/sin leer no se distingue solo por color: la alerta sin
 * leer va sobre --surface (fondo blanco) y lleva la pildora "Sin leer",
 * mientras la leida va sobre --surface-alt. El rojo (--bad) queda
 * reservado para el veredicto de la recomendacion, no para "sin leer":
 * hay alertas sin leer que son buenas noticias (un dividendo, un cambio a
 * BUY).
 */
class AlertsPage
{
    /**
     * @param list<Alert> $alerts alertas ya filtradas y limitadas por el repositorio
     * @param int $unreadCount total de alertas sin leer del usuario (no solo las mostradas)
     * @param string $filter 'all' o 'unread'
     * @param int $limit cuantas alertas devuelve como maximo el repositorio
     */
    public static function render(
        User $user,
        array $alerts,
        int $unreadCount,
        string $filter,
        int $limit,
        string $csrfToken,
        ?string $message,
        ?string $error = null
    ): string {
        $token = Layout::escape($csrfToken);
        $messageHtml = $message !== null && $message !== '' ? sprintf('<div class="form-success">%s</div>', Layout::escape($message)) : '';
        $errorHtml = $error !== null && $error !== '' ? sprintf('<div class="form-error">%s</div>', Layout::escape($error)) : '';
        $toolbar = self::renderToolbar($alerts, $filter, $token);
        $rows = self::renderRows($alerts, $filter, $token);
        $limitNote = count($alerts) >= $limit ? sprintf(
            '<p class="muted panel-note">Mostrando las %d alertas mas recientes.</p>',
            $limit
        ) : '';

        $body = <<<HTML
        {$messageHtml}
        {$errorHtml}

        <section class="panel">
            <h2>Alertas ({$unreadCount} sin leer)</h2>
            <p class="muted panel-note">Avisos cuando una accion de tu cartera o de tu watchlist cambia de recomendacion (por ejemplo, a STRONG SELL). Solo se muestran aqui, dentro de la aplicacion: no se envian por correo ni notificacion push.</p>
            {$toolbar}
            {$rows}
            {$limitNote}
        </section>
HTML;

        return Layout::render('Alertas - Stock Analyzer', '', $body, $user, 'alerts');
    }

    /**
     * Filtros y acciones masivas. Las acciones masivas se pintan solo si
     * tienen algo que hacer sobre lo que se esta mostrando.
     *
     * @param list<Alert> $alerts
     */
    private static function renderToolbar(array $alerts, string $filter, string $csrfToken): string
    {
        $hasUnread = self::countBy($alerts, false) > 0;
        $hasRead = self::countBy($alerts, true) > 0;

        $filters = sprintf(
            '<a class="alert-filter%s" href="?page=alerts&amp;filter=all">Todas</a><a class="alert-filter%s" href="?page=alerts&amp;filter=unread">Sin leer</a>',
            $filter === 'unread' ? '' : ' alert-filter-active',
            $filter === 'unread' ? ' alert-filter-active' : ''
        );

        $markAll = $hasUnread ? self::bulkForm($csrfToken, 'mark_all_read', 'Marcar todas como leidas', '') : '';
        $deleteRead = $hasRead ? self::bulkForm($csrfToken, 'delete_read', 'Borrar las leidas', ' class="secondary-button"') : '';

        return sprintf('<div class="alert-toolbar">%s%s%s</div>', $filters, $markAll, $deleteRead);
    }

    private static function bulkForm(string $csrfToken, string $action, string $label, string $buttonAttributes): string
    {
        return sprintf(
            '<form method="post" action="?page=alerts" class="inline-form"><input type="hidden" name="csrf_token" value="%s"><button type="submit" name="alerts_action" value="%s"%s>%s</button></form>',
            $csrfToken,
            $action,
            $buttonAttributes,
            Layout::escape($label)
        );
    }

    /**
     * @param list<Alert> $alerts
     */
    private static function renderRows(array $alerts, string $filter, string $csrfToken): string
    {
        if ($alerts === []) {
            return self::renderEmptyState($filter);
        }

        $items = [];

        foreach ($alerts as $alert) {
            $items[] = self::renderAlert($alert, $csrfToken);
        }

        return '<div class="alert-list">' . implode('', $items) . '</div>';
    }

    private static function renderAlert(Alert $alert, string $csrfToken): string
    {
        $pill = $alert->isRead() ? '' : '<span class="alert-pill">Sin leer</span>';

        return sprintf(
            '<article class="alert%s" id="alert-%d" tabindex="-1">'
            . '<div class="alert-head">'
            . '<strong class="alert-ticker"><a href="?ticker=%s">%s</a></strong>'
            . '<time class="alert-date" datetime="%s">%s</time>'
            . '%s'
            . '<div class="alert-actions">%s%s</div>'
            . '</div>'
            . '<p class="alert-message">%s</p>'
            . '</article>',
            $alert->isRead() ? '' : ' alert-unread',
            $alert->getId(),
            urlencode($alert->getTicker()),
            Layout::escape($alert->getTicker()),
            Layout::escape($alert->getCreatedAt()->format(DateTimeInterface::ATOM)),
            Layout::escape($alert->getCreatedAt()->format('d/m/Y H:i')),
            $pill,
            self::toggleForm($alert, $csrfToken),
            self::deleteForm($alert, $csrfToken),
            Layout::escape($alert->getMessage())
        );
    }

    /**
     * Marcar como leida / no leida. La accion enviada es explicita (no un
     * "toggle" que invierta el servidor) para que reenviar el formulario no
     * cambie el estado por accidente.
     */
    private static function toggleForm(Alert $alert, string $csrfToken): string
    {
        $read = $alert->isRead();

        return self::alertForm(
            $alert,
            $csrfToken,
            $read ? 'mark_unread' : 'mark_read',
            $read ? '&#9675;' : '&#9679;',
            $read ? 'Marcar como no leida' : 'Marcar como leida',
            'alert-action'
        );
    }

    private static function deleteForm(Alert $alert, string $csrfToken): string
    {
        return self::alertForm($alert, $csrfToken, 'delete', '&times;', 'Borrar alerta', 'alert-action alert-action-delete');
    }

    private static function alertForm(Alert $alert, string $csrfToken, string $action, string $symbol, string $title, string $class): string
    {
        return sprintf(
            '<form method="post" action="?page=alerts" class="inline-form"><input type="hidden" name="csrf_token" value="%s"><input type="hidden" name="alert_id" value="%d"><button type="submit" name="alerts_action" value="%s" class="%s" title="%s" aria-label="%s">%s</button></form>',
            $csrfToken,
            $alert->getId(),
            $action,
            $class,
            Layout::escape($title),
            Layout::escape($title),
            $symbol
        );
    }

    /**
     * Con el borrado de alertas la lista vacia deja de ser algo que solo se
     * ve el primer dia, asi que se trata como un estado propio y no como un
     * parrafo suelto.
     */
    private static function renderEmptyState(string $filter): string
    {
        if ($filter === 'unread') {
            return '<div class="alert-empty"><h3>Todo leido</h3><p class="muted">No te queda ninguna alerta sin leer.</p><p><a href="?page=alerts&amp;filter=all">Ver todas las alertas</a></p></div>';
        }

        return '<div class="alert-empty"><h3>Sin alertas</h3><p class="muted">Las alertas se generan cuando la recomendacion de un ticker de tu cartera o de tu watchlist cambia respecto a la ultima vez que la vimos.</p><p><a href="?page=watchlist">Ir a mi watchlist</a> &middot; <a href="?page=portfolio">Ir a mi cartera</a></p></div>';
    }

    /**
     * @param list<Alert> $alerts
     */
    private static function countBy(array $alerts, bool $read): int
    {
        return count(array_filter($alerts, static fn (Alert $alert): bool => $alert->isRead() === $read));
    }
}
