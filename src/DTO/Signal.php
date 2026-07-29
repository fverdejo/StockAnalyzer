<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

use StockAnalyzer\Enums\ScoreCategory;
use StockAnalyzer\Enums\SignalVerdict;

/**
 * Representa la lectura de un unico dato (tecnico o fundamental) ya
 * interpretada: que mide, si es favorable, neutral o desfavorable, y una
 * frase explicativa en castellano. Los analizadores generan Signals a la
 * vez que calculan la puntuacion, para que la cifra y el texto explicativo
 * salgan siempre de la misma fuente y nunca se contradigan.
 *
 * `category` (ver versions.md v2.17) permite distinguir de donde viene
 * cada señal (tecnica, fundamental, valoracion...) sin tener que adivinarlo
 * por su `label`. Se anadio porque RecommendationExplainer e
 * IndicatorEducation, al no distinguir el origen, acababan mostrando casi
 * siempre señales tecnicas en el resumen y en "indicadores determinantes"
 * (el analisis tecnico genera mas señales por accion que el fundamental, y
 * ademas se calcula primero), dejando fuera el analisis fundamental que
 * tambien se calcula.
 */
class Signal
{
    public function __construct(
        private readonly string $label,
        private readonly SignalVerdict $verdict,
        private readonly string $message,
        private readonly ScoreCategory $category = ScoreCategory::TECHNICAL
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

    public function getCategory(): ScoreCategory
    {
        return $this->category;
    }

    /**
     * "Tecnica" en sentido amplio: TECHNICAL, MOMENTUM y RISK se calculan
     * a partir del precio/volumen/indicadores (TechnicalScoreAnalyzer);
     * el resto (FUNDAMENTAL, VALUATION, QUALITY, DIVIDEND, NEWS) viene de
     * los resultados financieros de la empresa o de noticias, no del
     * grafico de precio.
     */
    public function isTechnical(): bool
    {
        return in_array($this->category, [ScoreCategory::TECHNICAL, ScoreCategory::MOMENTUM, ScoreCategory::RISK], true);
    }
}
