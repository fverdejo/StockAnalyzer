<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Models;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Enums\TransactionType;
use StockAnalyzer\Models\Portfolio;
use StockAnalyzer\Models\Transaction;

/**
 * `Portfolio::getTransactionProfit()`/`getTransactionProfitPercent()`.
 *
 * La venta se corrigio en `v2.97`: antes se comparaba contra el precio de
 * mercado de HOY, la misma regla que una compra. Para una venta reciente
 * eso es casi siempre ~0 (el precio de venta y el de hoy son practicamente
 * el mismo numero), aunque la operacion se hubiera cerrado con beneficio o
 * perdida real frente a lo que costaron esas acciones — que es la pregunta
 * que de verdad importa al vender. Pasa a comparar contra el coste medio
 * en el momento exacto de venderse.
 *
 * La compra se corrigio en `v2.105`: hasta entonces comparaba contra el
 * precio de mercado de hoy ("como le iria a esta compra si se sigue el
 * precio hasta ahora"), lo que para una compra ya vendida por completo
 * duplicaba de forma confusa el mismo numero que el beneficio realizado
 * de su venta (bug real reportado por el usuario). Una compra pasa a
 * mostrar siempre 0: es un log de lo que paso EN esa operacion, y comprar
 * no genera ganancia ni perdida por si solo; el rendimiento de una
 * posicion todavia abierta ya se ve sin ambiguedad en "Beneficio latente".
 */
final class PortfolioTransactionProfitTest extends TestCase
{
    private function transaction(int $id, TransactionType $type, float $quantity, float $price): Transaction
    {
        return new Transaction($id, 1, 'ADBE', $type, $quantity, $price, new DateTimeImmutable('2026-08-21 20:25:00'));
    }

    public function testElBeneficioDeUnaCompraSiempreEsCeroAunqueElPrecioDeHoyHayaSubido(): void
    {
        $compra = $this->transaction(1, TransactionType::BUY, 10.0, 100.0);
        $portfolio = new Portfolio([], [$compra], 0.0, ['ADBE' => 120.0], ['ADBE' => 'USD']);

        self::assertSame(0.0, $portfolio->getTransactionProfit($compra));
        self::assertSame(0.0, $portfolio->getTransactionProfitPercent($compra));
    }

    public function testElBeneficioDeUnaCompraSiempreEsCeroAunqueElPrecioDeHoyHayaBajado(): void
    {
        $compra = $this->transaction(1, TransactionType::BUY, 10.0, 100.0);
        $portfolio = new Portfolio([], [$compra], 0.0, ['ADBE' => 60.0], ['ADBE' => 'USD']);

        self::assertSame(0.0, $portfolio->getTransactionProfit($compra));
        self::assertSame(0.0, $portfolio->getTransactionProfitPercent($compra));
    }

    /**
     * El caso exacto de la queja del usuario: una venta hecha hace un
     * minuto, con el precio de venta identico (o casi) al precio de
     * mercado de "hoy". Antes de la correccion esto daba 0,00 (0,00%)
     * siempre, sin importar si la venta fue rentable de verdad.
     */
    public function testUnaVentaRecienHechaNoSaleEnCeroSiHuboBeneficioOPerdidaReal(): void
    {
        $venta = $this->transaction(2, TransactionType::SELL, 10.0, 419.47);
        // Precio de mercado "de hoy" identico al de venta (venta reciente):
        // con la regla vieja, el beneficio habria salido exactamente 0.
        $portfolio = new Portfolio(
            [],
            [$venta],
            0.0,
            ['ADBE' => 419.47],
            ['ADBE' => 'USD'],
            null,
            [],
            null,
            null,
            [2 => 350.0] // coste medio de esas acciones cuando se compraron
        );

        self::assertEqualsWithDelta((419.47 - 350.0) * 10.0, $portfolio->getTransactionProfit($venta), 0.0001);
        self::assertGreaterThan(0.0, $portfolio->getTransactionProfit($venta), 'Se vendio por encima del coste: tiene que salir en beneficio, no en 0.');
    }

    public function testUnaVentaEnPerdidaSaleNegativaAunqueElPrecioDeHoyNoSeHayaMovido(): void
    {
        $venta = $this->transaction(3, TransactionType::SELL, 5.0, 90.0);
        $portfolio = new Portfolio(
            [],
            [$venta],
            0.0,
            ['ADBE' => 90.0],
            ['ADBE' => 'USD'],
            null,
            [],
            null,
            null,
            [3 => 120.0]
        );

        self::assertEqualsWithDelta(-150.0, $portfolio->getTransactionProfit($venta), 0.0001);
        self::assertEqualsWithDelta(-25.0, $portfolio->getTransactionProfitPercent($venta), 0.0001);
    }

    /**
     * Sin coste medio conocido para esa venta concreta (dato inconsistente,
     * no deberia pasar en produccion pero el metodo no puede inventarselo),
     * null en vez de un numero fabricado.
     */
    public function testUnaVentaSinCosteMedioConocidoDevuelveNull(): void
    {
        $venta = $this->transaction(4, TransactionType::SELL, 1.0, 100.0);
        $portfolio = new Portfolio([], [$venta], 0.0, ['ADBE' => 100.0], ['ADBE' => 'USD']);

        self::assertNull($portfolio->getTransactionProfit($venta));
        self::assertNull($portfolio->getTransactionProfitPercent($venta));
    }
}
