<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use DateTimeImmutable;
use PDO;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Models\User;

/**
 * Guarda, por usuario y ticker, la fecha ex-dividendo para la que ya se
 * genero una alerta de "proximo dividendo" (ver
 * Services\AlertService::checkUpcomingDividend()). Mismo patron que
 * `TickerAlertStateRepository`, pero la clave de comparacion no es "el
 * ultimo valor visto" sino "la ultima fecha ex-dividendo ya alertada":
 * evita crear una alerta nueva cada dia dentro de la ventana de aviso
 * para la MISMA fecha, sin bloquear una alerta nueva y legitima cuando
 * Yahoo anuncia una fecha ex-dividendo distinta (el siguiente reparto).
 */
class TickerDividendAlertStateRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function getLastAlertedExDividendDate(User $user, string $ticker): ?DateTimeImmutable
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT last_alerted_ex_dividend_date FROM ticker_dividend_alert_state WHERE user_id = :user_id AND ticker = :ticker'
        );
        $statement->execute([
            'user_id' => $user->getId(),
            'ticker' => strtoupper($ticker),
        ]);
        $value = $statement->fetchColumn();

        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }

    public function setLastAlertedExDividendDate(User $user, string $ticker, DateTimeImmutable $exDividendDate): void
    {
        $statement = $this->connection->getPdo()->prepare(
            'INSERT INTO ticker_dividend_alert_state (user_id, ticker, last_alerted_ex_dividend_date, updated_at)
             VALUES (:user_id, :ticker, :ex_dividend_date, NOW())
             ON DUPLICATE KEY UPDATE last_alerted_ex_dividend_date = VALUES(last_alerted_ex_dividend_date), updated_at = NOW()'
        );
        $statement->execute([
            'user_id' => $user->getId(),
            'ticker' => strtoupper($ticker),
            'ex_dividend_date' => $exDividendDate->format('Y-m-d'),
        ]);
    }
}
