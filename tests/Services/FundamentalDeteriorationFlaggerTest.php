<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\DTO\FundamentalTtmSnapshot;
use StockAnalyzer\Services\FundamentalDeteriorationFlagger;

/**
 * E1 ("Deterioro fundamental",
 * `PLAN_APROVECHAMIENTO_EODHD_Y_FUNDAMENTALES_2026-09-04.md` Bloque E):
 * fija el criterio EXACTO de la bandera compuesta predeclarada por
 * `auditor-estadistico` el 2026-09-05:
 *
 *     deterioro = (margen BAJA Y ROIC BAJA Y FCF BAJA)
 *              O (deuda/patrimonio SUBE Y FCF BAJA)
 *
 * Cubre cada combinacion de las dos clausulas OR y, sobre todo, que la
 * ausencia de dato en un factor lo EXCLUYE de la formula en vez de
 * contarlo como "no empeoro" (mismo criterio "ausencia no es neutral" que
 * `FundamentalChangeAssessor`/P3.3).
 */
final class FundamentalDeteriorationFlaggerTest extends TestCase
{
    private function snapshot(
        ?float $operatingMargin,
        ?float $roic,
        ?float $freeCashFlowYield,
        ?float $debtToEquity
    ): FundamentalTtmSnapshot {
        return new FundamentalTtmSnapshot($operatingMargin, $roic, $freeCashFlowYield, $debtToEquity);
    }

    private function flagger(): FundamentalDeteriorationFlagger
    {
        return new FundamentalDeteriorationFlagger();
    }

    /**
     * Clausula A sola: margen, ROIC y FCF yield bajan los tres a la vez.
     * Deuda/patrimonio se queda igual (no sube), asi que la clausula B no
     * aporta nada -- el resultado depende solo de A.
     */
    public function testMargenRoicYFcfBajanALaVezEsDeterioro(): void
    {
        $current = $this->snapshot(operatingMargin: 10.0, roic: 8.0, freeCashFlowYield: 2.0, debtToEquity: 1.0);
        $previousYear = $this->snapshot(operatingMargin: 15.0, roic: 12.0, freeCashFlowYield: 4.0, debtToEquity: 1.0);

        self::assertTrue($this->flagger()->isDeteriorating($current, $previousYear));
    }

    /**
     * Clausula B sola: deuda/patrimonio sube y FCF yield baja. Margen y
     * ROIC MEJORAN (no bajan), asi que la clausula A no puede cumplirse --
     * el resultado depende solo de B.
     */
    public function testDeudaSubeYFcfBajaEsDeterioro(): void
    {
        $current = $this->snapshot(operatingMargin: 20.0, roic: 18.0, freeCashFlowYield: 2.0, debtToEquity: 1.5);
        $previousYear = $this->snapshot(operatingMargin: 15.0, roic: 12.0, freeCashFlowYield: 4.0, debtToEquity: 1.0);

        self::assertTrue($this->flagger()->isDeteriorating($current, $previousYear));
    }

    /**
     * Las dos clausulas se cumplen a la vez: sigue siendo `true` (es un OR,
     * no exige que se cumpla una unica via).
     */
    public function testAmbasClausulasCumplidasSimultaneamenteEsDeterioro(): void
    {
        $current = $this->snapshot(operatingMargin: 10.0, roic: 8.0, freeCashFlowYield: 2.0, debtToEquity: 2.0);
        $previousYear = $this->snapshot(operatingMargin: 15.0, roic: 12.0, freeCashFlowYield: 4.0, debtToEquity: 1.0);

        self::assertTrue($this->flagger()->isDeteriorating($current, $previousYear));
    }

    public function testNingunaClausulaSeCumpleNoEsDeterioro(): void
    {
        // Todo mejora: margen, ROIC y FCF suben, deuda baja.
        $current = $this->snapshot(operatingMargin: 20.0, roic: 18.0, freeCashFlowYield: 6.0, debtToEquity: 0.5);
        $previousYear = $this->snapshot(operatingMargin: 15.0, roic: 12.0, freeCashFlowYield: 4.0, debtToEquity: 1.0);

        self::assertFalse($this->flagger()->isDeteriorating($current, $previousYear));
    }

    /**
     * Solo margen baja (ROIC y FCF no bajan): la clausula A exige los TRES
     * a la vez, dos de tres no basta.
     */
    public function testSoloMargenBajaNoBastaParaLaClausulaA(): void
    {
        $current = $this->snapshot(operatingMargin: 10.0, roic: 15.0, freeCashFlowYield: 5.0, debtToEquity: 1.0);
        $previousYear = $this->snapshot(operatingMargin: 15.0, roic: 12.0, freeCashFlowYield: 4.0, debtToEquity: 1.0);

        self::assertFalse($this->flagger()->isDeteriorating($current, $previousYear));
    }

