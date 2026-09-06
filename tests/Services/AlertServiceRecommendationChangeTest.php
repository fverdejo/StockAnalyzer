<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Models\User;
use StockAnalyzer\Services\AlertService;

/**
 * `AlertService::checkRecommendationChange()` no tenia test dedicado (solo
 * se ejercitaba indirectamente vía `ApplicationHoldingsAnalysisTest`).
 *
 * Cubre ademas la guarda anhadida el 2026-09-06 (bug real senalado por
 * Astra/Codex, `MEJORAS_MOTOR_ASTRA_2026-09-06.md`, P1): `'DATOS_INSUFICIENTES'`
 * (ver `DTO\StockAnalysis::getRecommendation()`) no es una recomendacion de
 * mercado que haya cambiado, es una falta de dato -- ni genera alerta ni se
 * guarda como "ultimo estado", para que al recuperarse el dato la
 * comparacion siga siendo contra la ultima recomendacion REAL conocida.
 */
final class AlertServiceRecommendationChangeTest extends TestCase
{
    private InMemoryAlertRepository $alerts;
    private InMemoryTickerAlertStateRepository $state;
    private AlertService $service;

    protected function setUp(): void
    {
        $this->alerts = new InMemoryAlertRepository();
        $this->state = new InMemoryTickerAlertStateRepository();
        $this->service = new AlertService(
            $this->alerts,
            $this->state,
            new InMemoryTickerDividendAlertStateRepository(),
            new InMemoryTickerStopLossAlertStateRepository(),
            new InMemoryTickerEarningsAlertStateRepository()
        );
    }

    private function user(): User
    {
        return new User(1, 'test@example.com', new DateTimeImmutable('2026-01-01 00:00:00'));
    }

    public function testLaPrimeraObservacionSoloFijaElEstado(): void
    {
        $this->service->checkRecommendationChange($this->user(), 'ADBE', 'BUY');

        self::assertSame(0, $this->alerts->countCreated());
        self::assertSame('BUY', $this->state->getLastRecommendation($this->user(), 'ADBE'));
    }

    public function testUnCambioRealGeneraUnaAlerta(): void
    {
        $this->service->checkRecommendationChange($this->user(), 'ADBE', 'BUY');
        $this->service->checkRecommendationChange($this->user(), 'ADBE', 'SELL');

        self::assertSame(1, $this->alerts->countCreated());
        self::assertSame('ADBE ha pasado de Comprar a Vender.', $this->alerts->lastMessage());
    }

    public function testSinCambioNoGeneraAlerta(): void
    {
        $this->service->checkRecommendationChange($this->user(), 'ADBE', 'HOLD');
        $this->service->checkRecommendationChange($this->user(), 'ADBE', 'HOLD');

        self::assertSame(0, $this->alerts->countCreated());
    }

    /**
     * El caso que motivo la guarda: sin datos suficientes no es un cambio
     * de recomendacion de mercado, es una falta de dato.
     */
    public function testDatosInsuficientesNuncaGeneraAlerta(): void
    {
        $this->service->checkRecommendationChange($this->user(), 'ADBE', 'BUY');
        $this->service->checkRecommendationChange($this->user(), 'ADBE', 'DATOS_INSUFICIENTES');

        self::assertSame(0, $this->alerts->countCreated());
    }

    /**
     * Tampoco se guarda como "ultimo estado": al recuperarse el dato, la
     * comparacion sigue siendo contra BUY (la ultima recomendacion REAL),
     * no contra "sin datos" -- así que volver a BUY no cuenta como cambio,
     * pero pasar de BUY a algo real distinto si.
     */
    public function testNoSeGuardaComoUltimoEstadoConocido(): void
    {
        $this->service->checkRecommendationChange($this->user(), 'ADBE', 'BUY');
        $this->service->checkRecommendationChange($this->user(), 'ADBE', 'DATOS_INSUFICIENTES');

        self::assertSame('BUY', $this->state->getLastRecommendation($this->user(), 'ADBE'));

        $this->service->checkRecommendationChange($this->user(), 'ADBE', 'BUY');
        self::assertSame(0, $this->alerts->countCreated(), 'Seguir en BUY tras un hueco de datos no es un cambio.');

        $this->service->checkRecommendationChange($this->user(), 'ADBE', 'SELL');
        self::assertSame(1, $this->alerts->countCreated(), 'De BUY a SELL, ignorando el hueco de datos, si es un cambio real.');
    }

    /**
     * `DATOS_INSUFICIENTES` como PRIMERA observacion (nunca hubo una
     * recomendacion real todavia): tampoco fija ninguna base, para no
     * comparar mas adelante contra "sin datos".
     */
    public function testDatosInsuficientesComoPrimeraObservacionNoFijaBase(): void
    {
        $this->service->checkRecommendationChange($this->user(), 'ADBE', 'DATOS_INSUFICIENTES');

        self::assertNull($this->state->getLastRecommendation($this->user(), 'ADBE'));

        $this->service->checkRecommendationChange($this->user(), 'ADBE', 'BUY');
        self::assertSame(0, $this->alerts->countCreated(), 'La primera recomendacion REAL sigue sin base previa que comparar.');
    }
}
