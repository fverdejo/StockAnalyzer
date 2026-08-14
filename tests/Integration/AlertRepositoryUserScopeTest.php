<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Integration;

use DateTimeImmutable;
use StockAnalyzer\Models\User;
use StockAnalyzer\Repository\AlertRepository;

/**
 * El pendiente que `roadmap.md` arrastraba desde `v2.69`: "un test de
 * integracion contra MySQL para el `AND user_id` de las alertas; la
 * comprobacion manual con dos usuarios ya se hizo y pasa, pero nada impide
 * una regresion futura".
 *
 * Es el unico sitio de la aplicacion donde un id llega directamente del
 * POST del cliente (el boton de marcar/borrar de la pagina de alertas) y se
 * usa en un `WHERE`. Sin la condicion `AND user_id`, cualquiera podria
 * marcar o borrar alertas ajenas iterando ids: no es un fallo de
 * presentacion, es que un usuario toque los datos de otro.
 *
 * Estos casos no se pueden escribir con un doble en memoria. Un
 * `InMemoryAlertRepository` implementa el filtro en PHP, asi que probaria
 * el doble y no el SQL, que es justo donde vive el riesgo.
 */
final class AlertRepositoryUserScopeTest extends IntegrationTestCase
{
    private AlertRepository $repository;
    private User $victima;
    private User $atacante;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new AlertRepository($this->connection());
        $this->victima = new User($this->createUser('victima@example.com'), 'victima@example.com', new DateTimeImmutable());
        $this->atacante = new User($this->createUser('atacante@example.com'), 'atacante@example.com', new DateTimeImmutable());
    }

    public function testCadaUsuarioSoloVeSusAlertas(): void
    {
        $this->repository->create($this->victima, 'AAPL', 'AAPL ha pasado de HOLD a BUY.');
        $this->repository->create($this->atacante, 'TSLA', 'TSLA ha pasado de BUY a SELL.');

        $deLaVictima = $this->repository->findRecentByUser($this->victima);
        $delAtacante = $this->repository->findRecentByUser($this->atacante);

        self::assertCount(1, $deLaVictima);
        self::assertCount(1, $delAtacante);
        self::assertSame('AAPL', $deLaVictima[0]->getTicker());
        self::assertSame('TSLA', $delAtacante[0]->getTicker());
    }

    public function testElContadorDeNoLeidasNoCuentaLasDeOtroUsuario(): void
    {
        $this->repository->create($this->atacante, 'TSLA', 'Una.');
        $this->repository->create($this->atacante, 'NVDA', 'Otra.');

        self::assertSame(0, $this->repository->countUnread($this->victima));
        self::assertSame(2, $this->repository->countUnread($this->atacante));
    }

    /**
     * El caso que motiva todo: el id viene del POST, asi que se prueba
     * exactamente eso — el atacante envia el id de una alerta que no es
     * suya.
     */
    public function testNoSePuedeMarcarComoLeidaLaAlertaDeOtroUsuario(): void
    {
        $this->repository->create($this->victima, 'AAPL', 'Suya.');
        $idAjeno = $this->repository->findRecentByUser($this->victima)[0]->getId();

        $this->repository->markRead($this->atacante, $idAjeno);

        self::assertSame(1, $this->repository->countUnread($this->victima), 'La alerta de la victima sigue sin leer.');
        self::assertFalse($this->repository->findRecentByUser($this->victima)[0]->isRead());
    }

    public function testNoSePuedeDesmarcarLaAlertaDeOtroUsuario(): void
    {
        $this->repository->create($this->victima, 'AAPL', 'Suya.');
        $idAjeno = $this->repository->findRecentByUser($this->victima)[0]->getId();
        $this->repository->markRead($this->victima, $idAjeno);

        $this->repository->markUnread($this->atacante, $idAjeno);

        self::assertTrue($this->repository->findRecentByUser($this->victima)[0]->isRead(), 'Sigue leida.');
    }

    public function testNoSePuedeBorrarLaAlertaDeOtroUsuario(): void
    {
        $this->repository->create($this->victima, 'AAPL', 'Suya.');
        $idAjeno = $this->repository->findRecentByUser($this->victima)[0]->getId();

        $this->repository->delete($this->atacante, $idAjeno);

        self::assertCount(1, $this->repository->findRecentByUser($this->victima), 'La alerta sigue existiendo.');
    }

    public function testMarcarTodasComoLeidasNoTocaLasDeOtroUsuario(): void
    {
        $this->repository->create($this->victima, 'AAPL', 'Suya.');
        $this->repository->create($this->atacante, 'TSLA', 'Del atacante.');

        $this->repository->markAllRead($this->atacante);

        self::assertSame(1, $this->repository->countUnread($this->victima));
        self::assertSame(0, $this->repository->countUnread($this->atacante));
    }

    public function testBorrarLasLeidasNoTocaLasDeOtroUsuario(): void
    {
        $this->repository->create($this->victima, 'AAPL', 'Suya.');
        $this->repository->create($this->atacante, 'TSLA', 'Del atacante.');
        $this->repository->markAllRead($this->victima);
        $this->repository->markAllRead($this->atacante);

        $this->repository->deleteRead($this->atacante);

        self::assertCount(1, $this->repository->findRecentByUser($this->victima));
        self::assertCount(0, $this->repository->findRecentByUser($this->atacante));
    }

    /**
     * El filtro "sin leer" es una consulta aparte (para que use el indice
     * `idx_alerts_user_unread`), asi que lleva su propio `AND user_id` y
     * necesita su propio caso.
     */
    public function testElFiltroDeNoLeidasTambienAislaPorUsuario(): void
    {
        $this->repository->create($this->victima, 'AAPL', 'Sin leer, suya.');
        $this->repository->create($this->atacante, 'TSLA', 'Sin leer, del atacante.');

        $sinLeer = $this->repository->findRecentUnreadByUser($this->victima);

        self::assertCount(1, $sinLeer);
        self::assertSame('AAPL', $sinLeer[0]->getTicker());
    }

    /**
     * El ticker se guarda siempre en mayusculas: la pagina de alertas
     * enlaza a `?ticker=` con ese valor, y el resto de la aplicacion
     * normaliza a mayusculas.
     */
    public function testElTickerSeNormalizaAMayusculas(): void
    {
        $this->repository->create($this->victima, 'aapl', 'Minusculas.');

        self::assertSame('AAPL', $this->repository->findRecentByUser($this->victima)[0]->getTicker());
    }

    /**
     * `LIMIT` va como entero enlazado (`PARAM_INT`) y no interpolado. Con
     * `ATTR_EMULATE_PREPARES => false`, enlazarlo como cadena hace que
     * MySQL rechace la consulta entera: es un fallo que solo aparece contra
     * el servidor real.
     */
    public function testElLimiteFuncionaContraMysqlDeVerdad(): void
    {
        foreach (['AAPL', 'MSFT', 'NVDA'] as $ticker) {
            $this->repository->create($this->victima, $ticker, 'Alerta de ' . $ticker);
        }

        self::assertCount(2, $this->repository->findRecentByUser($this->victima, 2));
        self::assertCount(3, $this->repository->findRecentByUser($this->victima));
    }

    /**
     * `ON DELETE CASCADE` en la clave foranea: borrar la cuenta se lleva
     * sus alertas. Sin el, quedarian filas huerfanas apuntando a un
     * `user_id` inexistente.
     */
    public function testBorrarElUsuarioSeLlevaSusAlertas(): void
    {
        $this->repository->create($this->victima, 'AAPL', 'Suya.');
        $this->repository->create($this->atacante, 'TSLA', 'Del otro.');

        $pdo = $this->pdoOrSkip();
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $this->victima->getId()]);

        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM alerts WHERE ticker = "AAPL"')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM alerts WHERE ticker = "TSLA"')->fetchColumn());
    }
}
