<?php

/**
 * ------------------------------------------------------------------------
 * Smart Assign - Suíte de testes unitários standalone
 *
 * Copyright (C) 2026 by G. Martins
 * ------------------------------------------------------------------------
 */

if (in_array('--typed-rightname-check', $argv ?? ($_SERVER['argv'] ?? []), true)) {
    putenv('SMARTASSIGN_TEST_TYPED_RIGHTNAME=1');
    putenv('SMARTASSIGN_TEST_GLPI_VERSION=12.0.0');
    require_once __DIR__ . '/bootstrap.php';

    if (PluginSmartassignCompat::glpiMajor() !== 12) {
        fwrite(STDERR, "Esperado glpiMajor() === 12\n");
        exit(1);
    }
    if (!PluginSmartassignCompat::typedRightname()) {
        fwrite(STDERR, "Esperado typedRightname() === true com CommonGLPI tipado\n");
        exit(1);
    }
    $rp = new ReflectionProperty('PluginSmartassignConfig', 'rightname');
    if (!$rp->hasType()) {
        fwrite(STDERR, "Esperado PluginSmartassignConfig::\$rightname tipado no GLPI 12\n");
        exit(1);
    }
    if (PluginSmartassignConfig::$rightname !== 'config') {
        fwrite(STDERR, "Esperado PluginSmartassignConfig::\$rightname === 'config'\n");
        exit(1);
    }
    if (PluginSmartassignCompat::assetPath('css/smartassign.css') !== 'css/smartassign.css') {
        fwrite(STDERR, "Esperado assetPath sem prefixo public/ no GLPI 12\n");
        exit(1);
    }
    Plugin::$getWebDirCalled = false;
    $webPath = PluginSmartassignCompat::webPath();
    if ($webPath !== '/glpi/plugins/smartassign' || strpos($webPath, '/marketplace/') !== false || Plugin::$getWebDirCalled) {
        fwrite(STDERR, "webPath() no GLPI 12 deve retornar /plugins/smartassign sem chamar Plugin::getWebDir()\n");
        exit(1);
    }
    if (PluginSmartassignCompat::fromCore('Suporte &amp; TI') !== 'Suporte &amp; TI') {
        fwrite(STDERR, "fromCore() no GLPI 12 nao deve alterar texto\n");
        exit(1);
    }
    if (method_exists('Html', 'displayRightError')) {
        fwrite(STDERR, "Html::displayRightError() nao deve existir no mock GLPI 12\n");
        exit(1);
    }
    $denied = false;
    try {
        PluginSmartassignCompat::denyAccess();
    } catch (\Glpi\Exception\Http\AccessDeniedHttpException $e) {
        $denied = true;
    }
    if (!$denied) {
        fwrite(STDERR, "denyAccess() no GLPI 12 deve lancar AccessDeniedHttpException\n");
        exit(1);
    }
    $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];
    $_POST   = ['entities_id' => 'abc', 'is_active' => '1'];
    $formDenied = false;
    try {
        include __DIR__ . '/../smartassign/front/config.form.php';
    } catch (\Glpi\Exception\Http\AccessDeniedHttpException $e) {
        $formDenied = true;
    }
    if (!$formDenied) {
        fwrite(STDERR, "config.form.php no GLPI 12 deve lancar AccessDeniedHttpException em POST invalido\n");
        exit(1);
    }
    exit(0);
}

if (in_array('--glpi11-check', $argv ?? ($_SERVER['argv'] ?? []), true)) {
    putenv('SMARTASSIGN_TEST_TYPED_RIGHTNAME=0');
    putenv('SMARTASSIGN_TEST_GLPI_VERSION=11.0.0');
    require_once __DIR__ . '/bootstrap.php';

    if (PluginSmartassignCompat::glpiMajor() !== 11 || !PluginSmartassignCompat::isGlpi11OrHigher()) {
        fwrite(STDERR, "Esperado glpiMajor() === 11 e isGlpi11OrHigher() === true\n");
        exit(1);
    }
    if (PluginSmartassignCompat::typedRightname()) {
        fwrite(STDERR, "Esperado typedRightname() === false no GLPI 11\n");
        exit(1);
    }
    if (PluginSmartassignCompat::assetPath('css/smartassign.css') !== 'css/smartassign.css') {
        fwrite(STDERR, "Esperado assetPath sem prefixo public/ no GLPI 11\n");
        exit(1);
    }
    Plugin::$getWebDirCalled = false;
    $webPath = PluginSmartassignCompat::webPath();
    if ($webPath !== '/glpi/plugins/smartassign' || strpos($webPath, '/marketplace/') !== false || Plugin::$getWebDirCalled) {
        fwrite(STDERR, "webPath() no GLPI 11 deve retornar /plugins/smartassign sem chamar Plugin::getWebDir()\n");
        exit(1);
    }
    if (PluginSmartassignCompat::fromCore('DOMAIN\\user &amp; TI') !== 'DOMAIN\\user &amp; TI') {
        fwrite(STDERR, "fromCore() no GLPI 11 nao deve alterar texto\n");
        exit(1);
    }
    Html::$rightErrorShown = false;
    PluginSmartassignCompat::denyAccess();
    if (!Html::$rightErrorShown) {
        fwrite(STDERR, "denyAccess() no GLPI 11 deve acionar Html::displayRightError()\n");
        exit(1);
    }
    exit(0);
}

require_once __DIR__ . '/bootstrap.php';

/**
 * @param mixed $expected
 * @param mixed $actual
 */
