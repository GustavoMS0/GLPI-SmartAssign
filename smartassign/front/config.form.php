<?php

/**
 * ------------------------------------------------------------------------
 * Smart Assign - Tela de configuração (Configurar > Plugins > Smart Assign)
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
    include_once __DIR__ . '/../../../inc/includes.php';
}

require_once __DIR__ . '/../inc/compat.class.php';
require_once __DIR__ . '/../inc/config.class.php';
require_once __DIR__ . '/../inc/assigner.class.php';

/** @var array $CFG_GLPI */
/** @var DBmysql $DB */
global $CFG_GLPI, $DB;

$isPost = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');
if ($isPost) {
    Session::checkRight('config', UPDATE);
    if (!PluginSmartassignCompat::requireSafePost()) {
        if (class_exists('Html') && method_exists('Html', 'displayErrorAndDie')) {
            Html::displayErrorAndDie(__('Origem da requisição não permitida.', 'smartassign'), true);
        } else {
            PluginSmartassignCompat::denyAccess();
        }
        return;
    }
} else {
    Session::checkRight('config', READ);
}

$canUpdate = Session::haveRight('config', UPDATE);
$self      = PluginSmartassignCompat::webPath() . '/front/config.form.php';
$e         = static function ($val): string {
    return htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8');
};

$defaultEntity = Session::haveAccessToEntity(0)
    ? 0
    : (int) ($_SESSION['glpiactive_entity'] ?? -1);

if ($isPost) {
    $postEid = filter_var(
        $_POST['entities_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]]
    );
    if ($postEid === false || !Session::haveAccessToEntity((int) $postEid)) {
        PluginSmartassignCompat::denyAccess();
        return;
    }
    $eid = (int) $postEid;

    if (isset($_POST['delete_override'])) {
        PluginSmartassignConfig::deleteConfig($eid);
        Session::addMessageAfterRedirect(
            __('Configuração própria removida. A entidade volta a seguir a herança.', 'smartassign'),
            true,
            INFO
        );
    } else {
        if (PluginSmartassignConfig::saveConfig($eid, $_POST)) {
            Session::addMessageAfterRedirect(
                __('Configuração do Smart Assign salva com sucesso.', 'smartassign'),
                true,
                INFO
            );
        } else {
            Session::addMessageAfterRedirect(
                __('Não foi possível salvar a configuração do Smart Assign.', 'smartassign'),
                false,
                ERROR
            );
        }
    }

    Html::redirect($self . '?entities_id=' . $eid);
    return;
}

$eid = $defaultEntity;
if (isset($_REQUEST['entities_id'])) {
    $reqEid = filter_var(
        $_REQUEST['entities_id'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]]
    );
    if ($reqEid !== false && Session::haveAccessToEntity((int) $reqEid)) {
        $eid = (int) $reqEid;
    }
}

if ($eid < 0 || !Session::haveAccessToEntity($eid)) {
    PluginSmartassignCompat::denyAccess();
    return;
}

$own        = PluginSmartassignConfig::getOwnConfig($eid);
$effective  = PluginSmartassignConfig::resolveForEntity($eid);
$entityName = PluginSmartassignCompat::fromCore((string) Dropdown::getDropdownName('glpi_entities', $eid));
if ($entityName === '' || $entityName === '&nbsp;') {
    $entityName = '#' . $eid;
}

// Carrega usuários ativos para o seletor de exclusão
$usersList = [];
foreach (
    $DB->request([
        'SELECT' => ['id', 'name', 'realname', 'firstname'],
        'FROM'   => 'glpi_users',
        'WHERE'  => [
            'is_active'  => 1,
            'is_deleted' => 0,
        ],
        'ORDER'  => ['realname ASC', 'firstname ASC', 'name ASC'],
        'LIMIT'  => 1000,
    ]) as $urow
) {
    $uid   = (int) $urow['id'];
    $login = PluginSmartassignCompat::fromCore((string) ($urow['name'] ?? ''));
    $full  = trim(
        PluginSmartassignCompat::fromCore((string) ($urow['firstname'] ?? ''))
        . ' '
        . PluginSmartassignCompat::fromCore((string) ($urow['realname'] ?? ''))
    );
    $label = $full !== '' ? ($full . ' (' . $login . ')') : $login;
    $usersList[$uid] = $label;
}

