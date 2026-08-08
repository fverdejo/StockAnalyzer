<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\DTO\CorporateEvents;
use StockAnalyzer\Models\User;
use StockAnalyzer\Services\AlertService;

/**
 * Cubre la alerta de resultados proximos (ver versions.md v2.57). Los dos
 * comportamientos criticos son la guarda de fecha estrictamente futura
 * (Yahoo devuelve fechas de resultados ya pasadas cuando la empresa aun no
 * ha anunciado la siguiente: observado en real con TEF.MC) y el dedupe por
 * fecha, para no repetir la misma alerta cada dia dentro de la ventana.
 *
 * Las fechas se construyen siempre relativas a "hoy" para que el test no
 * caduque.
 */
final class AlertServiceEarningsTest extends TestCase
{
    private InMemoryAlertRepository $alerts;
    private AlertService $service;

    protected function setUp(): void
    {
        $this->alerts = new InMemoryAlertRepository();
        $this->service = new AlertService(
            $this->alerts,
            new InMemoryTickerAlertStateRepository(),
            new InMemoryTickerDividendAlertStateRepository(),
            new InMemoryTickerStopLossAlertStateRepository(),
            new InMemoryTickerEarningsAlertStateRepository()
        );
    }

    private function user(): User
    {
        return new User(1, 'test@example.com', new DateTimeImmutable('2026-01-01 00:00:00'));
    }

    private function events(string $modifier, bool $isEstimate = false): CorporateEvents
    {
        return new CorporateEvents(
            (new DateTimeImmutable('today'))->modify($modifier),
            null,
            $isEstimate
        );
    }

    public function testFechaPasadaNoAlerta(): void
    {
        $this->service->checkUpcomingEarnings($this->user(), 'TEF.MC', $this->events('-9 days'));

        self::assertSame(0, $this->alerts->countCreated());
    }

    public function testHoyNoAlerta(): void
    {
        $this->service->checkUpcomingEarnings($this->user(), 'AAPL', $this->events('+0 days'));

        self::assertSame(0, $this->alerts->countCreated());
    }

    public function testFueraDeVentanaNoAlerta(): void
    {
        $this->service->checkUpcomingEarnings($this->user(), 'AAPL', $this->events('+8 days'));

        self::assertSame(0, $this->alerts->countCreated());
    }

    public function testSinEventosNoAlerta(): void
    {
        $this->service->checkUpcomingEarnings($this->user(), 'AAPL', null);
        $this->service->checkUpcomingEarnings($this->user(), 'AAPL', CorporateEvents::empty());

        self::assertSame(0, $this->alerts->countCreated());
    }

    public function testDentroDeVentanaAlertaUnaSolaVezPorFecha(): void
    {
        $events = $this->events('+3 days');

        $this->service->checkUpcomingEarnings($this->user(), 'AAPL', $events);
        $this->service->checkUpcomingEarnings($this->user(), 'AAPL', $events);
        // Misma fecha, otro objeto CorporateEvents: el dedupe es por fecha
        // (lo que Yahoo devuelve en la visita siguiente), no por instancia.
        $this->service->checkUpcomingEarnings($this->user(), 'AAPL', $this->events('+3 days'));

        self::assertSame(1, $this->alerts->countCreated());
        self::assertSame('AAPL', $this->alerts->created()[0]['ticker']);
        self::assertStringContainsString('publica resultados el', (string) $this->alerts->lastMessage());
        self::assertStringContainsString('en 3 dias', (string) $this->alerts->lastMessage());
        self::assertStringNotContainsString('estimada', (string) $this->alerts->lastMessage());
    }

    public function testUnaFechaDistintaVuelveAAlertar(): void
    {
        $this->service->checkUpcomingEarnings($this->user(), 'AAPL', $this->events('+3 days'));
        $this->service->checkUpcomingEarnings($this->user(), 'AAPL', $this->events('+5 days'));

        self::assertSame(2, $this->alerts->countCreated());
    }

    public function testFechaEstimadaLoDiceEnElMensaje(): void
    {
        $this->service->checkUpcomingEarnings($this->user(), 'AAPL', $this->events('+4 days', true));

        self::assertSame(1, $this->alerts->countCreated());
        self::assertStringContainsString('fecha estimada', (string) $this->alerts->lastMessage());
    }

    public function testVentanaConfigurablePorParametro(): void
    {
        $this->service->checkUpcomingEarnings($this->user(), 'AAPL', $this->events('+9 days'), 10);

        self::assertSame(1, $this->alerts->countCreated());
    }
}
