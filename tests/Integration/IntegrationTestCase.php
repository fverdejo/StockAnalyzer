<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use StockAnalyzer\Infrastructure\Database\Connection;
use Throwable;

/**
 * Base de los tests que hablan con MySQL de verdad.
 *
 * Hasta `v2.90` la suite entera funcionaba sin base de datos, lo que dejaba
 * sin cubrir justo lo que solo se puede comprobar con SQL real: que el
 * `WHERE ... AND user_id` de las alertas (`v2.69`) aisla a un usuario de
 * otro, que un `UNIQUE` impide duplicar y que un `ON DELETE CASCADE` limpia
 * lo que debe. Eso se habia verificado a mano con dos usuarios, y nada
 * impedia una regresion.
 *
 * Tres reglas de seguridad, en este orden:
 *
 * 1. **Nunca la base de datos de la aplicacion.** La conexion sale de
 *    `DB_DSN_TEST`, no de `DB_DSN`, y si no esta definida se usa el esquema
 *    `test` que DDEV ya crea aparte. `assertNotAppDatabase()` compara el
 *    esquema destino con el de la aplicacion y aborta si coinciden: estos
 *    tests hacen `TRUNCATE`, y equivocarse de esquema seria borrar la
 *    cartera real del usuario.
 * 2. **Se saltan solos si no hay base de datos.** El `php` del host no tiene
 *    driver PDO ni MySQL delante; ahi estos casos se marcan como skipped y
 *    la suite sigue verde. Dentro de `ddev exec` se ejecutan de verdad.
 * 3. **Cada test arranca con las tablas vacias**, no con lo que dejo el
 *    anterior.
 */
abstract class IntegrationTestCase extends TestCase
{
    private static ?PDO $pdo = null;
    private static ?string $skipReason = null;

    /**
     * Tablas que se vacian antes de cada test, en orden de dependencia
     * (las hijas primero: hay claves foraneas hacia `users`).
     *
     * @var list<string>
     */
    private const TABLES = [
        'alerts',
        'ticker_alert_state',
        'ticker_dividend_alert_state',
        'ticker_stop_loss_alert_state',
        'ticker_earnings_alert_state',
        'watchlist_items',
        'transactions',
        'score_history',
        'fundamentals_history',
        'fundamentals_history_v2110',
        'eodhd_raw_fundamentals',
        'index_membership',
        'eodhd_raw_index_membership',
        'market_data_cache',
        'market_history_cache',
        'market_movers_cache',
        'ticker_backtest_cache',
        'corporate_profile_cache',
        'daily_rankings',
        'news_items',
        'users',
    ];

