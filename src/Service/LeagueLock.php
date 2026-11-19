<?php

namespace ErnestDefoe\Fantasy\Service;

use ErnestDefoe\Fantasy\League;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * Run a change to one league while no other change to it runs.
 *
 * 🚨 Two locks, because neither holds on every database. The row lock
 * (`lockForUpdate`) serialises the change on MySQL and PostgreSQL, but SQLite
 * ignores it. The cache lock covers SQLite and also makes a second request give
 * up at once. It never waits: this runs inside a web request, and waiting would
 * hold a PHP worker for as long as the other change takes. The request that
 * loses is told "busy, try again".
 */
class LeagueLock
{
    public function __construct(private ConnectionInterface $db, private Cache $cache)
    {
    }

    /**
     * @template T of array
     *
     * @param callable(League): T $callback given the league re-read under the row lock
     * @return T|array{ok: false, problem: string}
     */
    public function run(League $league, callable $callback): array
    {
        $store = $this->cache->getStore();
        $lock = $store instanceof LockProvider ? $store->lock('fantasy.league.'.$league->id, 10) : null;

        if ($lock !== null && ! $lock->get()) {
            return ['ok' => false, 'problem' => 'busy'];
        }

        try {
            return $this->db->transaction(
                fn () => $callback(League::query()->whereKey($league->id)->lockForUpdate()->firstOrFail())
            );
        } finally {
            $lock?->release();
        }
    }
}
