<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use StockAnalyzer\Models\User;
use StockAnalyzer\Repository\AlertRepository;

/**
 * AlertRepository en memoria para los tests de Services\AlertService: se
 * queda con los mensajes creados en vez de escribirlos en la BD, para
 * poder afirmar cuantas alertas se generaron y con que texto.
 *
 * Extiende el repositorio real (no implementa una interfaz nueva: no
 * existe, y no se crea una solo por los tests) sin llamar a su
 * constructor, asi que nunca hay Connection ni PDO por medio. Los metodos
 * de lectura del repositorio real no los usa AlertService, por eso no se
 * sobrescriben.
 */
final class InMemoryAlertRepository extends AlertRepository
{
    /** @var list<array{ticker: string, message: string}> */
    private array $created = [];

    public function __construct()
    {
    }

    public function create(User $user, string $ticker, string $message): void
    {
        $this->created[] = ['ticker' => strtoupper($ticker), 'message' => $message];
    }

    /**
     * @return list<array{ticker: string, message: string}>
     */
    public function created(): array
    {
        return $this->created;
    }

    public function countCreated(): int
    {
        return count($this->created);
    }

    public function lastMessage(): ?string
    {
        $last = end($this->created);

        return $last === false ? null : $last['message'];
    }
}
