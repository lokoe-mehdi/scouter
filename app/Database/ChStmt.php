<?php

namespace App\Database;

use PDO;

/**
 * Minimal PDOStatement-compatible result wrapper for {@see ChPdo}.
 *
 * Supports the slice the report pages use: execute([':p'=>v]) with named
 * params, then fetchAll / fetch / fetchColumn / rowCount. Named params are
 * substituted into ClickHouse `{name:Type}` placeholders (server-side bind).
 * Defaults to FETCH_OBJ, like PostgresDatabase.
 *
 * @package    Scouter
 * @subpackage Database
 */
class ChStmt
{
    private ChPdo $pdo;
    private string $sql;
    /** @var array<int,array<string,mixed>> */
    private array $rows = [];
    private int $cursor = 0;

    public function __construct(ChPdo $pdo, string $sql)
    {
        $this->pdo = $pdo;
        $this->sql = $sql;
    }

    public function execute(array $params = []): bool
    {
        $sql = $this->sql;
        $bind = [];

        if ($params && array_is_list($params)) {
            // Positional (?) placeholders → {_p0:T}, {_p1:T}, … left to right.
            $i = 0;
            $sql = preg_replace_callback('/\?/', function () use (&$i, $params, &$bind) {
                $name = '_p' . $i;
                $val = $params[$i] ?? null;
                $bind[$name] = $val;
                $i++;
                return '{' . $name . ':' . $this->chType($val) . '}';
            }, $sql);
        } else {
            // Named (:name) placeholders. Normalise keys (strip leading ':').
            foreach ($params as $k => $v) {
                $bind[ltrim((string) $k, ':')] = $v;
            }
            // Longest names first so :crawl_id is replaced before :crawl.
            $names = array_keys($bind);
            usort($names, fn($a, $b) => strlen($b) <=> strlen($a));
            foreach ($names as $name) {
                $type = $this->chType($bind[$name]);
                $sql = preg_replace('/:' . preg_quote($name, '/') . '\b/', '{' . $name . ':' . $type . '}', $sql);
            }
        }

        // Translate AFTER binding: placeholders are now {name:Type}, so the
        // category regex injected by translate() (with its literal '?') is safe.
        $sql = $this->pdo->translate($sql);

        $rows = $this->pdo->runSelect($sql, $bind);
        // CH returns Array(...) columns as JSON lists; reports written for PG
        // expect the PG array literal '{a,b,c}'. Convert list-arrays (leave
        // Map/object values, which arrive as assoc arrays, untouched).
        foreach ($rows as &$row) {
            foreach ($row as $col => $val) {
                if (is_array($val) && array_is_list($val)) {
                    $row[$col] = '{' . implode(',', array_map(fn($x) => is_array($x) ? json_encode($x) : (string) $x, $val)) . '}';
                }
            }
        }
        unset($row);
        $this->rows = $rows;
        $this->cursor = 0;
        return true;
    }

    private function chType($v): string
    {
        if (is_int($v) || is_bool($v)) return 'Int64';
        if (is_float($v)) return 'Float64';
        return 'String';
    }

    public function fetchAll(int $mode = PDO::FETCH_OBJ, $arg = null): array
    {
        if ($mode === PDO::FETCH_COLUMN) {
            $idx = is_int($arg) ? $arg : 0;
            return array_map(fn($r) => array_values($r)[$idx] ?? null, $this->rows);
        }
        return array_map(fn($r) => $this->shape($r, $mode), $this->rows);
    }

    public function fetch(int $mode = PDO::FETCH_OBJ)
    {
        if (!isset($this->rows[$this->cursor])) {
            return false;
        }
        $row = $this->rows[$this->cursor++];
        if ($mode === PDO::FETCH_COLUMN) {
            return array_values($row)[0] ?? null;
        }
        return $this->shape($row, $mode);
    }

    public function fetchColumn(int $col = 0)
    {
        if (!isset($this->rows[$this->cursor])) {
            return false;
        }
        $row = $this->rows[$this->cursor++];
        $vals = array_values($row);
        return $vals[$col] ?? false;
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    /** @param array<string,mixed> $row */
    private function shape(array $row, int $mode)
    {
        switch ($mode) {
            case PDO::FETCH_ASSOC:
                return $row;
            case PDO::FETCH_NUM:
                return array_values($row);
            case PDO::FETCH_BOTH:
                return array_merge($row, array_values($row));
            case PDO::FETCH_OBJ:
            default:
                return (object) $row;
        }
    }
}
