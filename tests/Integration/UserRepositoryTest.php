<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Integration;

use PDOException;
use StockAnalyzer\Models\User;
use StockAnalyzer\Repository\UserRepository;

/**
 * Registro y verificacion de email (`v2.11`), contra la base de datos.
 *
 * Es el unico repositorio donde la correccion depende casi entera del
 * motor: el `UNIQUE` del email es lo que impide dos cuentas iguales, las
 * caducidades se calculan con `DATE_ADD(NOW(), INTERVAL 24 HOUR)` en SQL y
 * no en PHP, y `regenerateVerificationToken()` decide si devuelve `null`
 * mirando el `rowCount()` del `UPDATE`. Nada de eso se puede comprobar sin
 * MySQL delante.
 */
final class UserRepositoryTest extends IntegrationTestCase
{
    private UserRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new UserRepository($this->connection());
    }

    public function testUnUsuarioNuevoNaceSinVerificarYConToken(): void
    {
        $user = $this->repository->create('nuevo@example.com', 'hash', 'token-1');

        self::assertFalse($user->isEmailVerified());

        $pendiente = $this->repository->findPendingVerification('token-1');

        self::assertNotNull($pendiente);
        self::assertSame($user->getId(), $pendiente['id']);
        self::assertSame('nuevo@example.com', $pendiente['email']);
        // La caducidad la calcula MySQL con INTERVAL 24 HOUR.
        self::assertGreaterThan(new \DateTimeImmutable('+23 hours'), $pendiente['expires_at']);
        self::assertLessThan(new \DateTimeImmutable('+25 hours'), $pendiente['expires_at']);
    }

    /**
     * El email se guarda y se busca siempre en minusculas, para que
     * "Ana@Example.com" y "ana@example.com" no sean dos cuentas.
     */
    public function testElEmailSeNormalizaAMinusculas(): void
    {
        $this->repository->create('Ana@Example.COM', 'hash', 'token-1');

        $credenciales = $this->repository->findCredentialsByEmail('ana@example.com');

        self::assertNotNull($credenciales);
        self::assertSame('ana@example.com', $credenciales['user']->getEmail());
        self::assertNotNull($this->repository->findCredentialsByEmail('ANA@EXAMPLE.COM'));
    }

    /**
     * Lo unico que impide de verdad dos cuentas con el mismo correo es el
     * `UNIQUE KEY uniq_users_email`. Una comprobacion previa en PHP tiene
     * una carrera entre el SELECT y el INSERT; el indice no.
     */
    public function testNoSePuedeRegistrarDosVecesElMismoEmail(): void
    {
        $this->repository->create('ana@example.com', 'hash', 'token-1');

        $this->expectException(PDOException::class);

        $this->repository->create('ana@example.com', 'otro-hash', 'token-2');
    }

    public function testVerificarElEmailConsumeElToken(): void
    {
        $user = $this->repository->create('ana@example.com', 'hash', 'token-1');

        $this->repository->markEmailVerified($user->getId());

        $verificado = $this->repository->findById($user->getId());

        self::assertNotNull($verificado);
        self::assertTrue($verificado->isEmailVerified());
        self::assertNull(
            $this->repository->findPendingVerification('token-1'),
            'El token usado deja de valer: no se puede reutilizar el enlace.'
        );
    }

    public function testReenviarLaVerificacionCambiaElTokenYAnulaElAnterior(): void
    {
        $this->repository->create('ana@example.com', 'hash', 'token-viejo');

        $user = $this->repository->regenerateVerificationToken('ana@example.com', 'token-nuevo');

        self::assertInstanceOf(User::class, $user);
        self::assertNull($this->repository->findPendingVerification('token-viejo'));
        self::assertNotNull($this->repository->findPendingVerification('token-nuevo'));
    }

    /**
     * Devuelve `null` para un email que no existe y para uno ya
     * verificado, sin distinguir entre los dos casos: si respondiera
     * distinto, el formulario de reenvio serviria para averiguar que
     * correos tienen cuenta.
     */
    public function testReenviarNoDistingueEntreCuentaInexistenteYCuentaYaVerificada(): void
    {
        $user = $this->repository->create('ana@example.com', 'hash', 'token-1');
        $this->repository->markEmailVerified($user->getId());

        self::assertNull($this->repository->regenerateVerificationToken('ana@example.com', 'token-2'));
        self::assertNull($this->repository->regenerateVerificationToken('no-existe@example.com', 'token-3'));
    }

    public function testUnTokenQueNoExisteNoDevuelveNada(): void
    {
        $this->repository->create('ana@example.com', 'hash', 'token-1');

        self::assertNull($this->repository->findPendingVerification('token-inventado'));
    }

    /**
     * `findPendingVerification()` no mira la caducidad (de eso se encarga
     * `AuthService` comparando `expires_at`), pero si tiene que devolverla
     * para que se pueda comparar. Un token caducado sigue apareciendo con
     * su fecha en el pasado, no desaparece: la diferencia importa, porque
     * es lo que permite dar el mensaje "el enlace ha caducado" en vez de
     * "el enlace no es valido".
     */
    public function testUnTokenCaducadoSigueLocalizableConSuFechaEnElPasado(): void
    {
        $this->repository->create('ana@example.com', 'hash', 'token-1');
        $this->pdoOrSkip()->exec(
            'UPDATE users SET verification_expires_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE email = "ana@example.com"'
        );

        $pendiente = $this->repository->findPendingVerification('token-1');

        self::assertNotNull($pendiente);
        self::assertLessThan(new \DateTimeImmutable(), $pendiente['expires_at']);
    }
}
