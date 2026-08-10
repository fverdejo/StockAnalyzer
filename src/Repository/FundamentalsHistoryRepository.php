<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use DateTimeImmutable;
use PDO;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Models\Fundamentals;

/**
 * Snapshot diario de los fundamentales de cada ticker (ver versions.md
 * v2.74), la pieza que falta para poder backtestear algun dia el 56% del
 * score que hoy entra en todo backtest como constante por ticker.
 *
 * Solo captura y lectura basica: no hay ningun consumidor todavia porque
 * no existe historial acumulado. Es deliberado — el valor de esta tabla
 * aparece con el tiempo, asi que se siembra ahora aunque no luzca.
 *
 * Idempotente por ticker/dia, mismo patron UPSERT que
 * ScoreHistoryRepository: varias visitas el mismo dia sobrescriben en vez
 * de acumular filas.
 */
class FundamentalsHistoryRepository
{
    /**
     * Campos que se guardan, en orden estable. Se listan explicitamente y
     * no via reflexion para que añadir un getter a Fundamentals no cambie
     * en silencio el formato de todo el historico ya acumulado: cuando se
     * añada un ratio nuevo, esta lista es el sitio donde decidirlo.
     *
     * `freeCashFlowYield` no esta porque es derivado (FCF/capitalizacion) y
     * se puede recalcular de los dos que si estan.
     */
    private const FIELDS = [
        'per' => 'getPer',
        'peg' => 'getPeg',
        'roe' => 'getRoe',
        'roic' => 'getRoic',
        'eps' => 'getEps',
        'marketCap' => 'getMarketCap',
        'debtToEquity' => 'getDebtToEquity',
        'freeCashFlow' => 'getFreeCashFlow',
        'evToEbitda' => 'getEvToEbitda',
        'priceToBook' => 'getPriceToBook',
        'dividendYield' => 'getDividendYield',
        'payoutRatio' => 'getPayoutRatio',
        'grossMargin' => 'getGrossMargin',
        'operatingMargin' => 'getOperatingMargin',
        'netMargin' => 'getNetMargin',
        'revenueGrowth' => 'getRevenueGrowth',
        'currentRatio' => 'getCurrentRatio',
        'dividendGrowth5y' => 'getDividendGrowth5y',
    ];

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function recordSnapshot(string $ticker, Fundamentals $fundamentals, ?DateTimeImmutable $date = null): void
    {
        $date ??= new DateTimeImmutable();
        // JSON_PRESERVE_ZERO_FRACTION: sin el, un ratio que caiga en un
        // valor entero (un PER de 20.0) se escribe como `20` y al releerlo
        // vuelve como int, no como float. Aqui se esta construyendo un
        // registro historico que se leera dentro de meses y que no se
        // podra rehacer, asi que el tipo tambien es parte del dato.
        $payload = json_encode(
            self::toArray($fundamentals),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
        );

        $statement = $this->connection->getPdo()->prepare(
            'INSERT INTO fundamentals_history (ticker, snapshot_date, fundamentals_payload, created_at)
             VALUES (:ticker, :snapshot_date, :fundamentals_payload, NOW())
             ON DUPLICATE KEY UPDATE
                fundamentals_payload = VALUES(fundamentals_payload),
                created_at = NOW()'
        );
        $statement->execute([
            'ticker' => strtoupper($ticker),
            'snapshot_date' => $date->format('Y-m-d'),
            'fundamentals_payload' => $payload,
        ]);
    }

    /**
     * Fundamentales tal y como se veian en una fecha, o el snapshot
     * anterior mas cercano si ese dia concreto no se capturo (fines de
     * semana, dias sin ejecucion). Devuelve null si no hay ningun snapshot
     * en esa fecha o antes: para un backtest es preferible saltar la
     * muestra que usar datos del futuro, que es justo el sesgo que esta
     * tabla existe para eliminar.
     *
     * @return array<string,float|null>|null
     */
    public function findAsOf(string $ticker, DateTimeImmutable $date): ?array
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT fundamentals_payload
               FROM fundamentals_history
              WHERE ticker = :ticker AND snapshot_date <= :snapshot_date
           ORDER BY snapshot_date DESC
              LIMIT 1'
        );
        $statement->execute([
            'ticker' => strtoupper($ticker),
            'snapshot_date' => $date->format('Y-m-d'),
        ]);
        $payload = $statement->fetchColumn();

        if (!is_string($payload)) {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Cuantos dias distintos se han capturado de un ticker. Sirve para
     * saber cuando el historial es ya suficiente para intentar un backtest
     * fundamental de verdad.
     */
    public function countSnapshots(string $ticker): int
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT COUNT(*) FROM fundamentals_history WHERE ticker = :ticker'
        );
        $statement->execute(['ticker' => strtoupper($ticker)]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<string,float|null>
     */
    public static function toArray(Fundamentals $fundamentals): array
    {
        $values = [];

        foreach (self::FIELDS as $key => $getter) {
            /** @var float|null $value */
            $value = $fundamentals->{$getter}();
            $values[$key] = $value;
        }

        return $values;
    }
}
