<?php

declare(strict_types=1);

namespace MiGears\Dao;

use PDO;
use Psr\Log\LoggerInterface;
use MiGears\Sql\SqlBuilder;
use MiGears\Sql\Exception\RecordNotFoundException;
use MiGears\Sql\Exception\SqlException;

/**
 * Single-table CRUD trait for DAO classes.
 *
 * Provides common read/write operations without forcing inheritance. The using
 * class supplies its table metadata by implementing three abstract methods:
 *
 *   class UserDao
 *   {
 *       use SingleTableDao;
 *
 *       protected function table(): string { return 'users'; }
 *       protected function idColumn(): string { return 'id'; }
 *       protected function domainClass(): string { return UserDomain::class; }
 *
 *       public function __construct(PDO $pdo, LoggerInterface $logger)
 *       {
 *           $this->initDao($pdo, $logger);
 *       }
 *   }
 *
 *   $dao  = new UserDao($pdo, $logger);
 *   $user = $dao->getById(1);        // UserDomain|null
 *   $user = $dao->getByIdOrFail(1);  // UserDomain (throws if not found)
 *   $id   = $dao->insert($data);     // last insert id
 *   $user = $dao->save($userDomain); // Domain in, persisted Domain out (insert or update)
 *   $n    = $dao->patch($userDomain, ['user_name']); // affected rows, named columns only
 *   $n    = $dao->delete(1);         // affected rows
 *
 * Every entity read (getById / getByIdOrFail / getByIds / getAll) returns
 * hydrated Domain objects, so a caller never handles a raw row; the row ->
 * Domain step lives in hydrate(). Pagination is an opt-in strategy layered on
 * top of this trait: OffsetPagination::paginate() and CursorPagination::
 * paginateAfter() wrap a page in the matching MiGears\Utils DTO. Aggregates
 * (count) and projections issued through sql() still return scalars / raw
 * rows. CachedDao layers its primary-key cache on the same
 * Domain-returning contract.
 */
trait SingleTableDao
{
    protected ?SqlBuilder $sql = null;

    /** The table this DAO reads and writes. */
    abstract protected function table(): string;

    /** The primary key column of that table. */
    abstract protected function idColumn(): string;

    /** The Domain class rows hydrate into and save() persists. */
    abstract protected function domainClass(): string;

    /**
     * Returns the underlying SqlBuilder, ensuring the DAO is initialized.
     *
     * @throws SqlException if initDao() has not been called
     */
    final public function sql(): SqlBuilder
    {
        if ($this->sql === null) {
            throw new SqlException(static::class . ' has not been initialized; call initDao() from the constructor.');
        }
        return $this->sql;
    }

    /**
     * Initializes the DAO with a PDO connection.
     *
     * Call this from the using class constructor.
     */
    protected function initDao(PDO $pdo, LoggerInterface $logger): void
    {
        $this->sql = new SqlBuilder($pdo, $logger);
    }

    /**
     * Hydrates a raw row into a Domain object via $domainClass::fromArray().
     *
     * The using class supplies the class through domainClass(); there is no
     * hydration magic beyond that single call.
     *
     * @param array<string, mixed> $row
     */
    protected function hydrate(array $row): object
    {
        $class = $this->domainClass();
        return $class::fromArray($row);
    }

    /**
     * Canonical write entry: persists a Domain, returns the persisted Domain.
     *
     * A non-empty primary key on the Domain updates the row; otherwise the
     * Domain is inserted and a fresh Domain with the generated key is
     * returned. Numeric keys are normalised to int, while a non-numeric key
     * (e.g. a UUID) stays a string, so the caller never casts the raw id.
     */
    public function save(object $domain): object
    {
        /** @var array<string, mixed> $row */
        $row = $domain->toArray(); // @phpstan-ignore method.notFound
        $id = $row[$this->idColumn()] ?? null;

        if ($id) {
            $this->update($row);
            return $domain;
        }

        unset($row[$this->idColumn()]);
        $inserted = $this->insert($row);
        // A numeric auto-increment key becomes int; a non-numeric key (UUID) stays a string.
        $row[$this->idColumn()] = is_string($inserted) && ctype_digit($inserted) ? (int) $inserted : $inserted;

        return $this->hydrate($row);
    }

