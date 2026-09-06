<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\DTO\RiskLevels;
use StockAnalyzer\Models\User;
use StockAnalyzer\Services\AlertService;

/**
 * Cubre la alerta de stop-loss perdido (ver versions.md v2.56).
 *
 * **Correccion del 2026-09-06** (bug real senalado por Astra/Codex,
 * `MEJORAS_MOTOR_ASTRA_2026-09-06.md`, P0): la version anterior de este
 * fichero construia SIEMPRE los niveles con `RiskLevels::compute(100, ...)`
 * mientras variaba el precio recibido -- un escenario que no puede darse
 * en produccion, porque `Application::analyzeHoldingsForAlerts()` recalcula
 * los niveles con el precio DE ESE MISMO INSTANTE en cada visita
 * (`$levels->getStopLoss()` siempre queda por debajo de ESE precio, por
 * construccion). Con niveles fijos artificiales, el bug (comparar el
 * precio contra un stop que se acaba de calcular con ese mismo precio)
 * quedaba invisible. Aqui `levels()` recalcula el stop con el MISMO precio
 * que se observa en cada llamada, replicando el flujo real, y el propio
 * `AlertService` es quien ahora adopta el nivel una vez y lo mantiene fijo
 * mientras la posicion siga abierta (ver `checkStopLossBreach()`).
 *
 * ATR14 constante de 4, multiplicador 2,5: riesgo = 10 por accion.
 */
final class AlertServiceStopLossTest extends TestCase
{
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

    /**
     * Igual que StockAnalysisService/Application: el stop se calcula CON el
     * mismo precio que se va a comparar, siempre 10 por debajo de el.
     */
    private function levels(float $price): RiskLevels
    {
        return RiskLevels::compute($price, 4.0, 2.5, 2.0);
    }

    private function check(?float $price, ?DateTimeImmutable $positionOpenedAt): void
    {
        $this->service->checkStopLossBreach(
            $this->user(),
            'ADBE',
            $price === null ? null : $this->levels($price),
            $price,
            $positionOpenedAt,
            'USD'
        );
    }

    private function openedAt(string $date = '2026-01-01'): DateTimeImmutable
    {
        return new DateTimeImmutable($date);
    }

    public function testLaPrimeraObservacionAdoptaElStopYNuncaAlerta(): void
    {
        $this->check(100.0, $this->openedAt());

        self::assertSame(0, $this->alerts->countCreated());
        self::assertSame('above', $this->stopLossState->getLastState($this->user(), 'ADBE'));
        self::assertSame(90.0, $this->stopLossState->getActiveStop($this->user(), 'ADBE')?->price);
    }

    public function testUnaCaidaPorDebajoDelStopAdoptadoGeneraUnaSolaAlerta(): void
    {
        $this->check(100.0, $this->openedAt()); // adopta stop=90
        $this->check(85.0, $this->openedAt());  // el stop recalculado hoy seria 75, pero compara contra 90

        self::assertSame(1, $this->alerts->countCreated());
        self::assertSame('ADBE', $this->alerts->created()[0]['ticker']);
        self::assertSame(
            'ADBE ha perdido el stop-loss sugerido (precio 85,00 $, stop 90,00 $). Revisa si cierras la posicion.',
            $this->alerts->lastMessage()
        );
    }

    public function testMientrasSigaPorDebajoNoRepiteLaAlerta(): void
    {
        $this->check(100.0, $this->openedAt());
        $this->check(85.0, $this->openedAt());
        $this->check(84.0, $this->openedAt());
        $this->check(80.0, $this->openedAt());

        self::assertSame(1, $this->alerts->countCreated());
    }

    public function testRecuperarElNivelYVolverAPerderloAlertaDeNuevo(): void
    {
        $this->check(100.0, $this->openedAt());
        $this->check(85.0, $this->openedAt());
        $this->check(96.0, $this->openedAt());
        $this->check(88.0, $this->openedAt());

        self::assertSame(2, $this->alerts->countCreated());
    }

    public function testExactamenteEnElStopAdoptadoSeConsideraPerdido(): void
    {
        $this->check(100.0, $this->openedAt()); // adopta stop=90
        $this->check(90.0, $this->openedAt());  // exactamente en el limite

        self::assertSame(1, $this->alerts->countCreated());
    }

