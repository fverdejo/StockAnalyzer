<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\DTO\RiskLevels;
use StockAnalyzer\Models\User;
use StockAnalyzer\Services\AlertService;

/**
 * Cubre la alerta de stop-loss perdido (ver versions.md v2.56). Lo
 * importante aqui no es el calculo del nivel (eso ya lo cubre
 * RiskLevelsCalculatorTest/RiskLevelsTest) sino la semantica por
 * transicion: una posicion que sigue por debajo del stop no puede generar
 * una alerta nueva en cada visita a "Mi cartera", pero recuperar el nivel
 * y volver a perderlo si es un evento nuevo.
 *
 * Los niveles se construyen con RiskLevels::compute(100, 4, 2.5, 2), que
 * da stop-loss 90,00 y objetivo 120,00.
 */
final class AlertServiceStopLossTest extends TestCase
{
    private const STOP_LOSS = 90.0;

    private InMemoryAlertRepository $alerts;
    private InMemoryTickerStopLossAlertStateRepository $stopLossState;
    private AlertService $service;

    protected function setUp(): void
    {
        $this->alerts = new InMemoryAlertRepository();
        $this->stopLossState = new InMemoryTickerStopLossAlertStateRepository();
        $this->service = new AlertService(
            $this->alerts,
            new InMemoryTickerAlertStateRepository(),
            new InMemoryTickerDividendAlertStateRepository(),
            $this->stopLossState,
            new InMemoryTickerEarningsAlertStateRepository()
        );
    }

    private function user(): User
    {
        return new User(1, 'test@example.com', new DateTimeImmutable('2026-01-01 00:00:00'));
    }

    private function levels(): RiskLevels
    {
        return RiskLevels::compute(100.0, 4.0, 2.5, 2.0);
    }

    private function check(?float $price): void
    {
        $this->service->checkStopLossBreach($this->user(), 'ADBE', $this->levels(), $price, 'USD');
    }

    public function testPrecioPorEncimaDelStopNoAlerta(): void
    {
        $this->check(95.0);

        self::assertSame(0, $this->alerts->countCreated());
        self::assertSame('above', $this->stopLossState->getLastState($this->user(), 'ADBE'));
    }

    public function testPrimeraObservacionPorDebajoSoloFijaElEstado(): void
    {
        $this->check(85.0);

        self::assertSame(0, $this->alerts->countCreated());
        self::assertSame('below', $this->stopLossState->getLastState($this->user(), 'ADBE'));
    }

    public function testTransicionDeArribaAAbajoGeneraUnaSolaAlerta(): void
    {
        $this->check(95.0);
        $this->check(85.0);

        self::assertSame(1, $this->alerts->countCreated());
        self::assertSame('ADBE', $this->alerts->created()[0]['ticker']);
        self::assertSame(
            'ADBE ha perdido el stop-loss sugerido (precio 85,00 $, stop 90,00 $). Revisa si cierras la posicion.',
            $this->alerts->lastMessage()
        );
    }

    public function testMientrasSigaPorDebajoNoRepiteLaAlerta(): void
    {
        $this->check(95.0);
        $this->check(85.0);
        $this->check(84.0);
        $this->check(80.0);

        self::assertSame(1, $this->alerts->countCreated());
    }

    public function testRecuperarElNivelYVolverAPerderloAlertaDeNuevo(): void
    {
        $this->check(95.0);
        $this->check(85.0);
        $this->check(96.0);
        $this->check(88.0);

        self::assertSame(2, $this->alerts->countCreated());
    }

    public function testExactamenteEnElStopSeConsideraPerdido(): void
    {
        $this->check(95.0);
        $this->check(self::STOP_LOSS);

        self::assertSame(1, $this->alerts->countCreated());
    }

    public function testSinNivelesDeRiesgoNoHaceNada(): void
    {
        $this->service->checkStopLossBreach($this->user(), 'ADBE', null, 85.0, 'USD');

        self::assertSame(0, $this->alerts->countCreated());
        self::assertNull($this->stopLossState->getLastState($this->user(), 'ADBE'));
    }

    public function testSinPrecioActualNoHaceNada(): void
    {
        $this->check(null);

        self::assertSame(0, $this->alerts->countCreated());
        self::assertNull($this->stopLossState->getLastState($this->user(), 'ADBE'));
    }
}
