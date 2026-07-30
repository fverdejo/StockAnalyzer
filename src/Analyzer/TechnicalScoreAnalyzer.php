<?php

declare(strict_types=1);

namespace StockAnalyzer\Analyzer;

use StockAnalyzer\Config\ScoreWeights;
use StockAnalyzer\DTO\CategoryResult;
use StockAnalyzer\DTO\Signal;
use StockAnalyzer\DTO\TechnicalSnapshot;
use StockAnalyzer\Enums\ScoreCategory;
use StockAnalyzer\Enums\SignalVerdict;
use StockAnalyzer\Models\Stock;

/**
 * Traduce el TechnicalSnapshot en puntuacion para TECHNICAL, MOMENTUM y
 * RISK, junto con las Signals que explican cada punto. Es la contrapartida
 * tecnica de FundamentalAnalyzer: mismo patron (CategoryResult + Signal +
 * escalado segun ScoreWeights), distinta fuente de datos.
 */
class TechnicalScoreAnalyzer
{
    public function __construct(
        private readonly ScoreWeights $weights = new ScoreWeights()
    ) {
    }

    /**
     * @return list<CategoryResult>
     */
    public function analyze(Stock $stock, TechnicalSnapshot $technical): array
    {
        $price = $stock->getQuote()->getPrice();

        return [
            $this->scale($this->technical($price, $technical)),
            $this->scale($this->momentum($technical)),
            $this->scale($this->risk($price, $technical)),
        ];
    }

    /**
     * Ver FundamentalAnalyzer::scale(): mismo mecanismo de reescalado.
     */
    private function scale(CategoryResult $result): CategoryResult
    {
        $defaultMax = $result->getCategory()->maxScore();
        $configuredMax = $this->weights->getMax($result->getCategory());

        if ($defaultMax <= 0 || abs($defaultMax - $configuredMax) < 0.0001) {
            return $result;
        }

        return new CategoryResult(
            $result->getCategory(),
            $result->getScore() * ($configuredMax / $defaultMax),
            $result->getSignals()
        );
    }

