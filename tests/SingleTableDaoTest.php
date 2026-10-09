<?php

declare(strict_types=1);

namespace MiGears\Dao\Tests;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use MiGears\Dao\SingleTableDao;
use MiGears\Domain\DataAccess;
use MiGears\Sql\Exception\RecordNotFoundException;
use MiGears\Sql\Exception\SqlException;
use MiGears\Sql\SqlBuilder;

class SingleTableDaoTest extends TestCase
{
    private UserDao $dao;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
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

        $users = [
            ['user_name' => 'Alice',   'email' => 'alice@example.com',   'age' => 25, 'status' => 1],
            ['user_name' => 'Bob',     'email' => 'bob@example.com',     'age' => 30, 'status' => 1],
            ['user_name' => 'Charlie', 'email' => 'charlie@example.com', 'age' => 35, 'status' => 0],
            ['user_name' => 'Diana',   'email' => 'diana@example.com',   'age' => 28, 'status' => 1],
            ['user_name' => 'Eve',     'email' => 'eve@example.com',     'age' => 22, 'status' => 0],
        ];

        $stmt = $pdo->prepare(
            'INSERT INTO users (user_name, email, age, status) VALUES (:user_name, :email, :age, :status)'
        );
        foreach ($users as $user) {
            $stmt->execute($user);
        }

