<?php

declare(strict_types=1);

namespace MiGears\Dao\Tests;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use MiGears\Dao\SingleTableDao;
use MiGears\Dao\OffsetPagination;
use MiGears\Domain\DataAccess;
use MiGears\Utils\Paginator;

class OffsetPaginationTest extends TestCase
{
    private PagedUserDao $dao;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(<<<'SQL'
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_name VARCHAR(100) NOT NULL
            )
        SQL);

        $stmt = $pdo->prepare('INSERT INTO users (user_name) VALUES (:user_name)');
        foreach (['Alice', 'Bob', 'Charlie', 'Diana', 'Eve'] as $name) {
            $stmt->execute(['user_name' => $name]);
        }

        $this->dao = new PagedUserDao($pdo, new NullLogger());
    }

    public function testPaginateReturnsPaginatorOfDomains(): void
    {
        $page = $this->dao->paginate(1, 2);

        $this->assertInstanceOf(Paginator::class, $page);
        $this->assertSame(5, $page->total());
        $this->assertSame(1, $page->currentPage());
        $this->assertSame(2, $page->pageSize());
        $this->assertSame(2, $page->count());
        $this->assertContainsOnlyInstancesOf(PagedUserDomain::class, $page->items());
    }

    public function testPaginateOrdersByPrimaryKeyDescending(): void
    {
        $page = $this->dao->paginate(1, 2);

        $this->assertSame('Eve', $page->items()[0]->user_name);
        $this->assertSame('Diana', $page->items()[1]->user_name);
    }

    public function testPaginateSecondPage(): void
    {
        $page = $this->dao->paginate(2, 2);

        $this->assertSame('Charlie', $page->items()[0]->user_name);
        $this->assertSame('Bob', $page->items()[1]->user_name);
        $this->assertSame(3, $page->totalPages());
        $this->assertTrue($page->hasNext());
    }

    public function testPaginateLastPage(): void
    {
        $page = $this->dao->paginate(3, 2);

        $this->assertSame(5, $page->total());
        $this->assertSame(1, $page->count());
        $this->assertFalse($page->hasNext());
    }

    public function testPaginateBeyondLastPageReturnsNoItems(): void
    {
        $page = $this->dao->paginate(5, 2);

        $this->assertSame(5, $page->total());
        $this->assertSame(0, $page->count());
    }

    public function testPaginateRejectsPageBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->dao->paginate(0, 2);
    }

    public function testPaginateRejectsNonPositivePageSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->dao->paginate(1, 0);
    }

    public function testPaginateEmptyTableReturnsEmptyPage(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, user_name VARCHAR(100) NOT NULL)');

        $page = (new PagedUserDao($pdo, new NullLogger()))->paginate(1, 2);

        $this->assertSame(0, $page->total());
        $this->assertSame(0, $page->count());
        $this->assertSame([], $page->items());
    }
}

class PagedUserDao
{
    use SingleTableDao;
    use OffsetPagination;

    protected function table(): string { return 'users'; }

    protected function idColumn(): string { return 'id'; }

    protected function domainClass(): string { return PagedUserDomain::class; }

    public function __construct(PDO $pdo, LoggerInterface $logger)
    {
        $this->initDao($pdo, $logger);
    }
}

class PagedUserDomain
{
    use DataAccess;

    public function __construct(
        public readonly int $id,
        public readonly string $user_name,
    ) {}
}