    private function technical(float $price, TechnicalSnapshot $technical): CategoryResult
    {
        $signals = [];
        $score = 0.0;

        $sma20 = $technical->getSma20();
        if ($sma20 !== null) {
            $above = $price > $sma20;
            $score += $above ? 6.0 : 0.0;
            $signals[] = new Signal(
                'Precio vs SMA20',
                $above ? SignalVerdict::POSITIVE : SignalVerdict::NEGATIVE,
                sprintf(
                    'El precio cotiza %s su media movil de 20 sesiones (%s).',
                    $above ? 'por encima de' : 'por debajo de',
                    $this->fmt($sma20)
                ),
                ScoreCategory::TECHNICAL
            );
        } else {
            $score += 3.0;
        }

        $sma50 = $technical->getSma50();
        if ($sma50 !== null) {
            $above = $price > $sma50;
            $score += $above ? 6.0 : 0.0;
            $signals[] = new Signal(
                'Precio vs SMA50',
                $above ? SignalVerdict::POSITIVE : SignalVerdict::NEGATIVE,
                sprintf(
                    'El precio cotiza %s su media movil de 50 sesiones (%s), lo que refleja tendencia de %s plazo %s.',
                    $above ? 'por encima de' : 'por debajo de',
                    $this->fmt($sma50),
                    'medio',
                    $above ? 'alcista' : 'bajista'
                ),
                ScoreCategory::TECHNICAL
            );
        } else {
            $score += 3.0;
        }

        if ($sma20 !== null && $sma50 !== null) {
            $goldenCross = $sma20 > $sma50;
            $score += $goldenCross ? 4.0 : 0.0;
            $signals[] = new Signal(
                'Cruce de medias',
                $goldenCross ? SignalVerdict::POSITIVE : SignalVerdict::NEGATIVE,
                $goldenCross
                    ? 'La media de 20 sesiones esta por encima de la de 50 (cruce alcista).'
                    : 'La media de 20 sesiones esta por debajo de la de 50 (cruce bajista).',
                ScoreCategory::TECHNICAL
            );
        } else {
            $score += 2.0;
        }

        $histogram = $technical->getMacdHistogram();
        if ($histogram !== null && $price > 0) {
            $histPercent = ($histogram / $price) * 100;
            $points = match (true) {
                $histPercent > 0.5 => 6.0,
                $histPercent > 0 => 4.5,
                $histPercent > -0.5 => 2.0,
                default => 0.5,
            };
            $score += $points;
            $signals[] = new Signal(
                'MACD',
                $histPercent > 0 ? SignalVerdict::POSITIVE : SignalVerdict::NEGATIVE,
                sprintf(
                    'El histograma MACD es %s, lo que indica impulso %s.',
                    $histPercent > 0 ? 'positivo' : 'negativo',
                    $histPercent > 0.5 ? 'alcista fuerte' : ($histPercent > 0 ? 'alcista moderado' : ($histPercent > -0.5 ? 'bajista moderado' : 'bajista fuerte'))
                ),
                ScoreCategory::TECHNICAL
            );
        } else {
            $score += 3.0;
        }

        $upper = $technical->getBollingerUpper();
        $lower = $technical->getBollingerLower();
        if ($upper !== null && $lower !== null && $upper > $lower) {
            $position = ($price - $lower) / ($upper - $lower);
            $trendConfirmed = $sma20 !== null && $sma50 !== null && $sma20 > $sma50;
            $points = match (true) {
                $position <= 0.25 => 4.0,
                $position >= 0.85 => $trendConfirmed ? 3.0 : 1.0,
                default => 3.0,
            };
            $score += $points;
            $signals[] = new Signal(
                'Bandas de Bollinger',
                match (true) {
                    $position <= 0.25 => SignalVerdict::NEUTRAL,
                    $position >= 0.85 => $trendConfirmed ? SignalVerdict::POSITIVE : SignalVerdict::NEGATIVE,
                    default => SignalVerdict::POSITIVE,
                },
                match (true) {
                    $position <= 0.25 => 'El precio esta cerca de la banda inferior, una zona que historicamente ha actuado como soporte (aunque tambien puede reflejar debilidad continuada).',
                    $position >= 0.85 => $trendConfirmed
                        ? 'El precio esta cerca de la banda superior, pero con la tendencia alcista confirmada (SMA20 > SMA50) esto suele reflejar continuidad ("caminar por la banda"), no agotamiento.'
                        : 'El precio esta cerca de la banda superior sin una tendencia alcista confirmada detras, zona de sobrecompra a corto plazo.',
                    default => 'El precio se mueve en la zona media de las bandas, sin señal extrema.',
                },
                ScoreCategory::TECHNICAL
            );
        } else {
            $score += 2.0;
        }

        $volumeRatio = $technical->getVolumeRatio();
        if ($volumeRatio !== null) {
            $points = match (true) {
                $volumeRatio >= 1.5 => 4.0,
                $volumeRatio >= 1.0 => 3.0,
                $volumeRatio >= 0.6 => 2.0,
                default => 1.0,
            };
            $score += $points;
            $signals[] = new Signal(
                'Volumen',
                $volumeRatio >= 1.0 ? SignalVerdict::POSITIVE : SignalVerdict::NEUTRAL,
                sprintf(
                    'El volumen de la ultima sesion es %sx la media de 20 sesiones, %s.',
                    $this->fmt($volumeRatio),
                    $volumeRatio >= 1.5 ? 'lo que da conviccion al movimiento reciente' : ($volumeRatio >= 0.6 ? 'un nivel de participacion normal' : 'una participacion escasa')
                ),
                ScoreCategory::TECHNICAL
            );
        } else {
            $score += 2.0;
        }

        return new CategoryResult(ScoreCategory::TECHNICAL, $score, $signals);
    }

