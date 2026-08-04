<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use DateTimeImmutable;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Models\Score;

/**
 * Historial de puntuaciones por ticker/dia (ver versions.md v2.53), pensado
 * para poder comparar en el futuro el score de un ticker hoy contra hace N
 * dias ("re-rating"). Solo captura: no hay todavia ninguna lectura de
 * tendencia porque no existe aun suficiente historial acumulado para que
 * una señal de ese tipo sea fiable (idea anotada por analista-mercado el
 * 2026-08-03 en "Ideas adicionales sugeridas").
 *
 * Una fila por ticker/dia: `recordSnapshot()` es idempotente para visitas
 * repetidas el mismo dia (mismo patron UPSERT que
 * DailyRankingRepository::save()/MarketDataCacheRepository, via la clave
 * unica (ticker, snapshot_date)), sobrescribiendo con la puntuacion mas
 * reciente de ese dia en vez de acumular una fila por visita.
 */
class ScoreHistoryRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function recordSnapshot(string $ticker, Score $score, ?DateTimeImmutable $date = null): void
    {
        $date ??= new DateTimeImmutable();
        $breakdown = json_encode($score->getScores(), JSON_THROW_ON_ERROR);

        $statement = $this->connection->getPdo()->prepare(
            'INSERT INTO score_history (ticker, snapshot_date, total_score, max_total, percentage, category_breakdown, created_at)
             VALUES (:ticker, :snapshot_date, :total_score, :max_total, :percentage, :category_breakdown, NOW())
             ON DUPLICATE KEY UPDATE
                total_score = VALUES(total_score),
                max_total = VALUES(max_total),
                percentage = VALUES(percentage),
                category_breakdown = VALUES(category_breakdown),
                created_at = NOW()'
        );
        $statement->execute([
            'ticker' => strtoupper($ticker),
            'snapshot_date' => $date->format('Y-m-d'),
            'total_score' => $score->getTotal(),
            'max_total' => $score->getMaxTotal(),
            'percentage' => $score->getPercentage(),
            'category_breakdown' => $breakdown,
        ]);
    }
}
