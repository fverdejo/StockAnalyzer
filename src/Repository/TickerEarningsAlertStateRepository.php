<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use DateTimeImmutable;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Models\User;

/**
 * Guarda, por usuario y ticker, la fecha de resultados para la que ya se
 * genero una alerta de "resultados proximos" (ver
 * Services\AlertService::checkUpcomingEarnings()). Mismo molde que
 * `TickerDividendAlertStateRepository`: la clave de comparacion es "la
 * ultima fecha ya alertada", asi que no se repite la alerta cada dia
 * dentro de la misma ventana de aviso, pero si Yahoo publica una fecha de
 * resultados distinta (el siguiente trimestre, o una correccion de una
 * fecha estimada) vuelve a avisarse.
 */
class TickerEarningsAlertStateRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function getLastAlertedEarningsDate(User $user, string $ticker): ?DateTimeImmutable
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT last_alerted_earnings_date FROM ticker_earnings_alert_state WHERE user_id = :user_id AND ticker = :ticker'
        );
        $statement->execute([
            'user_id' => $user->getId(),
            'ticker' => strtoupper($ticker),
        ]);
        $value = $statement->fetchColumn();

        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }

    public function setLastAlertedEarningsDate(User $user, string $ticker, DateTimeImmutable $earningsDate): void
    {
        $statement = $this->connection->getPdo()->prepare(
            'INSERT INTO ticker_earnings_alert_state (user_id, ticker, last_alerted_earnings_date, updated_at)
             VALUES (:user_id, :ticker, :earnings_date, NOW())
             ON DUPLICATE KEY UPDATE last_alerted_earnings_date = VALUES(last_alerted_earnings_date), updated_at = NOW()'
        );
        $statement->execute([
            'user_id' => $user->getId(),
            'ticker' => strtoupper($ticker),
            'earnings_date' => $earningsDate->format('Y-m-d'),
        ]);
    }
}
