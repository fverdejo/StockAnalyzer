<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

use DateTimeImmutable;

/**
 * Un pago de dividendo real (ex-dividendo), tal y como lo devuelve el
 * proveedor de mercado (events.dividends de Yahoo Finance). Un objeto por
 * pago, sin agregar ni anualizar: eso es responsabilidad de
 * Services\DividendGrowthCalculator, que decide como sumar pagos en
 * ventanas de 12 meses porque la periodicidad real varia por ticker
 * (trimestral en la mayoria de EEUU, semestral/anual en muchos valores de
 * ibex35).
 */
class DividendPayment
{
    public function __construct(
        private readonly DateTimeImmutable $date,
        private readonly float $amount
    ) {
    }

    public function getDate(): DateTimeImmutable
    {
        return $this->date;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }
}