function assertSameValue($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $detail = ($message !== '' ? $message . ': ' : '')
            . 'esperado ' . var_export($expected, true)
            . ', obtido ' . var_export($actual, true);
        throw new RuntimeException($detail);
    }
}

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function seedUser(
    int $id,
    int $active = 1,
    int $deleted = 0,
    ?string $beginDate = null,
    ?string $endDate = null,
    string $name = ''
): void {
    global $DB;

    $DB->insert('glpi_users', [
        'id'         => $id,
        'name'       => $name !== '' ? $name : ('user' . $id),
        'firstname'  => 'Tech',
        'realname'   => (string) $id,
        'is_active'  => $active,
        'is_deleted' => $deleted,
        'begin_date' => $beginDate,
        'end_date'   => $endDate,
    ]);
}

function seedGroupMember(int $groupsId, int $usersId): void
{
    global $DB;

    $DB->insert('glpi_groups_users', [
        'groups_id' => $groupsId,
        'users_id'  => $usersId,
    ]);
}

function seedTicket(
    int $id,
    int $entitiesId = 0,
    int $status = 1,
    int $isDeleted = 0,
    int $isTemplate = 0,
    string $name = ''
): Ticket {
    global $DB;

    $DB->insert('glpi_tickets', [
        'id'          => $id,
        'entities_id' => $entitiesId,
        'status'      => $status,
        'is_deleted'  => $isDeleted,
        'is_template' => $isTemplate,
        'name'        => $name !== '' ? $name : ('Ticket #' . $id),
    ]);

    $ticket = new Ticket();
    $ticket->getFromDB($id);
    return $ticket;
}

function resetEnvironment(): void
{
    global $DB, $PLUGIN_HOOKS;

    $DB->reset();
    $PLUGIN_HOOKS                  = [];
    $_SESSION['glpi_currenttime']  = '2026-11-16 14:39:02';
    Ticket_User::$failNextAdd      = false;
    Session::$allowedEntities      = null;
    Html::$rightErrorShown         = false;
    Html::$redirectUrl             = '';
    Plugin::$getWebDirCalled       = false;
    PluginSmartassignAssigner::resetRuntimeState();
    plugin_smartassign_install();
}

function test_round_robin_wraps_around_active_group_members_only(): void
{
    global $DB;

    resetEnvironment();

    seedUser(101, 1, 0, null, null);
    seedUser(102, 0, 0, null, null);
    seedUser(103, 1, 1, null, null);
    seedUser(104, 1, 0, '2035-01-01 00:00:00', null);
    seedUser(105, 1, 0, null, '2020-01-01 00:00:00');
    seedUser(106, 1, 0, '2020-01-01 00:00:00', '2035-01-01 00:00:00');
    seedUser(107, 1, 0, null, null);

    foreach ([101, 102, 103, 104, 105, 106, 107] as $uid) {
        seedGroupMember(10, $uid);
    }

    PluginSmartassignConfig::saveConfig(0, [
        'is_active' => 1,
        'mode'      => PluginSmartassignConfig::MODE_ROUND_ROBIN,
    ]);
    $cfg = PluginSmartassignConfig::resolveForEntity(0);

    assertSameValue(101, PluginSmartassignAssigner::pickCandidate(10, $cfg), '1a escolha RR');
    assertSameValue(106, PluginSmartassignAssigner::pickCandidate(10, $cfg), '2a escolha RR pula inativos/expirados');
    assertSameValue(107, PluginSmartassignAssigner::pickCandidate(10, $cfg), '3a escolha RR');
    assertSameValue(101, PluginSmartassignAssigner::pickCandidate(10, $cfg), '4a escolha RR volta ao inicio');

    $pointer = 0;
    foreach ($DB->request(['FROM' => PluginSmartassignConfig::STATE_TABLE, 'WHERE' => ['groups_id' => 10]]) as $row) {
        $pointer = (int) $row['last_users_id'];
    }
    assertSameValue(101, $pointer, 'Ponteiro persistido em state');
}

function test_least_loaded_counts_active_tickets_globally_across_entities(): void
{
    global $DB;

    resetEnvironment();

    seedUser(201);
    seedUser(202);
    seedUser(203);
    foreach ([201, 202, 203] as $uid) {
        seedGroupMember(20, $uid);
    }

    // 201: 2 abertos (entidades 1 e 2) + 1 solucionado + 1 fechado + 1 na lixeira = conta apenas 2
    seedTicket(1, 1, CommonITILObject::ASSIGNED, 0);
    seedTicket(2, 2, CommonITILObject::PLANNED, 0);
    seedTicket(3, 1, CommonITILObject::SOLVED, 0);
    seedTicket(4, 2, CommonITILObject::CLOSED, 0);
    seedTicket(5, 1, CommonITILObject::ASSIGNED, 1);
    foreach ([1, 2, 3, 4, 5] as $tid) {
        $DB->insert('glpi_tickets_users', ['tickets_id' => $tid, 'users_id' => 201, 'type' => 2]);
    }

    // 202: 1 aberto na entidade 2
    seedTicket(6, 2, CommonITILObject::INCOMING, 0);
    $DB->insert('glpi_tickets_users', ['tickets_id' => 6, 'users_id' => 202, 'type' => 2]);

    // 203: 0 abertos (apenas solucionado)
    seedTicket(7, 1, CommonITILObject::SOLVED, 0);
    $DB->insert('glpi_tickets_users', ['tickets_id' => 7, 'users_id' => 203, 'type' => 2]);

    PluginSmartassignConfig::saveConfig(0, [
        'is_active' => 1,
        'mode'      => PluginSmartassignConfig::MODE_LEAST_LOADED,
    ]);
    $cfg = PluginSmartassignConfig::resolveForEntity(0);

    assertSameValue(203, PluginSmartassignAssigner::pickCandidate(20, $cfg), '203 possui 0 chamados abertos');

    // Simula atribuicao do chamado em aberto para 203 -> empate (1 aberto) entre 202 e 203; ponteiro esta em 203 -> desempata em 202
    seedTicket(8, 1, CommonITILObject::ASSIGNED, 0);
    $DB->insert('glpi_tickets_users', ['tickets_id' => 8, 'users_id' => 203, 'type' => 2]);
    assertSameValue(202, PluginSmartassignAssigner::pickCandidate(20, $cfg), 'Empate em 1 aberto desempata em 202');

    // Da mais um chamado aberto a 202 -> 203 fica isolado com 1 aberto
    seedTicket(9, 2, CommonITILObject::WAITING, 0);
    $DB->insert('glpi_tickets_users', ['tickets_id' => 9, 'users_id' => 202, 'type' => 2]);
    assertSameValue(203, PluginSmartassignAssigner::pickCandidate(20, $cfg), '203 volta a ter menor fila global');
}

