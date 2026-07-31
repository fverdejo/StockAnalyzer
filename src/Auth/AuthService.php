<?php

declare(strict_types=1);

namespace StockAnalyzer\Auth;

use InvalidArgumentException;
use RuntimeException;
use StockAnalyzer\Interfaces\MailerInterface;
use StockAnalyzer\Models\User;
use StockAnalyzer\Repository\UserRepository;

/**
 * Desde v2.11, register() ya no inicia sesion automaticamente: crea la
 * cuenta sin verificar, envia un correo de confirmacion con un token, y
 * login() rechaza el acceso hasta que ese token se confirme via
 * verifyEmail(). Antes de v2.11, registrarse abria sesion al instante.
 */
class AuthService
{
    private const SESSION_KEY = 'stock_analyzer_user';
    private const ATTEMPTS_KEY = 'stock_analyzer_login_attempts';
    private const MAX_ATTEMPTS = 8;

    /**
     * Construye AuthService.
     *
     * $verificationBaseUrl debe ser una URL absoluta (esquema + dominio +
     * ruta, p.ej. "https://stockanalyzer.ddev.site/?page=verify-email")
     * para que el enlace del correo de verificacion sea clicable desde
     * cualquier cliente de correo. Application la construye a partir de
     * Config\AppUrlConfig; el valor por defecto de aqui es solo relativo,
     * como fallback para quien instancie AuthService sin pasarlo.
     */
    public function __construct(
        private readonly UserRepository $users,
        private readonly MailerInterface $mailer,
        private readonly string $verificationBaseUrl = '?page=verify-email'
    ) {
        $this->ensureSessionStarted();
    }

    /**
     * Crea la cuenta pendiente de verificar y envia el correo de
     * confirmacion. No inicia sesion: el usuario debe confirmar el email
     * antes de poder entrar (ver login()).
     */
    public function register(string $email, string $password): User
    {
        $email = $this->normalizeEmail($email);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Introduce un email valido.');
        }

        if (strlen($password) < 8) {
            throw new InvalidArgumentException('La contrasena debe tener al menos 8 caracteres.');
        }

        if ($this->users->findCredentialsByEmail($email) !== null) {
            throw new InvalidArgumentException('Ya existe una cuenta con ese email.');
        }

        $token = $this->generateToken();
        $user = $this->users->create($email, password_hash($password, PASSWORD_DEFAULT), $token);
        $this->sendVerificationEmail($email, $token);

        return $user;
    }

    public function login(string $email, string $password): User
    {
        if ($this->getFailedAttempts() >= self::MAX_ATTEMPTS) {
            throw new RuntimeException('Demasiados intentos fallidos. Cierra el navegador o espera antes de intentarlo de nuevo.');
        }

        $credentials = $this->users->findCredentialsByEmail($this->normalizeEmail($email));

        if ($credentials === null || !password_verify($password, $credentials['password_hash'])) {
            $this->recordFailedAttempt();

            throw new InvalidArgumentException('Email o contrasena incorrectos.');
        }

        if (!$credentials['user']->isEmailVerified()) {
            throw new RuntimeException('Todavia no has confirmado tu email. Revisa tu correo (o pide reenviarlo) antes de iniciar sesion.');
        }

        $_SESSION[self::ATTEMPTS_KEY] = 0;
        $this->setCurrentUser($credentials['user']);

        return $credentials['user'];
    }

    /**
     * Confirma la cuenta a partir del token del enlace de verificacion.
     */
    public function verifyEmail(string $token): void
    {
        $token = trim($token);

        if ($token === '') {
            throw new InvalidArgumentException('Enlace de verificacion invalido.');
        }

        $pending = $this->users->findPendingVerification($token);

        if ($pending === null) {
            throw new InvalidArgumentException('El enlace de verificacion no es valido o ya se ha usado.');
        }

        if ($pending['expires_at'] < new \DateTimeImmutable()) {
            throw new InvalidArgumentException('El enlace de verificacion ha caducado. Pide que se reenvie el correo.');
        }

        $this->users->markEmailVerified($pending['id']);
    }

    /**
     * Reenvia el correo de confirmacion. No revela si el email existe o
     * no (siempre "silencioso") para no filtrar que cuentas estan
     * registradas a quien no las conoce.
     */
    public function resendVerification(string $email): void
    {
        $email = $this->normalizeEmail($email);
        $token = $this->generateToken();
        $user = $this->users->regenerateVerificationToken($email, $token);

        if ($user instanceof User) {
            $this->sendVerificationEmail($email, $token);
        }
    }

    private function sendVerificationEmail(string $email, string $token): void
    {
        $link = $this->verificationBaseUrl . '&token=' . urlencode($token);
        $body = "Hola,\n\nConfirma tu cuenta de Stock Analyzer pulsando este enlace (caduca en 24 horas):\n\n{$link}\n\nSi no has creado esta cuenta, ignora este correo.";

        try {
            $this->mailer->send($email, 'Confirma tu cuenta de Stock Analyzer', $body);
        } catch (\Throwable) {
            // El registro no debe fallar solo porque el envio de correo falle;
            // el usuario siempre puede pedir un reenvio mas tarde.
        }
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    public function currentUser(): ?User
    {
        $stored = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_array($stored)) {
            return null;
        }

        return new User(
            (int) ($stored['id'] ?? 0),
            (string) ($stored['email'] ?? ''),
            new \DateTimeImmutable((string) ($stored['created_at'] ?? 'now')),
            isset($stored['email_verified_at']) ? new \DateTimeImmutable((string) $stored['email_verified_at']) : null
        );
    }

    public function requireUser(): User
    {
        $user = $this->currentUser();

        if (!$user instanceof User) {
            throw new RuntimeException('Debes iniciar sesion para acceder a esta pagina.');
        }

        return $user;
    }

    private function setCurrentUser(User $user): void
    {
        $_SESSION[self::SESSION_KEY] = [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'created_at' => $user->getCreatedAt()->format(DATE_ATOM),
            'email_verified_at' => $user->getEmailVerifiedAt()?->format(DATE_ATOM),
        ];
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function getFailedAttempts(): int
    {
        return (int) ($_SESSION[self::ATTEMPTS_KEY] ?? 0);
    }

    private function recordFailedAttempt(): void
    {
        $_SESSION[self::ATTEMPTS_KEY] = $this->getFailedAttempts() + 1;
    }

    private function ensureSessionStarted(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}
