<?php

/**
 * ------------------------------------------------------------------------
 * Smart Assign - Camada de compatibilidade GLPI 10 / 11 / 12
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

class PluginSmartassignCompat
{
    /**
     * Retorna a versão principal do GLPI (10, 11, 12...).
     */
    public static function glpiMajor(): int
    {
        $version = defined('GLPI_VERSION') ? (string) GLPI_VERSION : '10.0.0';
        if (preg_match('/^(\d+)/', $version, $matches)) {
            return (int) $matches[1];
        }
        return 10;
    }

    /**
     * Indica se a versão em execução é GLPI 11.0.0-dev ou superior.
     */
    public static function isGlpi11OrHigher(): bool
    {
        return defined('GLPI_VERSION')
            && version_compare((string) GLPI_VERSION, '11.0.0-dev', '>=');
    }

    /**
     * Indica se CommonGLPI::$rightname possui declaração de tipo (GLPI 12+).
     */
    public static function typedRightname(): bool
    {
        if (!class_exists('CommonGLPI')) {
            return false;
        }
        try {
            $prop = new ReflectionProperty('CommonGLPI', 'rightname');
            return $prop->hasType();
        } catch (ReflectionException $e) {
            return false;
        }
    }

    /**
     * Caminho web base do plugin, evitando Plugin::getWebDir() no GLPI 11/12+.
     * No GLPI 11+, todos os plugins são servidos a partir de /plugins/<chave>.
     */
    public static function webPath(bool $absolute = false): string
    {
        global $CFG_GLPI;

        $isGlpi11Plus = self::isGlpi11OrHigher();

        if (
            !$isGlpi11Plus
            && class_exists('Plugin')
            && method_exists('Plugin', 'getWebDir')
        ) {
            $dir = (string) Plugin::getWebDir('smartassign', true, $absolute);
            if ($dir !== '') {
                return rtrim($dir, '/');
            }
        }

        $base = $absolute
            ? (string) ($CFG_GLPI['url_base'] ?? '')
            : (string) ($CFG_GLPI['root_doc'] ?? '');
        $base = rtrim($base, '/');

        if ($isGlpi11Plus) {
            return $base . '/plugins/smartassign';
        }

        $parentDir = basename(dirname(__DIR__, 2));
        $folder    = ($parentDir === 'marketplace') ? 'marketplace' : 'plugins';

        return $base . '/' . $folder . '/smartassign';
    }

    /**
     * Caminho de arquivo estático relativo ao plugin.
     * GLPI < 11.0.0-dev requer prefixo 'public/'; GLPI >= 11.0.0-dev serve a partir de public/.
     */
    public static function assetPath(string $relativePath): string
    {
        $clean = ltrim($relativePath, '/');
        if (strpos($clean, 'public/') === 0) {
            $clean = substr($clean, 7);
        }

        $prefix = self::isGlpi11OrHigher() ? '' : 'public/';

        return $prefix . $clean;
    }

    /**
     * Decodifica entidades HTML gravadas pelo núcleo no GLPI < 11.0.0-dev sem remover barras invertidas literais.
     */
    public static function fromCore(string $value): string
    {
        if (!self::isGlpi11OrHigher()) {
            if (
                class_exists('Glpi\\Toolbox\\Sanitizer')
                && method_exists('Glpi\\Toolbox\\Sanitizer', 'decodeHtmlSpecialChars')
            ) {
                return (string) \Glpi\Toolbox\Sanitizer::decodeHtmlSpecialChars($value);
            }
            return htmlspecialchars_decode($value, ENT_QUOTES);
        }
        return $value;
    }

    /**
     * Nega o acesso de forma compatível com GLPI 10/11 (Html::displayRightError) e GLPI 12+ (AccessDeniedHttpException).
     */
    public static function denyAccess(): void
    {
        if (class_exists('Html') && method_exists('Html', 'displayRightError')) {
            Html::displayRightError();
            return;
        }
        if (class_exists('Glpi\\Exception\\Http\\AccessDeniedHttpException')) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException('Access denied');
        }
        if (!headers_sent()) {
            http_response_code(403);
        }
    }

    /**
     * Escapa strings para $DB->insert() e $DB->update() no GLPI < 11.0.0-dev.
     */
    public static function dbValues(array $values): array
    {
        global $DB;

        if (
            defined('GLPI_VERSION')
            && version_compare((string) GLPI_VERSION, '11.0.0-dev', '<')
            && isset($DB)
            && is_object($DB)
            && method_exists($DB, 'escape')
        ) {
            foreach ($values as $key => $val) {
                if (is_string($val)) {
                    $values[$key] = $DB->escape($val);
                }
            }
        }

        return $values;
    }

    /**
     * Prepara entrada para métodos do núcleo (CommonDBTM::add/update) no GLPI < 11.0.0-dev.
     *
     * @param mixed $values
     * @return mixed
     */
    public static function coreInput($values)
    {
        if (defined('GLPI_VERSION') && version_compare((string) GLPI_VERSION, '11.0.0-dev', '<')) {
            if (class_exists('Glpi\\Toolbox\\Sanitizer')) {
                return \Glpi\Toolbox\Sanitizer::sanitize($values);
            }
            if (class_exists('Toolbox') && method_exists('Toolbox', 'addslashes_deep')) {
                return Toolbox::addslashes_deep($values);
            }
        }
        return $values;
    }

    /**
     * Retorna os IDs das entidades ancestrais em ordem do pai imediato até a raiz (0).
     *
     * @return int[]
     */
    public static function getEntitiesAncestorsFallback(int $entities_id): array
    {
        global $DB;

        if ($entities_id <= 0) {
            return [];
        }

        $ancestors = [];
        if (
            isset($DB)
            && is_object($DB)
            && method_exists($DB, 'tableExists')
            && $DB->tableExists('glpi_entities')
        ) {
            $current = $entities_id;
            $visited = [$entities_id => true];
            for ($depth = 0; $depth < 64; $depth++) {
                $parent = null;
                foreach (
                    $DB->request([
                        'SELECT' => ['entities_id'],
                        'FROM'   => 'glpi_entities',
                        'WHERE'  => ['id' => $current],
                        'LIMIT'  => 1,
                    ]) as $row
                ) {
                    $parent = (int) ($row['entities_id'] ?? -1);
                }
                if ($parent === null || $parent < 0 || isset($visited[$parent])) {
                    break;
                }
                $ancestors[]      = $parent;
                $visited[$parent] = true;
                $current          = $parent;
                if ($current === 0) {
                    break;
                }
            }
        }

        if ($ancestors === [] && function_exists('getAncestorsOf')) {
            $raw = getAncestorsOf('glpi_entities', $entities_id);
            if (is_array($raw) && $raw !== []) {
                foreach ($raw as $candidate) {
                    $id = (int) $candidate;
                    if ($id >= 0 && $id !== $entities_id && !in_array($id, $ancestors, true)) {
                        $ancestors[] = $id;
                    }
                }
                if (count($ancestors) > 1 && $ancestors[0] === 0) {
                    $ancestors = array_reverse($ancestors);
                }
            }
        }

        return $ancestors;
    }

    /**
     * Valida requisição POST por método e mesma origem (Sec-Fetch-Site ou Origin/Referer vs Host).
     * Não chama Session::checkCSRF() para evitar dupla validação em formulários com Html::closeForm().
     */
    public static function requireSafePost(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            if (!headers_sent()) {
                http_response_code(405);
            }
            return false;
        }

        $fetchSite = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
        if ($fetchSite !== '') {
            $valid = ($fetchSite === 'same-origin');
        } else {
            $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
            $host   = (string) ($_SERVER['HTTP_HOST'] ?? '');

            if ($origin === '' && isset($_SERVER['HTTP_REFERER'])) {
                $origin = (string) $_SERVER['HTTP_REFERER'];
            }

            $originHost   = parse_url($origin, PHP_URL_HOST);
            $originPort   = parse_url($origin, PHP_URL_PORT);
            $expectedHost = is_string($originHost)
                ? $originHost . ($originPort !== null ? ':' . $originPort : '')
                : '';

            $valid = $origin !== ''
                && $host !== ''
                && hash_equals(strtolower($host), strtolower($expectedHost));
        }

        if (!$valid) {
            if (!headers_sent()) {
                http_response_code(403);
            }
            return false;
        }

        return true;
    }
}
