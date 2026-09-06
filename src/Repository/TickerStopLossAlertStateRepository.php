<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use DateTimeImmutable;
use StockAnalyzer\DTO\ActiveStopLoss;
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
 *
 * Desde el 2026-09-06 tambien guarda el `ActiveStopLoss` ADOPTADO para la
 * posicion abierta actual (`active_stop_price`/`position_opened_at`,
 * migracion 028): correccion del bug real senalado por Astra/Codex donde
 * el stop se recalculaba en cada visita con el precio del mismo momento
 * que se comparaba contra el, asi que la alerta nunca podia dispararse.
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

    /**
     * El stop activo adoptado, o `null` si nunca se adopto ninguno para
     * este usuario/ticker (primera vez, o se limpio al cerrarse la
     * posicion anterior -- ver `clearActiveStop()`).
     */
    public function getActiveStop(User $user, string $ticker): ?ActiveStopLoss
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT active_stop_price, position_opened_at FROM ticker_stop_loss_alert_state
             WHERE user_id = :user_id AND ticker = :ticker'
        );
        $statement->execute([
            'user_id' => $user->getId(),
            'ticker' => strtoupper($ticker),
        ]);
        $row = $statement->fetch();

        if ($row === false || $row['active_stop_price'] === null || $row['position_opened_at'] === null) {
            return null;
        }

        return new ActiveStopLoss(
            (float) $row['active_stop_price'],
            new DateTimeImmutable((string) $row['position_opened_at'])
        );
    }

    /**
     * Adopta (o reemplaza tras cerrarse la posicion anterior) el stop
     * activo. No se llama mientras la misma racha siga abierta: el stop
     * adoptado se queda fijo hasta que `currentPositionOpenedAt()` cambie
     * de valor (ver `AlertService::checkStopLossBreach()`).
     */
    public function setActiveStop(User $user, string $ticker, float $price, DateTimeImmutable $positionOpenedAt): void
    {
        $statement = $this->connection->getPdo()->prepare(
            'INSERT INTO ticker_stop_loss_alert_state (user_id, ticker, last_state, active_stop_price, position_opened_at, updated_at)
             VALUES (:user_id, :ticker, :state, :price, :opened_at, NOW())
             ON DUPLICATE KEY UPDATE
                active_stop_price = VALUES(active_stop_price),
                position_opened_at = VALUES(position_opened_at),
                updated_at = NOW()'
        );
        $statement->execute([
            'user_id' => $user->getId(),
            'ticker' => strtoupper($ticker),
            // La adopcion es la base de comparacion (mismo criterio que la
            // "primera observacion" de siempre): el precio que genero este
            // stop esta, por construccion, en o por encima de el.
            'state' => 'above',
            'price' => $price,
            'opened_at' => $positionOpenedAt->format('Y-m-d H:i:s'),
        ]);
    }
}
