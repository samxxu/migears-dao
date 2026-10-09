<?php

declare(strict_types=1);

namespace MiGears\Dao;

use Psr\Log\LoggerInterface;
use MiGears\Cache\CacheInterface;

/**
 * Cached single-table DAO trait.
 *
 * Adds a primary-key cache on top of SingleTableDao:
 *   - getById / getByIds check the cache first, fall back to the inherited
 *     Domain read (which already hydrates the row), then cache the Domain
 *   - insert / update / delete invalidate the primary-key cache automatically
 *   - subclasses may override deleteCacheFor() to clear extra cache keys
 *     (e.g. byEmail / bySku) that reference the same Domain
 *   - a cache hit is returned only when it really is a domainClass() instance;
 *     anything else is a stale or foreign value: it is warned about, deleted,
 *     and treated as a miss
 *
 * Every entity read returns a Domain, because SingleTableDao already hydrates
 * them; CachedDao only wraps the primary-key reads with caching and inherits
 * getAll / count / getByIdOrFail unchanged. Pagination is not part of the cache
 * layer — add OffsetPagination or CursorPagination separately if needed.
 *
 * CachedDao touches neither migears/sql nor PDO: it depends only on
 * CacheInterface and the SingleTableDao contract, so all SQL (and its
 * exceptions, and the connection) stay in SingleTableDao.
 *
 * The using class supplies table(), idColumn() and domainClass() (declared
 * abstract by SingleTableDao) and calls initDao() + initCachedDao() from its
 * constructor:
 *
 *   class UserDao
 *   {
 *       use CachedDao;
 *
 *       protected function table(): string { return 'users'; }
 *       protected function idColumn(): string { return 'id'; }
 *       protected function domainClass(): string { return UserDomain::class; }
 *
 *       public function __construct(PDO $pdo, LoggerInterface $logger, CacheInterface $cache)
 *       {
 *           $this->initDao($pdo, $logger);
 *           $this->initCachedDao($cache, $logger);
 *       }
 *   }
 */
trait CachedDao
{
    use SingleTableDao {
        getById as protected dbGetById;
        getByIds as protected dbGetByIds;
        insert as protected dbInsert;
        update as protected dbUpdate;
        delete as protected dbDelete;
    }

    protected CacheInterface $cache;

    protected ?LoggerInterface $logger = null;

    /**
     * Initialises the cache layer: the cache and the logger its failures are
     * reported to. The database connection belongs to SingleTableDao, so the
     * using class calls initDao() separately.
     */
    protected function initCachedDao(CacheInterface $cache, LoggerInterface $logger): void
    {
        $this->cache = $cache;
        $this->logger = $logger;
    }

    /**
     * Returns the cache layer, ensuring initCachedDao() has been called.
     *
     * @throws \LogicException if initCachedDao() has not been called
     */
    private function cache(): CacheInterface
    {
        if (!isset($this->cache)) {
            throw new \LogicException(static::class . ' has not been initialized; call initCachedDao() from the constructor.');
        }
        return $this->cache;
    }

    protected function cacheKey(string $name, string|int $id): string
    {
        return $name . '_' . $this->normalizeId($id);
    }

    /**
     * A numeric primary key reaches the DAO either as an int or as a string,
     * and the string spelling is not canonical: '1', '01', '+1', ' 1' and
     * '1.0' all address the same row. The database always returns the
     * canonical integer, so every equivalent spelling must share one cache
     * key; otherwise the row is cached twice and invalidating one key leaves
     * the other stale. A string with a real fractional part ('1.5') or a
     * non-numeric key (a UUID) is left untouched.
     */
    private function normalizeId(string|int $id): string|int
    {
        if (!is_string($id)) {
            return $id;
        }

        $trimmed = trim($id);
        if (preg_match('/^[+-]?(\d+)(?:\.0+)?$/', $trimmed, $matches) === 1) {
            return (int) $matches[1];
        }
        return $id;
    }

    /**
     * Reads the Domain's own primary key so that a cache hit and a database
     * row are indexed the same way. They disagree for non-canonical numeric
     * strings such as '01': PHP keeps '01' as a string array key, while the
     * database returns the primary key as an int — the same row then lands in
     * the result twice.
     *
     * Reflection rather than get_object_vars(): the latter is called from this
     * trait's scope, so it only sees the Domain's public properties, and a
     * Domain that keeps its primary key non-public would yield null and put
     * the duplicate back. Reflection also leaves the public contract alone — a
     * Domain needs no accessor for the DAO to find its key — and mirrors the
     * ReflectionMethod use in deleteCacheForIsOverridden().
     */
    private function domainId(object $domain): int|string|null
    {
        if (!property_exists($domain, $this->idColumn())) {
            return null;
        }

        static $properties = [];
        $property = $properties[$domain::class . ':' . $this->idColumn()]
            ??= new \ReflectionProperty($domain, $this->idColumn());
        $value = $property->getValue($domain);
        return is_int($value) || is_string($value) ? $value : null;
    }

    /**
     * Discards a cache entry that is not a Domain of this DAO's class.
     *
     * A hit is only trusted when it really is a domainClass() instance; any
     * other non-null value is stale or foreign (an old class shape after a
     * deploy, a colliding key). Warn the developer and delete the key so no
     * dirty value lingers — the caller then treats the read as a miss.
     */
    private function purgeDirty(string $key, mixed $value): void
    {
        $this->logger?->warning(
            'CachedDao: discarding a cache value that is not a Domain',
            ['key' => $key, 'type' => get_debug_type($value)],
        );
        try {
            $this->cache()->delete($key);
        } catch (\Throwable $e) {
            $this->logger?->warning('CachedDao: cache delete failed', ['exception' => $e]);
        }
    }

