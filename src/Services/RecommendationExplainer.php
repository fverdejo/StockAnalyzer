<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\DTO\Explanation;
use StockAnalyzer\DTO\Signal;
use StockAnalyzer\DTO\StockAnalysis;
use StockAnalyzer\Enums\SignalVerdict;

/**
 * Convierte una StockAnalysis en texto explicativo. No recalcula ninguna
 * puntuacion: solo reordena y redacta las Signals que ya generaron los
 * analizadores, para que el texto y la cifra nunca puedan contradecirse.
 */
class RecommendationExplainer
{
    public function explain(StockAnalysis $analysis): Explanation
    {
        $signals = [];

        foreach ($analysis->getCategoryResults() as $categoryResult) {
            foreach ($categoryResult->getSignals() as $signal) {
                $signals[] = $signal;
            }
        }

        $positives = $this->filterByVerdict($signals, SignalVerdict::POSITIVE);
        $negatives = $this->filterByVerdict($signals, SignalVerdict::NEGATIVE);
        $neutrals = $this->filterByVerdict($signals, SignalVerdict::NEUTRAL);

        $summary = $this->buildSummary($analysis, $positives, $negatives);

        return new Explanation($summary, $positives, $negatives, $neutrals);
    }

    /**
     * @param list<Signal> $signals
     * @return list<Signal>
     */
    private function filterByVerdict(array $signals, SignalVerdict $verdict): array
    {
        return array_values(array_filter(
            $signals,
            static fn (Signal $signal): bool => $signal->getVerdict() === $verdict
        ));
    }

    /**
     * @param list<Signal> $positives
     * @param list<Signal> $negatives
     */
    private function buildSummary(StockAnalysis $analysis, array $positives, array $negatives): string
    {
        $score = $analysis->getScore();
        $company = $analysis->getStock()->getCompany();
        $name = $company->getName() !== '' ? $company->getName() : $company->getTicker();
        $recommendation = $score->getRecommendation();

        $intro = match ($recommendation) {
            'STRONG BUY' => sprintf('%s reune una combinacion muy favorable de señales tecnicas y fundamentales', $name),
            'BUY' => sprintf('%s reune mas señales a favor que en contra', $name),
            'HOLD' => sprintf('%s no muestra un sesgo claro entre señales favorables y desfavorables', $name),
            'SELL' => sprintf('%s acumula mas señales en contra que a favor', $name),
            default => sprintf('%s presenta una combinacion claramente desfavorable de señales', $name),
        };

        $sentences = [sprintf(
            '%s, con una puntuacion del %s%% sobre el maximo posible (%s).',
            $intro,
            $this->fmt($score->getPercentage()),
            $recommendation
        )];

        $highlighted = match (true) {
            in_array($recommendation, ['STRONG BUY', 'BUY'], true) => $positives,
            in_array($recommendation, ['SELL', 'STRONG SELL'], true) => $negatives,
            default => [],
        };

        // Se separan a proposito los motivos tecnicos de los fundamentales
        // (ver versions.md v2.17): el analisis tecnico genera mas Signal por
        // accion que el fundamental, asi que tomar simplemente "las 3
        // primeras" del array combinado dejaba casi siempre fuera el motivo
        // fundamental, aunque si contaba para la puntuacion.
        if ($highlighted !== []) {
            $technicalReasons = array_values(array_filter($highlighted, static fn (Signal $signal): bool => $signal->isTechnical()));
            $fundamentalReasons = array_values(array_filter($highlighted, static fn (Signal $signal): bool => !$signal->isTechnical()));

            if ($technicalReasons !== []) {
                $sentences[] = 'En el analisis tecnico: ' . implode(' ', array_map(
                    static fn (Signal $signal): string => $signal->getMessage(),
                    $this->pickRandom($technicalReasons, 2)
                ));
            }

            if ($fundamentalReasons !== []) {
                $sentences[] = 'En el analisis fundamental: ' . implode(' ', array_map(
                    static fn (Signal $signal): string => $signal->getMessage(),
                    $this->pickRandom($fundamentalReasons, 2)
                ));
            }
        }

        if ($recommendation === 'HOLD') {
            $sentences[] = 'Con señales mixtas, no hay ahora mismo una razon de peso ni para comprar ni para vender.';
        }

        if ($negatives !== [] && in_array($recommendation, ['STRONG BUY', 'BUY'], true)) {
            $sentences[] = 'Aun asi, conviene tener en cuenta: ' . $this->pickRandom($negatives, 1)[0]->getMessage();
        }

        return implode(' ', $sentences);
    }

    private function fmt(float $value): string
    {
        return number_format($value, 1, ',', '.');
    }

    /**
     * Elige $count señales al azar en vez de tomar siempre las primeras del
     * array, para que el resumen no cite siempre los mismos indicadores
     * (SMA20/SMA50, ROE/Deuda-Patrimonio...) por el orden fijo en que los
     * generan los analizadores.
     *
     * @param list<Signal> $signals
     * @return list<Signal>
     */
    private function pickRandom(array $signals, int $count): array
    {
        if (count($signals) <= $count) {
            return $signals;
        }

        $shuffled = $signals;
        shuffle($shuffled);

        return array_slice($shuffled, 0, $count);
    }
}