// Garante exibição de usuários já excluídos sem rotular usuários ativos além da 1000ª linha como inativos
$missingExcluded = array_diff($effective['excluded_users'], array_keys($usersList));
if ($missingExcluded !== []) {
    foreach (
        $DB->request([
            'SELECT' => ['id', 'name', 'realname', 'firstname', 'is_active', 'is_deleted'],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['id' => array_values($missingExcluded)],
        ]) as $urow
    ) {
        $uid   = (int) $urow['id'];
        $login = PluginSmartassignCompat::fromCore((string) ($urow['name'] ?? ''));
        $full  = trim(
            PluginSmartassignCompat::fromCore((string) ($urow['firstname'] ?? ''))
            . ' '
            . PluginSmartassignCompat::fromCore((string) ($urow['realname'] ?? ''))
        );
        $base            = $full !== '' ? ($full . ' (' . $login . ')') : $login;
        $isActiveUser    = !empty($urow['is_active']) && empty($urow['is_deleted']);
        $usersList[$uid] = $isActiveUser ? $base : ($base . ' [' . __('inativo', 'smartassign') . ']');
    }
}

$excludedMap = array_flip($effective['excluded_users']);
$logs        = PluginSmartassignConfig::getRecentLogs(25, true);
$disabled    = $canUpdate ? '' : ' disabled';

Html::header(__('Smart Assign', 'smartassign'), '', 'config', 'plugins');

echo '<div class="smartassign-container">';

// Cartão de seleção de entidade
echo '<div class="smartassign-card">';
echo '<div class="smartassign-header">';
echo '<div>';
echo '<h2 class="smartassign-title"><i class="ti ti-arrows-shuffle"></i> ' . $e(__('Smart Assign — Distribuição automática de chamados', 'smartassign')) . '</h2>';
echo '<p class="smartassign-subtitle">' . $e(__('Configure a estratégia de distribuição por entidade. Entidades filhas herdam configurações marcadas como recursivas até definirem regra própria.', 'smartassign')) . '</p>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="' . $e($self) . '" class="smartassign-entity-picker">';
echo '<div class="smartassign-entity-field">';
echo '<span class="form-label mb-1 d-block">' . $e(__('Entidade', 'smartassign')) . '</span>';
Entity::dropdown([
    'name'      => 'entities_id',
    'value'     => $eid,
    'entity'    => $_SESSION['glpiactiveentities'] ?? 0,
    'on_change' => 'this.form.submit()',
    'width'     => '100%',
]);
echo '</div>';
echo '<button type="submit" class="btn btn-outline-primary"><i class="ti ti-arrow-right"></i> ' . $e(__('Abrir', 'smartassign')) . '</button>';
echo '</form>';

if ($own !== null) {
    echo '<div class="alert alert-info smartassign-status-banner mt-3 mb-0">';
    echo '<div><i class="ti ti-building"></i> ' . sprintf(
        $e(__('Configuração própria da entidade %s.', 'smartassign')),
        '<strong>' . $e($entityName) . '</strong>'
    ) . '</div>';
    if ($canUpdate) {
        echo '<form method="post" action="' . $e($self) . '" class="m-0">';
        echo '<input type="hidden" name="entities_id" value="' . (int) $eid . '">';
        echo '<button type="submit" name="delete_override" value="1" class="btn btn-sm btn-outline-danger">'
            . '<i class="ti ti-arrow-back-up"></i> ' . $e(__('Restaurar herança', 'smartassign')) . '</button>';
        Html::closeForm();
    }
    echo '</div>';
} elseif (!empty($effective['inherited']) && $effective['source_entities_id'] !== null) {
    $sourceName = PluginSmartassignCompat::fromCore(
        (string) Dropdown::getDropdownName('glpi_entities', (int) $effective['source_entities_id'])
    );
    echo '<div class="alert alert-secondary mt-3 mb-0"><i class="ti ti-hierarchy-2"></i> '
        . sprintf(
            $e(__('A entidade %1$s herda a configuração recursiva de %2$s. Salvar abaixo cria uma regra específica para ela.', 'smartassign')),
            '<strong>' . $e($entityName) . '</strong>',
            '<strong>' . $e($sourceName) . '</strong>'
        ) . '</div>';
} else {
    echo '<div class="alert alert-secondary mt-3 mb-0"><i class="ti ti-info-circle"></i> '
        . sprintf(
            $e(__('A entidade %s ainda não possui configuração própria nem ancestral recursivo. Ajuste e salve abaixo para ativar.', 'smartassign')),
            '<strong>' . $e($entityName) . '</strong>'
        ) . '</div>';
}
echo '</div>';

