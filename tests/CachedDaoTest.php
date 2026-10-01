<?php

declare(strict_types=1);

namespace MiGears\Dao\Tests;

use PDO;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use MiGears\Cache\ArrayCache;
use MiGears\Cache\CacheInterface;
use MiGears\Dao\CachedDao;
use MiGears\Domain\DataAccess;
use MiGears\Sql\Exception\RecordNotFoundException;
use MiGears\Sql\Exception\SqlException;

class CachedDaoTest extends TestCase
{
    private PDO $pdo;
    private ArrayCache $cache;
    private CachedUserDao $dao;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_name VARCHAR(100) NOT NULL,
                email VARCHAR(100) NOT NULL,
                age INTEGER DEFAULT 0,
                status INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        SQL);

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (user_name, email, age, status) VALUES (:user_name, :email, :age, :status)'
        );
        foreach ([
            ['user_name' => 'Alice',   'email' => 'alice@example.com',   'age' => 25, 'status' => 1],
            ['user_name' => 'Bob',     'email' => 'bob@example.com',     'age' => 30, 'status' => 1],
            ['user_name' => 'Charlie', 'email' => 'charlie@example.com', 'age' => 35, 'status' => 0],
        ] as $user) {
            $stmt->execute($user);
        }

        $this->cache = new ArrayCache(new NullLogger());
        $this->dao = new CachedUserDao($this->pdo, new NullLogger(), $this->cache);
    }

    public function testGetByIdHydratesDomain(): void
    {
        $user = $this->dao->getById(1);
        $this->assertInstanceOf(CachedUserDomain::class, $user);
        $this->assertSame('Alice', $user->user_name);
    }

    public function testGetByIdOrFailReturnsDomain(): void
    {
        $user = $this->dao->getByIdOrFail(1);
        $this->assertInstanceOf(CachedUserDomain::class, $user);
        $this->assertSame('Alice', $user->user_name);
    }

    public function testGetByIdOrFailThrowsWhenNotFound(): void
    {
        $this->expectException(RecordNotFoundException::class);
        $this->dao->getByIdOrFail(999);
    }

    public function testGetByIdSecondCallComesFromCache(): void
    {
        $first = $this->dao->getById(1);
        $second = $this->dao->getById(1);
        $this->assertSame($first, $second);
    }

    public function testGetByIdNotFoundReturnsNull(): void
    {
        $this->assertNull($this->dao->getById(999));
    }

    public function testGetByIdsBatchHydratesAndCaches(): void
    {
        $users = $this->dao->getByIds([1, 3]);
        $this->assertCount(2, $users);
        $this->assertInstanceOf(CachedUserDomain::class, $users[1]);
        $this->assertSame('Charlie', $users[3]->user_name);

        $this->assertSame($users[1], $this->dao->getById(1));
    }

    public function testInsertThenGetByIdReturnsDomain(): void
    {
        $id = $this->dao->insert(['user_name' => 'Dave', 'email' => 'dave@example.com', 'age' => 40, 'status' => 1]);
        $this->assertSame('4', $id);

        $user = $this->dao->getById($id);
        $this->assertInstanceOf(CachedUserDomain::class, $user);
        $this->assertSame('Dave', $user->user_name);
    }

    public function testUpdateInvalidatesPrimaryKeyCache(): void
    {
        $this->dao->getById(1);
        $this->assertTrue($this->cache->has('users_1'));

        $this->dao->update(['id' => 1, 'user_name' => 'Alice Updated']);

        $this->assertFalse($this->cache->has('users_1'));
        $this->assertSame('Alice Updated', $this->dao->getById(1)->user_name);
    }

    public function testDeleteInvalidatesPrimaryKeyCache(): void
    {
        $this->dao->getById(1);
        $this->assertTrue($this->cache->has('users_1'));

        $this->dao->delete(1);

        $this->assertFalse($this->cache->has('users_1'));
        $this->assertNull($this->dao->getById(1));
    }

    public function testGetAllHydratesDomains(): void
    {
        $users = $this->dao->getAll();
        $this->assertCount(3, $users);
        $this->assertInstanceOf(CachedUserDomain::class, $users[0]);
    }

    public function testCount(): void
    {
        $this->assertSame(3, $this->dao->count());
    }

    public function testUpdateWithoutIdThrows(): void
    {
        $this->expectException(SqlException::class);
        $this->dao->update(['user_name' => 'No Id']);
    }

    public function testGetByIdsEmptyArrayReturnsEmpty(): void
    {
        $this->assertSame([], $this->dao->getByIds([]));
    }

    public function testGetByIdsWithNonExistentIdsReturnsPartial(): void
    {
        $users = $this->dao->getByIds([1, 999, 3]);
        $this->assertCount(2, $users);
        $this->assertArrayHasKey(1, $users);
        $this->assertArrayHasKey(3, $users);
    }

    public function testGetByIdsMixedCacheHitAndDbMiss(): void
    {
        // id=1 先走一次 getById 使其进缓存
        $warm = $this->dao->getById(1);

        // [1(命中缓存), 3(未缓存, 走 DB 回填)]
        $users = $this->dao->getByIds([1, 3]);
        $this->assertCount(2, $users);
        $this->assertSame($warm, $users[1]);
        $this->assertSame('Charlie', $users[3]->user_name);

        // 3 也应被写入缓存
        $this->assertSame($users[3], $this->dao->getById(3));
    }

    public function testUpdateOnlyIdReturnsZeroAndInvalidatesCache(): void
    {
        $this->dao->getById(1);
        $this->assertTrue($this->cache->has('users_1'));

        $this->assertSame(0, $this->dao->update(['id' => 1]));

        $this->assertFalse($this->cache->has('users_1'));
    }

    public function testInsertClearsResidualCacheForId(): void
    {
        // P2 场景：id=4 曾存在、被非 DAO 方式删除，导致缓存残留脏对象
        $stale = CachedUserDomain::fromArray(['id' => 4, 'user_name' => 'Stale', 'email' => 'x@y.z', 'age' => 0, 'status' => 1, 'created_at' => '2020-01-01 00:00:00']);
        $this->cache->set('users_4', $stale);

        // insert 会拿到自增 id=4，应顺手清理残留缓存
        $newId = $this->dao->insert(['user_name' => 'Frank', 'email' => 'f@example.com', 'age' => 30, 'status' => 1]);
        $this->assertSame('4', $newId);
        $this->assertFalse($this->cache->has('users_4'));

        // 读到的应是 DB 新记录，而非脏对象
        $user = $this->dao->getById(4);
        $this->assertInstanceOf(CachedUserDomain::class, $user);
        $this->assertSame('Frank', $user->user_name);
    }

    public function testDeleteCacheForHookReceivesNullOnDelete(): void
    {
        $dao = new HookUserDao($this->pdo, new NullLogger(), $this->cache);
        $dao->delete(1);
        $this->assertSame(1, $dao->hookCalls);
        $this->assertNull($dao->hookDomain);
    }

    public function testDeleteCacheForHookReceivesDomain(): void
    {
        $dao = new HookUserDao($this->pdo, new NullLogger(), $this->cache);
        $dao->getById(1);
        $dao->update(['id' => 1, 'user_name' => 'Renamed']);
        $this->assertSame(1, $dao->hookCalls);
        $this->assertSame('Renamed', $dao->hookDomain?->user_name);
    }

    public function testInsertWithExplicitIdPreservesPrimaryKey(): void
    {
        $id = $this->dao->insert(['id' => 100, 'user_name' => 'Zoe', 'email' => 'zoe@example.com', 'age' => 28, 'status' => 1]);
        $this->assertSame('100', $id);

        $user = $this->dao->getById(100);
        $this->assertInstanceOf(CachedUserDomain::class, $user);
        $this->assertSame('Zoe', $user->user_name);
    }

    public function testPaginateHydratesDomains(): void
    {
        $result = $this->dao->paginate(1, 2);
        $this->assertSame(3, $result['total']);
        $this->assertCount(2, $result['records']);
        $this->assertContainsOnlyInstancesOf(CachedUserDomain::class, $result['records']);
        $this->assertSame('Charlie', $result['records'][0]->user_name);
    }

    public function testDeleteCacheFailureDoesNotPropagate(): void
    {
        $flakyCache = $this->createMock(CacheInterface::class);
        $flakyCache->method('get')->willReturn(null);
        $flakyCache->method('delete')->willThrowException(new \RuntimeException('cache down'));
        $flakyCache->method('set')->willReturn(true);

        $dao = new CachedUserDao($this->pdo, new NullLogger(), $flakyCache);

        // Cache failure during delete must not break the write
        $affected = $dao->delete(1);
        $this->assertSame(1, $affected);
    }

    public function testUpdateWithStringPrimaryKeyInvalidatesCache(): void
    {
        $this->pdo->exec('CREATE TABLE uuid_users (uuid TEXT PRIMARY KEY, user_name VARCHAR(100) NOT NULL)');
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $dao = new UuidUserDao($this->pdo, new NullLogger(), $this->cache);
        $dao->insert(['uuid' => $uuid, 'user_name' => 'before']);

        // 首次读取写入缓存
        $this->assertSame('before', $dao->getById($uuid)->user_name);
        $this->assertTrue($this->cache->has('uuid_users_' . $uuid));

        $dao->update(['uuid' => $uuid, 'user_name' => 'after']);

        // 必须以原始字符串主键失效缓存，而不是 (int) 强转后的错误键
        $this->assertFalse($this->cache->has('uuid_users_' . $uuid));
        $this->assertSame('after', $dao->getById($uuid)->user_name);

        // deleteCacheFor 钩子收到正确的 domain，而非 null（insert 与 update 各触发一次）
        $this->assertSame(2, $dao->hookCalls);
        $this->assertSame($uuid, $dao->hookDomain?->uuid);
        $this->assertSame('after', $dao->hookDomain?->user_name);
    }

    public function testGetByIdsDoesNotDuplicateRowForNonCanonicalNumericId(): void
    {
        // '01' is not a canonical numeric string, so PHP keeps it as a string
        // array key while the database hands the primary key back as an int.
        // Keying cache hits by the requested value therefore put the same row
        // into the result twice.
        $this->dao->getById('01'); // warms the users_1 cache entry (id normalised)
        $this->assertTrue($this->cache->has('users_1'));

        $users = $this->dao->getByIds(['01', 1]);

        $this->assertCount(1, $users);
        $this->assertArrayHasKey(1, $users);
        $this->assertSame('Alice', $users[1]->user_name);
    }

    public function testPrimaryKeyReadsUseCacheButGetAllCountPaginateDoNot(): void
    {
        $cache = new ArrayCache(new NullLogger());
        $dao = new CachedUserDao($this->pdo, new NullLogger(), $cache);

        $dao->getAll();
        $dao->count();
        $dao->paginate(1, 2);

        // Only primary-key lookups populate the cache; the other reads always
        // hit the database (the README states exactly this).
        $this->assertFalse($cache->has('users_1'));
        $this->assertFalse($cache->has('users_2'));
        $this->assertFalse($cache->has('users_3'));
    }

    public function testUninitializedCacheThrowsSqlException(): void
    {
        $this->expectException(SqlException::class);

        $dao = new class {
            use CachedDao;

            protected string $table = 'users';
            protected string $idColumn = 'id';
            protected string $domainClass = CachedUserDomain::class;
        };

        $dao->getById(1);
    }

    public function testInitCachedDaoWithoutDomainClassThrows(): void
    {
        $this->expectException(SqlException::class);

        new class($this->pdo, new NullLogger(), new ArrayCache(new NullLogger())) {
            use CachedDao;

            protected string $table = 'users';
            protected string $idColumn = 'id';

            public function __construct(PDO $pdo, LoggerInterface $logger, CacheInterface $cache)
            {
                $this->initCachedDao($pdo, $logger, $cache);
            }
        };
    }

    public function testInsertWithDefaultHookSkipsReadBack(): void
    {
        $pdo = $this->makeCountingPdo();
        $dao = new CachedUserDao($pdo, new NullLogger(), new ArrayCache(new NullLogger()));

        $before = $pdo->prepares;
        $dao->insert(['user_name' => 'Dave', 'email' => 'dave@example.com', 'age' => 30, 'status' => 1]);

        // INSERT only — the default deleteCacheFor() is a no-op, so the
        // read-back SELECT that feeds the hook must be skipped.
        $this->assertSame(1, $pdo->prepares - $before);
    }

    public function testInsertWithOverriddenHookReadsBack(): void
    {
        $pdo = $this->makeCountingPdo();
        $dao = new HookUserDao($pdo, new NullLogger(), new ArrayCache(new NullLogger()));

        $before = $pdo->prepares;
        $dao->insert(['user_name' => 'Dave', 'email' => 'dave@example.com', 'age' => 30, 'status' => 1]);

        // INSERT + the read-back SELECT handed to the overridden hook.
        $this->assertSame(2, $pdo->prepares - $before);
    }

    public function testCacheFailureIsLogged(): void
    {
        $flakyCache = $this->createMock(CacheInterface::class);
        $flakyCache->method('get')->willReturn(null);
        $flakyCache->method('delete')->willThrowException(new \RuntimeException('cache down'));
        $flakyCache->method('set')->willReturn(true);

        $logger = new CapturingLogger();
        $dao = new CachedUserDao($this->pdo, $logger, $flakyCache);

        // The write still succeeds; the failure is swallowed but logged.
        $this->assertSame(1, $dao->delete(1));

        $warnings = array_values(array_filter(
            $logger->records,
            static fn(array $record): bool => $record['level'] === 'warning'
        ));
        $this->assertCount(1, $warnings);
        $this->assertSame('cache down', $warnings[0]['context']['exception']->getMessage());
    }

    public function testGetByIdNormalizesNumericCacheKey(): void
    {
        $this->dao->getById('01');

        $this->assertTrue($this->cache->has('users_1'));
        $this->assertFalse($this->cache->has('users_01'));

        // The int form shares the key, so it is served from the cache.
        $this->assertSame('Alice', $this->dao->getById(1)->user_name);
    }

    public function testGetByIdInvalidationCoversNonCanonicalNumericKey(): void
    {
        $this->dao->getById('01');
        $this->assertTrue($this->cache->has('users_1'));

        $this->dao->update(['id' => 1, 'user_name' => 'Alice Updated']);

        $this->assertFalse($this->cache->has('users_1'));
        $this->assertSame('Alice Updated', $this->dao->getById('01')->user_name);
    }

    /**
     * Equivalent spellings of the same numeric primary key must collapse onto
     * one cache key, or a write clears a key no read ever used.
     *
     * @dataProvider nonCanonicalIntegerIds
     */
    public function testGetByIdNormalisesEquivalentNumericSpellings(string $spelling): void
    {
        $this->dao->getById($spelling);

        // '+1', ' 1' and '1.0' all address row 1 and share its canonical key.
        $this->assertTrue($this->cache->has('users_1'));
        $this->assertFalse($this->cache->has('users_' . $spelling));

        // A canonical write therefore invalidates what the spelling reads.
        $this->dao->update(['id' => 1, 'user_name' => 'Alice Updated']);
        $this->assertSame('Alice Updated', $this->dao->getById($spelling)->user_name);
    }

    /** @return list<array{string}> */
    public static function nonCanonicalIntegerIds(): array
    {
        return [['+1'], [' 1'], ['1.0']];
    }

    public function testGetByIdsIndexesCacheHitsByDomainIdWhenPrimaryKeyIsNotPublic(): void
    {
        $dao = new HiddenIdUserDao($this->pdo, new NullLogger(), $this->cache);

        // Warm row 1, then request it through two spellings of the same key.
        // Both are cache hits, so the result must be keyed by the Domain's own
        // primary key — not by the requested spelling. get_object_vars() from
        // the trait's scope cannot see a non-public key and would key the same
        // row twice.
        $dao->getById(1);

        $users = $dao->getByIds(['01', 1]);

        $this->assertCount(1, $users);
        $this->assertArrayHasKey(1, $users);
        $this->assertSame('Alice', $users[1]->user_name);
    }

    public function testCacheReadFailureOnGetByIdFallsBackToDatabaseAndIsLogged(): void
    {
        $flakyCache = $this->createMock(CacheInterface::class);
        $flakyCache->method('get')->willThrowException(new \RuntimeException('cache read down'));
        $flakyCache->method('set')->willReturn(true);

        $logger = new CapturingLogger();
        $dao = new CachedUserDao($this->pdo, $logger, $flakyCache);

        $user = $dao->getById(1);
        $this->assertInstanceOf(CachedUserDomain::class, $user);
        $this->assertSame('Alice', $user->user_name);

        $warnings = array_values(array_filter(
            $logger->records,
            static fn(array $record): bool => $record['level'] === 'warning'
        ));
        $this->assertCount(1, $warnings);
        $this->assertSame('cache read down', $warnings[0]['context']['exception']->getMessage());
    }

    public function testCacheWriteFailureOnGetByIdFallsBackToDatabaseAndIsLogged(): void
    {
        $flakyCache = $this->createMock(CacheInterface::class);
        $flakyCache->method('get')->willReturn(null);
        $flakyCache->method('set')->willThrowException(new \RuntimeException('cache write down'));

        $logger = new CapturingLogger();
        $dao = new CachedUserDao($this->pdo, $logger, $flakyCache);

        $this->assertSame('Alice', $dao->getById(1)->user_name);

        $warnings = array_values(array_filter(
            $logger->records,
            static fn(array $record): bool => $record['level'] === 'warning'
        ));
        $this->assertCount(1, $warnings);
        $this->assertSame('cache write down', $warnings[0]['context']['exception']->getMessage());
    }

    public function testCacheFailureOnGetByIdsFallsBackToDatabase(): void
    {
        $flakyCache = $this->createMock(CacheInterface::class);
        $flakyCache->method('get')->willThrowException(new \RuntimeException('cache down'));
        $flakyCache->method('set')->willThrowException(new \RuntimeException('cache down'));

        $dao = new CachedUserDao($this->pdo, new NullLogger(), $flakyCache);

        $users = $dao->getByIds([1, 3]);

        $this->assertCount(2, $users);
        $this->assertSame('Alice', $users[1]->user_name);
        $this->assertSame('Charlie', $users[3]->user_name);
    }

    /**
     * The README's "Using CachedDao" example must be copyable: without the
     * constructor and initCachedDao() the DAO stays uninitialised.
     *
     * @dataProvider readmeCachedDaoSections
     */
    public function testReadmeCachedDaoExampleIncludesConstructor(string $header): void
    {
        $readme = (string) file_get_contents(__DIR__ . '/../README.md');

        $start = strpos($readme, $header);
        $this->assertNotFalse($start, "README is missing the section: {$header}");

        $section = substr($readme, $start);
        $end = strpos($section, "\n## ");
        if ($end !== false) {
            $section = substr($section, 0, $end);
        }

        $this->assertStringContainsString('public function __construct(', $section);
        $this->assertStringContainsString('initCachedDao($pdo, $logger, $cache);', $section);
    }

    /** @return list<array{string}> */
    public static function readmeCachedDaoSections(): array
    {
        return [['### Using CachedDao'], ['### 使用 CachedDao']];
    }

    private function makeCountingPdo(): CountingPdo
    {
        $pdo = new CountingPdo('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(<<<'SQL'
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_name VARCHAR(100) NOT NULL,
                email VARCHAR(100) NOT NULL,
                age INTEGER DEFAULT 0,
                status INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        SQL);

        return $pdo;
    }
}