    /**
     * Reads one record by primary key and hydrates it; null when not found.
     */
    public function getById(int|string $id): ?object
    {
        $row = $this->sql()->select()
            ->from($this->table())
            ->filter([$this->idColumn() => $id])
            ->single();

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Reads one record by primary key and hydrates it; throws when not found.
     *
     * @throws RecordNotFoundException
     */
    public function getByIdOrFail(int|string $id): object
    {
        $domain = $this->getById($id);
        if ($domain === null) {
            throw new RecordNotFoundException($this->table(), $id);
        }
        return $domain;
    }

    /**
     * Batch-reads by primary key list, returns Domains keyed by primary key.
     *
     * @param array<int|string> $ids
     * @return array<string|int, object>
     */
    public function getByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $ids = array_values(array_unique($ids));
        $placeholders = [];
        $params = [];

        foreach ($ids as $i => $id) {
            $name = "id_{$i}";
            $placeholders[] = ":{$name}";
            $params[$name] = $id;
        }

        $placeholderStr = implode(', ', $placeholders);
        $rows = $this->sql()->select()
            ->from($this->table())
            ->where("`{$this->idColumn()}` IN ({$placeholderStr})", $params)
            ->execute();

        $result = [];
        foreach ($rows as $row) {
            $result[$row[$this->idColumn()]] = $this->hydrate($row);
        }

        return $result;
    }

    /**
     * Reads all records, sorted by primary key descending, as Domains.
     *
     * @return array<int, object>
     */
    public function getAll(): array
    {
        $rows = $this->sql()->select()
            ->from($this->table())
            ->orderBy("`{$this->idColumn()}` DESC")
            ->execute();

        return array_map(fn(array $row) => $this->hydrate($row), $rows);
    }

    /**
     * Inserts a record, returns the auto-increment ID.
     *
     * @param array<string, mixed> $data
     * @throws SqlException if the insert succeeds but no ID is available
     */
    public function insert(array $data): string
    {
        $id = $this->sql()->insert($this->table())
            ->values($data)
            ->execute()
            ->lastInsertId();
        if ($id === false) {
            throw new SqlException('Insert succeeded but no last insert ID was returned by the driver');
        }
        return $id;
    }

    /**
     * Updates a record by primary key, returns affected row count.
     *
     * @param array<string, mixed> $data Must contain the primary key field
     * @throws SqlException Missing primary key in data
     */
    public function update(array $data): int
    {
        if (!isset($data[$this->idColumn()])) {
            throw new SqlException("Update data must contain the primary key column '{$this->idColumn()}'");
        }

        $id = $data[$this->idColumn()];
        unset($data[$this->idColumn()]);

        if ($data === []) {
            return 0;
        }

        return $this->sql()->update($this->table())
            ->set($data)
            ->filter([$this->idColumn() => $id])
            ->execute();
    }

    /**
     * Updates only the named columns of a Domain's row, located by its primary key.
     *
     * Where save() / update() write the whole row, patch() sends just $fields:
     * a column the caller did not name is never touched, so a concurrent change
     * to another column is not clobbered and a wide row's payload stays small.
     *
     * The Domain is the value source (as in save()): the caller names the changed
     * columns and the DAO reads their new values off the Domain. The primary key
     * comes from the Domain, never from $fields.
     *
     * @param object             $domain Domain carrying the primary key and the new values
     * @param array<int, string> $fields Column names to update
     * @return int affected rows
     * @throws SqlException no column named, primary key missing, or a field is not a Domain column
     */
    public function patch(object $domain, array $fields): int
    {
        if ($fields === []) {
            throw new SqlException('Patch must name at least one column to update');
        }

        /** @var array<string, mixed> $row */
        $row = $domain->toArray(); // @phpstan-ignore method.notFound

        $data = [$this->idColumn() => $row[$this->idColumn()] ?? null];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $row)) {
                throw new SqlException("Patch field '{$field}' is not a column of {$this->domainClass()}");
            }
            $data[$field] = $row[$field];
        }

        return $this->update($data);
    }

    /**
     * Deletes a record by primary key, returns affected row count.
     */
    public function delete(int|string $id): int
    {
        return $this->sql()->delete($this->table())
            ->filter([$this->idColumn() => $id])
            ->execute();
    }

    /**
     * Counts total records.
     */
    public function count(): int
    {
        return $this->sql()->select()
            ->from($this->table())
            ->count();
    }
}
