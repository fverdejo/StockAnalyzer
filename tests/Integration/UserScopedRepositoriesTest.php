<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Integration;

use DateTimeImmutable;
use StockAnalyzer\Enums\TransactionType;
use StockAnalyzer\Models\User;
use StockAnalyzer\Repository\TransactionRepository;
use StockAnalyzer\Repository\WatchlistRepository;

/**
 * Watchlist y operaciones de cartera, con dos usuarios reales en la misma
 * base de datos.
 *
 * Mismo motivo que `AlertRepositoryUserScopeTest`: el aislamiento entre
 * usuarios vive en el `WHERE` de cada consulta, y un doble en memoria
 * comprobaria el doble en vez del SQL. Aqui ademas hay dos garantias que
 * solo da el motor: el `UNIQUE (user_id, ticker)` de la watchlist y las
 * claves foraneas con `ON DELETE CASCADE`.
 */
final class UserScopedRepositoriesTest extends IntegrationTestCase
{
    private WatchlistRepository $watchlist;
    private TransactionRepository $transactions;
    private User $ana;
    private User $bruno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->watchlist = new WatchlistRepository($this->connection());
        $this->transactions = new TransactionRepository($this->connection());
        $this->ana = new User($this->createUser('ana@example.com'), 'ana@example.com', new DateTimeImmutable());
        $this->bruno = new User($this->createUser('bruno@example.com'), 'bruno@example.com', new DateTimeImmutable());
    }

    public function testLaWatchlistDeCadaUsuarioEsSuya(): void
    {
        $this->watchlist->add($this->ana, 'AAPL');
        $this->watchlist->add($this->bruno, 'TSLA');

        self::assertTrue($this->watchlist->isWatched($this->ana, 'AAPL'));
        self::assertFalse($this->watchlist->isWatched($this->ana, 'TSLA'));
        self::assertFalse($this->watchlist->isWatched($this->bruno, 'AAPL'));
        self::assertCount(1, $this->watchlist->findByUser($this->ana));
    }

    /**
     * Dejar de seguir un ticker no puede quitarselo a otro usuario que
     * siga el mismo valor, que es el caso normal: dos personas siguiendo
     * AAPL.
     */
    public function testDejarDeSeguirNoAfectaAlOtroUsuario(): void
    {
        $this->watchlist->add($this->ana, 'AAPL');
        $this->watchlist->add($this->bruno, 'AAPL');

        $this->watchlist->remove($this->ana, 'AAPL');

        self::assertFalse($this->watchlist->isWatched($this->ana, 'AAPL'));
        self::assertTrue($this->watchlist->isWatched($this->bruno, 'AAPL'), 'Bruno sigue siguiendolo.');
    }

    /**
     * El boton "Seguir" se puede pulsar dos veces (o llegar por dos
     * pestañas a la vez). El `UNIQUE (user_id, ticker)` mas el
     * `INSERT ... ON DUPLICATE KEY` o `IGNORE` es lo que evita la fila
     * duplicada, y eso solo lo demuestra el motor.
     */
    public function testSeguirDosVecesElMismoTickerNoDuplicaLaFila(): void
    {
        $this->watchlist->add($this->ana, 'AAPL');
        $this->watchlist->add($this->ana, 'AAPL');

        self::assertCount(1, $this->watchlist->findByUser($this->ana));
    }

    public function testElTickerDeLaWatchlistSeNormalizaAMayusculas(): void
    {
        $this->watchlist->add($this->ana, 'aapl');

        self::assertTrue($this->watchlist->isWatched($this->ana, 'AAPL'));
        self::assertSame('AAPL', $this->watchlist->findByUser($this->ana)[0]->getTicker());
    }

    public function testLasOperacionesDeCadaUsuarioSonSuyas(): void
    {
        $this->transactions->add($this->ana, 'AAPL', TransactionType::BUY, 2.0, 100.0);
        $this->transactions->add($this->bruno, 'TSLA', TransactionType::BUY, 1.0, 250.0);

        $deAna = $this->transactions->findByUser($this->ana);

        self::assertCount(1, $deAna);
        self::assertSame('AAPL', $deAna[0]->getTicker());
        self::assertCount(0, $this->transactions->findByUserAndTicker($this->ana, 'TSLA'));
    }

    /**
     * Las cantidades son `DECIMAL` desde `v2.2` justamente para poder
     * comprar fracciones de accion (`v2.6`). Un `FLOAT` o un `INT` en la
     * columna se comeria los decimales, y eso no lo ve ningun test que no
     * pase por la base de datos.
     */
    public function testLasFraccionesDeAccionSobrevivenAlViajeALaBaseDeDatos(): void
    {
        $this->transactions->add($this->ana, 'GOOGL', TransactionType::BUY, 0.978785, 347.750865);

        $guardada = $this->transactions->findByUserAndTicker($this->ana, 'GOOGL')[0];

        self::assertEqualsWithDelta(0.978785, $guardada->getQuantity(), 0.000001);
        self::assertEqualsWithDelta(347.750865, $guardada->getPrice(), 0.000001);
    }

    public function testBorrarUnUsuarioSeLlevaSuWatchlistYSusOperaciones(): void
    {
        $this->watchlist->add($this->ana, 'AAPL');
        $this->transactions->add($this->ana, 'AAPL', TransactionType::BUY, 1.0, 100.0);
        $this->watchlist->add($this->bruno, 'TSLA');
        $this->transactions->add($this->bruno, 'TSLA', TransactionType::BUY, 1.0, 250.0);

        $pdo = $this->pdoOrSkip();
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $this->ana->getId()]);

        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM watchlist_items')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn());
        self::assertCount(1, $this->watchlist->findByUser($this->bruno), 'Lo de Bruno sigue intacto.');
    }
}