    protected function setUp(): void
    {
        $pdo = self::pdo();

        if (!$pdo instanceof PDO) {
            self::markTestSkipped(self::$skipReason ?? 'Sin base de datos de pruebas disponible.');
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach (self::TABLES as $table) {
            $pdo->exec('TRUNCATE TABLE ' . $table);
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function pdoOrSkip(): PDO
    {
        $pdo = self::pdo();

        if (!$pdo instanceof PDO) {
            self::markTestSkipped(self::$skipReason ?? 'Sin base de datos de pruebas disponible.');
        }

        return $pdo;
    }

    /**
     * `Connection` envuelto sobre el PDO de pruebas. Los repositorios piden
     * un `Connection` en su constructor, asi que se le da uno cuyo
     * `getPdo()` devuelve la conexion al esquema de pruebas, en vez de
     * duplicar cada repositorio con una version "de test" que no seria el
     * codigo que corre en produccion.
     */
    protected function connection(): Connection
    {
        return new class ($this->pdoOrSkip()) extends Connection {
            public function __construct(private readonly PDO $testPdo)
            {
            }

            public function getPdo(): PDO
            {
                return $this->testPdo;
            }
        };
    }

    /**
     * Crea un usuario y devuelve su id. Casi todo lo que se prueba aqui
     * cuelga de un `user_id` con clave foranea, asi que no hay test que no
     * necesite esto.
     */
    protected function createUser(string $email): int
    {
        $pdo = $this->pdoOrSkip();
        $statement = $pdo->prepare(
            'INSERT INTO users (email, password_hash, created_at, email_verified_at)
             VALUES (:email, :hash, NOW(), NOW())'
        );
        $statement->execute(['email' => $email, 'hash' => password_hash('secreto', PASSWORD_DEFAULT)]);

        return (int) $pdo->lastInsertId();
    }

    private static function pdo(): ?PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        if (self::$skipReason !== null) {
            return null;
        }

        try {
            self::$pdo = self::connect();
        } catch (Throwable $exception) {
            self::$skipReason = 'Sin base de datos de pruebas: ' . $exception->getMessage();

            return null;
        }

        return self::$pdo;
    }

    private static function connect(): PDO
    {
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('la extension pdo_mysql no esta cargada.');
        }

        $dsn = self::env('DB_DSN_TEST');

        if ($dsn === null) {
            // DDEV crea el esquema `test` ademas del de la aplicacion,
            // precisamente para esto.
            $dsn = 'mysql:host=db;port=3306;dbname=test;charset=utf8mb4';
        }

        self::assertNotAppDatabase($dsn);

        $pdo = new PDO(
            $dsn,
            self::env('DB_USER_TEST') ?? self::env('DB_USER') ?? 'db',
            self::env('DB_PASSWORD_TEST') ?? self::env('DB_PASSWORD') ?? 'db',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        self::rebuildSchema($pdo);

        return $pdo;
    }

    /**
     * Vacia el esquema de pruebas y vuelve a aplicar las migraciones desde
     * cero, una vez por proceso de phpunit.
     *
     * Reconstruir en vez de "aplicar lo que falte" no es pereza: las
     * migraciones de este proyecto **no son idempotentes** y no pueden
     * serlo. La `017` borra de `market_data_cache` las dos columnas que la
     * `014` necesita para su `ADD COLUMN ... AFTER history_cached_at`, asi
     * que sobre un esquema ya migrado la segunda pasada revienta con un
     * `Unknown column`. Y ese orden es correcto en produccion, donde cada
     * migracion se aplica una sola vez.
     *
     * Partiendo de vacio, el esquema de pruebas es por construccion el
     * mismo que produce `database/migrations/` en produccion, que es lo
     * unico que hace que estos tests demuestren algo.
     */
    private static function rebuildSchema(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        /** @var list<string> $tables */
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        self::migrate($pdo);
    }

    /**
     * El esquema de pruebas NO puede ser el de la aplicacion: estos tests
     * hacen TRUNCATE de `users`, `transactions` y `alerts`.
     */
    private static function assertNotAppDatabase(string $testDsn): void
    {
        $appDsn = self::env('DB_DSN') ?? self::appDsnFromDotenv();

        if ($appDsn === null) {
            return;
        }

        $appSchema = self::schemaOf($appDsn);
        $testSchema = self::schemaOf($testDsn);

        if ($appSchema !== null && $appSchema === $testSchema) {
            throw new RuntimeException(sprintf(
                'DB_DSN_TEST apunta al mismo esquema que la aplicacion (%s). Estos tests hacen TRUNCATE.',
                $appSchema
            ));
        }
    }

    /**
     * `DB_DSN` casi nunca esta en el entorno del proceso: la aplicacion lo
     * lee de `.env` al abrir la conexion. Aqui se lee del fichero
     * directamente, porque si no la comprobacion de "no es la base de la
     * aplicacion" pasaria de largo justo cuando mas falta hace.
     */
    private static function appDsnFromDotenv(): ?string
    {
        $path = dirname(__DIR__, 2) . '/.env';

        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        return preg_match('/^\s*DB_DSN\s*=\s*(.+)$/m', $contents, $matches) === 1
            ? trim($matches[1], " \t\"'")
            : null;
    }

    private static function schemaOf(string $dsn): ?string
    {
        return preg_match('/dbname=([^;]+)/', $dsn, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * Aplica las migraciones reales del proyecto, en orden de nombre, sobre
     * un esquema vacio. Son las mismas de `database/migrations/`, no un
     * esquema paralelo escrito a mano: si el esquema de produccion y el de
     * los tests divergen, estos tests dejan de demostrar nada.
     *
     * Sin tolerancia a errores a proposito: sobre un esquema vacio las
     * migraciones deben aplicarse limpias, y que no lo hagan es justo el
     * tipo de fallo que interesa que salte aqui y no en la Raspberry.
     */
    private static function migrate(PDO $pdo): void
    {
        $files = glob(dirname(__DIR__, 2) . '/database/migrations/*.sql');

        if ($files === false) {
            throw new RuntimeException('No se pudo leer database/migrations.');
        }

        sort($files);

        foreach ($files as $file) {
            $sql = file_get_contents($file);

            if ($sql === false) {
                continue;
            }

            foreach (self::splitStatements($sql) as $statement) {
                $pdo->exec($statement);
            }
        }
    }

    /**
     * Trocea un fichero de migracion en sentencias. Los comentarios se
     * quitan ANTES de partir por `;`: varias migraciones llevan comentarios
     * `--` en prosa (con puntos y comas dentro), y partir en crudo dejaba
     * media frase como si fuera SQL.
     *
     * @return list<string>
     */
    private static function splitStatements(string $sql): array
    {
        $sinComentarios = preg_replace(['/^\s*--.*$/m', '#/\*.*?\*/#s'], '', $sql) ?? $sql;

        return array_values(array_filter(
            array_map('trim', explode(';', $sinComentarios)),
            static fn (string $statement): bool => $statement !== ''
        ));
    }

    private static function env(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
