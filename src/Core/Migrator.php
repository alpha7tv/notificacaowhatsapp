<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Executa as migrations SQL de database/migrations em ordem.
 * Regra do projeto: migrations devem ser ADITIVAS (compatíveis com a versão anterior do código),
 * para que o rollback de código seja seguro sem mexer no banco.
 */
final class Migrator
{
    public function __construct(private string $dir = BASE_PATH . '/database/migrations')
    {
    }

    public function ensureTable(): void
    {
        Db::pdo()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(150) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    /** @return string[] nomes (sem .sql) disponíveis no diretório */
    public function available(?string $dir = null): array
    {
        $files = glob(($dir ?? $this->dir) . '/*.sql') ?: [];
        $names = array_map(static fn ($f) => basename($f, '.sql'), $files);
        sort($names, SORT_STRING);
        return $names;
    }

    public function applied(): array
    {
        $this->ensureTable();
        return array_column(Db::all('SELECT version FROM schema_migrations ORDER BY version'), 'version');
    }

    public function pending(): array
    {
        return array_values(array_diff($this->available(), $this->applied()));
    }

    /** Migrations aplicadas no banco que NÃO existem no diretório informado (usado pelo rollback). */
    public function unknownTo(string $dir): array
    {
        return array_values(array_diff($this->applied(), $this->available($dir)));
    }

    /** @return string[] migrations aplicadas nesta execução */
    public function migrate(?callable $out = null): array
    {
        $this->ensureTable();
        $lockName = 'fiberlink_migrate';
        if ((int) Db::value('SELECT GET_LOCK(?, 60)', [$lockName]) !== 1) {
            throw new \RuntimeException('Outra execução de migrations está em andamento.');
        }
        $done = [];
        try {
            foreach ($this->pending() as $name) {
                $out && $out("Aplicando migration $name ...");
                $sql = (string) file_get_contents($this->dir . "/$name.sql");
                foreach (self::splitStatements($sql) as $stmt) {
                    Db::pdo()->exec($stmt);
                }
                Db::run('INSERT INTO schema_migrations (version) VALUES (?)', [$name]);
                $done[] = $name;
            }
        } finally {
            Db::value('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
        return $done;
    }

    /** Divide o SQL em comandos por ";" no fim da linha, ignorando comentários "--". */
    public static function splitStatements(string $sql): array
    {
        $out = [];
        $buf = '';
        foreach (preg_split('/\R/', $sql) as $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '--')) {
                continue;
            }
            $buf .= $line . "\n";
            if (str_ends_with($trim, ';')) {
                $stmt = trim($buf);
                $out[] = rtrim($stmt, ';');
                $buf = '';
            }
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }
}
