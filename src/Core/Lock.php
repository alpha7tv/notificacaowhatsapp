<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Lock distribuído com expiração (TTL), usado para impedir execuções concorrentes
 * de rotinas críticas. Com REDIS_HOST configurado usa SOMENTE Redis (se o Redis cair,
 * a rotina é pulada, nunca executada em duplicidade). Sem Redis, usa a tabela "locks".
 */
final class Lock
{
    /** @var array<string,string> */
    private static array $held = [];

    public static function acquire(string $name, int $ttlSeconds): bool
    {
        $token = bin2hex(random_bytes(16));
        if (RedisConn::configured()) {
            $r = RedisConn::get();
            if (!$r) {
                return false;
            }
            try {
                $ok = $r->set('lock:' . $name, $token, ['nx', 'ex' => max(1, $ttlSeconds)]);
            } catch (\Throwable) {
                RedisConn::reset();
                return false;
            }
            if ($ok) {
                self::$held[$name] = $token;
            }
            return (bool) $ok;
        }

        Db::run('DELETE FROM locks WHERE name = ? AND expires_at < NOW()', [$name]);
        try {
            Db::run('INSERT INTO locks (name, owner, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))', [$name, $token, max(1, $ttlSeconds)]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return false;
            }
            throw $e;
        }
        self::$held[$name] = $token;
        return true;
    }

    public static function release(string $name): void
    {
        $token = self::$held[$name] ?? null;
        if ($token === null) {
            return;
        }
        unset(self::$held[$name]);
        if (RedisConn::configured()) {
            $r = RedisConn::get();
            if ($r) {
                try {
                    $lua = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";
                    // O phpredis aplica OPT_PREFIX às chaves (KEYS) do eval automaticamente:
                    // passar a chave SEM prefixo (verificado em produção com phpredis 5.3).
                    $r->eval($lua, ['lock:' . $name, $token], 1);
                } catch (\Throwable) {
                    RedisConn::reset();
                }
            }
            return;
        }
        Db::run('DELETE FROM locks WHERE name = ? AND owner = ?', [$name, $token]);
    }

    /** Executa $fn com lock; retorna null se o lock já estiver em uso. */
    public static function run(string $name, int $ttl, callable $fn): mixed
    {
        if (!self::acquire($name, $ttl)) {
            return null;
        }
        try {
            return $fn();
        } finally {
            self::release($name);
        }
    }
}
