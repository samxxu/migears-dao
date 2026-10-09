<?php

declare(strict_types=1);

namespace MiGears\Dao;

use MiGears\Sql\SqlBuilder;
use MiGears\Utils\Paginator;

/**
 * Offset pagination for a SingleTableDao.
 *
 * Compose it on top of SingleTableDao to add paginate(): one page of the table,
 * ordered by the primary key descending, wrapped in a MiGears\Utils\Paginator
 * (page / pageSize / total / items).
 *
 *   class UserDao
 *   {
 *       use SingleTableDao;
 *       use OffsetPagination;
 *
 *       protected function table(): string { return 'users'; }
 *       protected function idColumn(): string { return 'id'; }
 *       protected function domainClass(): string { return UserDomain::class; }
 *   }
 *
 *   $page = (new UserDao($pdo, $logger))->paginate(2, 20);
 *   $page->items();   // UserDomain[]
 *   $page->total();   // int
 *
 * Every call runs a COUNT(*) for `total`, which is what the exact page count
 * needs; reach for CursorPagination when the table is large and an exact total
 * is not required.
 */
trait OffsetPagination
{
    abstract protected function sql(): SqlBuilder;

    abstract protected function table(): string;

    abstract protected function idColumn(): string;

    /** @param array<string, mixed> $row */
    abstract protected function hydrate(array $row): object;

    /**
     * @return Paginator<object>
     */
    public function paginate(int $page, int $pageSize): Paginator
    {
        $result = $this->sql()->select()
            ->from($this->table())
            ->orderBy("`{$this->idColumn()}` DESC")
            ->paginate($page, $pageSize);

        $items = array_values(array_map(fn(array $row) => $this->hydrate($row), $result['records']));

        return new Paginator(
            pageSize: $pageSize,
            currentPage: $page,
            total: $result['total'],
            items: $items,
        );
    }
}
