<?php

declare(strict_types=1);

namespace StockAnalyzer\Analyzer;

use StockAnalyzer\Config\ScoreWeights;
use StockAnalyzer\DTO\CategoryResult;
use StockAnalyzer\DTO\Signal;
use StockAnalyzer\Enums\ScoreCategory;
use StockAnalyzer\Enums\SignalVerdict;
use StockAnalyzer\Models\Fundamentals;

/**
 * Traduce los datos fundamentales (ya calculados por el proveedor) en
 * puntuacion para las categorias FUNDAMENTAL, VALUATION, QUALITY y
 * DIVIDEND, junto con las Signals que explican cada punto.
 *
 * Cuando un dato no esta disponible, ese componente recibe la mitad de su
 * puntuacion maxima (neutro) en lugar de cero: la ausencia de dato no es
 * lo mismo que un mal dato, y no debe penalizar a la empresa.
 *
 * Las formulas internas de cada categoria estan escritas sobre la escala
 * POR DEFECTO de ScoreCategory (30/20/10/5). scale() las reescala
 * proporcionalmente al maximo que haya configurado el usuario en
 * ScoreWeights, para poder soportar pesos configurables sin reescribir (ni
 * arriesgar) ninguna formula.
 */
class FundamentalAnalyzer
{
    public function __construct(
        private readonly ScoreWeights $weights = new ScoreWeights()
    ) {
    }

    /**
     * @return list<CategoryResult>
     */
    public function analyze(Fundamentals $fundamentals): array
    {
        return [
            $this->scale($this->fundamentalHealth($fundamentals)),
            $this->scale($this->valuation($fundamentals)),
            $this->scale($this->quality($fundamentals)),
            $this->scale($this->dividend($fundamentals)),
        ];
    }

