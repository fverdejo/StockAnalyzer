<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use StockAnalyzer\Models\User;
use StockAnalyzer\Services\Application;

/**
 * Cubre el despacho de acciones de la pagina de alertas
 * (Application::applyAlertsAction) y, con el repositorio en memoria, el
 * contrato de los metodos nuevos de AlertRepository.
 *
 * Dos comportamientos criticos:
 *
 * 1. Una accion vacia o desconocida tiene que lanzar. Antes el redirect de
 *    exito estaba fuera del `if`, asi que cualquier POST devolvia el banner
 *    verde de "Alertas marcadas como leidas" sin haber hecho nada.
 * 2. El alert_id llega del POST del cliente: ninguna accion puede afectar a
 *    una alerta de otro usuario.
 *
 * Application se instancia sin constructor (su constructor es la raiz de
 * composicion: abriria una Connection real) y se le inyecta el repositorio
 * en memoria. Es el unico punto de entrada posible al `match`:
 * handleAlertsAction() termina en redirect(), que hace exit.
 */
final class ApplicationAlertsActionTest extends TestCase
{
    private InMemoryAlertRepository $alerts;
    private Application $application;

    protected function setUp(): void
    {
        $this->alerts = new InMemoryAlertRepository();
        $this->application = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Application::class, 'alertRepository'))->setValue($this->application, $this->alerts);
    }

    private function user(int $id = 1): User
    {
        return new User($id, sprintf('user%d@example.com', $id), new DateTimeImmutable('2026-01-01 00:00:00'));
    }

    private function apply(User $user, string $action, int $alertId = 0): string
    {
        $method = new ReflectionMethod(Application::class, 'applyAlertsAction');

        return (string) $method->invoke($this->application, $user, $action, $alertId);
    }

    private function createAlert(User $user, string $ticker = 'AAPL'): int
    {
        $this->alerts->create($user, $ticker, sprintf('%s cambia a BUY.', $ticker));

        return $this->alerts->lastId();
    }

    public function testMarcarLeidaYNoLeidaSonAccionesExplicitas(): void
    {
        $user = $this->user();
        $id = $this->createAlert($user);

        self::assertSame('Alerta marcada como leida.', $this->apply($user, 'mark_read', $id));
        self::assertTrue($this->alerts->isRead($id));

        // Idempotente: repetir la accion no invierte el estado.
        $this->apply($user, 'mark_read', $id);
        self::assertTrue($this->alerts->isRead($id));

        self::assertSame('Alerta marcada como no leida.', $this->apply($user, 'mark_unread', $id));
        self::assertFalse($this->alerts->isRead($id));

        $this->apply($user, 'mark_unread', $id);
        self::assertFalse($this->alerts->isRead($id));
    }

    public function testBorrarAlerta(): void
    {
        $user = $this->user();
        $id = $this->createAlert($user);

        self::assertSame('Alerta borrada.', $this->apply($user, 'delete', $id));
        self::assertFalse($this->alerts->exists($id));
    }

    public function testMarcarTodasComoLeidas(): void
    {
        $user = $this->user();
        $first = $this->createAlert($user, 'AAPL');
        $second = $this->createAlert($user, 'MSFT');

        self::assertSame('Alertas marcadas como leidas.', $this->apply($user, 'mark_all_read'));
        self::assertTrue($this->alerts->isRead($first));
        self::assertTrue($this->alerts->isRead($second));
        self::assertSame(0, $this->alerts->countUnread($user));
    }

    public function testBorrarLasLeidasConservaLasNoLeidas(): void
    {
        $user = $this->user();
        $read = $this->createAlert($user, 'AAPL');
        $unread = $this->createAlert($user, 'MSFT');
        $this->apply($user, 'mark_read', $read);

        self::assertSame('Alertas leidas borradas.', $this->apply($user, 'delete_read'));
        self::assertFalse($this->alerts->exists($read));
        self::assertTrue($this->alerts->exists($unread));
    }

    public function testAccionVaciaLanza(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Accion de alertas no soportada.');

        $this->apply($this->user(), '');
    }

    public function testAccionDesconocidaLanza(): void
    {
        $this->expectException(RuntimeException::class);

        $this->apply($this->user(), 'borrar_todas');
    }

    public function testAccionSobreUnaAlertaSinIdLanza(): void
    {
        $user = $this->user();
        $id = $this->createAlert($user);

        try {
            $this->apply($user, 'mark_read');
            self::fail('Se esperaba una excepcion al no indicar la alerta.');
        } catch (RuntimeException $exception) {
            self::assertSame('No se indico que alerta.', $exception->getMessage());
        }

        self::assertFalse($this->alerts->isRead($id));
    }

    public function testNoSePuedeMarcarNiBorrarLaAlertaDeOtroUsuario(): void
    {
        $owner = $this->user(1);
        $intruder = $this->user(2);
        $id = $this->createAlert($owner);

        $this->apply($intruder, 'mark_read', $id);
        self::assertFalse($this->alerts->isRead($id), 'Otro usuario no puede marcar la alerta como leida.');

        $this->apply($owner, 'mark_read', $id);
        $this->apply($intruder, 'mark_unread', $id);
        self::assertTrue($this->alerts->isRead($id), 'Otro usuario no puede marcar la alerta como no leida.');

        $this->apply($intruder, 'delete', $id);
        self::assertTrue($this->alerts->exists($id), 'Otro usuario no puede borrar la alerta.');

        $this->apply($intruder, 'delete_read');
        self::assertTrue($this->alerts->exists($id), 'Borrar las leidas solo afecta a las propias.');

        $this->apply($intruder, 'mark_all_read');
        self::assertTrue($this->alerts->isRead($id));
    }

    public function testFiltroSinLeerSoloDevuelveLasNoLeidas(): void
    {
        $user = $this->user();
        $read = $this->createAlert($user, 'AAPL');
        $this->createAlert($user, 'MSFT');
        $this->apply($user, 'mark_read', $read);

        $unreadOnly = $this->alerts->findRecentUnreadByUser($user);

        self::assertCount(1, $unreadOnly);
        self::assertSame('MSFT', $unreadOnly[0]->getTicker());
        self::assertCount(2, $this->alerts->findRecentByUser($user));
    }

    public function testLasAlertasDeOtroUsuarioNoSeListanNiSeCuentan(): void
    {
        $owner = $this->user(1);
        $other = $this->user(2);
        $this->createAlert($owner, 'AAPL');

        self::assertSame([], $this->alerts->findRecentByUser($other));
        self::assertSame(0, $this->alerts->countUnread($other));
        self::assertSame(1, $this->alerts->countUnread($owner));
    }
}