if (!$canUpdate) {
    echo '<div class="alert alert-warning mb-3"><i class="ti ti-lock"></i> '
        . $e(__('Você possui permissão somente leitura em configuração. As alterações estão bloqueadas.', 'smartassign')) . '</div>';
}

// Formulário principal de configuração
echo '<form method="post" action="' . $e($self) . '" class="smartassign-card">';
echo '<input type="hidden" name="entities_id" value="' . (int) $eid . '">';

echo '<h3 class="smartassign-section-title"><i class="ti ti-toggle-right"></i> ' . $e(__('Ativação e escopo', 'smartassign')) . '</h3>';
echo '<div class="smartassign-switches mb-4">';

echo '<label class="form-check form-switch smartassign-switch-box">';
echo '<input type="hidden" name="is_active" value="0">';
echo '<input class="form-check-input" type="checkbox" role="switch" name="is_active" value="1"'
    . (!empty($effective['is_active']) ? ' checked' : '') . $disabled . '>';
echo '<span class="form-check-label"><strong>' . $e(__('Ativar Smart Assign nesta entidade', 'smartassign')) . '</strong>'
    . '<small class="smartassign-hint">' . $e(__('Quando desativado, nenhum chamado desta entidade é atribuído automaticamente.', 'smartassign')) . '</small></span>';
echo '</label>';

echo '<label class="form-check form-switch smartassign-switch-box">';
echo '<input type="hidden" name="is_recursive" value="0">';
echo '<input class="form-check-input" type="checkbox" role="switch" name="is_recursive" value="1"'
    . (!empty($effective['is_recursive']) ? ' checked' : '') . $disabled . '>';
echo '<span class="form-check-label"><strong>' . $e(__('Aplicar às entidades filhas (recursivo)', 'smartassign')) . '</strong>'
    . '<small class="smartassign-hint">' . $e(__('Subentidades sem regra própria passam a usar esta configuração.', 'smartassign')) . '</small></span>';
echo '</label>';

echo '</div>';

// Modo de distribuição
echo '<h3 class="smartassign-section-title"><i class="ti ti-scale"></i> ' . $e(__('Modo de distribuição', 'smartassign')) . '</h3>';
echo '<div class="smartassign-mode-grid mb-4">';

$isRoundRobin  = ($effective['mode'] === PluginSmartassignConfig::MODE_ROUND_ROBIN);
$isLeastLoaded = ($effective['mode'] === PluginSmartassignConfig::MODE_LEAST_LOADED);

echo '<label class="smartassign-mode-option' . ($isRoundRobin ? ' is-selected' : '') . '">';
echo '<input class="form-check-input" type="radio" name="mode" value="' . $e(PluginSmartassignConfig::MODE_ROUND_ROBIN) . '"'
    . ($isRoundRobin ? ' checked' : '') . $disabled . '>';
echo '<div>';
echo '<div class="smartassign-mode-name"><i class="ti ti-refresh"></i> ' . $e(__('Rodízio', 'smartassign')) . '</div>';
echo '<div class="smartassign-hint">' . $e(__('Alterna sequencialmente entre os técnicos ativos do grupo, avançando a partir do último técnico que recebeu chamado.', 'smartassign')) . '</div>';
echo '</div>';
echo '</label>';

