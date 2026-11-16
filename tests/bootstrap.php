<?php

/**
 * ------------------------------------------------------------------------
 * Smart Assign - Bootstrap da suíte de testes standalone (GLPI 10/11/12)
 *
 * Copyright (C) 2026 by G. Martins
 * ------------------------------------------------------------------------
 */

namespace {
    if (!defined('GLPI_ROOT')) {
        define('GLPI_ROOT', dirname(__DIR__));
    }

    if (!defined('GLPI_VERSION')) {
        $envVersion = getenv('SMARTASSIGN_TEST_GLPI_VERSION');
        define('GLPI_VERSION', ($envVersion !== false && $envVersion !== '') ? $envVersion : '10.0.18');
    }
}

namespace Glpi\Toolbox {
    class Sanitizer
    {
        /**
         * @param mixed $value
         * @return mixed
         */
        public static function sanitize($value)
        {
            if (is_array($value)) {
                $out = [];
                foreach ($value as $k => $v) {
                    $out[$k] = self::sanitize($v);
                }
                return $out;
            }
            if (is_string($value)) {
                return addslashes(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
            }
            return $value;
        }

        /**
         * @param mixed $value
         * @return mixed
         */
        public static function unsanitize($value)
        {
            if (is_array($value)) {
                $out = [];
                foreach ($value as $k => $v) {
                    $out[$k] = self::unsanitize($v);
                }
                return $out;
            }
            if (is_string($value)) {
                return htmlspecialchars_decode(stripslashes($value), ENT_QUOTES);
            }
            return $value;
        }

        /**
         * @param mixed $value
         * @return mixed
         */
        public static function decodeHtmlSpecialChars($value)
        {
            if (is_array($value)) {
                $out = [];
                foreach ($value as $k => $v) {
                    $out[$k] = self::decodeHtmlSpecialChars($v);
                }
                return $out;
            }
            if (is_string($value)) {
                return htmlspecialchars_decode($value, ENT_QUOTES);
            }
            return $value;
        }
    }
}

namespace Glpi\Exception\Http {
    if (version_compare((string) GLPI_VERSION, '12.0.0-dev', '>=')) {
        class AccessDeniedHttpException extends \RuntimeException
        {
        }
    }
}

namespace {

if (!defined('INFO')) {
    define('INFO', 0);
}
if (!defined('WARNING')) {
    define('WARNING', 1);
}
if (!defined('ERROR')) {
    define('ERROR', 2);
}
if (!defined('READ')) {
    define('READ', 1);
}
if (!defined('UPDATE')) {
    define('UPDATE', 2);
}

global $CFG_GLPI, $PLUGIN_HOOKS, $DB;

$CFG_GLPI = [
    'root_doc' => '/glpi',
    'url_base' => 'https://glpi.example.test/glpi',
];
$PLUGIN_HOOKS = [];

class SmartassignMockDB
{
    /** @var array<string, array<int, array<string, mixed>>> */
    public $tables = [];

    /** @var array<string, int> */
    public $autoInc = [];

    /** @var string|null */
    public $simulateDuplicateThrowOn = null;

    /** @var string|null */
    public $simulateDuplicateFalseOn = null;

    /** @var int */
    public $lastErrno = 0;

    public function reset(): void
    {
        $this->tables                     = [];
        $this->autoInc                    = [];
        $this->simulateDuplicateThrowOn = null;
        $this->simulateDuplicateFalseOn = null;
        $this->lastErrno                  = 0;
        $this->seedCoreTables();
    }

    public function seedCoreTables(): void
    {
        $core = [
            'glpi_entities',
            'glpi_users',
            'glpi_groups',
            'glpi_groups_users',
            'glpi_tickets',
            'glpi_tickets_users',
            'glpi_groups_tickets',
        ];
        foreach ($core as $table) {
            if (!isset($this->tables[$table])) {
                $this->tables[$table]  = [];
                $this->autoInc[$table] = 1;
            }
        }
    }

