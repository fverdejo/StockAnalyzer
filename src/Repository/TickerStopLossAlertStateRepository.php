<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Models\User;

/**
 * Guarda, por usuario y ticker, si la ultima vez que se miro el precio
 * estaba por encima o por debajo del stop-loss sugerido (ver
 * Services\AlertService::checkStopLossBreach()). Mismo patron que
 * `TickerAlertStateRepository`: el valor guardado es "el ultimo estado
 * visto", no "la ultima alerta enviada", porque la alerta se decide por
 * transicion (above -> below) y no por el estado absoluto. Asi una
 * posicion que sigue por debajo del stop no genera una alerta nueva cada
 * dia, pero si vuelve a recuperar el nivel y lo pierde otra vez si.
 */
class TickerStopLossAlertStateRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function getLastState(User $user, string $ticker): ?string
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT last_state FROM ticker_stop_loss_alert_state WHERE user_id = :user_id AND ticker = :ticker'
        );
        $statement->execute([
            'user_id' => $user->getId(),
            'ticker' => strtoupper($ticker),
        ]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    public function setLastState(User $user, string $ticker, string $state): void
    {
        $statement = $this->connection->getPdo()->prepare(
            'INSERT INTO ticker_stop_loss_alert_state (user_id, ticker, last_state, updated_at)
             VALUES (:user_id, :ticker, :state, NOW())
             ON DUPLICATE KEY UPDATE last_state = VALUES(last_state), updated_at = NOW()'
        );
        $statement->execute([
            'user_id' => $user->getId(),
            'ticker' => strtoupper($ticker),
            'state' => $state,
        ]);
    }
}
