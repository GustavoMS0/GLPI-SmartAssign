<?php

/**
 * ------------------------------------------------------------------------
 * Smart Assign - Atribuição automática de chamados para o GLPI
 *
 * Distribui chamados atribuídos a grupos técnicos por rodízio ou menor fila
 * entre técnicos ativos, respeitando limite de chamados em aberto,
 * usuários excluídos e configuração por entidade.
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

define('PLUGIN_SMARTASSIGN_VERSION', '1.0.0');
define('PLUGIN_SMARTASSIGN_MIN_GLPI', '10.0.0');
define('PLUGIN_SMARTASSIGN_MAX_GLPI', '12.0.99');
define('PLUGIN_SMARTASSIGN_MIN_PHP', '7.4');

/**
 * Inicialização do plugin e registro dos hooks do GLPI.
 */
function plugin_init_smartassign()
{
    global $PLUGIN_HOOKS;

    if (defined('GLPI_ROOT')) {
        include_once __DIR__ . '/inc/compat.class.php';
    }

    $PLUGIN_HOOKS['csrf_compliant']['smartassign'] = true;
    $PLUGIN_HOOKS['config_page']['smartassign']    = 'front/config.form.php';

    $cssPath = class_exists('PluginSmartassignCompat')
        ? PluginSmartassignCompat::assetPath('css/smartassign.css')
        : ((defined('GLPI_VERSION') && version_compare((string) GLPI_VERSION, '11.0.0-dev', '>='))
            ? 'css/smartassign.css'
            : 'public/css/smartassign.css');

    $PLUGIN_HOOKS['add_css']['smartassign'] = [$cssPath];

    $PLUGIN_HOOKS['post_prepareadd']['smartassign'] = [
        'Ticket' => [PluginSmartassignAssigner::class, 'postPrepareAddTicket'],
    ];

    $PLUGIN_HOOKS['item_add']['smartassign'] = [
        'Ticket'       => [PluginSmartassignAssigner::class, 'itemAddTicket'],
        'Group_Ticket' => [PluginSmartassignAssigner::class, 'itemAddGroupTicket'],
    ];

    $PLUGIN_HOOKS['item_purge']['smartassign'] = [
        'Ticket' => [PluginSmartassignAssigner::class, 'itemPurgeTicket'],
        'Group'  => [PluginSmartassignAssigner::class, 'itemPurgeGroup'],
    ];
}

/**
 * Informações exibidas em Configurar > Plugins.
 */
function plugin_version_smartassign()
{
    return [
        'name'         => 'Smart Assign',
        'version'      => PLUGIN_SMARTASSIGN_VERSION,
        'author'       => 'G. Martins',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/GustavoMS0/GLPI-SmartAssign',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_SMARTASSIGN_MIN_GLPI,
                'max' => PLUGIN_SMARTASSIGN_MAX_GLPI,
            ],
            'php'  => [
                'min' => PLUGIN_SMARTASSIGN_MIN_PHP,
            ],
        ],
    ];
}

function plugin_smartassign_check_prerequisites()
{
    if (version_compare(PHP_VERSION, PLUGIN_SMARTASSIGN_MIN_PHP, '<')) {
        return false;
    }
    if (!extension_loaded('json')) {
        return false;
    }
    return true;
}

function plugin_smartassign_check_config($verbose = false)
{
    return true;
}