    /**
     * Deuda sube pero FCF NO baja: la clausula B exige las dos condiciones
     * a la vez.
     */
    public function testDeudaSubeSinFcfBajaNoBastaParaLaClausulaB(): void
    {
        $current = $this->snapshot(operatingMargin: 20.0, roic: 18.0, freeCashFlowYield: 6.0, debtToEquity: 1.5);
        $previousYear = $this->snapshot(operatingMargin: 15.0, roic: 12.0, freeCashFlowYield: 4.0, debtToEquity: 1.0);

        self::assertFalse($this->flagger()->isDeteriorating($current, $previousYear));
    }

    /**
     * Margen sin dato en la fecha ACTUAL: se excluye de la clausula A, que
     * ya no puede confirmarse aunque ROIC y FCF si bajen. Sin deuda al
     * alza, la clausula B tampoco se cumple -- resultado `false`, nunca
     * "false porque el margen no bajo" (el margen no se evalua en absoluto).
     */
    public function testMargenSinDatoEnFechaActualExcluyeLaClausulaA(): void
    {
        $current = $this->snapshot(operatingMargin: null, roic: 8.0, freeCashFlowYield: 2.0, debtToEquity: 1.0);
        $previousYear = $this->snapshot(operatingMargin: 15.0, roic: 12.0, freeCashFlowYield: 4.0, debtToEquity: 1.0);

        self::assertFalse($this->flagger()->isDeteriorating($current, $previousYear));
    }

    /**
     * Mismo caso que arriba pero la ausencia esta en la fecha DE HACE UN
     * AÑO, no en la actual: tambien excluye el factor (ausencia en
     * CUALQUIERA de las dos fechas).
     */
    public function testMargenSinDatoEnFechaAnteriorExcluyeLaClausulaA(): void
    {
        $current = $this->snapshot(operatingMargin: 10.0, roic: 8.0, freeCashFlowYield: 2.0, debtToEquity: 1.0);
        $previousYear = $this->snapshot(operatingMargin: null, roic: 12.0, freeCashFlowYield: 4.0, debtToEquity: 1.0);

        self::assertFalse($this->flagger()->isDeteriorating($current, $previousYear));
    }

    /**
     * Consecuencia directa de que las dos clausulas compartan "FCF BAJA"
     * (documentada en el docblock de `FundamentalDeteriorationFlagger`):
     * sin dato de FCF yield en cualquiera de las dos fechas, NINGUNA
     * clausula puede cumplirse, aunque margen y ROIC bajen Y deuda suba a
     * la vez.
     */
    public function testSinDatoDeFcfEnCualquieraDeLasDosFechasImpideAmbasClausulas(): void
    {
        $current = $this->snapshot(operatingMargin: 10.0, roic: 8.0, freeCashFlowYield: null, debtToEquity: 1.5);
        $previousYear = $this->snapshot(operatingMargin: 15.0, roic: 12.0, freeCashFlowYield: 4.0, debtToEquity: 1.0);

        self::assertFalse($this->flagger()->isDeteriorating($current, $previousYear));
    }

    /**
     * ROIC sin dato en NINGUNA de las dos fechas (los dos null a la vez):
     * mismo criterio de exclusion, no un caso distinto.
     */
    public function testRoicSinDatoEnNingunaDeLasDosFechasExcluyeElFactor(): void
    {
        $current = $this->snapshot(operatingMargin: 10.0, roic: null, freeCashFlowYield: 2.0, debtToEquity: 1.0);
        $previousYear = $this->snapshot(operatingMargin: 15.0, roic: null, freeCashFlowYield: 4.0, debtToEquity: 1.0);

        self::assertFalse($this->flagger()->isDeteriorating($current, $previousYear));
    }

    /**
     * Ruido de redondeo de coma flotante (por debajo del NOISE_EPSILON de
     * 0,001) no cuenta como bajada real: con un cambio de 0,0001 en los
     * tres factores de la clausula A, ninguno se considera "bajado".
     */
    public function testRuidoDeRedondeoPorDebajoDelEpsilonNoCuentaComoBajada(): void
    {
        $current = $this->snapshot(operatingMargin: 15.0001, roic: 12.0001, freeCashFlowYield: 4.0001, debtToEquity: 1.0);
        $previousYear = $this->snapshot(operatingMargin: 15.0, roic: 12.0, freeCashFlowYield: 4.0, debtToEquity: 1.0);

        self::assertFalse($this->flagger()->isDeteriorating($current, $previousYear));
    }
}
