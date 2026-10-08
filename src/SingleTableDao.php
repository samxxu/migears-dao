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
 * Provides common read/write operations without forcing inheritance.
 * User DAO classes use this trait and define $table + $idColumn.
 *
 *   class UserDao
 *   {
 *       use SingleTableDao;
 *
 *       protected string $table = 'users';
 *       protected string $idColumn = 'id';
 *
 *       public function __construct(PDO $pdo, LoggerInterface $logger)
 *       {
 *           $this->initDao($pdo, $logger);
 *       }
 *   }
 *
 *   $dao = new UserDao($pdo, $logger);
 *   $row = $dao->getById(1);     // array|null
 *   $id  = $dao->insert($data);  // last insert id
 *   $n   = $dao->update($data);  // affected rows
 *   $n   = $dao->delete(1);      // affected rows
 *
 * Raw reads (getById / getByIds / getAll / paginate) return rows for
 * table-level use. The Domain-oriented entry points are findById() (read a
 * row by a scalar key and hydrate it) and save() (persist a Domain and return
 * the persisted Domain); both need $domainClass. CachedDao layers its
 * primary-key cache on top of the same contract.
 */
trait SingleTableDao
{
    protected ?SqlBuilder $sql = null;

    /**
     * Returns the underlying SqlBuilder, ensuring the DAO is initialized.
     *
     * @throws SqlException if initDao() has not been called
     */
    final protected function sql(): SqlBuilder
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
     * The using class must define $table and $idColumn properties.
     */
    protected function initDao(PDO $pdo, LoggerInterface $logger): void
    {
        if (!isset($this->table) || $this->table === '') {
            throw new SqlException(static::class . ' must define $table property');
        }
        if (!isset($this->idColumn) || $this->idColumn === '') {
            throw new SqlException(static::class . ' must define $idColumn property');
        }
        $this->sql = new SqlBuilder($pdo, $logger);
    }

    /**
     * Hydrates a raw row into a Domain object via $domainClass::fromArray().
     *
     * The using class may declare `protected string $domainClass`. DAOs that
     * only need raw rows (table-gateway style) leave it undefined and never
     * call this method. It is deliberately loud: calling it without a
     * $domainClass throws rather than silently returning the raw row, so a
     * missing declaration surfaces instead of being hidden.
     *
     * @param array<string, mixed> $row
     * @throws SqlException if $domainClass is not defined
     */
    protected function hydrate(array $row): object
    {
        if (!isset($this->domainClass) || $this->domainClass === '') {
            throw new SqlException(static::class . ' must define $domainClass property to hydrate rows');
        }
        $class = $this->domainClass;
        return $class::fromArray($row);
    }

    /**
     * Canonical read entry: fetches one row by a scalar key and hydrates it.
     *
     * Returns null when no row exists. CachedDao overrides this to serve the
     * same Domain-returning contract from its primary-key cache.
     */
    public function findById(int|string $id): ?object
    {
        $row = $this->getById($id);
        return $row === null ? null : $this->hydrate($row);
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
        $row = $this->domainToArray($domain);
        $id = $row[$this->idColumn] ?? null;

        if ($id) {
            $this->update($row);
            return $domain;
        }

        unset($row[$this->idColumn]);
        $row[$this->idColumn] = $this->normalizeKey($this->insert($row));

        return $this->hydrate($row);
    }

    /**
     * Reads the Domain's persistence row.
     *
     * Domains expose toArray() through MiGears\Domain\DataAccess; this package
     * accepts any object (there is no shared Domain interface to type against),
     * so the single call needs one narrow static-analysis escape.
     *
     * @return array<string, mixed>
     */
    private function domainToArray(object $domain): array
    {
        /** @var array<string, mixed> $row */
        $row = $domain->toArray(); // @phpstan-ignore method.notFound

        return $row;
    }

    /**
     * Normalises a generated primary key: a numeric string becomes int, so an
     * auto-increment key comes back as int without a hand-written cast, while
     * a non-numeric key (UUID) is left as a string.
     */
    private function normalizeKey(string|int $id): string|int
    {
        return is_string($id) && ctype_digit($id) ? (int) $id : $id;
    }

    /**
     * Queries a single record by primary key, returns null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function getById(int|string $id): ?array
    {
        return $this->sql()->select()
            ->from($this->table)
            ->filter([$this->idColumn => $id])
            ->single();
    }

    /**
     * Queries a single record by primary key, throws if not found.
     *
     * @return array<string, mixed>
     * @throws RecordNotFoundException
     */
    public function getByIdOrFail(int|string $id): array
    {
        $row = $this->getById($id);
        if ($row === null) {
            throw new RecordNotFoundException($this->table, $id);
        }
        return $row;
    }

    /**
     * Batch queries by primary key list, returns array keyed by primary key.
     *
     * @param array<int|string> $ids
     * @return array<string|int, array<string, mixed>>
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
            ->from($this->table)
            ->where("`{$this->idColumn}` IN ({$placeholderStr})", $params)
            ->execute();

        $result = [];
        foreach ($rows as $row) {
            $result[$row[$this->idColumn]] = $row;
        }

        return $result;
    }

    /**
     * Queries all records, sorted by primary key descending.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAll(): array
    {
        return $this->sql()->select()
            ->from($this->table)
            ->orderBy("`{$this->idColumn}` DESC")
            ->execute();
    }

    /**
     * Inserts a record, returns the auto-increment ID.
     *
     * @param array<string, mixed> $data
     * @throws SqlException if the insert succeeds but no ID is available
     */
    public function insert(array $data): string
    {
        $id = $this->sql()->insert($this->table)
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
        if (!isset($data[$this->idColumn])) {
            throw new SqlException("Update data must contain the primary key column '{$this->idColumn}'");
        }

        $id = $data[$this->idColumn];
        unset($data[$this->idColumn]);

        if ($data === []) {
            return 0;
        }

        return $this->sql()->update($this->table)
            ->set($data)
            ->filter([$this->idColumn => $id])
            ->execute();
    }

    /**
     * Deletes a record by primary key, returns affected row count.
     */
    public function delete(int|string $id): int
    {
        return $this->sql()->delete($this->table)
            ->filter([$this->idColumn => $id])
            ->execute();
    }

    /**
     * Counts total records.
     */
    public function count(): int
    {
        return $this->sql()->select()
            ->from($this->table)
            ->count();
    }

    /**
     * Paginated query.
     *
     * @return array{records: array<int, array<string, mixed>>, total: int}
     */
    public function paginate(int $page, int $pageSize): array
    {
        return $this->sql()->select()
            ->from($this->table)
            ->orderBy("`{$this->idColumn}` DESC")
            ->paginate($page, $pageSize);
    }

    /**
     * Gets the underlying SqlBuilder for complex queries.
     *
     * @throws SqlException if initDao() has not been called
     */
    public function getSqlBuilder(): SqlBuilder
    {
        return $this->sql();
    }

    /**
     * Gets the table name.
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Gets the primary key column name.
     */
    public function getIdColumn(): string
    {
        return $this->idColumn;
    }
}
