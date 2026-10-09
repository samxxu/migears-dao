<?php

declare(strict_types=1);

namespace MiGears\Dao;

use MiGears\Sql\SqlBuilder;
use MiGears\Utils\FlowPaginator;

/**
 * Cursor (flow) pagination for a SingleTableDao.
 *
 * Compose it on top of SingleTableDao to add paginateAfter(): the next pageSize
 * records after a cursor, ordered by the primary key descending, wrapped in a
 * MiGears\Utils\FlowPaginator. The cursor is the primary key of the previous
 * page's last item; pass null for the first page.
 *
 *   class UserDao
 *   {
 *       use SingleTableDao;
 *       use CursorPagination;
 *
 *       protected function table(): string { return 'users'; }
 *       protected function idColumn(): string { return 'id'; }
 *       protected function domainClass(): string { return UserDomain::class; }
 *   }
 *
 *   $dao  = new UserDao($pdo, $logger);
 *   $page = $dao->paginateAfter(null, 20);                  // first page
 *   $page = $dao->paginateAfter($page->nextCursor(), 20);   // next page
 *
 * One extra row is fetched to derive hasMore, so there is no COUNT(*) and no
 * offset: unlike OffsetPagination this stays cheap on a large table, at the
 * cost of an exact total.
 *
 * The Domain must expose the primary key publicly: FlowPaginator reads the
 * cursor back off the last item to produce nextCursor(), and a non-public
 * property reads as null.
 */
trait CursorPagination
{
    abstract protected function sql(): SqlBuilder;

    abstract protected function table(): string;

    abstract protected function idColumn(): string;

    /** @param array<string, mixed> $row */
    abstract protected function hydrate(array $row): object;

    /**
     * @return FlowPaginator<object>
     */
    public function paginateAfter(int|string|null $cursor, int $pageSize): FlowPaginator
    {
        if ($pageSize < 1) {
            throw new \InvalidArgumentException('Page size must be >= 1, got ' . $pageSize);
        }

        $query = $this->sql()->select()
            ->from($this->table())
            ->orderBy("`{$this->idColumn()}` DESC")
            ->limit($pageSize + 1);

        if ($cursor !== null) {
            $query->where("`{$this->idColumn()}` < :cursor", ['cursor' => $cursor]);
        }

        $rows = $query->execute();

        $hasMore = count($rows) > $pageSize;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $pageSize);
        }

        $items = array_values(array_map(fn(array $row) => $this->hydrate($row), $rows));

        return new FlowPaginator(
            pageSize: $pageSize,
            cursor: $cursor,
            cursorColumn: $this->idColumn(),
            items: $items,
            hasMore: $hasMore,
        );
    }
}