    private function momentum(TechnicalSnapshot $technical): CategoryResult
    {
        $signals = [];

        $momentum = $technical->getMomentum30();
        $momentumPoints = $momentum !== null
            ? max(0.0, min(3.5 + ($momentum * 0.28), 7.0))
            : 3.5;

        if ($momentum !== null) {
            $signals[] = new Signal(
                'Momentum 30 dias',
                $momentum > 0 ? SignalVerdict::POSITIVE : SignalVerdict::NEGATIVE,
                sprintf(
                    'El precio se ha movido un %s%% en los ultimos 30 dias.',
                    $this->fmt($momentum)
                ),
                ScoreCategory::MOMENTUM
            );
        }

        $rsi = $technical->getRsi14();
        $rsiPoints = match (true) {
            $rsi === null => 1.5,
            $rsi >= 50 && $rsi <= 70 => 3.0,
            $rsi > 70 && $rsi <= 80 => 2.0,
            $rsi >= 40 && $rsi < 50 => 2.0,
            $rsi > 80 => 1.0,
            $rsi >= 30 && $rsi < 40 => 1.5,
            default => 0.5,
        };

        if ($rsi !== null) {
            $signals[] = new Signal(
                'RSI (14)',
                match (true) {
                    $rsi > 80, $rsi < 30 => SignalVerdict::NEGATIVE,
                    $rsi >= 50 && $rsi <= 70 => SignalVerdict::POSITIVE,
                    default => SignalVerdict::NEUTRAL,
                },
                sprintf(
                    'El RSI(14) esta en %s: %s.',
                    $this->fmt($rsi),
                    match (true) {
                        $rsi > 80 => 'zona de sobrecompra marcada, riesgo de correccion a corto plazo',
                        $rsi > 70 => 'zona de sobrecompra',
                        $rsi >= 50 => 'impulso alcista saludable',
                        $rsi >= 40 => 'zona neutral',
                        $rsi >= 30 => 'perdiendo fuerza, cerca de sobreventa',
                        default => 'zona de sobreventa marcada, posible agotamiento del movimiento bajista',
                    }
                ),
                ScoreCategory::MOMENTUM
            );
        }

        return new CategoryResult(ScoreCategory::MOMENTUM, $momentumPoints + $rsiPoints, $signals);
    }

    private function risk(float $price, TechnicalSnapshot $technical): CategoryResult
    {
        $signals = [];

        $volatility = $technical->getVolatility20();
        $volPoints = $volatility !== null ? max(0.0, min(6 - ($volatility * 1.1), 6.0)) : 3.0;

        if ($volatility !== null) {
            $signals[] = new Signal(
                'Volatilidad 20 dias',
                $volatility < 2 ? SignalVerdict::POSITIVE : ($volatility < 4 ? SignalVerdict::NEUTRAL : SignalVerdict::NEGATIVE),
                sprintf(
                    'La volatilidad diaria de los ultimos 20 dias es del %s%%, %s.',
                    $this->fmt($volatility),
                    $volatility < 2 ? 'un nivel contenido' : ($volatility < 4 ? 'un nivel moderado' : 'un nivel elevado')
                ),
                ScoreCategory::RISK
            );
        }

        $atr = $technical->getAtr14();
        $atrPercent = ($atr !== null && $price > 0) ? ($atr / $price) * 100 : null;
        $atrPoints = $atrPercent !== null ? max(0.0, min(4 - ($atrPercent * 0.8), 4.0)) : 2.0;

        if ($atrPercent !== null) {
            $signals[] = new Signal(
                'Rango medio diario (ATR)',
                $atrPercent < 2.5 ? SignalVerdict::POSITIVE : ($atrPercent < 4.5 ? SignalVerdict::NEUTRAL : SignalVerdict::NEGATIVE),
                sprintf(
                    'El rango medio de las ultimas 14 sesiones equivale al %s%% del precio.',
                    $this->fmt($atrPercent)
                ),
                ScoreCategory::RISK
            );
        }

        return new CategoryResult(ScoreCategory::RISK, $volPoints + $atrPoints, $signals);
    }

    private function fmt(float $value): string
    {
        return number_format($value, 1, ',', '.');
    }
}