echo '<label class="smartassign-mode-option' . ($isLeastLoaded ? ' is-selected' : '') . '">';
echo '<input class="form-check-input" type="radio" name="mode" value="' . $e(PluginSmartassignConfig::MODE_LEAST_LOADED) . '"'
    . ($isLeastLoaded ? ' checked' : '') . $disabled . '>';
echo '<div>';
echo '<div class="smartassign-mode-name"><i class="ti ti-chart-bar"></i> ' . $e(__('Menor fila', 'smartassign')) . '</div>';
echo '<div class="smartassign-hint">' . $e(__('Atribui ao técnico ativo do grupo com menos chamados em aberto (não solucionados/fechados) em todas as entidades; usa rodízio no empate.', 'smartassign')) . '</div>';
echo '</div>';
echo '</label>';

echo '</div>';

// Gatilhos e limites
echo '<h3 class="smartassign-section-title"><i class="ti ti-bolt"></i> ' . $e(__('Gatilhos e regras', 'smartassign')) . '</h3>';
echo '<div class="smartassign-switches mb-3">';

echo '<label class="form-check form-switch smartassign-switch-box">';
echo '<input type="hidden" name="assign_on_create" value="0">';
echo '<input class="form-check-input" type="checkbox" role="switch" name="assign_on_create" value="1"'
    . (!empty($effective['assign_on_create']) ? ' checked' : '') . $disabled . '>';
echo '<span class="form-check-label"><strong>' . $e(__('Atribuir na criação do chamado', 'smartassign')) . '</strong>'
    . '<small class="smartassign-hint">' . $e(__('Dispara quando o chamado nasce já vinculado a um grupo técnico (inclusive via regra de negócio).', 'smartassign')) . '</small></span>';
echo '</label>';

echo '<label class="form-check form-switch smartassign-switch-box">';
echo '<input type="hidden" name="assign_on_group_change" value="0">';
echo '<input class="form-check-input" type="checkbox" role="switch" name="assign_on_group_change" value="1"'
    . (!empty($effective['assign_on_group_change']) ? ' checked' : '') . $disabled . '>';
echo '<span class="form-check-label"><strong>' . $e(__('Atribuir ao adicionar ou trocar grupo técnico', 'smartassign')) . '</strong>'
    . '<small class="smartassign-hint">' . $e(__('Dispara quando um grupo técnico é atribuído posteriormente a um chamado aberto.', 'smartassign')) . '</small></span>';
echo '</label>';

echo '<label class="form-check form-switch smartassign-switch-box">';
echo '<input type="hidden" name="skip_if_assigned" value="0">';
echo '<input class="form-check-input" type="checkbox" role="switch" name="skip_if_assigned" value="1"'
    . (!empty($effective['skip_if_assigned']) ? ' checked' : '') . $disabled . '>';
echo '<span class="form-check-label"><strong>' . $e(__('Não sobrescrever técnico já atribuído', 'smartassign')) . '</strong>'
    . '<small class="smartassign-hint">' . $e(__('Quando ligado, pula se o chamado já tiver qualquer técnico atribuído. Desligado, pula só se alguém deste grupo já estiver atribuído.', 'smartassign')) . '</small></span>';
echo '</label>';

echo '</div>';

echo '<div class="smartassign-field-row mb-4">';
echo '<label class="form-label mb-1" for="smartassign-max-open">' . $e(__('Limite de chamados em aberto por técnico', 'smartassign')) . '</label>';
echo '<input type="number" min="0" step="1" id="smartassign-max-open" name="max_open_tickets" class="form-control smartassign-number-input" value="' . (int) $effective['max_open_tickets'] . '"' . $disabled . '>';
echo '<small class="smartassign-hint">' . $e(__('Contagem global entre todas as entidades (chamados não excluídos e com status menor que Solucionado). Use 0 para ilimitado.', 'smartassign')) . '</small>';
echo '</div>';

// Usuários excluídos
echo '<h3 class="smartassign-section-title"><i class="ti ti-user-off"></i> ' . $e(__('Usuários excluídos da fila', 'smartassign')) . '</h3>';

