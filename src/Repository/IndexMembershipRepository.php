<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use DateTimeImmutable;
use PDO;
use StockAnalyzer\DTO\IndexMembershipRecord;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Interfaces\IndexMembershipCheckerInterface;

/**
 * Repositorio normalizado de membresia de indice
 * (`022_create_index_membership.sql`, roadmap.md "Segundo bloque" punto 2,
 * 2026-09-02): ticker/indice/fecha de entrada/fecha de salida/activo o
 * delisted. Poblado desde `EodhdIndexMembershipParser`, hoy solo para
 * `GSPC` (S&P 500) -- ver el docblock de la migracion 022 para el porque.
 */
class IndexMembershipRepository implements IndexMembershipCheckerInterface
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * UPSERT por (ticker, index_code): una re-ingesta actualiza fechas y
     * estado en vez de duplicar filas, mismo criterio que
     * `EodhdRawFundamentalsRepository::store()`.
     *
     * @param list<IndexMembershipRecord> $records
     */
    public function storeAll(array $records): void
    {
        $statement = $this->connection->getPdo()->prepare(
            'INSERT INTO index_membership
                (ticker, index_code, company_name, start_date, end_date, is_active_now, is_delisted, original_symbol, source)
             VALUES
                (:ticker, :index_code, :company_name, :start_date, :end_date, :is_active_now, :is_delisted, :original_symbol, :source)
             ON DUPLICATE KEY UPDATE
                company_name = VALUES(company_name),
                start_date = VALUES(start_date),
                end_date = VALUES(end_date),
                is_active_now = VALUES(is_active_now),
                is_delisted = VALUES(is_delisted),
                original_symbol = VALUES(original_symbol),
                source = VALUES(source)'
        );

        foreach ($records as $record) {
            $statement->execute([
                'ticker' => $record->ticker,
                'index_code' => $record->indexCode,
                'company_name' => $record->companyName,
                'start_date' => $record->startDate?->format('Y-m-d'),
                'end_date' => $record->endDate?->format('Y-m-d'),
                'is_active_now' => $record->isActiveNow ? 1 : 0,
                'is_delisted' => $record->isDelisted ? 1 : 0,
                'original_symbol' => $record->originalSymbol,
                'source' => 'eodhd_historical_ticker_components',
            ]);
        }
    }

    /**
     * Si $ticker era miembro de $indexCode en $date. `start_date` NULL
     * cuenta como "ya era miembro" (EODHD no conoce cuando entro, pero
     * confirma que estaba dentro cuando empezo su propio rastreo); `end_date`
     * NULL con `is_active_now` cuenta como "sigue siendo miembro hoy" -- ver
     * `IndexMembershipRecord::coversDate()`, misma logica aqui expresada en
     * SQL para no traer toda la tabla a PHP en cada consulta.
     */
    public function isMemberAt(string $ticker, string $indexCode, DateTimeImmutable $date): bool
    {
        // Dos placeholders distintos para la misma fecha (:date_start /
        // :date_end): con PDO::ATTR_EMULATE_PREPARES en false (ver
        // IntegrationTestCase, mismo driver que produccion) un parametro
        // nombrado no se puede reutilizar dos veces en la misma sentencia.
        $statement = $this->connection->getPdo()->prepare(
            'SELECT 1 FROM index_membership
             WHERE ticker = :ticker AND index_code = :index_code
                AND (start_date IS NULL OR start_date <= :date_start)
                AND (end_date IS NULL OR end_date >= :date_end)
             LIMIT 1'
        );
        $statement->execute([
            'ticker' => strtoupper($ticker),
            'index_code' => strtoupper($indexCode),
            'date_start' => $date->format('Y-m-d'),
            'date_end' => $date->format('Y-m-d'),
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Todos los tickers que fueron miembro de $indexCode en algun momento
     * (activos hoy o no) y que YA NO estan en $currentTickers -- el
     * universo de "antiguos componentes" del punto 3 del plan. Se filtra en
     * PHP, no en SQL, porque $currentTickers viene de `config/universes.php`
     * (`UniverseTickerResolver`), no de otra tabla.
     *
     * @param list<string> $currentTickers
     * @return list<string>
     */
    public function formerMembersNotIn(string $indexCode, array $currentTickers): array
    {
        $currentSet = array_flip(array_map(
            static fn (string $ticker): string => strtoupper($ticker),
            $currentTickers
        ));

        $statement = $this->connection->getPdo()->prepare(
            'SELECT DISTINCT ticker FROM index_membership
             WHERE index_code = :index_code AND is_active_now = 0'
        );
        $statement->execute(['index_code' => strtoupper($indexCode)]);

        /** @var list<string> $tickers */
        $tickers = $statement->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_filter(
            $tickers,
            static fn (string $ticker): bool => !isset($currentSet[$ticker])
        ));
    }

    public function count(string $indexCode): int
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT COUNT(*) FROM index_membership WHERE index_code = :index_code'
        );
        $statement->execute(['index_code' => strtoupper($indexCode)]);

        return (int) $statement->fetchColumn();
    }
}
