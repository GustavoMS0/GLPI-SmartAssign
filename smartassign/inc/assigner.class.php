<?php

/**
 * ------------------------------------------------------------------------
 * Smart Assign - Motor de atribuição automática de chamados
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
require_once __DIR__ . '/config.class.php';

class PluginSmartassignAssigner
{
    public const ACTOR_ASSIGN = 2;
    public const STATUS_SOLVED = 5;
    public const STATUS_CLOSED = 6;

    /**
     * Criações de Ticket em andamento indexadas por spl_object_id($ticket).
     *
     * @var array<int, array{ref: WeakReference, explicit_users: int[]}>
     */
    private static array $inFlightCreations = [];

    /** Anti-reentrância por tickets_id dentro da mesma requisição PHP */
    private static array $processedTickets = [];

    /**
     * Reinicia o estado em memória entre cenários (usado em testes).
     */
    public static function resetRuntimeState(): void
    {
        self::$inFlightCreations = [];
        self::$processedTickets  = [];
    }

    /**
     * Hook post_prepareadd em Ticket: executa após Ticket::prepareInputForAdd()
     * (quando _actors['assign'] e RuleTicket já foram aplicados).
     *
     * @param mixed $item
     */
    public static function postPrepareAddTicket($item): void
    {
        if (!($item instanceof Ticket)) {
            return;
        }
        if (!isset($item->input) || !is_array($item->input)) {
            return;
        }

        self::pruneInFlightCreations();

        $oid = spl_object_id($item);
        self::$inFlightCreations[$oid] = [
            'ref'            => WeakReference::create($item),
            'explicit_users' => self::extractExplicitInputUsers($item->input),
        ];
    }

    /**
     * Hook item_add em Ticket: trata atribuição na criação quando não houve disparo prévio por Group_Ticket.
     *
     * @param mixed $item
     */
    public static function itemAddTicket($item): void
    {
        global $DB;

        if (!($item instanceof Ticket)) {
            return;
        }

        $oid        = spl_object_id($item);
        $entry      = self::$inFlightCreations[$oid] ?? null;
        $stackUsers = is_array($entry) ? ($entry['explicit_users'] ?? []) : [];

        try {
            $tickets_id = (int) $item->getID();
            if ($tickets_id <= 0 || !empty(self::$processedTickets[$tickets_id])) {
                return;
            }

            $groups_id = 0;
            foreach (
                $DB->request([
                    'SELECT' => ['groups_id'],
                    'FROM'   => 'glpi_groups_tickets',
                    'WHERE'  => [
                        'tickets_id' => $tickets_id,
                        'type'       => self::ACTOR_ASSIGN,
                    ],
                    'ORDER'  => 'id ASC',
                    'LIMIT'  => 1,
                ]) as $row
            ) {
                $groups_id = (int) ($row['groups_id'] ?? 0);
            }

            if ($groups_id <= 0 && isset($item->input) && is_array($item->input)) {
                $inputGroups = self::extractExplicitInputGroups($item->input);
                if ($inputGroups !== []) {
                    $groups_id = $inputGroups[0];
                }
            }

            if ($groups_id <= 0) {
                return;
            }

            $inputUsers = $stackUsers;
            if ($inputUsers === [] && isset($item->input) && is_array($item->input)) {
                $inputUsers = self::extractExplicitInputUsers($item->input);
            }

            self::processAssignment($tickets_id, $groups_id, true, $inputUsers);
        } finally {
            unset(self::$inFlightCreations[$oid]);
            self::pruneInFlightCreations();
        }
    }

    /**
     * Hook item_add em Group_Ticket: diferencia inclusão durante criação vs alteração posterior de grupo.
     *
     * @param mixed $item
     */
    public static function itemAddGroupTicket($item): void
    {
        if (!is_object($item) || (!($item instanceof Group_Ticket) && get_class($item) !== 'Group_Ticket')) {
            return;
        }

        $fields = isset($item->fields) && is_array($item->fields) ? $item->fields : [];
        $input  = isset($item->input) && is_array($item->input) ? $item->input : [];

        $type       = (int) ($fields['type'] ?? ($input['type'] ?? 0));
        $tickets_id = (int) ($fields['tickets_id'] ?? ($input['tickets_id'] ?? 0));
        $groups_id  = (int) ($fields['groups_id'] ?? ($input['groups_id'] ?? 0));

        if ($type !== self::ACTOR_ASSIGN || $tickets_id <= 0 || $groups_id <= 0) {
            return;
        }

        $activeCreation = self::findActiveCreationForTicket($tickets_id);
        $isCreate       = ($activeCreation !== null);
        $inputUsers     = $isCreate ? ($activeCreation['explicit_users'] ?? []) : [];

        self::processAssignment($tickets_id, $groups_id, $isCreate, $inputUsers);
    }

    /**
     * Limpeza de logs ao expurgar permanentemente um Ticket.
     *
     * @param mixed $item
     */
    public static function itemPurgeTicket($item): void
    {
        global $DB;

        if (!($item instanceof Ticket)) {
            return;
        }

        $tickets_id = (int) $item->getID();
        if ($tickets_id > 0) {
            $DB->delete(PluginSmartassignConfig::LOGS_TABLE, ['tickets_id' => $tickets_id]);
        }
    }

    /**
     * Limpeza de estado ao expurgar permanentemente um Group.
     *
     * @param mixed $item
     */
    public static function itemPurgeGroup($item): void
    {
        global $DB;

        if (!($item instanceof Group)) {
            return;
        }

        $groups_id = (int) $item->getID();
        if ($groups_id > 0) {
            $DB->delete(PluginSmartassignConfig::STATE_TABLE, ['groups_id' => $groups_id]);
        }
    }

    /**
     * Executa a validação completa do chamado e realiza a atribuição do técnico escolhido.
     *
     * @param int[] $inputAssignedUsers
     */
    private static function processAssignment(
        int $tickets_id,
        int $groups_id,
        bool $isCreate,
        array $inputAssignedUsers = []
    ): bool {
        global $DB;

        if ($tickets_id <= 0 || $groups_id <= 0) {
            return false;
        }

        if (!empty(self::$processedTickets[$tickets_id])) {
            return false;
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return false;
        }

        if (!empty($ticket->fields['is_deleted']) || !empty($ticket->fields['is_template'])) {
            return false;
        }

        $solvedStatus = defined('CommonITILObject::SOLVED') ? (int) CommonITILObject::SOLVED : self::STATUS_SOLVED;
        $status       = (int) ($ticket->fields['status'] ?? 0);
        if ($status >= $solvedStatus) {
            return false;
        }

        $entities_id = (int) ($ticket->fields['entities_id'] ?? 0);
        $config      = PluginSmartassignConfig::resolveForEntity($entities_id);

        if (empty($config['is_active'])) {
            return false;
        }
        if ($isCreate && empty($config['assign_on_create'])) {
            return false;
        }
        if (!$isCreate && empty($config['assign_on_group_change'])) {
            return false;
        }

        $assignedUserIds = self::getAssignedTicketUsers($tickets_id);
        if ($isCreate && $inputAssignedUsers !== []) {
            $assignedUserIds = array_values(array_unique(array_merge($assignedUserIds, $inputAssignedUsers)));
        }

        if (!empty($config['skip_if_assigned'])) {
            if ($assignedUserIds !== []) {
                return false;
            }
        } elseif ($assignedUserIds !== []) {
            $excludedConfig = PluginSmartassignConfig::parseExcludedUsers($config['excluded_users'] ?? []);
            $groupMembers   = self::getEligibleGroupMembers($groups_id, $excludedConfig);
            if (array_intersect($assignedUserIds, $groupMembers) !== []) {
                return false;
            }
        }

        $chosenUserId = self::pickCandidate($groups_id, $config, $assignedUserIds, false);
        if ($chosenUserId <= 0) {
            return false;
        }

        self::$processedTickets[$tickets_id] = true;

        $ticketUser = new Ticket_User();
        $added      = $ticketUser->add(PluginSmartassignCompat::coreInput([
            'tickets_id' => $tickets_id,
            'users_id'   => $chosenUserId,
            'type'       => self::ACTOR_ASSIGN,
        ]));

        if (!$added) {
            return false;
        }

        self::updateGroupPointer($groups_id, $chosenUserId);

        $DB->insert(
            PluginSmartassignConfig::LOGS_TABLE,
            PluginSmartassignCompat::dbValues([
                'tickets_id'    => $tickets_id,
                'groups_id'     => $groups_id,
                'users_id'      => $chosenUserId,
                'mode'          => (string) ($config['mode'] ?? PluginSmartassignConfig::MODE_ROUND_ROBIN),
                'reason'        => $isCreate ? 'ticket_create' : 'group_assign',
                'date_creation' => self::currentDatetime(),
            ])
        );

        return true;
    }

    /**
     * Seleciona o próximo técnico elegível do grupo conforme o modo configurado.
     *
     * @param int[] $excludedUserIds
     */
    public static function pickCandidate(
        int $groups_id,
        array $config,
        array $excludedUserIds = [],
        bool $advancePointer = true
    ): int {
        if ($groups_id <= 0) {
            return 0;
        }

        $configExcluded = PluginSmartassignConfig::parseExcludedUsers($config['excluded_users'] ?? []);
        $allExcluded    = array_values(array_unique(array_merge(
            $configExcluded,
            self::extractIdList($excludedUserIds)
        )));

        $candidates = self::getEligibleGroupMembers($groups_id, $allExcluded);
        if ($candidates === []) {
            return 0;
        }

        $counts  = self::loadOpenTicketCounts($candidates);
        $maxOpen = max(0, (int) ($config['max_open_tickets'] ?? 0));

        if ($maxOpen > 0) {
            $filtered = [];
            foreach ($candidates as $uid) {
                if (($counts[$uid] ?? 0) < $maxOpen) {
                    $filtered[] = $uid;
                }
            }
            $candidates = $filtered;
            if ($candidates === []) {
                return 0;
            }
        }

        $lastUserId = self::getGroupPointer($groups_id);
        $mode       = (string) ($config['mode'] ?? PluginSmartassignConfig::MODE_ROUND_ROBIN);
        $chosen     = 0;

        if ($mode === PluginSmartassignConfig::MODE_LEAST_LOADED) {
            $minLoad = null;
            foreach ($candidates as $uid) {
                $load = $counts[$uid] ?? 0;
                if ($minLoad === null || $load < $minLoad) {
                    $minLoad = $load;
                }
            }

            $tied = [];
            foreach ($candidates as $uid) {
                if (($counts[$uid] ?? 0) === $minLoad) {
                    $tied[] = $uid;
                }
            }

            $chosen = self::nextRoundRobin($tied, $lastUserId);
        } else {
            $chosen = self::nextRoundRobin($candidates, $lastUserId);
        }

        if ($chosen > 0 && $advancePointer) {
            self::updateGroupPointer($groups_id, $chosen);
        }

        return $chosen;
    }

    /**
     * Retorna os IDs dos técnicos elegíveis do grupo em ordem crescente de users_id.
     *
     * @param int[] $excludedUserIds
     * @return int[]
     */
    private static function getEligibleGroupMembers(int $groups_id, array $excludedUserIds = []): array
    {
        global $DB;

        $excludedMap = [];
        foreach ($excludedUserIds as $uid) {
            $uid = (int) $uid;
            if ($uid > 0) {
                $excludedMap[$uid] = true;
            }
        }

        $nowTs = strtotime(self::currentDatetime());
        if ($nowTs === false) {
            $nowTs = time();
        }

        $members = [];
        foreach (
            $DB->request([
                'SELECT'     => ['gu.users_id', 'u.begin_date', 'u.end_date'],
                'FROM'       => 'glpi_groups_users AS gu',
                'INNER JOIN' => [
                    'glpi_users AS u' => [
                        'ON' => [
                            'gu' => 'users_id',
                            'u'  => 'id',
                        ],
                    ],
                ],
                'WHERE'      => [
                    'gu.groups_id' => $groups_id,
                    'u.is_active'  => 1,
                    'u.is_deleted' => 0,
                ],
                'ORDER'      => 'gu.users_id ASC',
            ]) as $row
        ) {
            $uid = (int) ($row['users_id'] ?? 0);
            if ($uid <= 0 || isset($excludedMap[$uid])) {
                continue;
            }
            if (!self::isUserDateValid($row['begin_date'] ?? null, $row['end_date'] ?? null, $nowTs)) {
                continue;
            }
            $members[$uid] = true;
        }

        $ids = array_keys($members);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * Carrega a contagem global de chamados abertos por técnico considerando todas as entidades.
     *
     * @param int[] $userIds
     * @return array<int, int>
     */
    private static function loadOpenTicketCounts(array $userIds): array
    {
        global $DB;

        $counts = [];
        foreach ($userIds as $uid) {
            $uid = (int) $uid;
            if ($uid > 0) {
                $counts[$uid] = 0;
            }
        }
        if ($counts === []) {
            return [];
        }

        $solved = defined('CommonITILObject::SOLVED') ? (int) CommonITILObject::SOLVED : self::STATUS_SOLVED;
        $closed = defined('CommonITILObject::CLOSED') ? (int) CommonITILObject::CLOSED : self::STATUS_CLOSED;

        foreach (
            $DB->request([
                'SELECT'     => ['tu.users_id'],
                'COUNT'      => 'open_count',
                'FROM'       => 'glpi_tickets_users AS tu',
                'INNER JOIN' => [
                    'glpi_tickets AS t' => [
                        'ON' => [
                            'tu' => 'tickets_id',
                            't'  => 'id',
                        ],
                    ],
                ],
                'WHERE'      => [
                    'tu.type'      => self::ACTOR_ASSIGN,
                    'tu.users_id'  => array_keys($counts),
                    't.is_deleted' => 0,
                    'NOT'          => ['t.status' => [$solved, $closed]],
                ],
                'GROUPBY'    => ['tu.users_id'],
            ]) as $row
        ) {
            $uid = (int) ($row['users_id'] ?? 0);
            if (isset($counts[$uid])) {
                $counts[$uid] = (int) ($row['open_count'] ?? 0);
            }
        }

        return $counts;
    }

    /**
     * Retorna os IDs dos técnicos já atribuídos ao chamado (type = 2).
     *
     * @return int[]
     */
    private static function getAssignedTicketUsers(int $tickets_id): array
    {
        global $DB;

        $assigned = [];
        foreach (
            $DB->request([
                'SELECT' => ['users_id'],
                'FROM'   => 'glpi_tickets_users',
                'WHERE'  => [
                    'tickets_id' => $tickets_id,
                    'type'       => self::ACTOR_ASSIGN,
                ],
            ]) as $row
        ) {
            $uid = (int) ($row['users_id'] ?? 0);
            if ($uid > 0) {
                $assigned[$uid] = true;
            }
        }

        return array_keys($assigned);
    }

    /**
     * Seleciona o próximo ID em lista ordenada crescente após $lastUserId, com volta ao início.
     *
     * @param int[] $sortedUserIds
     */
    private static function nextRoundRobin(array $sortedUserIds, int $lastUserId): int
    {
        if ($sortedUserIds === []) {
            return 0;
        }

        foreach ($sortedUserIds as $uid) {
            if ($uid > $lastUserId) {
                return $uid;
            }
        }

        return $sortedUserIds[0];
    }

    private static function getGroupPointer(int $groups_id): int
    {
        global $DB;

        foreach (
            $DB->request([
                'SELECT' => ['last_users_id'],
                'FROM'   => PluginSmartassignConfig::STATE_TABLE,
                'WHERE'  => ['groups_id' => $groups_id],
                'LIMIT'  => 1,
            ]) as $row
        ) {
            return (int) ($row['last_users_id'] ?? 0);
        }

        return 0;
    }

    private static function updateGroupPointer(int $groups_id, int $users_id): void
    {
        global $DB;

        $exists = false;
        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => PluginSmartassignConfig::STATE_TABLE,
                'WHERE'  => ['groups_id' => $groups_id],
                'LIMIT'  => 1,
            ]) as $row
        ) {
            $exists = true;
        }

        $now        = self::currentDatetime();
        $updateData = PluginSmartassignCompat::dbValues([
            'last_users_id' => $users_id,
            'date_mod'      => $now,
        ]);

        if ($exists) {
            $DB->update(
                PluginSmartassignConfig::STATE_TABLE,
                $updateData,
                ['groups_id' => $groups_id]
            );
            return;
        }

        $insertData = PluginSmartassignCompat::dbValues([
            'groups_id'     => $groups_id,
            'last_users_id' => $users_id,
            'date_mod'      => $now,
        ]);

        $inserted = false;
        $dupError = false;
        try {
            $inserted = (bool) $DB->insert(PluginSmartassignConfig::STATE_TABLE, $insertData);
        } catch (\Throwable $e) {
            $code     = $e->getCode();
            $msg      = $e->getMessage();
            $dupError = ((int) $code === 1062
                || (string) $code === '23000'
                || stripos($msg, 'Duplicate entry') !== false
                || stripos($msg, 'duplicate key') !== false);
            if (!$dupError) {
                throw $e;
            }
        }

        if (!$inserted) {
            if (
                !$dupError
                && is_object($DB)
                && method_exists($DB, 'errno')
                && (int) $DB->errno() === 1062
            ) {
                $dupError = true;
            }
            if ($dupError) {
                $DB->update(
                    PluginSmartassignConfig::STATE_TABLE,
                    $updateData,
                    ['groups_id' => $groups_id]
                );
            }
        }
    }

    /**
     * Remove registros de criações abortadas ou já liberadas da memória.
     */
    private static function pruneInFlightCreations(): void
    {
        foreach (self::$inFlightCreations as $oid => $entry) {
            $obj = $entry['ref']->get();
            if ($obj === null || !isset($obj->input) || $obj->input === false) {
                unset(self::$inFlightCreations[$oid]);
            }
        }
    }

    /**
     * Verifica se há uma criação de Ticket ativa correspondendo ao tickets_id informado.
     */
    private static function findActiveCreationForTicket(int $tickets_id): ?array
    {
        self::pruneInFlightCreations();

        if ($tickets_id <= 0 || self::$inFlightCreations === []) {
            return null;
        }

        $entries = array_reverse(self::$inFlightCreations, true);
        foreach ($entries as $oid => $entry) {
            $obj = $entry['ref']->get();
            if ($obj === null || !isset($obj->input) || $obj->input === false) {
                unset(self::$inFlightCreations[$oid]);
                continue;
            }
            $objId = (int) ($obj->fields['id'] ?? 0);
            if ($objId === $tickets_id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Extrai técnicos atribuídos na entrada de criação (_users_id_assign e _actors['assign']).
     *
     * @return int[]
     */
    private static function extractExplicitInputUsers(array $input): array
    {
        $users = self::extractIdList($input['_users_id_assign'] ?? []);
        if (isset($input['_actors']) && is_array($input['_actors']) && isset($input['_actors']['assign'])) {
            $actorUsers = self::extractIdList($input['_actors']['assign'], 'User');
            foreach ($actorUsers as $uid) {
                if (!in_array($uid, $users, true)) {
                    $users[] = $uid;
                }
            }
        }
        return $users;
    }

    /**
     * Extrai grupos atribuídos na entrada de criação (_groups_id_assign e _actors['assign']).
     *
     * @return int[]
     */
    private static function extractExplicitInputGroups(array $input): array
    {
        $groups = self::extractIdList($input['_groups_id_assign'] ?? []);
        if (isset($input['_actors']) && is_array($input['_actors']) && isset($input['_actors']['assign'])) {
            $actorGroups = self::extractIdList($input['_actors']['assign'], 'Group');
            foreach ($actorGroups as $gid) {
                if (!in_array($gid, $groups, true)) {
                    $groups[] = $gid;
                }
            }
        }
        return $groups;
    }

    /**
     * Valida vigência do usuário (begin_date / end_date) contra o timestamp atual.
     *
     * @param mixed $beginDate
     * @param mixed $endDate
     */
    private static function isUserDateValid($beginDate, $endDate, int $nowTs): bool
    {
        if ($beginDate !== null) {
            $beginStr = trim((string) $beginDate);
            if ($beginStr !== '' && $beginStr !== '0000-00-00 00:00:00') {
                $beginTs = strtotime($beginStr);
                if ($beginTs !== false && $beginTs > $nowTs) {
                    return false;
                }
            }
        }

        if ($endDate !== null) {
            $endStr = trim((string) $endDate);
            if ($endStr !== '' && $endStr !== '0000-00-00 00:00:00') {
                $endTs = strtotime($endStr);
                if ($endTs !== false && $endTs < $nowTs) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Extrai lista ordenada de IDs inteiros positivos a partir de escalar ou array do GLPI,
     * incluindo entradas brutas de _actors['assign'] com itemtype.
     *
     * @param mixed $raw
     * @return int[]
     */
    private static function extractIdList($raw, ?string $actorItemtype = null): array
    {
        if ($actorItemtype === null && (is_int($raw) || is_string($raw))) {
            $id = (int) $raw;
            return $id > 0 ? [$id] : [];
        }

        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $val) {
            if (is_array($val)) {
                if (isset($val['itemtype'])) {
                    $expected = $actorItemtype !== null ? $actorItemtype : 'User';
                    if ((string) $val['itemtype'] !== $expected) {
                        continue;
                    }
                    $candidate = $val['items_id'] ?? ($expected === 'User' ? ($val['users_id'] ?? 0) : ($val['groups_id'] ?? 0));
                    foreach (self::extractIdList($candidate) as $cid) {
                        if (!in_array($cid, $ids, true)) {
                            $ids[] = $cid;
                        }
                    }
                    continue;
                }
                if ($actorItemtype !== null) {
                    continue;
                }
                $id = (int) ($val['users_id'] ?? ($val['groups_id'] ?? ($val['items_id'] ?? ($val['id'] ?? 0))));
            } elseif ($actorItemtype !== null) {
                continue;
            } else {
                $id = (int) $val;
            }

            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private static function currentDatetime(): string
    {
        if (!empty($_SESSION['glpi_currenttime'])) {
            return (string) $_SESSION['glpi_currenttime'];
        }
        return date('Y-m-d H:i:s');
    }
}
