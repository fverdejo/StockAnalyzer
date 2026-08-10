<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Web;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Models\Alert;
use StockAnalyzer\Models\User;
use StockAnalyzer\Web\AlertsPage;

/**
 * La pagina de alertas se compone con sprintf, asi que un placeholder de
 * mas o de menos es un fatal en produccion y no un fallo de estilo: estos
 * tests renderizan la pagina completa en sus tres estados (con alertas,
 * vacia y filtrada) y comprueban lo que no puede romperse.
 */
final class AlertsPageTest extends TestCase
{
    private function user(): User
    {
        return new User(1, 'test@example.com', new DateTimeImmutable('2026-01-01 00:00:00'));
    }

    /**
     * @return list<Alert>
     */
    private function alerts(): array
    {
        return [
            new Alert(7, 'AAPL', 'AAPL pasa de HOLD a BUY.', new DateTimeImmutable('2026-08-09 10:05:00', new DateTimeZone('UTC')), null),
            new Alert(6, 'TEF.MC', 'TEF.MC paga dividendo.', new DateTimeImmutable('2026-08-08 09:00:00', new DateTimeZone('UTC')), new DateTimeImmutable('2026-08-08 10:00:00', new DateTimeZone('UTC'))),
        ];
    }

    private function render(): string
    {
        return AlertsPage::render($this->user(), $this->alerts(), 35, 'all', 30, 'token', null, null);
    }

    public function testElTituloUsaElTotalSinLeerYNoSoloLasMostradas(): void
    {
        self::assertStringContainsString('Alertas (35 sin leer)', $this->render());
    }

    public function testCadaAlertaEsAnclableYOfreceSusAcciones(): void
    {
        $html = $this->render();

        self::assertStringContainsString('id="alert-7"', $html);
        self::assertStringContainsString('tabindex="-1"', $html);
        // La accion enviada es explicita segun el estado actual, no un toggle.
        self::assertStringContainsString('value="mark_read" class="alert-action" title="Marcar como leida"', $html);
        self::assertStringContainsString('value="mark_unread" class="alert-action" title="Marcar como no leida"', $html);
        self::assertSame(2, substr_count($html, 'value="delete" class="alert-action alert-action-delete"'));
        self::assertSame(4, substr_count($html, 'name="alert_id"'));
        self::assertSame(6, substr_count($html, 'name="csrf_token"'));
    }

    public function testMensajeYFechaFormateados(): void
    {
        $html = $this->render();

        self::assertStringContainsString('AAPL pasa de HOLD a BUY.', $html);
        self::assertStringContainsString('<time class="alert-date" datetime="2026-08-09T10:05:00+00:00">09/08/2026 10:05</time>', $html);
    }

    public function testSinLeerNoSePintaConElRojoDeVenta(): void
    {
        $html = $this->render();

        self::assertStringContainsString('class="alert alert-unread"', $html);
        self::assertStringContainsString('<span class="alert-pill">Sin leer</span>', $html);
        // El rojo de --bad (.signal-negative) queda para el veredicto, no
        // para "sin leer". La hoja de estilos comun sigue definiendo la
        // clase, asi que se comprueba que no se usa en el marcado.
        self::assertStringNotContainsString('class="signal', $html);
        self::assertStringNotContainsString('signal-negative"', $html);
    }

    public function testAccionesMasivasSoloSiTienenAlgoQueHacer(): void
    {
        $html = $this->render();
        self::assertStringContainsString('value="mark_all_read"', $html);
        self::assertStringContainsString('value="delete_read"', $html);

        $soloLeidas = AlertsPage::render($this->user(), [$this->alerts()[1]], 0, 'all', 30, 'token', null, null);
        self::assertStringNotContainsString('value="mark_all_read"', $soloLeidas);
        self::assertStringContainsString('value="delete_read"', $soloLeidas);

        $soloSinLeer = AlertsPage::render($this->user(), [$this->alerts()[0]], 1, 'unread', 30, 'token', null, null);
        self::assertStringContainsString('value="mark_all_read"', $soloSinLeer);
        self::assertStringNotContainsString('value="delete_read"', $soloSinLeer);
    }

    public function testAvisoDeLimiteSoloAlAlcanzarlo(): void
    {
        self::assertStringNotContainsString('alertas mas recientes', $this->render());

        $llena = [];

        for ($i = 1; $i <= 30; $i++) {
            $llena[] = new Alert($i, 'AAPL', 'Cambio de recomendacion.', new DateTimeImmutable('2026-08-09 10:00:00', new DateTimeZone('UTC')), null);
        }

        self::assertStringContainsString('Mostrando las 30 alertas mas recientes.', AlertsPage::render($this->user(), $llena, 30, 'all', 30, 'token', null, null));
    }

    public function testEstadoVacioSegunElFiltro(): void
    {
        $todas = AlertsPage::render($this->user(), [], 0, 'all', 30, 'token', null, null);
        self::assertStringContainsString('alert-empty', $todas);
        self::assertStringContainsString('?page=watchlist', $todas);

        $sinLeer = AlertsPage::render($this->user(), [], 0, 'unread', 30, 'token', null, null);
        self::assertStringContainsString('Todo leido', $sinLeer);
        self::assertStringContainsString('?page=alerts&amp;filter=all', $sinLeer);
    }

    public function testTodoDatoDinamicoVaEscapado(): void
    {
        $html = AlertsPage::render(
            $this->user(),
            [new Alert(1, 'AAPL', '<script>alert(1)</script>', new DateTimeImmutable('2026-08-09 10:00:00', new DateTimeZone('UTC')), null)],
            1,
            'all',
            30,
            '"><script>',
            '<b>hola</b>',
            '<i>error</i>'
        );

        // Se comprueban los payloads concretos, no la ausencia de la cadena
        // "<script>" a secas: desde v2.82 Layout emite un <script> propio y
        // legitimo (el tooltip de los iconos de ayuda en tablas), asi que ese
        // atajo daria un falso positivo. Ademas de que el payload no aparezca
        // en crudo se exige que SI aparezca escapado, que es lo que de verdad
        // demuestra que paso por Layout::escape() y no que se perdio por el
        // camino.
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('"><script>', $html);
        self::assertStringNotContainsString('<b>hola</b>', $html);
        self::assertStringContainsString('&lt;b&gt;hola&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<i>error</i>', $html);
        self::assertStringContainsString('&lt;i&gt;error&lt;/i&gt;', $html);
    }
}
