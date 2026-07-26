<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

use StockAnalyzer\Enums\SignalVerdict;

/**
 * Representa la lectura de un unico dato (tecnico o fundamental) ya
 * interpretada: que mide, si es favorable, neutral o desfavorable, y una
 * frase explicativa en castellano. Los analizadores generan Signals a la
 * vez que calculan la puntuacion, para que la cifra y el texto explicativo
 * salgan siempre de la misma fuente y nunca se contradigan.
 */
class Signal
{
    public function __construct(
        private readonly string $label,
        private readonly SignalVerdict $verdict,
        private readonly string $message
    ) {
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getVerdict(): SignalVerdict
    {
        return $this->verdict;
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}