function test_respects_max_open_tickets_and_excluded_users(): void
{
    global $DB;

    resetEnvironment();

    seedUser(301);
    seedUser(302);
    seedUser(303);
    foreach ([301, 302, 303] as $uid) {
        seedGroupMember(30, $uid);
    }

    // 302 com 2 chamados abertos (atingiu limite 2)
    seedTicket(11, 0, CommonITILObject::ASSIGNED, 0);
    seedTicket(12, 0, CommonITILObject::ASSIGNED, 0);
    $DB->insert('glpi_tickets_users', ['tickets_id' => 11, 'users_id' => 302, 'type' => 2]);
    $DB->insert('glpi_tickets_users', ['tickets_id' => 12, 'users_id' => 302, 'type' => 2]);

    // 303 com 1 chamado aberto (abaixo do limite 2)
    seedTicket(13, 0, CommonITILObject::ASSIGNED, 0);
    $DB->insert('glpi_tickets_users', ['tickets_id' => 13, 'users_id' => 303, 'type' => 2]);

    PluginSmartassignConfig::saveConfig(0, [
        'is_active'        => 1,
        'mode'             => PluginSmartassignConfig::MODE_ROUND_ROBIN,
        'max_open_tickets' => 2,
        'excluded_users'   => [301],
    ]);
    $cfg = PluginSmartassignConfig::resolveForEntity(0);

    // 301 excluido por lista, 302 excluido por limite -> escolhe 303
    assertSameValue(303, PluginSmartassignAssigner::pickCandidate(30, $cfg), 'Ignora usuario excluido e no limite');

    // Atinge limite em 303 tambem -> ninguem disponivel
    seedTicket(14, 0, CommonITILObject::INCOMING, 0);
    $DB->insert('glpi_tickets_users', ['tickets_id' => 14, 'users_id' => 303, 'type' => 2]);
    assertSameValue(0, PluginSmartassignAssigner::pickCandidate(30, $cfg), 'Retorna 0 quando todos atingem limite');
}