class CachedUserDao
{
    use CachedDao;

    protected string $table = 'users';
    protected string $idColumn = 'id';
    protected string $domainClass = CachedUserDomain::class;

    public function __construct(PDO $pdo, LoggerInterface $logger, CacheInterface $cache)
    {
        $this->initCachedDao($pdo, $logger, $cache);
    }
}

class HookUserDao
{
    use CachedDao;

    protected string $table = 'users';
    protected string $idColumn = 'id';
    protected string $domainClass = CachedUserDomain::class;

    public int $hookCalls = 0;
    public ?CachedUserDomain $hookDomain = null;

    public function __construct(PDO $pdo, LoggerInterface $logger, CacheInterface $cache)
    {
        $this->initCachedDao($pdo, $logger, $cache);
    }

    protected function deleteCacheFor(?object $domain): void
    {
        $this->hookCalls++;
        $this->hookDomain = $domain;
    }
}

class CachedUserDomain
{
    use DataAccess;

    public function __construct(
        public readonly int $id,
        public readonly string $user_name,
        public readonly string $email,
        public readonly int $age,
        public readonly int $status,
        public readonly string $created_at,
    ) {}
}

class UuidUserDao
{
    use CachedDao;

    protected string $table = 'uuid_users';
    protected string $idColumn = 'uuid';
    protected string $domainClass = UuidUserDomain::class;