    /**
     * Reads one Domain by primary key, served from the cache when present.
     *
     * A hit is trusted only when it is a domainClass() instance; a stale or
     * foreign value is discarded (purgeDirty) and treated as a miss. A miss
     * delegates to the inherited read, which fetches and hydrates the row, and
     * the returned Domain is stored under the primary-key cache key.
     */
    public function getById(int|string $id): ?object
    {
        $cache = $this->cache();
        $key = $this->cacheKey($this->table(), $id);
        try {
            $cached = $cache->get($key);
        } catch (\Throwable $e) {
            $this->logger?->warning('CachedDao: cache read failed; falling back to database', ['exception' => $e]);
            $cached = null;
        }

        $domainClass = $this->domainClass();
        if ($cached instanceof $domainClass) {
            return $cached;
        }
        if ($cached !== null) {
            $this->purgeDirty($key, $cached);
        }

        $domain = $this->dbGetById($id);
        if ($domain === null) {
            return null;
        }

        try {
            $cache->set($key, $domain);
        } catch (\Throwable $e) {
            $this->logger?->warning('CachedDao: cache write failed', ['exception' => $e]);
        }
        return $domain;
    }

    /**
     * Batch-reads Domains by primary key, filling the cache entry by entry.
     *
     * Cache hits are keyed by the Domain's own primary key; a hit is trusted
     * only when it is a domainClass() instance (a stale or foreign value is
     * purged and treated as a miss); misses are read from the database in one
     * query and cached.
     */
    public function getByIds(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $cache = $this->cache();
        $domainClass = $this->domainClass();
        $result = [];
        $missing = [];
        foreach ($ids as $id) {
            $key = $this->cacheKey($this->table(), $id);
            try {
                $cached = $cache->get($key);
            } catch (\Throwable $e) {
                $this->logger?->warning('CachedDao: cache read failed; falling back to database', ['exception' => $e]);
                $cached = null;
            }
            if ($cached instanceof $domainClass) {
                $result[$this->domainId($cached) ?? $id] = $cached;
                continue;
            }
            if ($cached !== null) {
                $this->purgeDirty($key, $cached);
            }
            $missing[] = $id;
        }

        if ($missing !== []) {
            foreach ($this->dbGetByIds($missing) as $id => $domain) {
                try {
                    $cache->set($this->cacheKey($this->table(), $id), $domain);
                } catch (\Throwable $e) {
                    $this->logger?->warning('CachedDao: cache write failed', ['exception' => $e]);
                }
                $result[$id] = $domain;
            }
        }

        return $result;
    }

    public function insert(array $data): string
    {
        $explicitId = $data[$this->idColumn()] ?? null;
        $id = $this->dbInsert($data);
        try {
            $this->cacheRemove($explicitId ?? $id);
        } catch (\Throwable $e) {
            $this->logger?->warning('CachedDao: cache invalidation failed after insert', ['exception' => $e]);
        }
        return $id;
    }

    /**
     * Updates a record and invalidates its cache entry.
     *
     * The primary-key validation (and its exception) belongs to the inherited
     * update(); this override only adds the cache invalidation, so the cache
     * layer raises nothing of its own here.
     */
    public function update(array $data): int
    {
        $affected = $this->dbUpdate($data);
        try {
            $this->cacheRemove($data[$this->idColumn()]);
        } catch (\Throwable $e) {
            $this->logger?->warning('CachedDao: cache invalidation failed after update', ['exception' => $e]);
        }
        return $affected;
    }

    public function delete(int|string $id): int
    {
        $affected = $this->dbDelete($id);
        try {
            $this->cacheRemove($id);
        } catch (\Throwable $e) {
            $this->logger?->warning('CachedDao: cache invalidation failed after delete', ['exception' => $e]);
        }
        return $affected;
    }

    /**
     * Clears the primary-key cache entry. Extra related keys are cleared by
     * subclasses overriding deleteCacheFor(), which receives the fresh Domain
     * (uncached read) so it reflects the state after the write. When the hook
     * is not overridden the read-back is skipped: there is no one to receive
     * the Domain, so the extra SELECT would be pure overhead on every write.
     */
    public function cacheRemove(int|string $id): void
    {
        $this->cache()->delete($this->cacheKey($this->table(), $id));
        if (!$this->deleteCacheForIsOverridden()) {
            return;
        }
        $this->deleteCacheFor($this->dbGetById($id));
    }

    /**
     * Detects, once per class, whether deleteCacheFor() is overridden, so that
     * cacheRemove() pays the read-back SELECT only when a hook will receive the
     * Domain. A trait method keeps the trait's file as its source, so a
     * differing file means the using class (or a parent) supplied its own
     * implementation.
     */
    private function deleteCacheForIsOverridden(): bool
    {
        static $overridden = [];
        return $overridden[static::class] ??= (new \ReflectionMethod($this, 'deleteCacheFor'))->getFileName() !== __FILE__;
    }

    /**
     * Subclass hook: clear extra cache keys that reference the same Domain
     * (e.g. a byEmail / bySku lookup). cacheRemove() calls it with the fresh
     * Domain — an uncached read taken after the write — or null when the record
     * no longer exists. The default implementation is a no-op.
     */
    protected function deleteCacheFor(?object $domain): void
    {
    }
}
