<?php

/**
 * ------------------------------------------------------------------------
 * Smart Assign - Configuração por entidade e esquema de banco
 *
 * Copyright (C) 2026 by G. Martins
 * ------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Smart Assign.
 *
 * Smart Assign is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Smart Assign is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Smart Assign. If not, see <http://www.gnu.org/licenses/>.
 * ------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die("Acesso direto não permitido.");
}

require_once __DIR__ . '/compat.class.php';

interface PluginSmartassignConfigConstants
{
    public const TABLE = 'glpi_plugin_smartassign_configs';
    public const STATE_TABLE = 'glpi_plugin_smartassign_state';
    public const LOGS_TABLE = 'glpi_plugin_smartassign_logs';

    public const MODE_ROUND_ROBIN = 'round_robin';
    public const MODE_LEAST_LOADED = 'least_loaded';
}

trait PluginSmartassignConfigBody
{
    public static function getTypeName($nb = 0)
    {
        return 'Smart Assign';
    }

    /**
     * Valores padrão quando nenhuma configuração foi definida para a entidade ou ancestrais.
     */
    public static function defaults(int $entities_id = 0): array
    {
        return [
            'id'                     => 0,
            'entities_id'            => $entities_id,
            'source_entities_id'     => null,
            'inherited'              => false,
            'is_recursive'           => 0,
            'is_active'              => 0,
            'mode'                   => self::MODE_ROUND_ROBIN,
            'assign_on_create'       => 1,
            'assign_on_group_change' => 1,
            'max_open_tickets'       => 0,
            'skip_if_assigned'       => 1,
            'excluded_users'         => [],
            'date_mod'               => null,
        ];
    }

    /**
     * Instala as tabelas do plugin no banco do GLPI.
     */
    public static function installSchema(): void
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            $DB->doQuery(
                "CREATE TABLE `" . self::TABLE . "` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `entities_id` int unsigned NOT NULL DEFAULT '0',
                    `is_recursive` tinyint NOT NULL DEFAULT '0',
                    `is_active` tinyint NOT NULL DEFAULT '0',
                    `mode` varchar(32) NOT NULL DEFAULT 'round_robin',
                    `assign_on_create` tinyint NOT NULL DEFAULT '1',
                    `assign_on_group_change` tinyint NOT NULL DEFAULT '1',
                    `max_open_tickets` int unsigned NULL DEFAULT '0',
                    `skip_if_assigned` tinyint NOT NULL DEFAULT '1',
                    `excluded_users` text NULL,
                    `date_mod` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `entities_id` (`entities_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC"
            );
        }

        if (!$DB->tableExists(self::STATE_TABLE)) {
            $DB->doQuery(
                "CREATE TABLE `" . self::STATE_TABLE . "` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `groups_id` int unsigned NOT NULL DEFAULT '0',
                    `last_users_id` int unsigned NOT NULL DEFAULT '0',
                    `date_mod` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `groups_id` (`groups_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC"
            );
        }

        if (!$DB->tableExists(self::LOGS_TABLE)) {
            $DB->doQuery(
                "CREATE TABLE `" . self::LOGS_TABLE . "` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `tickets_id` int unsigned NOT NULL DEFAULT '0',
                    `groups_id` int unsigned NOT NULL DEFAULT '0',
                    `users_id` int unsigned NOT NULL DEFAULT '0',
                    `mode` varchar(32) NOT NULL DEFAULT '',
                    `reason` varchar(64) NOT NULL DEFAULT '',
                    `date_creation` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `tickets_id` (`tickets_id`),
                    KEY `groups_id` (`groups_id`),
                    KEY `date_creation` (`date_creation`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC"
            );
        }
    }

    /**
     * Remove as tabelas do plugin na desinstalação.
     */
    public static function uninstallSchema(): void
    {
        global $DB;

        foreach ([self::LOGS_TABLE, self::STATE_TABLE, self::TABLE] as $table) {
            if ($DB->tableExists($table)) {
                $DB->doQuery('DROP TABLE `' . $table . '`');
            }
        }
    }

    /**
     * Converte lista/JSON/CSV de IDs de usuários excluídos para inteiros positivos únicos ordenados.
     *
     * @param mixed $raw
     * @return int[]
     */
    public static function parseExcludedUsers($raw): array
    {
        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '') {
                return [];
            }
            if ($trimmed[0] === '[') {
                $decoded = json_decode($trimmed, true);
                $items   = is_array($decoded) ? $decoded : [];
            } else {
                $items = explode(',', $trimmed);
            }
        } elseif (is_array($raw)) {
            $items = $raw;
        } else {
            return [];
        }

        $unique = [];
        foreach ($items as $item) {
            $uid = (int) $item;
            if ($uid > 0) {
                $unique[$uid] = true;
            }
        }
        $ids = array_keys($unique);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * Normaliza uma linha vinda do banco para o formato interno da configuração.
     */
    private static function normalizeRow(
        array $row,
        int $entities_id,
        int $sourceEntitiesId,
        bool $inherited
    ): array {
        $mode = (string) ($row['mode'] ?? self::MODE_ROUND_ROBIN);
        if ($mode !== self::MODE_ROUND_ROBIN && $mode !== self::MODE_LEAST_LOADED) {
            $mode = self::MODE_ROUND_ROBIN;
        }

        $maxOpen = isset($row['max_open_tickets']) ? max(0, (int) $row['max_open_tickets']) : 0;

        return [
            'id'                     => (int) ($row['id'] ?? 0),
            'entities_id'            => $entities_id,
            'source_entities_id'     => $sourceEntitiesId,
            'inherited'              => $inherited,
            'is_recursive'           => !empty($row['is_recursive']) ? 1 : 0,
            'is_active'              => !empty($row['is_active']) ? 1 : 0,
            'mode'                   => $mode,
            'assign_on_create'       => !empty($row['assign_on_create']) ? 1 : 0,
            'assign_on_group_change' => !empty($row['assign_on_group_change']) ? 1 : 0,
            'max_open_tickets'       => $maxOpen,
            'skip_if_assigned'       => !empty($row['skip_if_assigned']) ? 1 : 0,
            'excluded_users'         => self::parseExcludedUsers($row['excluded_users'] ?? '[]'),
            'date_mod'               => isset($row['date_mod']) ? (string) $row['date_mod'] : null,
        ];
    }

    /**
     * Retorna a configuração própria da entidade, ou null se não existir registro específico.
     */
    public static function getOwnConfig(int $entities_id): ?array
    {
        global $DB;

        if ($entities_id < 0) {
            return null;
        }

        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['entities_id' => $entities_id],
                'LIMIT' => 1,
            ]) as $row
        ) {
            return self::normalizeRow($row, $entities_id, $entities_id, false);
        }

        return null;
    }

    /**
     * Resolve a configuração efetiva para a entidade:
     * 1) configuração própria exata;
     * 2) ancestral mais próximo com is_recursive = 1;
     * 3) valores padrão do plugin.
     */
    public static function resolveForEntity(int $entities_id): array
    {
        global $DB;

        if ($entities_id < 0) {
            return self::defaults(0);
        }

        $own = self::getOwnConfig($entities_id);
        if ($own !== null) {
            return $own;
        }

        $ancestors = PluginSmartassignCompat::getEntitiesAncestorsFallback($entities_id);
        if ($ancestors !== []) {
            $byEntity = [];
            foreach (
                $DB->request([
                    'FROM'  => self::TABLE,
                    'WHERE' => [
                        'entities_id'  => $ancestors,
                        'is_recursive' => 1,
                    ],
                ]) as $row
            ) {
                $byEntity[(int) $row['entities_id']] = $row;
            }

            foreach ($ancestors as $ancestorId) {
                if (isset($byEntity[$ancestorId])) {
                    return self::normalizeRow(
                        $byEntity[$ancestorId],
                        $entities_id,
                        (int) $ancestorId,
                        true
                    );
                }
            }
        }

        return self::defaults($entities_id);
    }

    /**
     * Salva ou atualiza a configuração de uma entidade.
     */
    public static function saveConfig(int $entities_id, array $post): bool
    {
        global $DB;

        if ($entities_id < 0) {
            return false;
        }

        $mode = (string) ($post['mode'] ?? self::MODE_ROUND_ROBIN);
        if ($mode !== self::MODE_ROUND_ROBIN && $mode !== self::MODE_LEAST_LOADED) {
            $mode = self::MODE_ROUND_ROBIN;
        }

        $excluded = self::parseExcludedUsers($post['excluded_users'] ?? []);
        $maxOpen  = max(0, (int) ($post['max_open_tickets'] ?? 0));
        $now      = !empty($_SESSION['glpi_currenttime'])
            ? (string) $_SESSION['glpi_currenttime']
            : date('Y-m-d H:i:s');

        $payload = [
            'is_recursive'           => !empty($post['is_recursive']) ? 1 : 0,
            'is_active'              => !empty($post['is_active']) ? 1 : 0,
            'mode'                   => $mode,
            'assign_on_create'       => !empty($post['assign_on_create']) ? 1 : 0,
            'assign_on_group_change' => !empty($post['assign_on_group_change']) ? 1 : 0,
            'max_open_tickets'       => $maxOpen,
            'skip_if_assigned'       => !empty($post['skip_if_assigned']) ? 1 : 0,
            'excluded_users'         => json_encode($excluded),
            'date_mod'               => $now,
        ];

        $existing = self::getOwnConfig($entities_id);
        $dbData   = PluginSmartassignCompat::dbValues($payload);

        if ($existing !== null) {
            return (bool) $DB->update(
                self::TABLE,
                $dbData,
                ['entities_id' => $entities_id]
            );
        }

        $dbData['entities_id'] = $entities_id;
        return (bool) $DB->insert(self::TABLE, $dbData);
    }

    /**
     * Remove a configuração própria de uma entidade para restaurar herança.
     */
    public static function deleteConfig(int $entities_id): bool
    {
        global $DB;

        if ($entities_id < 0) {
            return false;
        }

        return (bool) $DB->delete(self::TABLE, ['entities_id' => $entities_id]);
    }

    /**
     * Lista os logs recentes de atribuição automática com resolução de nomes.
     */
    public static function getRecentLogs(int $limit = 25, bool $filterByEntityAccess = true): array
    {
        global $DB;

        $limit   = max(1, min(100, $limit));
        $fetch   = $filterByEntityAccess ? min(200, $limit * 4) : $limit;
        $rawLogs = [];

        foreach (
            $DB->request([
                'FROM'  => self::LOGS_TABLE,
                'ORDER' => 'id DESC',
                'LIMIT' => $fetch,
            ]) as $row
        ) {
            $rawLogs[] = $row;
        }

        if ($rawLogs === []) {
            return [];
        }

        $ticketIds = [];
        $groupIds  = [];
        $userIds   = [];
        foreach ($rawLogs as $row) {
            $tid = (int) ($row['tickets_id'] ?? 0);
            $gid = (int) ($row['groups_id'] ?? 0);
            $uid = (int) ($row['users_id'] ?? 0);
            if ($tid > 0) {
                $ticketIds[$tid] = $tid;
            }
            if ($gid > 0) {
                $groupIds[$gid] = $gid;
            }
            if ($uid > 0) {
                $userIds[$uid] = $uid;
            }
        }

        $tickets = [];
        if ($ticketIds !== []) {
            foreach (
                $DB->request([
                    'SELECT' => ['id', 'name', 'entities_id'],
                    'FROM'   => 'glpi_tickets',
                    'WHERE'  => ['id' => array_values($ticketIds)],
                ]) as $trow
            ) {
                $tickets[(int) $trow['id']] = [
                    'name'        => PluginSmartassignCompat::fromCore((string) ($trow['name'] ?? '')),
                    'entities_id' => (int) ($trow['entities_id'] ?? 0),
                ];
            }
        }

        $groups = [];
        if ($groupIds !== []) {
            foreach (
                $DB->request([
                    'SELECT' => ['id', 'name', 'completename'],
                    'FROM'   => 'glpi_groups',
                    'WHERE'  => ['id' => array_values($groupIds)],
                ]) as $grow
            ) {
                $gname = trim((string) ($grow['completename'] ?? ''));
                if ($gname === '') {
                    $gname = (string) ($grow['name'] ?? '');
                }
                $groups[(int) $grow['id']] = PluginSmartassignCompat::fromCore($gname);
            }
        }

        $users = [];
        if ($userIds !== []) {
            foreach (
                $DB->request([
                    'SELECT' => ['id', 'name', 'realname', 'firstname'],
                    'FROM'   => 'glpi_users',
                    'WHERE'  => ['id' => array_values($userIds)],
                ]) as $urow
            ) {
                $full = trim(
                    PluginSmartassignCompat::fromCore((string) ($urow['firstname'] ?? ''))
                    . ' '
                    . PluginSmartassignCompat::fromCore((string) ($urow['realname'] ?? ''))
                );
                $login = PluginSmartassignCompat::fromCore((string) ($urow['name'] ?? ''));
                $users[(int) $urow['id']] = $full !== '' ? $full : $login;
            }
        }

        $result = [];
        foreach ($rawLogs as $row) {
            $tid        = (int) ($row['tickets_id'] ?? 0);
            $gid        = (int) ($row['groups_id'] ?? 0);
            $uid        = (int) ($row['users_id'] ?? 0);
            $ticketInfo = $tickets[$tid] ?? null;

            if ($filterByEntityAccess && class_exists('Session')) {
                if ($ticketInfo !== null && !Session::haveAccessToEntity($ticketInfo['entities_id'])) {
                    continue;
                }
                if ($ticketInfo === null && !Session::haveAccessToEntity(0)) {
                    continue;
                }
            }

            $result[] = [
                'id'            => (int) $row['id'],
                'tickets_id'    => $tid,
                'ticket_name'   => $ticketInfo !== null ? $ticketInfo['name'] : '',
                'groups_id'     => $gid,
                'group_name'    => $groups[$gid] ?? ('#' . $gid),
                'users_id'      => $uid,
                'user_name'     => $users[$uid] ?? ('#' . $uid),
                'mode'          => (string) ($row['mode'] ?? ''),
                'reason'        => (string) ($row['reason'] ?? ''),
                'date_creation' => (string) ($row['date_creation'] ?? ''),
            ];

            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }
}

if (PluginSmartassignCompat::typedRightname()) {
    class PluginSmartassignConfig extends CommonGLPI implements PluginSmartassignConfigConstants
    {
        use PluginSmartassignConfigBody;

        public static string $rightname = 'config';
    }
} else {
    class PluginSmartassignConfig extends CommonGLPI implements PluginSmartassignConfigConstants
    {
        use PluginSmartassignConfigBody;

        public static $rightname = 'config';
    }
}
