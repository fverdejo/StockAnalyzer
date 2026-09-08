<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Integration;

use DateTimeImmutable;
use StockAnalyzer\Repository\DailyRankingRepository;
use StockAnalyzer\Services\AnalysisRefreshTrigger;

/**
 * Sustituto del cron de la Pi (ver AnalysisRefreshTrigger): estos tests
 * cubren la decision de disparar o no, nunca el `bin/analyze.php` real -- el
 * spawner se sustituye por uno que solo registra la llamada, igual que
 * `HttpClientRetryTest` sustituye el sleeper real.
 */
final class AnalysisRefreshTriggerTest extends IntegrationTestCase
{
    private DailyRankingRepository $dailyRankings;
    private string $testRoot;
    private string $lockFile;
    private DateTimeImmutable $unMartes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dailyRankings = new DailyRankingRepository($this->connection());
        // Misma forma que produccion (storage/locks + storage/logs
        // hermanos bajo storage/), para que spawnBackgroundAnalysis()
        // escriba el log dentro de este mismo directorio de prueba.
        $this->testRoot = sys_get_temp_dir() . '/stockanalyzer-test-' . uniqid('', true);
        $this->lockFile = $this->testRoot . '/storage/locks/analyze.lock';
        // 2026-09-08 es martes.
        $this->unMartes = new DateTimeImmutable('2026-09-08 10:00:00');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testRoot);

        parent::tearDown();
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }

    /**
     * @param list<string> $calls
     */
    private function makeTrigger(array &$calls): AnalysisRefreshTrigger
    {
        $calls = [];

        return new AnalysisRefreshTrigger(
            $this->dailyRankings,
            '/var/www/StockAnalyzer',
            $this->lockFile,
            static function (string $command) use (&$calls): void {
                $calls[] = $command;
            }
        );
    }

    public function testSinSnapshotDeHoyLanzaElAnalisisEnSegundoPlano(): void
    {
        $calls = [];
        $trigger = $this->makeTrigger($calls);

        $trigger->triggerIfStale($this->unMartes);

        self::assertCount(1, $calls);
        // escapeshellarg() envuelve cada valor en comillas simples.
        self::assertStringContainsString("--universe='largecap60'", $calls[0]);
        self::assertStringContainsString('bin/analyze.php', $calls[0]);
        self::assertFileExists($this->lockFile);
    }

    public function testConSnapshotDeHoyYaGuardadoNoLanzaNada(): void
    {
        $this->dailyRankings->save('largecap60', ['AAPL'], ['count' => 1], $this->unMartes);

        $calls = [];
        $trigger = $this->makeTrigger($calls);

        $trigger->triggerIfStale($this->unMartes);

        self::assertCount(0, $calls);
    }

    public function testEnFinDeSemanaNoLanzaNadaAunqueFalteElSnapshot(): void
    {
        $calls = [];
        $trigger = $this->makeTrigger($calls);

        $sabado = new DateTimeImmutable('2026-09-12 10:00:00'); // sabado

        $trigger->triggerIfStale($sabado);

        self::assertCount(0, $calls);
    }

    public function testConCandadoRecienTocadoNoRelanzaAunqueFalteElSnapshot(): void
    {
        $calls = [];
        $trigger = $this->makeTrigger($calls);

        $trigger->triggerIfStale($this->unMartes);
        self::assertCount(1, $calls);

        // Segunda peticion pocos segundos despues: el candado sigue fresco,
        // no debe lanzar un segundo bin/analyze.php en paralelo.
        $trigger->triggerIfStale($this->unMartes->modify('+30 seconds'));

        self::assertCount(1, $calls);
    }

    public function testConCandadoCaducadoVuelveARelanzar(): void
    {
        $calls = [];
        $trigger = $this->makeTrigger($calls);

        $trigger->triggerIfStale($this->unMartes);
        self::assertCount(1, $calls);

        // El candado tiene mas de 10 minutos: se asume que el proceso
        // anterior murio a medias, y hace falta poder reintentar.
        $trigger->triggerIfStale($this->unMartes->modify('+11 minutes'));

        self::assertCount(2, $calls);
    }
}