    public int $hookCalls = 0;
    public ?UuidUserDomain $hookDomain = null;

    public function __construct(PDO $pdo, LoggerInterface $logger, CacheInterface $cache)
    {
        $this->initCachedDao($pdo, $logger, $cache);
    }

    protected function deleteCacheFor(?object $domain): void
    {
        $this->hookCalls++;
        $this->hookDomain = $domain;
    }
}

class UuidUserDomain
{
    use DataAccess;

    public function __construct(
        public readonly string $uuid,
        public readonly string $user_name,
    ) {}
}

/**
 * A Domain whose primary key is deliberately non-public: the DAO must still be
 * able to read it to index cache hits, without any get_object_vars() from the
 * trait's scope and without a public accessor.
 */
class HiddenIdUserDomain
{
    use DataAccess;

    public function __construct(
        private readonly int $id,
        public readonly string $user_name,
        public readonly string $email,
        public readonly int $age,
        public readonly int $status,
        public readonly string $created_at,
    ) {}
}

class HiddenIdUserDao
{
    use CachedDao;

    protected string $table = 'users';
    protected string $idColumn = 'id';
    protected string $domainClass = HiddenIdUserDomain::class;

    public function __construct(PDO $pdo, LoggerInterface $logger, CacheInterface $cache)
    {
        $this->initCachedDao($pdo, $logger, $cache);
    }
}

class CountingPdo extends PDO
{
    public int $prepares = 0;

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->prepares++;
        return parent::prepare($query, $options);
    }
}

class CapturingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
