<?php

declare(strict_types=1);

namespace MiGears\Dao\Tests;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use MiGears\Dao\SingleTableDao;
use MiGears\Dao\CursorPagination;
use MiGears\Domain\DataAccess;
use MiGears\Utils\FlowPaginator;

class CursorPaginationTest extends TestCase
{
    private PDO $pdo;

    private CursorUserDao $dao;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_name VARCHAR(100) NOT NULL
            )
        SQL);

        $stmt = $this->pdo->prepare('INSERT INTO users (user_name) VALUES (:user_name)');
        foreach (['Alice', 'Bob', 'Charlie', 'Diana', 'Eve'] as $name) {
            $stmt->execute(['user_name' => $name]);
        }

        $this->dao = new CursorUserDao($this->pdo, new NullLogger());
    }

    public function testFirstPageReturnsPageSizeItemsAndHasMore(): void
    {
        $page = $this->dao->paginateAfter(null, 2);

        $this->assertInstanceOf(FlowPaginator::class, $page);
        $this->assertSame(2, $page->count());
        $this->assertContainsOnlyInstancesOf(CursorUserDomain::class, $page->items());
        $this->assertSame('Eve', $page->items()[0]->user_name);
        $this->assertSame('Diana', $page->items()[1]->user_name);
        $this->assertTrue($page->hasMore());
        $this->assertNull($page->cursor());
        $this->assertSame(4, $page->nextCursor());
    }

    public function testNextPageUsesTheCursor(): void
    {
        $page = $this->dao->paginateAfter(4, 2);

        $this->assertSame('Charlie', $page->items()[0]->user_name);
        $this->assertSame('Bob', $page->items()[1]->user_name);
        $this->assertTrue($page->hasMore());
        $this->assertSame(2, $page->nextCursor());
    }

    public function testLastPageHasNoMore(): void
    {
        $page = $this->dao->paginateAfter(2, 2);

        $this->assertSame(1, $page->count());
        $this->assertSame('Alice', $page->items()[0]->user_name);
        $this->assertFalse($page->hasMore());
        $this->assertNull($page->nextCursor());
    }

    public function testExactPageSizeHasNoMore(): void
    {
        $page = $this->dao->paginateAfter(null, 5);

        $this->assertSame(5, $page->count());
        $this->assertFalse($page->hasMore());
        $this->assertNull($page->nextCursor());
    }

    public function testPageSizeLargerThanTableHasNoMore(): void
    {
        $page = $this->dao->paginateAfter(null, 10);

        $this->assertSame(5, $page->count());
        $this->assertFalse($page->hasMore());
    }

    public function testCursorBelowAllIdsReturnsEmptyPage(): void
    {
        $page = $this->dao->paginateAfter(1, 2);

        $this->assertTrue($page->isEmpty());
        $this->assertFalse($page->hasMore());
        $this->assertSame(0, $page->count());
    }

    public function testWalkingAllPagesViaNextCursorVisitsEveryRowOnce(): void
    {
        $seen = [];
        $cursor = null;

        do {
            $page = $this->dao->paginateAfter($cursor, 2);
            foreach ($page->items() as $item) {
                $seen[] = $item->id;
            }
            $cursor = $page->nextCursor();
        } while ($page->hasMore());

        $this->assertSame([5, 4, 3, 2, 1], $seen);
    }

    public function testPaginateAfterRejectsPageSizeBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->dao->paginateAfter(null, 0);
    }

    public function testNonPublicPrimaryKeyIsRejectedWhenMorePagesExist(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $dao = new PrivateKeyCursorUserDao($this->pdo, new NullLogger());
        $dao->paginateAfter(null, 2);
    }

    public function testPageSizeOneReturnsOneRowAndAdvancesCursor(): void
    {
        $page = $this->dao->paginateAfter(null, 1);

        $this->assertSame(1, $page->count());
        $this->assertSame('Eve', $page->items()[0]->user_name);
        $this->assertTrue($page->hasMore());
        $this->assertSame(5, $page->nextCursor());
    }

    public function testCursorEqualToAnExistingIdIsExcluded(): void
    {
        $page = $this->dao->paginateAfter(3, 5);

        // Strict "<": the row whose id equals the cursor is not repeated.
        $this->assertSame([2, 1], array_map(static fn($user) => $user->id, $page->items()));
        $this->assertFalse($page->hasMore());
    }
}

class CursorUserDao
{
    use SingleTableDao;
    use CursorPagination;

    protected function table(): string { return 'users'; }

    protected function idColumn(): string { return 'id'; }

    protected function domainClass(): string { return CursorUserDomain::class; }

    public function __construct(PDO $pdo, LoggerInterface $logger)
    {
        $this->initDao($pdo, $logger);
    }
}

class CursorUserDomain
{
    use DataAccess;

    public function __construct(
        public readonly int $id,
        public readonly string $user_name,
    ) {}
}

/**
 * Primary key deliberately non-public: FlowPaginator reads the cursor off
 * $item->id and cannot see it, so a page with more rows is rejected.
 */
class PrivateKeyCursorUserDomain
{
    use DataAccess;

    public function __construct(
        private readonly int $id,
        public readonly string $user_name,
    ) {}
}

class PrivateKeyCursorUserDao
{
    use SingleTableDao;
    use CursorPagination;

    protected function table(): string { return 'users'; }

    protected function idColumn(): string { return 'id'; }

    protected function domainClass(): string { return PrivateKeyCursorUserDomain::class; }

    public function __construct(PDO $pdo, LoggerInterface $logger)
    {
        $this->initDao($pdo, $logger);
    }
}