    /**
     * El caso central que motivo la correccion: si el stop se recalculara
     * en cada visita con el precio de ese momento (el bug original), esta
     * caida NUNCA generaria alerta porque 60 > (60-10)=50 siempre. Con el
     * stop adoptado y fijo en 90, 60 esta claramente por debajo.
     */
    public function testUnaCaidaSostenidaQueElBugOriginalNuncaHabriaDetectado(): void
    {
        $this->check(100.0, $this->openedAt());
        $this->check(60.0, $this->openedAt());

        self::assertSame(1, $this->alerts->countCreated());
        self::assertSame(
            'ADBE ha perdido el stop-loss sugerido (precio 60,00 $, stop 90,00 $). Revisa si cierras la posicion.',
            $this->alerts->lastMessage()
        );
    }

    public function testCerrarLaPosicionYReabrirlaAdoptaUnStopNuevo(): void
    {
        $this->check(100.0, $this->openedAt('2026-01-01')); // adopta 90, racha 1
        $this->check(85.0, $this->openedAt('2026-01-01'));  // pierde 90, 1 alerta

        // Se vendio del todo y se volvio a comprar: nueva racha, precio de
        // reapertura mas bajo (60 -> stop 50). No hereda el 90 de la racha
        // anterior ya cerrada, y la adopcion nunca alerta.
        $this->check(60.0, $this->openedAt('2026-03-01'));

        self::assertSame(1, $this->alerts->countCreated(), 'La reapertura adopta, no alerta.');
        self::assertSame(50.0, $this->stopLossState->getActiveStop($this->user(), 'ADBE')?->price);

        // Con el stop de la nueva racha (50), una caida a 45 si alerta.
        $this->check(45.0, $this->openedAt('2026-03-01'));

        self::assertSame(2, $this->alerts->countCreated());
    }

    public function testSinNivelesDeRiesgoNoHaceNada(): void
    {
        $this->service->checkStopLossBreach($this->user(), 'ADBE', null, 85.0, $this->openedAt(), 'USD');

        self::assertSame(0, $this->alerts->countCreated());
        self::assertNull($this->stopLossState->getLastState($this->user(), 'ADBE'));
    }

    public function testSinPrecioActualNoHaceNada(): void
    {
        $this->check(null, $this->openedAt());

        self::assertSame(0, $this->alerts->countCreated());
        self::assertNull($this->stopLossState->getLastState($this->user(), 'ADBE'));
    }

    /**
     * Sin fecha de apertura de posicion no se puede saber si un stop ya
     * adoptado sigue perteneciendo a la misma racha: "dato no disponible"
     * tampoco aqui se convierte en "el stop se ha perdido".
     */
    public function testSinFechaDeAperturaDePosicionNoHaceNada(): void
    {
        $this->check(100.0, $this->openedAt());
        $this->check(85.0, null);

        self::assertSame(0, $this->alerts->countCreated());
    }

    /**
     * `isBelowActiveStop()` (2026-09-06, P2 de
     * `MEJORAS_MOTOR_ASTRA_2026-09-06.md`): lee el ULTIMO estado guardado,
     * no si se envio una alerta -- una posicion puede llevar dias por
     * debajo del stop sin generar una alerta nueva (ver
     * `testMientrasSigaPorDebajoNoRepiteLaAlerta`), pero
     * `PositionDecisionAdvisor` necesita saber que la condicion de salida
     * SIGUE activa, no solo que se disparo una vez.
     */
    public function testIsBelowActiveStopReflejaElUltimoEstado(): void
    {
        self::assertFalse($this->service->isBelowActiveStop($this->user(), 'ADBE'), 'Sin ninguna observacion todavia.');

        $this->check(100.0, $this->openedAt());
        self::assertFalse($this->service->isBelowActiveStop($this->user(), 'ADBE'), 'Adopcion: por encima por construccion.');

        $this->check(85.0, $this->openedAt());
        self::assertTrue($this->service->isBelowActiveStop($this->user(), 'ADBE'));

        $this->check(84.0, $this->openedAt());
        self::assertTrue($this->service->isBelowActiveStop($this->user(), 'ADBE'), 'Sigue por debajo, aunque no genere una alerta nueva.');

        $this->check(96.0, $this->openedAt());
        self::assertFalse($this->service->isBelowActiveStop($this->user(), 'ADBE'), 'Recupero el nivel.');
    }
}
