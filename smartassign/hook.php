<?php

/**
 * ------------------------------------------------------------------------
 * Smart Assign - Instalação e desinstalação
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

/**
 * Cria as tabelas de configuração por entidade, estado de rodízio e logs.
 */
function plugin_smartassign_install()
{
    include_once __DIR__ . '/inc/compat.class.php';
    include_once __DIR__ . '/inc/config.class.php';

    PluginSmartassignConfig::installSchema();

    return true;
}

/**
 * Remove as tabelas criadas pelo plugin.
 */
function plugin_smartassign_uninstall()
{
    include_once __DIR__ . '/inc/compat.class.php';
    include_once __DIR__ . '/inc/config.class.php';

    PluginSmartassignConfig::uninstallSchema();

    return true;
}