    public function tableExists(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    public function errno(): int
    {
        return $this->lastErrno;
    }

    public function escape(string $str): string
    {
        return str_replace(["\\", "'", '"'], ["\\\\", "\\'", '\\"'], $str);
    }

    private function unescapeOnLegacy(array $values): array
    {
        if (version_compare((string) GLPI_VERSION, '11.0.0-dev', '<')) {
            foreach ($values as $k => $v) {
                if (is_string($v)) {
                    $values[$k] = stripslashes($v);
                }
            }
        }
        return $values;
    }

    public function doQuery(string $sql): bool
    {
        if (preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
            $table = $m[1];
            if (!isset($this->tables[$table])) {
                $this->tables[$table]  = [];
                $this->autoInc[$table] = 1;
            }
            return true;
        }
        if (preg_match('/^\s*DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
            $table = $m[1];
            unset($this->tables[$table], $this->autoInc[$table]);
            return true;
        }
        throw new RuntimeException('Query DDL inesperada nos testes: ' . $sql);
    }

    /**
     * @return int|false
     */
    public function insert(string $table, array $values)
    {
        $this->lastErrno = 0;

        if (!isset($this->tables[$table])) {
            return false;
        }

        if ($this->simulateDuplicateThrowOn === $table) {
            $this->simulateDuplicateThrowOn = null;
            $cid                            = $this->autoInc[$table] ?? 1;
            $this->autoInc[$table]          = $cid + 1;
            $this->tables[$table][$cid]     = [
                'id'            => $cid,
                'groups_id'     => (int) ($values['groups_id'] ?? 0),
                'last_users_id' => 1,
            ];
            throw new RuntimeException("Duplicate entry for key 'groups_id'", 1062);
        }

        if ($this->simulateDuplicateFalseOn === $table) {
            $this->simulateDuplicateFalseOn = null;
            $cid                            = $this->autoInc[$table] ?? 1;
            $this->autoInc[$table]          = $cid + 1;
            $this->tables[$table][$cid]     = [
                'id'            => $cid,
                'groups_id'     => (int) ($values['groups_id'] ?? 0),
                'last_users_id' => 1,
            ];
            $this->lastErrno                = 1062;
            return false;
        }

        $values = $this->unescapeOnLegacy($values);
        if (!array_key_exists('id', $values) || $values['id'] === null || $values['id'] === '') {
            $id                    = $this->autoInc[$table] ?? 1;
            $values['id']          = $id;
            $this->autoInc[$table] = $id + 1;
        } else {
            $id           = (int) $values['id'];
            $values['id'] = $id;
            if (($this->autoInc[$table] ?? 1) <= $id) {
                $this->autoInc[$table] = $id + 1;
            }
        }

        $this->tables[$table][$id] = $values;
        return $id;
    }

    public function update(string $table, array $values, array $where): bool
    {
        if (!isset($this->tables[$table])) {
            return false;
        }

        $values = $this->unescapeOnLegacy($values);
        foreach ($this->tables[$table] as $id => $row) {
            if ($this->matchWhere($row, $where)) {
                foreach ($values as $k => $v) {
                    $row[$k] = $v;
                }
                $this->tables[$table][$id] = $row;
            }
        }
        return true;
    }

    public function delete(string $table, array $where): bool
    {
        if (!isset($this->tables[$table])) {
            return false;
        }

        foreach ($this->tables[$table] as $id => $row) {
            if ($this->matchWhere($row, $where)) {
                unset($this->tables[$table][$id]);
            }
        }
        return true;
    }

    public function request(array $criteria): ArrayIterator
    {
        $fromSpec = (string) ($criteria['FROM'] ?? '');
        [$baseTable, $baseAlias] = $this->parseTableAlias($fromSpec);

        if (!isset($this->tables[$baseTable])) {
            return new ArrayIterator([]);
        }

        $working = [];
        foreach ($this->tables[$baseTable] as $row) {
            $aliased = [];
            foreach ($row as $col => $val) {
                $aliased[$col]                 = $val;
                $aliased[$baseAlias . '.' . $col] = $val;
            }
            $working[] = $aliased;
        }

        if (!empty($criteria['INNER JOIN']) && is_array($criteria['INNER JOIN'])) {
            foreach ($criteria['INNER JOIN'] as $joinSpec => $joinCond) {
                [$joinTable, $joinAlias] = $this->parseTableAlias((string) $joinSpec);
                $joinRows                = $this->tables[$joinTable] ?? [];
                $on                      = $joinCond['ON'] ?? [];
                $onKeys                  = array_keys($on);
                $leftAlias               = (string) ($onKeys[0] ?? $baseAlias);
                $leftCol                 = (string) ($on[$leftAlias] ?? 'id');
                $rightAlias              = (string) ($onKeys[1] ?? $joinAlias);
                $rightCol                = (string) ($on[$rightAlias] ?? 'id');

                $joined = [];
                foreach ($working as $leftRow) {
                    $leftLookup = $leftAlias . '.' . $leftCol;
                    $leftVal    = $leftRow[$leftLookup] ?? ($leftRow[$leftCol] ?? null);
                    foreach ($joinRows as $rightRow) {
                        $rightVal = $rightRow[$rightCol] ?? null;
                        if ($leftVal !== null && $rightVal !== null && (string) $leftVal === (string) $rightVal) {
                            $merged = $leftRow;
                            foreach ($rightRow as $rCol => $rVal) {
                                $merged[$joinAlias . '.' . $rCol] = $rVal;
                                if (!array_key_exists($rCol, $merged)) {
                                    $merged[$rCol] = $rVal;
                                }
                            }
                            $joined[] = $merged;
                        }
                    }
                }
                $working = $joined;
            }
        }

        if (!empty($criteria['WHERE']) && is_array($criteria['WHERE'])) {
            $filtered = [];
            foreach ($working as $row) {
                if ($this->matchWhere($row, $criteria['WHERE'])) {
                    $filtered[] = $row;
                }
            }
            $working = $filtered;
        }

        if (!empty($criteria['ORDER'])) {
            $orders = is_array($criteria['ORDER']) ? $criteria['ORDER'] : [$criteria['ORDER']];
            usort($working, function (array $a, array $b) use ($orders): int {
                foreach ($orders as $orderClause) {
                    $parts = preg_split('/\s+/', trim((string) $orderClause));
                    $col   = $parts[0] ?? 'id';
                    $dir   = strtoupper($parts[1] ?? 'ASC');
                    $va    = $a[$col] ?? ($a[$this->stripAlias($col)] ?? null);
                    $vb    = $b[$col] ?? ($b[$this->stripAlias($col)] ?? null);
                    if (is_numeric($va) && is_numeric($vb)) {
                        $cmp = ((float) $va <=> (float) $vb);
                    } else {
                        $cmp = strcmp((string) $va, (string) $vb);
                    }
                    if ($cmp !== 0) {
                        return ($dir === 'DESC') ? -$cmp : $cmp;
                    }
                }
                return 0;
            });
        }

        $selectCols = isset($criteria['SELECT']) && is_array($criteria['SELECT'])
            ? $criteria['SELECT']
            : null;
        $countAlias = isset($criteria['COUNT']) ? (string) $criteria['COUNT'] : null;

        if (!empty($criteria['GROUPBY'])) {
            $groupCols = is_array($criteria['GROUPBY']) ? $criteria['GROUPBY'] : [$criteria['GROUPBY']];
            $groups    = [];
            foreach ($working as $row) {
                $keyParts = [];
                foreach ($groupCols as $gCol) {
                    $keyParts[] = (string) ($row[$gCol] ?? ($row[$this->stripAlias((string) $gCol)] ?? ''));
                }
                $gKey = implode('|', $keyParts);
                if (!isset($groups[$gKey])) {
                    $groups[$gKey] = [];
                }
                $groups[$gKey][] = $row;
            }

            $out = [];
            foreach ($groups as $gRows) {
                $first     = $gRows[0];
                $projected = $this->projectRow($first, $selectCols);
                if ($countAlias !== null) {
                    $projected[$countAlias] = count($gRows);
                }
                $out[] = $projected;
            }
            $working = $out;
        } elseif ($countAlias !== null) {
            $projected              = $working !== [] ? $this->projectRow($working[0], $selectCols) : [];
            $projected[$countAlias] = count($working);
            $working                = [$projected];
        } else {
            $out = [];
            foreach ($working as $row) {
                $out[] = $this->projectRow($row, $selectCols);
            }
            $working = $out;
        }

        if (isset($criteria['LIMIT'])) {
            $limit   = max(0, (int) $criteria['LIMIT']);
            $working = array_slice($working, 0, $limit);
        }

        return new ArrayIterator($working);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseTableAlias(string $spec): array
    {
        if (preg_match('/^\s*([a-zA-Z0-9_]+)\s+AS\s+([a-zA-Z0-9_]+)\s*$/i', $spec, $m)) {
            return [$m[1], $m[2]];
        }
        $clean = trim($spec);
        return [$clean, $clean];
    }

    private function stripAlias(string $col): string
    {
        $pos = strpos($col, '.');
        return $pos !== false ? substr($col, $pos + 1) : $col;
    }

    private function projectRow(array $row, ?array $selectCols): array
    {
        if ($selectCols === null) {
            $clean = [];
            foreach ($row as $k => $v) {
                if (strpos((string) $k, '.') === false) {
                    $clean[$k] = $v;
                }
            }
            return $clean;
        }

        $projected = [];
        foreach ($selectCols as $col) {
            $colStr = (string) $col;
            $alias  = $this->stripAlias($colStr);
            if (array_key_exists($colStr, $row)) {
                $projected[$alias] = $row[$colStr];
            } elseif (array_key_exists($alias, $row)) {
                $projected[$alias] = $row[$alias];
            } else {
                $projected[$alias] = null;
            }
        }
        return $projected;
    }

    private function matchWhere(array $row, array $where): bool
    {
        foreach ($where as $key => $val) {
            if ($key === 'NOT' && is_array($val)) {
                if ($this->matchWhere($row, $val)) {
                    return false;
                }
                continue;
            }

            $col    = (string) $key;
            $actual = array_key_exists($col, $row)
                ? $row[$col]
                : ($row[$this->stripAlias($col)] ?? null);

            if (is_array($val)) {
                if ($val === []) {
                    return false;
                }
                $matched = false;
                foreach ($val as $item) {
                    if ($actual !== null && (string) $actual === (string) $item) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    return false;
                }
            } elseif ($val === null) {
                if ($actual !== null) {
                    return false;
                }
            } else {
                if ($actual === null || (string) $actual !== (string) $val) {
                    return false;
                }
            }
        }
        return true;
    }
}

$DB = new SmartassignMockDB();
$DB->reset();

// Simulação de CommonGLPI: sem tipo por padrão (GLPI 10/11) ou tipado quando solicitado (GLPI 12)
if (getenv('SMARTASSIGN_TEST_TYPED_RIGHTNAME') === '1') {
    class CommonGLPI
    {
        public static string $rightname = '';
    }
} else {
    class CommonGLPI
    {
        public static $rightname = '';
    }
}

class CommonDBTM extends CommonGLPI
{
    /** @var array<string, mixed> */
    public $fields = [];

    /** @var array<string, mixed>|false */
    public $input = [];

    public static function getTable(): string
    {
        return '';
    }

    public function getID(): int
    {
        return (int) ($this->fields['id'] ?? 0);
    }

    public function getFromDB(int $id): bool
    {
        global $DB;

        $table = static::getTable();
        if ($table === '' || !isset($DB->tables[$table][$id])) {
            return false;
        }
        $this->fields = $DB->tables[$table][$id];
        return true;
    }

    /**
     * @return int|false
     */
    public function add(array $input)
    {
        global $DB;

        $table = static::getTable();
        if ($table === '') {
            return false;
        }
        $id = $DB->insert($table, $input);
        if ($id === false) {
            return false;
        }
        $this->fields = $DB->tables[$table][$id];
        return $id;
    }
}

class CommonITILObject extends CommonDBTM
{
    public const INCOMING = 1;
    public const ASSIGNED = 2;
    public const PLANNED  = 3;
    public const WAITING  = 4;
    public const SOLVED   = 5;
    public const CLOSED   = 6;
}

class Ticket extends CommonITILObject
{
    public static function getTable(): string
    {
        return 'glpi_tickets';
    }
}

class Ticket_User extends CommonDBTM
{
    public static bool $failNextAdd = false;

    public static function getTable(): string
    {
        return 'glpi_tickets_users';
    }

    public function add(array $input)
    {
        if (self::$failNextAdd) {
            self::$failNextAdd = false;
            return false;
        }
        return parent::add($input);
    }
}

class Group_Ticket extends CommonDBTM
{
    public static function getTable(): string
    {
        return 'glpi_groups_tickets';
    }
}

class Group extends CommonDBTM
{
    public static function getTable(): string
    {
        return 'glpi_groups';
    }
}

class User extends CommonDBTM
{
    public static function getTable(): string
    {
        return 'glpi_users';
    }
}

class Plugin
{
    public static bool $getWebDirCalled = false;

    public static function getWebDir(string $plugin, bool $full = true, bool $absolute = false): string
    {
        self::$getWebDirCalled = true;
        return ($absolute ? 'https://glpi.example.test/glpi' : '/glpi') . '/plugins/' . $plugin;
    }
}

class Session
{
    /** @var int[]|null */
    public static ?array $allowedEntities = null;

    public static function checkRight(string $module, int $right): void
    {
    }

    public static function haveRight(string $module, int $right): bool
    {
        return true;
    }

    public static function haveAccessToEntity(int $entities_id): bool
    {
        if (self::$allowedEntities === null) {
            return true;
        }
        return in_array($entities_id, self::$allowedEntities, true);
    }

    public static function addMessageAfterRedirect(string $msg, bool $check_once = false, int $message_type = INFO): void
    {
    }
}

if (version_compare((string) GLPI_VERSION, '12.0.0-dev', '>=')) {
    class Html
    {
        public static string $redirectUrl = '';

        public static function redirect(string $url): void
        {
            self::$redirectUrl = $url;
        }
    }
} else {
    class Html
    {
        public static bool $rightErrorShown = false;
        public static string $redirectUrl = '';

        public static function displayRightError(): void
        {
            self::$rightErrorShown = true;
        }

        public static function displayErrorAndDie(string $msg, bool $with_header = false): void
        {
            throw new RuntimeException($msg);
        }

        public static function redirect(string $url): void
        {
            self::$redirectUrl = $url;
        }
    }
}

function __(string $str, string $domain = ''): string
{
    return $str;
}

require_once __DIR__ . '/../smartassign/inc/compat.class.php';
require_once __DIR__ . '/../smartassign/inc/config.class.php';
require_once __DIR__ . '/../smartassign/inc/assigner.class.php';
require_once __DIR__ . '/../smartassign/setup.php';
require_once __DIR__ . '/../smartassign/hook.php';

}
