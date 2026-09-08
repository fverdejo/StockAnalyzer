<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;
use StockAnalyzer\Repository\DailyRankingRepository;

/**
 * Sustituye al cron systemd de la Raspberry Pi para `largecap60` (retirado
 * v2.11x, ver versions.md/roadmap.md: sin un equipo encendido 24h, ya no
 * hay quien lo dispare a las 23:00). Sin este mecanismo, un dia entero sin
 * abrir la aplicacion en el PC seria un dia sin snapshot en `daily_rankings`
 * / `score_history` / `fundamentals_history` para ese universo.
 *
 * Se llama desde Application::run() en cada peticion GET. La comprobacion
 * en si (una consulta a `daily_rankings` + un stat() de fichero) es barata;
 * solo hace algo real cuando falta el snapshot de hoy, y entonces lanza
 * `bin/analyze.php --universe=largecap60` como proceso independiente en
 * segundo plano (no bloquea la respuesta HTTP).
 *
 * Deliberadamente NO cubre `--all-universes`: msci_world/sp400/sp600 (y el
 * resto de universos marcados `selectable = false`) son justo los que ya se
 * excluyeron del Home por ser demasiado pesados para una peticion web (ver
 * config/universes.php) -- lanzarlos sin avisar cada vez que se abre la app
 * tendria el mismo problema. Siguen disponibles a mano: `php bin/analyze.php
 * --universe=sp400`.
 */
class AnalysisRefreshTrigger
{
    private const UNIVERSE = 'largecap60';

    /**
     * Si el candado tiene menos de esto, se asume que ya hay una ejecucion
     * en marcha (o recien lanzada) y no se relanza otra. Un `bin/analyze.php
     * --universe=largecap60` normal tarda del orden de 1-2 minutos (ver
     * versions.md v2.105, 305 tickers reales tardaron 2m17s): 10 minutos da
     * margen de sobra sin dejar un candado atascado indefinidamente si el
     * proceso anterior murio a medias.
     */
    private const LOCK_STALE_AFTER_SECONDS = 600;

    /** @var callable(string): void */
    private $spawner;

    /**
     * $spawner solo existe para poder sustituir el exec() real en tests
     * (un test no debe lanzar bin/analyze.php de verdad contra Yahoo).
     * Ninguna llamada de produccion lo pasa: Application.php siempre
     * construye este servicio con el constructor de 3 argumentos.
     *
     * @param callable(string): void|null $spawner
     */
    public function __construct(
        private readonly DailyRankingRepository $dailyRankings,
        private readonly string $projectRoot,
        private readonly string $lockFile,
        ?callable $spawner = null
    ) {
        $this->spawner = $spawner ?? static function (string $command): void {
            exec($command);
        };
    }

    public function triggerIfStale(?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();

        // Sabado (6) / domingo (7): no hay sesion de mercado nueva que
        // sembrar. Sin esto, cada apertura de la app en fin de semana
        // intentaria (y fallaria en tener nada nuevo que aportar) relanzar
        // el analisis sin necesidad.
        if ((int) $now->format('N') >= 6) {
            return;
        }

        $latestDate = $this->dailyRankings->latestDate(self::UNIVERSE);

        if ($latestDate !== null && $latestDate->format('Y-m-d') === $now->format('Y-m-d')) {
            return;
        }

        if ($this->isLockFresh($now)) {
            return;
        }

        $this->touchLock($now);
        $this->spawnBackgroundAnalysis();
    }

    private function isLockFresh(DateTimeImmutable $now): bool
    {
        if (!is_file($this->lockFile)) {
            return false;
        }

        $mtime = filemtime($this->lockFile);

        if ($mtime === false) {
            return false;
        }

        return ($now->getTimestamp() - $mtime) < self::LOCK_STALE_AFTER_SECONDS;
    }

    /**
     * El mtime se fija explicitamente a $now (en vez de dejar que touch()
     * use el reloj real) para que isLockFresh() sea determinista con la
     * misma fecha inyectada, tanto en produccion (donde $now siempre es el
     * reloj real) como en tests.
     */
    private function touchLock(DateTimeImmutable $now): void
    {
        $directory = dirname($this->lockFile);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        touch($this->lockFile, $now->getTimestamp());
    }

    private function spawnBackgroundAnalysis(): void
    {
        $logFile = dirname($this->lockFile, 2) . '/logs/analyze-refresh.log';
        $logDirectory = dirname($logFile);

        if (!is_dir($logDirectory)) {
            mkdir($logDirectory, 0775, true);
        }

        // PHP_BINARY NO sirve aqui: dentro de PHP-FPM (que es como se
        // ejecuta este codigo, al ser una peticion web) apunta al binario
        // php-fpm, no al CLI -- confirmado en vivo contra ddev real, donde
        // el proceso lanzado solo escribia la ayuda de `php-fpm --help` en
        // el log en vez de ejecutar bin/analyze.php. Se usa `php` a secas,
        // resuelto por PATH, igual que se invocaria a mano.
        $command = sprintf(
            'cd %s && php bin/analyze.php --universe=%s < /dev/null >> %s 2>&1 &',
            escapeshellarg($this->projectRoot),
            escapeshellarg(self::UNIVERSE),
            escapeshellarg($logFile)
        );

        ($this->spawner)($command);
    }
}
