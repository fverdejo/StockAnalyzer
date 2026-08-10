<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Repository;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Repository\FundamentalsHistoryRepository;

/**
 * `fundamentals_history` (v2.74) acumula desde hoy la serie que hara
 * backtesteable algun dia el 56% del peso del score. Como no habra
 * oportunidad de rehacer los snapshots pasados —Yahoo no sirve
 * fundamentales fechados—, lo que se guarda mal hoy queda mal para
 * siempre. Estos casos fijan el formato del payload.
 *
 * La escritura contra MySQL no se puede testear sin base de datos (el
 * `NOW()` del UPSERT descarta SQLite), asi que aqui se prueba la parte
 * pura: la serializacion.
 */
final class FundamentalsHistorySnapshotTest extends TestCase
{
    private function fullFundamentals(): Fundamentals
    {
        return new Fundamentals(
            per: 20.0,
            peg: 1.5,
            roe: 25.0,
            roic: 12.0,
            eps: 5.0,
            marketCap: 1_000_000.0,
            debtToEquity: 0.4,
            freeCashFlow: 50_000.0,
            evToEbitda: 9.0,
            priceToBook: 3.0,
            dividendYield: 2.5,
            payoutRatio: 40.0,
            grossMargin: 55.0,
            operatingMargin: 30.0,
            netMargin: 22.0,
            revenueGrowth: 8.0,
            currentRatio: 1.8,
            dividendGrowth5y: 6.0
        );
    }

    public function testGuardaLos18CamposConSuValor(): void
    {
        $payload = FundamentalsHistoryRepository::toArray($this->fullFundamentals());

        self::assertCount(18, $payload);
        self::assertSame(20.0, $payload['per']);
        self::assertSame(0.4, $payload['debtToEquity']);
        self::assertSame(6.0, $payload['dividendGrowth5y']);
        self::assertSame(1.8, $payload['currentRatio']);
    }

    /**
     * FCF/capitalizacion es derivado de dos campos que si se guardan, asi
     * que no se persiste: duplicar un dato calculable es la via a que un
     * dia no cuadren entre si.
     */
    public function testNoGuardaElCampoDerivado(): void
    {
        self::assertArrayNotHasKey('freeCashFlowYield', FundamentalsHistoryRepository::toArray($this->fullFundamentals()));
    }

    /**
     * Un null es informacion: significa "el proveedor no dio este dato ese
     * dia". Si se omitiera la clave, al releer el snapshot no se podria
     * distinguir de un campo que aun no existia.
     */
    public function testLosNullSeConservanComoNull(): void
    {
        $payload = FundamentalsHistoryRepository::toArray(Fundamentals::empty());

        self::assertCount(18, $payload);
        self::assertArrayHasKey('roic', $payload);
        self::assertNull($payload['roic']);
        self::assertNull($payload['per']);
    }

    /**
     * El orden de las claves es estable: el payload se guarda como JSON y
     * un orden cambiante generaria diferencias falsas al compararlos.
     */
    public function testElOrdenDeLasClavesEsEstable(): void
    {
        self::assertSame(
            array_keys(FundamentalsHistoryRepository::toArray($this->fullFundamentals())),
            array_keys(FundamentalsHistoryRepository::toArray(Fundamentals::empty()))
        );
    }

    /**
     * Un ratio que caiga en un valor entero debe volver del JSON como
     * float y no como int (`JSON_PRESERVE_ZERO_FRACTION`): en un registro
     * historico que no se podra rehacer, el tipo tambien es parte del dato.
     */
    public function testElPayloadConservaLosFloatEnteros(): void
    {
        $json = json_encode(
            FundamentalsHistoryRepository::toArray($this->fullFundamentals()),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
        );

        self::assertJson($json);
        self::assertStringContainsString('"per":20.0', $json);
        self::assertSame(20.0, json_decode($json, true)['per']);
    }
}
