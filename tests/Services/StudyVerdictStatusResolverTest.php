<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Enums\StudyVerdictStatus;
use StockAnalyzer\Services\StudyVerdictStatusResolver;

/**
 * `StudyVerdictStatusResolver` (C10.3, predeclaracion cerrada de
 * `auditor-estadistico`, 2026-09-28 -- ver `versions.md`).
 */
final class StudyVerdictStatusResolverTest extends TestCase
{
    public function testSinResolucionSuficienteDaNoInformativoSeaComoSeaElRestoDeCampos(): void
    {
        $resolver = new StudyVerdictStatusResolver();

        self::assertSame(StudyVerdictStatus::NO_INFORMATIVO, $resolver->resolve(false, true, true));
        self::assertSame(StudyVerdictStatus::NO_INFORMATIVO, $resolver->resolve(false, null, false));
    }

    public function testResolucionSuficienteSinMetodoCalibradoDaMetodoNoCalibrado(): void
    {
        $resolver = new StudyVerdictStatusResolver();

        self::assertSame(StudyVerdictStatus::METODO_NO_CALIBRADO, $resolver->resolve(true, true, false));
        self::assertSame(StudyVerdictStatus::METODO_NO_CALIBRADO, $resolver->resolve(true, false, false));
    }

    public function testResolucionSuficienteMetodoCalibradoYCiExcluyeCeroDaValidadoProvisional(): void
    {
        $resolver = new StudyVerdictStatusResolver();

        self::assertSame(StudyVerdictStatus::VALIDADO_PROVISIONAL, $resolver->resolve(true, true, true));
    }

    public function testResolucionSuficienteMetodoCalibradoYCiNoExcluyeCeroDaInformativoSinVentaja(): void
    {
        $resolver = new StudyVerdictStatusResolver();

        self::assertSame(StudyVerdictStatus::INFORMATIVO_SIN_VENTAJA, $resolver->resolve(true, false, true));
    }
}
