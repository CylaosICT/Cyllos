<?php

namespace App\Backup;

use Doctrine\DBAL\Connection;

/**
 * Pure-PHP dump/restore of the whole MySQL/MariaDB schema (tables, rows and
 * views) through DBAL, so backups work without the mysqldump/mysql binaries.
 *
 * File format: a gzipped list of SQL statements, each followed by a line that
 * only contains STATEMENT_SEPARATOR — which makes restoring a simple
 * line-oriented read, with no SQL parsing.
 */
class DatabaseDumper
{
    private const STATEMENT_SEPARATOR = '-- @@';
    private const ROWS_PER_INSERT = 200;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function dump(string $gzipPath): void
    {
        $tables = [];
        $views = [];
        foreach ($this->connection->fetchAllNumeric('SHOW FULL TABLES') as [$name, $type]) {
            if (strtoupper((string) $type) === 'VIEW') {
                $views[] = (string) $name;
            } else {
                $tables[] = (string) $name;
            }
        }

        $handle = gzopen($gzipPath, 'wb6');
        if ($handle === false) {
            throw new \RuntimeException('Impossible de créer le fichier de sauvegarde.');
        }

        try {
            $this->write($handle, '-- Cyllos database dump ' . date('c'));
            $this->write($handle, 'SET FOREIGN_KEY_CHECKS=0');
            $this->write($handle, "SET NAMES 'utf8mb4'");

            foreach ($views as $view) {
                $this->write($handle, 'DROP VIEW IF EXISTS ' . $this->quoteIdentifier($view));
            }

            foreach ($tables as $table) {
                $quoted = $this->quoteIdentifier($table);
                $create = $this->connection->fetchNumeric('SHOW CREATE TABLE ' . $quoted);
                $this->write($handle, 'DROP TABLE IF EXISTS ' . $quoted);
                $this->write($handle, (string) $create[1]);
                $this->dumpRows($handle, $table);
            }

            foreach ($views as $view) {
                $create = $this->connection->fetchAssociative('SHOW CREATE VIEW ' . $this->quoteIdentifier($view));
                $sql = (string) ($create['Create View'] ?? '');
                // A definer from another server/user would make the restore fail.
                $sql = (string) preg_replace('/DEFINER=`[^`]*`@`[^`]*`\s*/', '', $sql);
                $this->write($handle, $sql);
            }

            $this->write($handle, 'SET FOREIGN_KEY_CHECKS=1');
        } finally {
            gzclose($handle);
        }
    }

    /**
     * Replaces the current content of the database with the dump.
     */
    public function restore(string $gzipPath): void
    {
        $handle = gzopen($gzipPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Fichier de sauvegarde illisible.');
        }

        try {
            $statement = '';
            while (($line = gzgets($handle)) !== false) {
                if (rtrim($line, "\r\n") === self::STATEMENT_SEPARATOR) {
                    $this->execute($statement);
                    $statement = '';

                    continue;
                }
                $statement .= $line;
            }
            $this->execute($statement);
        } finally {
            gzclose($handle);
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * @param resource $handle
     */
    private function dumpRows($handle, string $table): void
    {
        $quotedTable = $this->quoteIdentifier($table);
        $batch = [];
        $columns = null;

        foreach ($this->connection->iterateAssociative('SELECT * FROM ' . $quotedTable) as $row) {
            $columns ??= implode(', ', array_map($this->quoteIdentifier(...), array_keys($row)));
            $batch[] = '(' . implode(', ', array_map($this->quoteValue(...), $row)) . ')';

            if (\count($batch) >= self::ROWS_PER_INSERT) {
                $this->write($handle, \sprintf('INSERT INTO %s (%s) VALUES %s', $quotedTable, $columns, implode(",\n", $batch)));
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->write($handle, \sprintf('INSERT INTO %s (%s) VALUES %s', $quotedTable, $columns, implode(",\n", $batch)));
        }
    }

    private function quoteValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        $value = (string) $value;
        // Binary data would be mangled by a charset-aware string literal.
        if (!mb_check_encoding($value, 'UTF-8')) {
            return '0x' . bin2hex($value);
        }

        return $this->connection->quote($value);
    }

    private function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * @param resource $handle
     */
    private function write($handle, string $statement): void
    {
        gzwrite($handle, $statement . "\n" . self::STATEMENT_SEPARATOR . "\n");
    }

    private function execute(string $statement): void
    {
        $statement = trim($statement);
        if ($statement === '' || str_starts_with($statement, '--')) {
            return;
        }
        $this->connection->executeStatement($statement);
    }
}