function test_skip_if_assigned_prevents_overwrite_when_enabled(): void
{
    global $DB;

    resetEnvironment();

    seedUser(401);
    seedUser(402);
    seedUser(403);
    seedGroupMember(40, 401);
    seedGroupMember(40, 402);
    seedGroupMember(41, 403);

    PluginSmartassignConfig::saveConfig(0, [
        'is_active'              => 1,
        'mode'                   => PluginSmartassignConfig::MODE_ROUND_ROBIN,
        'assign_on_create'       => 1,
        'assign_on_group_change' => 1,
        'skip_if_assigned'       => 1,
    ]);

    // Chamado 1001 ja possui tecnico 999 atribuido
    seedTicket(1001, 0, CommonITILObject::ASSIGNED, 0);
    $DB->insert('glpi_tickets_users', ['tickets_id' => 1001, 'users_id' => 999, 'type' => 2]);

    $gt1 = new Group_Ticket();
    $gt1->add(['tickets_id' => 1001, 'groups_id' => 40, 'type' => 2]);
    PluginSmartassignAssigner::itemAddGroupTicket($gt1);

    $assigned1 = [];
    foreach ($DB->request(['FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => 1001, 'type' => 2]]) as $row) {
        $assigned1[] = (int) $row['users_id'];
    }
    assertSameValue([999], $assigned1, 'skip_if_assigned=1 nao sobrescreve nem adiciona');

    // Agora desabilita skip_if_assigned: chamado 1002 tem tecnico 403 (grupo 41); ao vincular grupo 40 deve adicionar 401 sem apagar 403
    PluginSmartassignConfig::saveConfig(0, [
        'is_active'              => 1,
        'mode'                   => PluginSmartassignConfig::MODE_ROUND_ROBIN,
        'assign_on_create'       => 1,
        'assign_on_group_change' => 1,
        'skip_if_assigned'       => 0,
    ]);

    seedTicket(1002, 0, CommonITILObject::ASSIGNED, 0);
    $DB->insert('glpi_tickets_users', ['tickets_id' => 1002, 'users_id' => 403, 'type' => 2]);

    $gt2 = new Group_Ticket();
    $gt2->add(['tickets_id' => 1002, 'groups_id' => 40, 'type' => 2]);
    PluginSmartassignAssigner::itemAddGroupTicket($gt2);

    $assigned2 = [];
    foreach ($DB->request(['FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => 1002, 'type' => 2], 'ORDER' => 'id ASC']) as $row) {
        $assigned2[] = (int) $row['users_id'];
    }
    assertSameValue([403, 401], $assigned2, 'skip_if_assigned=0 adiciona membro do grupo sem apagar existente');

    // Nova requisicao no mesmo chamado para o mesmo grupo 40 nao deve adicionar 402 porque 401 ja pertence ao grupo 40
    PluginSmartassignAssigner::resetRuntimeState();
    PluginSmartassignAssigner::itemAddGroupTicket($gt2);

    $assigned2After = [];
    foreach ($DB->request(['FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => 1002, 'type' => 2], 'ORDER' => 'id ASC']) as $row) {
        $assigned2After[] = (int) $row['users_id'];
    }
    assertSameValue([403, 401], $assigned2After, 'skip_if_assigned=0 pula quando membro do grupo alvo ja esta atribuido');
}

function test_assign_on_create_false_skips_business_rule_group_during_ticket_add(): void
{
    global $DB;

    resetEnvironment();

    seedUser(501);
    seedGroupMember(50, 501);

    PluginSmartassignConfig::saveConfig(0, [
        'is_active'              => 1,
        'mode'                   => PluginSmartassignConfig::MODE_ROUND_ROBIN,
        'assign_on_create'       => 0,
        'assign_on_group_change' => 1,
        'skip_if_assigned'       => 1,
    ]);

    $ticket        = seedTicket(2001, 0, CommonITILObject::INCOMING, 0);
    $ticket->input = ['entities_id' => 0, 'status' => CommonITILObject::INCOMING];

    // Ciclo de criacao: post_prepareadd -> Group_Ticket adicionado por regra de negocio -> Ticket item_add
    PluginSmartassignAssigner::postPrepareAddTicket($ticket);

    $gt = new Group_Ticket();
    $gt->add(['tickets_id' => 2001, 'groups_id' => 50, 'type' => 2]);
    PluginSmartassignAssigner::itemAddGroupTicket($gt);

    PluginSmartassignAssigner::itemAddTicket($ticket);

    $assigned = [];
    foreach ($DB->request(['FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => 2001, 'type' => 2]]) as $row) {
        $assigned[] = (int) $row['users_id'];
    }
    assertSameValue([], $assigned, 'assign_on_create=0 ignora grupo inserido por regra de negocio na criacao');

    // Alteracao posterior de grupo (fora do post_prepareadd) deve atribuir normalmente
    PluginSmartassignAssigner::itemAddGroupTicket($gt);
    $assignedLater = [];
    foreach ($DB->request(['FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => 2001, 'type' => 2]]) as $row) {
        $assignedLater[] = (int) $row['users_id'];
    }
    assertSameValue([501], $assignedLater, 'Alteracao posterior de grupo atribui quando assign_on_group_change=1');
}

function test_anti_reentrancy_prevents_double_assignment_in_same_request(): void
{
    global $DB;

    resetEnvironment();

    seedUser(601);
    seedUser(602);
    seedUser(603);
    seedGroupMember(60, 601);
    seedGroupMember(60, 602);
    seedGroupMember(61, 603);

    PluginSmartassignConfig::saveConfig(0, [
        'is_active'              => 1,
        'mode'                   => PluginSmartassignConfig::MODE_ROUND_ROBIN,
        'assign_on_create'       => 1,
        'assign_on_group_change' => 1,
        'skip_if_assigned'       => 0,
    ]);

    $ticket        = seedTicket(3001, 0, CommonITILObject::INCOMING, 0);
    $ticket->input = ['entities_id' => 0, '_groups_id_assign' => 60];

    PluginSmartassignAssigner::postPrepareAddTicket($ticket);

    $gt1 = new Group_Ticket();
    $gt1->add(['tickets_id' => 3001, 'groups_id' => 60, 'type' => 2]);
    PluginSmartassignAssigner::itemAddGroupTicket($gt1);

    // Segundo disparo para outro grupo e item_add do chamado na mesma requisicao
    $gt2 = new Group_Ticket();
    $gt2->add(['tickets_id' => 3001, 'groups_id' => 61, 'type' => 2]);
    PluginSmartassignAssigner::itemAddGroupTicket($gt2);
    PluginSmartassignAssigner::itemAddTicket($ticket);

    $assigned = [];
    foreach ($DB->request(['FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => 3001, 'type' => 2]]) as $row) {
        $assigned[] = (int) $row['users_id'];
    }
    assertSameValue([601], $assigned, 'Anti-reentrancia garante no maximo uma atribuicao por chamado na mesma requisicao');

    $logCount = 0;
    foreach ($DB->request(['FROM' => PluginSmartassignConfig::LOGS_TABLE, 'WHERE' => ['tickets_id' => 3001]]) as $row) {
        $logCount++;
    }
    assertSameValue(1, $logCount, 'Apenas 1 registro de log gravado');

    // Chamado excluido, modelo, solucionado e grupo requerente (type=1) devem ser ignorados
    seedTicket(3002, 0, CommonITILObject::INCOMING, 1, 0);
    seedTicket(3003, 0, CommonITILObject::INCOMING, 0, 1);
    seedTicket(3004, 0, CommonITILObject::SOLVED, 0, 0);
    seedTicket(3005, 0, CommonITILObject::INCOMING, 0, 0);

    foreach ([3002, 3003, 3004] as $skipTid) {
        $gtSkip = new Group_Ticket();
        $gtSkip->add(['tickets_id' => $skipTid, 'groups_id' => 60, 'type' => 2]);
        PluginSmartassignAssigner::itemAddGroupTicket($gtSkip);
    }

    $gtRequester = new Group_Ticket();
    $gtRequester->add(['tickets_id' => 3005, 'groups_id' => 60, 'type' => 1]);
    PluginSmartassignAssigner::itemAddGroupTicket($gtRequester);

    $extraAssigned = 0;
    foreach (
        $DB->request([
            'FROM'  => 'glpi_tickets_users',
            'WHERE' => ['tickets_id' => [3002, 3003, 3004, 3005]],
        ]) as $row
    ) {
        $extraAssigned++;
    }
    assertSameValue(0, $extraAssigned, 'Chamados invalidos ou grupos nao tecnicos nao recebem tecnico');
}

function test_entity_recursive_inheritance(): void
{
    global $DB;

    resetEnvironment();

    $DB->insert('glpi_entities', ['id' => 0, 'entities_id' => -1, 'name' => 'Root']);
    $DB->insert('glpi_entities', ['id' => 1, 'entities_id' => 0, 'name' => 'Matriz']);
    $DB->insert('glpi_entities', ['id' => 2, 'entities_id' => 1, 'name' => 'Filial A']);
    $DB->insert('glpi_entities', ['id' => 3, 'entities_id' => 2, 'name' => 'Sub-Filial A1']);
    $DB->insert('glpi_entities', ['id' => 4, 'entities_id' => 0, 'name' => 'Filial B']);
    $DB->insert('glpi_entities', ['id' => 5, 'entities_id' => 4, 'name' => 'Sub-Filial B1']);

    PluginSmartassignConfig::saveConfig(0, [
        'is_active'        => 1,
        'is_recursive'     => 1,
        'mode'             => PluginSmartassignConfig::MODE_LEAST_LOADED,
        'max_open_tickets' => 5,
    ]);
    PluginSmartassignConfig::saveConfig(1, [
        'is_active'        => 1,
        'is_recursive'     => 1,
        'mode'             => PluginSmartassignConfig::MODE_ROUND_ROBIN,
        'max_open_tickets' => 3,
    ]);
    PluginSmartassignConfig::saveConfig(2, [
        'is_active'        => 1,
        'is_recursive'     => 0,
        'mode'             => PluginSmartassignConfig::MODE_LEAST_LOADED,
        'max_open_tickets' => 10,
    ]);

    $cfgFilialA = PluginSmartassignConfig::resolveForEntity(2);
    assertSameValue(false, $cfgFilialA['inherited'], 'Filial A usa regra propria');
    assertSameValue(2, $cfgFilialA['source_entities_id']);
    assertSameValue(10, $cfgFilialA['max_open_tickets']);

    // Sub-Filial A1: pai imediato (2) tem is_recursive=0, entao sobe para Matriz (1) que tem is_recursive=1
    $cfgSubA1 = PluginSmartassignConfig::resolveForEntity(3);
    assertSameValue(true, $cfgSubA1['inherited'], 'Sub-Filial A1 herda ancestral recursivo mais proximo (1)');
    assertSameValue(1, $cfgSubA1['source_entities_id']);
    assertSameValue(PluginSmartassignConfig::MODE_ROUND_ROBIN, $cfgSubA1['mode']);
    assertSameValue(3, $cfgSubA1['max_open_tickets']);

    // Sub-Filial B1: pai (4) nao tem config -> herda da raiz (0)
    $cfgSubB1 = PluginSmartassignConfig::resolveForEntity(5);
    assertSameValue(true, $cfgSubB1['inherited']);
    assertSameValue(0, $cfgSubB1['source_entities_id']);
    assertSameValue(PluginSmartassignConfig::MODE_LEAST_LOADED, $cfgSubB1['mode']);
    assertSameValue(5, $cfgSubB1['max_open_tickets']);

    // Sobrescrita na filha B1 prevalece, e remocao restaura heranca
    PluginSmartassignConfig::saveConfig(5, [
        'is_active' => 0,
        'mode'      => PluginSmartassignConfig::MODE_ROUND_ROBIN,
    ]);
    $cfgSubB1Own = PluginSmartassignConfig::resolveForEntity(5);
    assertSameValue(false, $cfgSubB1Own['inherited']);
    assertSameValue(0, $cfgSubB1Own['is_active']);

    PluginSmartassignConfig::deleteConfig(5);
    $cfgSubB1Restored = PluginSmartassignConfig::resolveForEntity(5);
    assertSameValue(true, $cfgSubB1Restored['inherited']);
    assertSameValue(0, $cfgSubB1Restored['source_entities_id']);
}

function test_actors_assign_and_ruleticket_users_respected_by_skip_if_assigned(): void
{
    global $DB;

    resetEnvironment();

    seedUser(801);
    seedUser(802);
    seedGroupMember(80, 801);
    seedGroupMember(80, 802);

    PluginSmartassignConfig::saveConfig(0, [
        'is_active'        => 1,
        'mode'             => PluginSmartassignConfig::MODE_ROUND_ROBIN,
        'assign_on_create' => 1,
        'skip_if_assigned' => 1,
    ]);

    // 1) Web UI _actors['assign'] com User explicito deve bloquear atribuicao automatica durante criacao
    $tActors        = seedTicket(8001, 0, CommonITILObject::INCOMING, 0);
    $tActors->input = [
        'entities_id' => 0,
        '_actors'     => [
            'assign' => [
                ['itemtype' => 'User', 'items_id' => 801],
                ['itemtype' => 'Group', 'items_id' => 80],
            ],
        ],
    ];
    PluginSmartassignAssigner::postPrepareAddTicket($tActors);

    $gt1 = new Group_Ticket();
    $gt1->add(['tickets_id' => 8001, 'groups_id' => 80, 'type' => 2]);
    PluginSmartassignAssigner::itemAddGroupTicket($gt1);
    PluginSmartassignAssigner::itemAddTicket($tActors);

    $assigned1 = [];
    foreach ($DB->request(['FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => 8001]]) as $row) {
        $assigned1[] = (int) $row['users_id'];
    }
    assertSameValue([], $assigned1, '_actors[assign] com User respeitado por skip_if_assigned=1');

    // 2) RuleTicket injetou _users_id_assign antes de post_prepareadd
    $tRule        = seedTicket(8002, 0, CommonITILObject::INCOMING, 0);
    $tRule->input = [
        'entities_id'      => 0,
        '_users_id_assign' => [802],
        '_groups_id_assign' => [80],
    ];
    PluginSmartassignAssigner::postPrepareAddTicket($tRule);

    $gt2 = new Group_Ticket();
    $gt2->add(['tickets_id' => 8002, 'groups_id' => 80, 'type' => 2]);
    PluginSmartassignAssigner::itemAddGroupTicket($gt2);
    PluginSmartassignAssigner::itemAddTicket($tRule);

    $assigned2 = [];
    foreach ($DB->request(['FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => 8002]]) as $row) {
        $assigned2[] = (int) $row['users_id'];
    }
    assertSameValue([], $assigned2, '_users_id_assign de RuleTicket respeitado por skip_if_assigned=1');

    // 3) _actors['assign'] contendo apenas Group deve atribuir via itemAddTicket
    $tGroupOnly        = seedTicket(8003, 0, CommonITILObject::INCOMING, 0);
    $tGroupOnly->input = [
        'entities_id' => 0,
        '_actors'     => [
            'assign' => [
                ['itemtype' => 'Group', 'items_id' => 80],
            ],
        ],
    ];
    PluginSmartassignAssigner::postPrepareAddTicket($tGroupOnly);
    PluginSmartassignAssigner::itemAddTicket($tGroupOnly);

    $assigned3 = [];
    foreach ($DB->request(['FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => 8003, 'type' => 2]]) as $row) {
        $assigned3[] = (int) $row['users_id'];
    }
    assertSameValue([801], $assigned3, '_actors[assign] com apenas Group atribui candidato');
}

function test_aborted_ticket_add_does_not_leak_into_later_group_ticket(): void
{
    global $DB;

    resetEnvironment();

    seedUser(811);
    seedGroupMember(81, 811);

    PluginSmartassignConfig::saveConfig(0, [
        'is_active'              => 1,
        'mode'                   => PluginSmartassignConfig::MODE_ROUND_ROBIN,
        'assign_on_create'       => 0,
        'assign_on_group_change' => 1,
        'skip_if_assigned'       => 1,
    ]);

    // Caso A: outro hook abortou definindo $ticket->input = false depois de post_prepareadd
    $tAbortInput        = new Ticket();
    $tAbortInput->input = ['entities_id' => 0];
    PluginSmartassignAssigner::postPrepareAddTicket($tAbortInput);
    $tAbortInput->input = false;

    // Caso B: addToDB falhou (objeto segue vivo sem fields['id'])
    $tAbortDb        = new Ticket();
    $tAbortDb->input = ['entities_id' => 0];
    PluginSmartassignAssigner::postPrepareAddTicket($tAbortDb);

    // Caso C: objeto coletado pelo GC apos sair de escopo
    $tAbortGc        = new Ticket();
    $tAbortGc->input = ['entities_id' => 0];
    PluginSmartassignAssigner::postPrepareAddTicket($tAbortGc);
    unset($tAbortGc);

    // Agora um chamado existente recebe grupo tecnico -> NAO pode ser confundido com criacao
    seedTicket(8004, 0, CommonITILObject::INCOMING, 0);
    $gt = new Group_Ticket();
    $gt->add(['tickets_id' => 8004, 'groups_id' => 81, 'type' => 2]);
    PluginSmartassignAssigner::itemAddGroupTicket($gt);

    $assigned = [];
    foreach ($DB->request(['FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => 8004, 'type' => 2]]) as $row) {
        $assigned[] = (int) $row['users_id'];
    }
    assertSameValue([811], $assigned, 'Criacoes abortadas nao vazam estado para Group_Ticket posterior');

    $reasons = [];
    foreach ($DB->request(['FROM' => PluginSmartassignConfig::LOGS_TABLE, 'WHERE' => ['tickets_id' => 8004]]) as $row) {
        $reasons[] = (string) $row['reason'];
    }
    assertSameValue(['group_assign'], $reasons, 'Log deve registrar group_assign em chamado existente');
}

function test_failed_ticket_user_add_does_not_advance_pointer_and_handles_duplicate_state(): void
{
    global $DB;

    resetEnvironment();

    seedUser(901);
    seedUser(902);
    seedGroupMember(90, 901);
    seedGroupMember(90, 902);

    PluginSmartassignConfig::saveConfig(0, [
        'is_active'              => 1,
        'mode'                   => PluginSmartassignConfig::MODE_ROUND_ROBIN,
        'assign_on_create'       => 1,
        'assign_on_group_change' => 1,
        'skip_if_assigned'       => 1,
    ]);

    // 1) Falha em Ticket_User::add() nao deve gravar ponteiro nem log
    seedTicket(9001, 0, CommonITILObject::INCOMING, 0);
    Ticket_User::$failNextAdd = true;

    $gt1 = new Group_Ticket();
    $gt1->add(['tickets_id' => 9001, 'groups_id' => 90, 'type' => 2]);
    PluginSmartassignAssigner::itemAddGroupTicket($gt1);

    $stateCount = 0;
    foreach ($DB->request(['FROM' => PluginSmartassignConfig::STATE_TABLE, 'WHERE' => ['groups_id' => 90]]) as $row) {
        $stateCount++;
    }
    assertSameValue(0, $stateCount, 'Ponteiro nao avanca quando Ticket_User::add falha');

    $logCount = 0;
    foreach ($DB->request(['FROM' => PluginSmartassignConfig::LOGS_TABLE, 'WHERE' => ['tickets_id' => 9001]]) as $row) {
        $logCount++;
    }
    assertSameValue(0, $logCount, 'Log nao e gravado quando Ticket_User::add falha');

    // 2) Proxima atribuicao valida ainda escolhe 901
    seedTicket(9002, 0, CommonITILObject::INCOMING, 0);

    $gt2 = new Group_Ticket();
    $gt2->add(['tickets_id' => 9002, 'groups_id' => 90, 'type' => 2]);
    PluginSmartassignAssigner::itemAddGroupTicket($gt2);

    $assigned2 = [];
    foreach ($DB->request(['FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => 9002, 'type' => 2]]) as $row) {
        $assigned2[] = (int) $row['users_id'];
    }
    assertSameValue([901], $assigned2, 'Apos falha anterior, primeiro candidato 901 e mantido');

    // 3) Simula colisao concorrente em updateGroupPointer (excecao 1062 e retorno false + errno 1062)
    $DB->delete(PluginSmartassignConfig::STATE_TABLE, ['groups_id' => 91]);
    $DB->delete(PluginSmartassignConfig::STATE_TABLE, ['groups_id' => 92]);
    $rm = new ReflectionMethod('PluginSmartassignAssigner', 'updateGroupPointer');
    $rm->setAccessible(true);

    $DB->simulateDuplicateThrowOn = PluginSmartassignConfig::STATE_TABLE;
    try {
        $rm->invoke(null, 91, 901);
        $threwUnexpected = false;
    } catch (Throwable $e) {
        $threwUnexpected = true;
    }
    assertSameValue(false, $threwUnexpected, 'updateGroupPointer absorve excecao 1062 e faz retry via update');
    $ptr91 = 0;
    foreach ($DB->request(['FROM' => PluginSmartassignConfig::STATE_TABLE, 'WHERE' => ['groups_id' => 91]]) as $row) {
        $ptr91 = (int) $row['last_users_id'];
    }
    assertSameValue(901, $ptr91, 'updateGroupPointer atualiza ponteiro apos excecao 1062');

    $DB->simulateDuplicateFalseOn = PluginSmartassignConfig::STATE_TABLE;
    $rm->invoke(null, 92, 902);
    $ptr92 = 0;
    foreach ($DB->request(['FROM' => PluginSmartassignConfig::STATE_TABLE, 'WHERE' => ['groups_id' => 92]]) as $row) {
        $ptr92 = (int) $row['last_users_id'];
    }
    assertSameValue(902, $ptr92, 'updateGroupPointer atualiza ponteiro quando insert falha por chave duplicada');
}

function test_compat_typed_and_untyped_rightname_and_hooks(): void
{
    global $PLUGIN_HOOKS, $DB;

    resetEnvironment();

    assertSameValue(false, PluginSmartassignCompat::typedRightname(), 'GLPI 10/11 possui $rightname sem tipo');
    $rp = new ReflectionProperty('PluginSmartassignConfig', 'rightname');
    assertSameValue(false, $rp->hasType(), 'PluginSmartassignConfig::$rightname acompanha CommonGLPI sem tipo');
    assertSameValue('config', PluginSmartassignConfig::$rightname);

    // webPath no GLPI 10 usa Plugin::getWebDir; fromCore preserva barras invertidas literais; dbValues/coreInput sanitizam
    Plugin::$getWebDirCalled = false;
    assertSameValue('/glpi/plugins/smartassign', PluginSmartassignCompat::webPath());
    assertSameValue(true, Plugin::$getWebDirCalled, 'GLPI 10 usa Plugin::getWebDir() em webPath()');
    assertSameValue('DOMAIN\\tech & N1', PluginSmartassignCompat::fromCore('DOMAIN\\tech &amp; N1'), 'fromCore preserva barras invertidas');
    $escaped = PluginSmartassignCompat::dbValues(['note' => "O'Brien"]);
    assertSameValue("O\\'Brien", $escaped['note'], 'dbValues escapa aspas no GLPI 10');
    $sanitized = PluginSmartassignCompat::coreInput(['txt' => 'A & B']);
    assertSameValue('A &amp; B', $sanitized['txt'], 'coreInput sanitiza entrada no GLPI 10');

    Html::$rightErrorShown = false;
    PluginSmartassignCompat::denyAccess();
    assertSameValue(true, Html::$rightErrorShown, 'denyAccess() aciona Html::displayRightError() no GLPI 10');
    Html::$rightErrorShown = false;

    plugin_init_smartassign();
    assertSameValue(true, $PLUGIN_HOOKS['csrf_compliant']['smartassign'] ?? false);
    assertSameValue('front/config.form.php', $PLUGIN_HOOKS['config_page']['smartassign'] ?? '');
    assertSameValue(['public/css/smartassign.css'], $PLUGIN_HOOKS['add_css']['smartassign'] ?? []);
    assertSameValue(
        [PluginSmartassignAssigner::class, 'postPrepareAddTicket'],
        $PLUGIN_HOOKS['post_prepareadd']['smartassign']['Ticket'] ?? null
    );
    assertSameValue(
        [PluginSmartassignAssigner::class, 'itemAddTicket'],
        $PLUGIN_HOOKS['item_add']['smartassign']['Ticket'] ?? null
    );
    assertSameValue(
        [PluginSmartassignAssigner::class, 'itemAddGroupTicket'],
        $PLUGIN_HOOKS['item_add']['smartassign']['Group_Ticket'] ?? null
    );

    if (function_exists('exec')) {
        $cmd11    = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --glpi11-check 2>&1';
        $output11 = [];
        $code11   = 0;
        exec($cmd11, $output11, $code11);
        assertSameValue(0, $code11, 'Subprocesso GLPI 11 falhou: ' . implode("\n", $output11));

        $cmd12    = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --typed-rightname-check 2>&1';
        $output12 = [];
        $code12   = 0;
        exec($cmd12, $output12, $code12);
        assertSameValue(0, $code12, 'Subprocesso GLPI 12 com $rightname tipado falhou: ' . implode("\n", $output12));
    }

    // Validacao de requireSafePost()
    $origServer  = $_SERVER;
    $origPost    = $_POST;
    $origRequest = $_REQUEST;
    $origSession = $_SESSION;
    try {
        $_SERVER = ['REQUEST_METHOD' => 'GET'];
        assertSameValue(false, PluginSmartassignCompat::requireSafePost(), 'GET rejeitado em requireSafePost');
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_SEC_FETCH_SITE' => 'cross-site'];
        assertSameValue(false, PluginSmartassignCompat::requireSafePost(), 'cross-site rejeitado');
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];
        assertSameValue(true, PluginSmartassignCompat::requireSafePost(), 'same-origin aceito');
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'HTTP_HOST'      => 'glpi.example.test',
            'HTTP_ORIGIN'    => 'https://glpi.example.test',
        ];
        assertSameValue(true, PluginSmartassignCompat::requireSafePost(), 'Origin equivalente ao Host aceito');
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'HTTP_HOST'      => 'glpi.example.test',
            'HTTP_ORIGIN'    => 'https://evil.example.net',
        ];
        assertSameValue(false, PluginSmartassignCompat::requireSafePost(), 'Origin divergente rejeitado');

        // Fail-closed em front/config.form.php para POST entities_id invalido e GET sem acesso
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];
        $_POST   = ['entities_id' => 'abc', 'is_active' => '1'];
        Html::$rightErrorShown = false;
        include __DIR__ . '/../smartassign/front/config.form.php';
        assertSameValue(true, Html::$rightErrorShown, 'POST entities_id=abc falha fechado com displayRightError');
        assertSameValue(null, PluginSmartassignConfig::getOwnConfig(0), 'POST entities_id=abc nao grava na entidade 0');

        $_POST = ['entities_id' => '-1', 'is_active' => '1'];
        Html::$rightErrorShown = false;
        include __DIR__ . '/../smartassign/front/config.form.php';
        assertSameValue(true, Html::$rightErrorShown, 'POST entities_id=-1 falha fechado');

        Session::$allowedEntities = [0];
        $_POST = ['entities_id' => '99', 'is_active' => '1'];
        Html::$rightErrorShown = false;
        include __DIR__ . '/../smartassign/front/config.form.php';
        assertSameValue(true, Html::$rightErrorShown, 'POST entities_id sem acesso falha fechado');

        $_SERVER = ['REQUEST_METHOD' => 'GET'];
        $_POST   = [];
        $_REQUEST = [];
        $_SESSION = ['glpi_currenttime' => '2026-11-16 14:39:02'];
        Session::$allowedEntities = [];
        Html::$rightErrorShown = false;
        include __DIR__ . '/../smartassign/front/config.form.php';
        assertSameValue(true, Html::$rightErrorShown, 'GET sem entidade acessivel falha fechado');
    } finally {
        $_SERVER                  = $origServer;
        $_POST                    = $origPost;
        $_REQUEST                 = $origRequest;
        $_SESSION                 = $origSession;
        Session::$allowedEntities = null;
        Html::$rightErrorShown    = false;
    }

    // Logs recentes e limpeza em item_purge
    seedUser(701);
    seedGroupMember(70, 701);
    $DB->insert('glpi_groups', ['id' => 70, 'name' => 'N1', 'completename' => 'Suporte &amp; N1']);
    PluginSmartassignConfig::saveConfig(0, ['is_active' => 1, 'assign_on_create' => 1]);
    $t700        = seedTicket(7001, 0, CommonITILObject::INCOMING, 0, 0, 'Impressora offline');
    $t700->input = ['_groups_id_assign' => 70];
    PluginSmartassignAssigner::itemAddTicket($t700);
    $logs = PluginSmartassignConfig::getRecentLogs(25, true);
    assertSameValue(1, count($logs), 'getRecentLogs retorna 1 log');
    assertSameValue(701, $logs[0]['users_id']);
    assertSameValue('Suporte & N1', $logs[0]['group_name']);

    PluginSmartassignAssigner::itemPurgeTicket($t700);
    assertSameValue([], PluginSmartassignConfig::getRecentLogs(25, true), 'itemPurgeTicket remove log');

    $grp70         = new Group();
    $grp70->fields = ['id' => 70];
    PluginSmartassignAssigner::itemPurgeGroup($grp70);
    $stateRows = 0;
    foreach ($DB->request(['FROM' => PluginSmartassignConfig::STATE_TABLE, 'WHERE' => ['groups_id' => 70]]) as $r) {
        $stateRows++;
    }
    assertSameValue(0, $stateRows, 'itemPurgeGroup remove ponteiro');

    assertTrueValue(plugin_smartassign_uninstall(), 'Desinstalacao limpa');
}

$tests = [
    'test_round_robin_wraps_around_active_group_members_only',
    'test_least_loaded_counts_active_tickets_globally_across_entities',
    'test_respects_max_open_tickets_and_excluded_users',
    'test_skip_if_assigned_prevents_overwrite_when_enabled',
    'test_assign_on_create_false_skips_business_rule_group_during_ticket_add',
    'test_anti_reentrancy_prevents_double_assignment_in_same_request',
    'test_entity_recursive_inheritance',
    'test_actors_assign_and_ruleticket_users_respected_by_skip_if_assigned',
    'test_aborted_ticket_add_does_not_leak_into_later_group_ticket',
    'test_failed_ticket_user_add_does_not_advance_pointer_and_handles_duplicate_state',
    'test_compat_typed_and_untyped_rightname_and_hooks',
];

$passed = 0;
foreach ($tests as $fn) {
    $fn();
    $passed++;
    echo "[PASS] {$fn}\n";
}

echo "\nTotal: {$passed}/" . count($tests) . " testes aprovados.\n";