    /**
     * Reescala un CategoryResult calculado sobre el maximo POR DEFECTO de su
     * categoria (ScoreCategory::maxScore()) al maximo REALMENTE configurado
     * en ScoreWeights. Con la configuracion por defecto ambos coinciden y
     * esto no cambia nada.
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

    private function fundamentalHealth(Fundamentals $fundamentals): CategoryResult
    {
        $signals = [];
        $score = 0.0;

        $roe = $fundamentals->getRoe();
        if ($roe !== null) {
            $points = match (true) {
                $roe >= 20 => 12.0,
                $roe >= 15 => 10.0,
                $roe >= 10 => 7.0,
                $roe >= 5 => 4.0,
                $roe >= 0 => 2.0,
                default => 0.0,
            };
            $score += $points;
            $signals[] = new Signal(
                'ROE',
                $roe >= 15 ? SignalVerdict::POSITIVE : ($roe >= 5 ? SignalVerdict::NEUTRAL : SignalVerdict::NEGATIVE),
                sprintf(
                    'La rentabilidad sobre recursos propios (ROE) es del %s%%, %s.',
                    $this->fmt($roe),
                    match (true) {
                        $roe >= 15 => 'un nivel solido',
                        $roe >= 5 => 'un nivel modesto',
                        default => 'un nivel debil',
                    }
                ),
                ScoreCategory::FUNDAMENTAL
            );
        } else {
            $score += 6.0;
        }

        $debtToEquity = $fundamentals->getDebtToEquity();
        if ($debtToEquity !== null) {
            $points = match (true) {
                $debtToEquity < 0.3 => 10.0,
                $debtToEquity < 0.7 => 8.0,
                $debtToEquity < 1.2 => 6.0,
                $debtToEquity < 2.0 => 3.0,
                default => 1.0,
            };
            $score += $points;
            $signals[] = new Signal(
                'Deuda/Patrimonio',
                $debtToEquity < 0.7 ? SignalVerdict::POSITIVE : ($debtToEquity < 1.5 ? SignalVerdict::NEUTRAL : SignalVerdict::NEGATIVE),
                sprintf(
                    'El ratio deuda/patrimonio es %s, %s.',
                    $this->fmt($debtToEquity),
                    match (true) {
                        $debtToEquity < 0.7 => 'un apalancamiento contenido',
                        $debtToEquity < 1.5 => 'un apalancamiento moderado',
                        default => 'un apalancamiento elevado que añade riesgo financiero',
                    }
                ),
                ScoreCategory::FUNDAMENTAL
            );
        } else {
            $score += 5.0;
        }

        $fcfYield = $fundamentals->getFreeCashFlowYield();
        if ($fcfYield !== null) {
            $points = match (true) {
                $fcfYield >= 6 => 8.0,
                $fcfYield >= 3 => 6.0,
                $fcfYield >= 1 => 4.0,
                $fcfYield >= 0 => 2.0,
                default => 0.0,
            };
            $score += $points;
            $signals[] = new Signal(
                'Flujo de caja libre',
                $fcfYield >= 3 ? SignalVerdict::POSITIVE : ($fcfYield >= 0 ? SignalVerdict::NEUTRAL : SignalVerdict::NEGATIVE),
                sprintf(
                    'La rentabilidad por flujo de caja libre sobre capitalizacion es del %s%%, %s.',
                    $this->fmt($fcfYield),
                    match (true) {
                        $fcfYield >= 3 => 'generando caja de forma solida',
                        $fcfYield >= 0 => 'generando caja de forma ajustada',
                        default => 'con flujo de caja negativo, una señal de alerta',
                    }
                ),
                ScoreCategory::FUNDAMENTAL
            );
        } else {
            $score += 4.0;
        }

        return new CategoryResult(ScoreCategory::FUNDAMENTAL, $score, $signals);
    }

    private function valuation(Fundamentals $fundamentals): CategoryResult
    {
        $signals = [];
        $score = 0.0;

        $per = $fundamentals->getPer();
        if ($per !== null) {
            if ($per <= 0) {
                $score += 1.0;
                $signals[] = new Signal(
                    'PER',
                    SignalVerdict::NEGATIVE,
                    'El PER no es representativo porque la empresa registra perdidas.',
                    ScoreCategory::VALUATION
                );
            } else {
                $points = match (true) {
                    $per < 12 => 6.0,
                    $per < 18 => 5.0,
                    $per < 25 => 3.5,
                    $per < 35 => 2.0,
                    default => 0.5,
                };
                $score += $points;
                $signals[] = new Signal(
                    'PER',
                    $per < 18 ? SignalVerdict::POSITIVE : ($per < 30 ? SignalVerdict::NEUTRAL : SignalVerdict::NEGATIVE),
                    sprintf(
                        'El PER es %s, %s.',
                        $this->fmt($per),
                        match (true) {
                            $per < 18 => 'un multiplo moderado',
                            $per < 30 => 'un multiplo exigente pero no extremo',
                            default => 'un multiplo elevado que descuenta mucho crecimiento futuro',
                        }
                    ),
                    ScoreCategory::VALUATION
                );
            }
        } else {
            $score += 3.0;
        }

        $peg = $fundamentals->getPeg();
        if ($peg !== null) {
            $points = match (true) {
                $peg <= 0 => 2.0,
                $peg < 1 => 5.0,
                $peg < 1.5 => 3.5,
                $peg < 2.5 => 2.0,
                default => 0.5,
            };
            $score += $points;
            $signals[] = new Signal(
                'PEG',
                $peg > 0 && $peg < 1.5 ? SignalVerdict::POSITIVE : SignalVerdict::NEUTRAL,
                sprintf(
                    'El PEG es %s, %s.',
                    $this->fmt($peg),
                    $peg > 0 && $peg < 1
                        ? 'lo que sugiere que el precio no refleja todo el crecimiento esperado'
                        : 'un nivel que ya incorpora buena parte del crecimiento esperado'
                ),
                ScoreCategory::VALUATION
            );
        } else {
            $score += 2.5;
        }

        $evToEbitda = $fundamentals->getEvToEbitda();
        if ($evToEbitda !== null) {
            $points = match (true) {
                $evToEbitda <= 0 => 2.0,
                $evToEbitda < 8 => 5.0,
                $evToEbitda < 12 => 3.5,
                $evToEbitda < 18 => 2.0,
                default => 0.5,
            };
            $score += $points;
            $signals[] = new Signal(
                'EV/EBITDA',
                $evToEbitda > 0 && $evToEbitda < 12 ? SignalVerdict::POSITIVE : SignalVerdict::NEUTRAL,
                sprintf('El EV/EBITDA es %s.', $this->fmt($evToEbitda)),
                ScoreCategory::VALUATION
            );
        } else {
            $score += 2.5;
        }

        $priceToBook = $fundamentals->getPriceToBook();
        if ($priceToBook !== null) {
            $points = match (true) {
                $priceToBook <= 0 => 1.5,
                $priceToBook < 1.5 => 4.0,
                $priceToBook < 3 => 3.0,
                $priceToBook < 6 => 1.5,
                default => 0.5,
            };
            $score += $points;
            $signals[] = new Signal(
                'Precio/Valor contable',
                $priceToBook > 0 && $priceToBook < 3 ? SignalVerdict::POSITIVE : SignalVerdict::NEUTRAL,
                sprintf('Cotiza a %sx su valor contable.', $this->fmt($priceToBook)),
                ScoreCategory::VALUATION
            );
        } else {
            $score += 2.0;
        }

        return new CategoryResult(ScoreCategory::VALUATION, $score, $signals);
    }

    private function quality(Fundamentals $fundamentals): CategoryResult
    {
        $signals = [];
        $score = 0.0;

        $netMargin = $fundamentals->getNetMargin();
        if ($netMargin !== null) {
            $points = match (true) {
                $netMargin >= 20 => 6.0,
                $netMargin >= 10 => 4.5,
                $netMargin >= 5 => 3.0,
                $netMargin >= 0 => 1.5,
                default => 0.0,
            };
            $score += $points;
            $signals[] = new Signal(
                'Margen neto',
                $netMargin >= 10 ? SignalVerdict::POSITIVE : ($netMargin >= 0 ? SignalVerdict::NEUTRAL : SignalVerdict::NEGATIVE),
                sprintf('El margen neto es del %s%%.', $this->fmt($netMargin)),
                ScoreCategory::QUALITY
            );
        } else {
            $score += 3.0;
        }

        $operatingMargin = $fundamentals->getOperatingMargin();
        if ($operatingMargin !== null) {
            $points = match (true) {
                $operatingMargin >= 25 => 4.0,
                $operatingMargin >= 15 => 3.0,
                $operatingMargin >= 8 => 2.0,
                $operatingMargin >= 0 => 1.0,
                default => 0.0,
            };
            $score += $points;
            $signals[] = new Signal(
                'Margen operativo',
                $operatingMargin >= 15 ? SignalVerdict::POSITIVE : ($operatingMargin >= 0 ? SignalVerdict::NEUTRAL : SignalVerdict::NEGATIVE),
                sprintf('El margen operativo es del %s%%.', $this->fmt($operatingMargin)),
                ScoreCategory::QUALITY
            );
        } else {
            $score += 2.0;
        }

        return new CategoryResult(ScoreCategory::QUALITY, $score, $signals);
    }

    private function dividend(Fundamentals $fundamentals): CategoryResult
    {
        $yield = $fundamentals->getDividendYield();

        if ($yield === null || $yield <= 0) {
            return new CategoryResult(
                ScoreCategory::DIVIDEND,
                1.5,
                [new Signal(
                    'Dividendo',
                    SignalVerdict::NEUTRAL,
                    'La empresa no reparte dividendo (o el dato no esta disponible); no es necesariamente negativo si reinvierte el capital en crecimiento.',
                    ScoreCategory::DIVIDEND
                )]
            );
        }

        $signals = [];

        $yieldPoints = match (true) {
            $yield > 8 => 2.0,
            $yield >= 4 => 3.5,
            $yield >= 2 => 3.0,
            default => 2.0,
        };
        $signals[] = new Signal(
            'Rentabilidad por dividendo',
            $yield >= 2 && $yield <= 8 ? SignalVerdict::POSITIVE : SignalVerdict::NEUTRAL,
            sprintf(
                'La rentabilidad por dividendo es del %s%%%s.',
                $this->fmt($yield),
                $yield > 8 ? ', un nivel inusualmente alto que conviene revisar (puede reflejar riesgo de recorte)' : ''
            ),
            ScoreCategory::DIVIDEND
        );

        $payout = $fundamentals->getPayoutRatio();
        $payoutPoints = 1.5;

        if ($payout !== null) {
            $payoutPoints = match (true) {
                $payout <= 0 => 1.0,
                $payout < 60 => 1.5,
                $payout < 85 => 1.0,
                default => 0.3,
            };
            $signals[] = new Signal(
                'Payout ratio',
                $payout > 0 && $payout < 70 ? SignalVerdict::POSITIVE : SignalVerdict::NEUTRAL,
                sprintf('Destina el %s%% del beneficio a dividendos.', $this->fmt($payout)),
                ScoreCategory::DIVIDEND
            );
        }

        return new CategoryResult(ScoreCategory::DIVIDEND, $yieldPoints + $payoutPoints, $signals);
    }

    private function fmt(float $value): string
    {
        return number_format($value, 1, ',', '.');
    }
}