if ($effective['excluded_users'] !== []) {
    echo '<div class="smartassign-excluded-badges mb-2">';
    foreach ($effective['excluded_users'] as $exUid) {
        $label = $usersList[$exUid] ?? ('#' . $exUid);
        echo '<span class="smartassign-badge"><i class="ti ti-user-x"></i> ' . $e($label) . '</span>';
    }
    echo '</div>';
}

echo '<select name="excluded_users[]" id="smartassign-excluded-users" class="form-select smartassign-select-multi mb-1" multiple size="7"' . $disabled . '>';
foreach ($usersList as $uid => $label) {
    $selected = isset($excludedMap[$uid]) ? ' selected' : '';
    echo '<option value="' . (int) $uid . '"' . $selected . '>' . $e($label) . '</option>';
}
echo '</select>';
echo '<small class="smartassign-hint d-block mb-4">' . $e(__('Segure Ctrl (ou Cmd) para selecionar ou remover múltiplos usuários que nunca devem receber atribuição automática.', 'smartassign')) . '</small>';

if ($canUpdate) {
    echo '<div class="smartassign-actions">';
    echo '<button type="submit" name="update" value="1" class="btn btn-primary">'
        . '<i class="ti ti-device-floppy"></i> ' . $e(__('Salvar configuração', 'smartassign')) . '</button>';
    echo '</div>';
}

Html::closeForm();

// Logs recentes
echo '<div class="smartassign-card">';
echo '<h3 class="smartassign-section-title"><i class="ti ti-history"></i> ' . $e(__('Últimas atribuições automáticas (25 mais recentes)', 'smartassign')) . '</h3>';

if ($logs === []) {
    echo '<p class="text-muted mb-0">' . $e(__('Nenhuma atribuição automática registrada até o momento.', 'smartassign')) . '</p>';
} else {
    echo '<div class="table-responsive">';
    echo '<table class="table table-hover table-sm smartassign-logs-table">';
    echo '<thead><tr>';
    echo '<th>' . $e(__('Data/Hora', 'smartassign')) . '</th>';
    echo '<th>' . $e(__('Chamado', 'smartassign')) . '</th>';
    echo '<th>' . $e(__('Grupo', 'smartassign')) . '</th>';
    echo '<th>' . $e(__('Técnico', 'smartassign')) . '</th>';
    echo '<th>' . $e(__('Modo', 'smartassign')) . '</th>';
    echo '<th>' . $e(__('Gatilho', 'smartassign')) . '</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    foreach ($logs as $log) {
        $tid       = (int) $log['tickets_id'];
        $ticketUrl = method_exists('Ticket', 'getFormURLWithID')
            ? Ticket::getFormURLWithID($tid)
            : (rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/') . '/front/ticket.form.php?id=' . $tid);
        $ticketLabel = '#' . $tid . ($log['ticket_name'] !== '' ? ' — ' . $log['ticket_name'] : '');
        $modeLabel   = ($log['mode'] === PluginSmartassignConfig::MODE_LEAST_LOADED)
            ? __('Menor fila', 'smartassign')
            : __('Rodízio', 'smartassign');
        $reasonLabel = ($log['reason'] === 'ticket_create')
            ? __('Criação', 'smartassign')
            : __('Alteração de grupo', 'smartassign');
        $dateLabel   = $log['date_creation'] !== ''
            ? Html::convDateTime($log['date_creation'])
            : '';

        echo '<tr>';
        echo '<td class="text-nowrap">' . $e($dateLabel) . '</td>';
        echo '<td><a href="' . $e($ticketUrl) . '">' . $e($ticketLabel) . '</a></td>';
        echo '<td>' . $e($log['group_name']) . '</td>';
        echo '<td>' . $e($log['user_name']) . '</td>';
        echo '<td><span class="badge bg-blue-lt">' . $e($modeLabel) . '</span></td>';
        echo '<td>' . $e($reasonLabel) . '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
}

echo '</div>';
echo '</div>';

Html::footer();