        $this->dao = new UserDao($pdo, new NullLogger());
    }

    public function testGetByIdReturnsDomain(): void
    {
        $user = $this->dao->getById(1);
        $this->assertInstanceOf(UserDomain::class, $user);
        $this->assertSame('Alice', $user->user_name);
    }

    public function testGetByIdReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->dao->getById(999));
    }

    public function testGetByIdOrFailReturnsDomain(): void
    {
        $user = $this->dao->getByIdOrFail(1);
        $this->assertInstanceOf(UserDomain::class, $user);
        $this->assertSame('Alice', $user->user_name);
    }

    public function testGetByIdOrFailThrowsWhenNotFound(): void
    {
        $this->expectException(RecordNotFoundException::class);
        $this->dao->getByIdOrFail(999);
    }

    public function testGetByIdsReturnsDomainsIndexedById(): void
    {
        $users = $this->dao->getByIds([1, 2, 3]);
        $this->assertCount(3, $users);
        $this->assertContainsOnlyInstancesOf(UserDomain::class, $users);
        $this->assertSame('Alice', $users[1]->user_name);
        $this->assertSame('Bob', $users[2]->user_name);
    }

    public function testGetByIdsWithEmptyArrayReturnsEmpty(): void
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

    public function testGetByIdsWithDuplicateIds(): void
    {
        $users = $this->dao->getByIds([1, 1, 2]);
        $this->assertCount(2, $users);
    }

    public function testGetAllReturnsDomains(): void
    {
        $users = $this->dao->getAll();
        $this->assertCount(5, $users);
        $this->assertContainsOnlyInstancesOf(UserDomain::class, $users);
        $this->assertSame('Eve', $users[0]->user_name);
    }

    public function testInsertReturnsId(): void
    {
        $id = $this->dao->insert([
            'user_name' => 'Frank',
            'email' => 'frank@example.com',
            'age' => 40,
            'status' => 1,
        ]);

        $this->assertEquals(6, (int) $id);
    }

    public function testUpdateById(): void
    {
        $affected = $this->dao->update([
            'id' => 1,
            'user_name' => 'Alice Updated',
            'age' => 26,
        ]);

        $this->assertEquals(1, $affected);
    }

    public function testUpdateWithoutIdThrowsException(): void
    {
        $this->expectException(SqlException::class);
        $this->dao->update(['user_name' => 'No Id']);
    }

    public function testUpdateWithOnlyIdReturnsZero(): void
    {
        $this->assertEquals(0, $this->dao->update(['id' => 1]));
    }

    public function testDeleteById(): void
    {
        $affected = $this->dao->delete(1);
        $this->assertEquals(1, $affected);
        $this->assertNull($this->dao->getById(1));
        $this->assertEquals(4, $this->dao->count());
    }

    public function testDeleteNonExistentReturnsZero(): void
    {
        $this->assertEquals(0, $this->dao->delete(999));
    }

    public function testCount(): void
    {
        $this->assertEquals(5, $this->dao->count());
    }

    public function testSqlReturnsBuilderInstance(): void
    {
        $this->assertInstanceOf(SqlBuilder::class, $this->dao->sql());
    }

    public function testCustomIdColumnDao(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec(<<<'SQL'
            CREATE TABLE posts (
                post_id INTEGER PRIMARY KEY AUTOINCREMENT,
                title VARCHAR(100) NOT NULL,
                content TEXT
            )
        SQL);
        $pdo->exec("INSERT INTO posts (title, content) VALUES ('Hello', 'World')");

        $dao = new PostDao($pdo, new NullLogger());
        $post = $dao->getById(1);

        $this->assertInstanceOf(PostDomain::class, $post);
        $this->assertSame('Hello', $post->title);
    }

    public function testEmptyTableQueries(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE posts (post_id INTEGER PRIMARY KEY AUTOINCREMENT, title VARCHAR(100), content TEXT)');

        $dao = new PostDao($pdo, new NullLogger());
        $this->assertSame([], $dao->getAll());
        $this->assertSame([], $dao->getByIds([1, 2]));
        $this->assertSame(0, $dao->count());
        $this->assertNull($dao->getById(1));
    }

    public function testDaoWithDomainRoundTrip(): void
    {
        $user = $this->dao->getByIdOrFail(1);
        $this->assertSame('Alice', $user->user_name);

        // Domain → array → Domain round-trips unchanged
        $this->assertEquals($user, UserDomain::fromArray($user->toArray()));
    }

    public function testUninitializedDaoThrowsSqlException(): void
    {
        $this->expectException(SqlException::class);

        $pdo = new PDO('sqlite::memory:');
        $dao = new class($pdo, new NullLogger()) {
            use SingleTableDao;

            protected function table(): string { return 'users'; }
            protected function idColumn(): string { return 'id'; }
            protected function domainClass(): string { return UserDomain::class; }

            public function __construct(PDO $pdo, LoggerInterface $logger)
            {
                // Deliberately skip initDao() to simulate the error
            }
        };
        $dao->getById(1);
    }

    public function testHydrateReturnsDomainWhenDomainClassDeclared(): void
    {
        $dao = new class(new PDO('sqlite::memory:'), new NullLogger()) {
            use SingleTableDao;

            protected function table(): string { return 'users'; }
            protected function idColumn(): string { return 'id'; }
            protected function domainClass(): string { return UserDomain::class; }

            public function __construct(PDO $pdo, LoggerInterface $logger)
            {
                $this->initDao($pdo, $logger);
            }

            public function hydrateRow(array $row): object
            {
                return $this->hydrate($row);
            }
        };

        $user = $dao->hydrateRow([
            'id' => 1,
            'user_name' => 'Alice',
            'email' => 'alice@example.com',
            'age' => 25,
            'status' => 1,
            'created_at' => '2026-01-01 00:00:00',
        ]);

        $this->assertInstanceOf(UserDomain::class, $user);
        $this->assertSame('Alice', $user->user_name);
    }

    public function testHydrateThrowsOnTypeMismatch(): void
    {
        $this->expectException(\TypeError::class);

        $dao = new class(new PDO('sqlite::memory:'), new NullLogger()) {
            use SingleTableDao;

            protected function table(): string { return 'users'; }
            protected function idColumn(): string { return 'id'; }
            protected function domainClass(): string { return UserDomain::class; }

            public function __construct(PDO $pdo, LoggerInterface $logger)
            {
                $this->initDao($pdo, $logger);
            }

            public function hydrateRow(array $row): object
            {
                return $this->hydrate($row);
            }
        };

        // UserDomain::id is int; a non-numeric string must fail the strict binding
        // rather than being silently coerced (the "Type Contract").
        $dao->hydrateRow([
            'id' => 'not-an-int',
            'user_name' => 'Alice',
            'email' => 'alice@example.com',
            'age' => 25,
            'status' => 1,
            'created_at' => '2026-01-01 00:00:00',
        ]);
    }

    public function testSaveInsertsNewDomainAndNormalisesKeyToInt(): void
    {
        $created = $this->dao->save(new UserDomain(
            id: 0,
            user_name: 'Frank',
            email: 'frank@example.com',
            age: 40,
            status: 1,
            created_at: '2026-02-01 00:00:00',
        ));

        $this->assertInstanceOf(UserDomain::class, $created);
        $this->assertSame(6, $created->id);
        $this->assertSame('Frank', $created->user_name);
        $this->assertNotNull($this->dao->getById(6));
    }

    public function testSaveUpdatesExistingDomain(): void
    {
        $saved = $this->dao->save(new UserDomain(
            id: 1,
            user_name: 'Alice Renamed',
            email: 'alice@example.com',
            age: 26,
            status: 1,
            created_at: '2026-01-01 00:00:00',
        ));

        $this->assertSame(1, $saved->id);
        $this->assertSame('Alice Renamed', $this->dao->getById(1)->user_name);
    }
}

class UserDao
{
    use SingleTableDao;

    protected function table(): string { return 'users'; }

    protected function idColumn(): string { return 'id'; }

    protected function domainClass(): string { return UserDomain::class; }

    public function __construct(PDO $pdo, LoggerInterface $logger)
    {
        $this->initDao($pdo, $logger);
    }
}

class PostDao
{
    use SingleTableDao;

    protected function table(): string { return 'posts'; }

    protected function idColumn(): string { return 'post_id'; }

    protected function domainClass(): string { return PostDomain::class; }

    public function __construct(PDO $pdo, LoggerInterface $logger)
    {
        $this->initDao($pdo, $logger);
    }
}

class UserDomain
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

class PostDomain
{
    use DataAccess;

    public function __construct(
        public readonly int $post_id,
        public readonly string $title,
        public readonly ?string $content,
    ) {}
}
