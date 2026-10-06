<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/*
 * La logique pure du plugin : normalisation des pages, révision, construction
 * du layout, résolution d'une action de tuile en commande, bornage, fusion des
 * changements, génération de tuiles depuis les types génériques.
 *
 * Aucune dépendance au coeur de Jeedom : tout ce qui vient de la base arrive
 * en tableaux simples (ou par une fonction de lecture passée en paramètre).
 * C'est ce qui permet à tests/run.php de tout vérifier hors ligne.
 *
 * Contrat : docs/api.md (schéma 1). Il fait foi.
 */
class jeetvbeLayout {
    const SCHEMA = 1;

    /* Types et icônes du contrat. Un type inconnu devient « info », une icône
     * inconnue « generic » : la TV ferait de même, autant ne rien lui envoyer
     * qu'elle doive corriger. */
    const TYPES = array('switch', 'shutter', 'slider', 'info', 'scene');
    const ICONS = array('light', 'plug', 'shutter', 'thermostat', 'temperature', 'scene', 'fan', 'lock', 'alarm', 'generic');

    /* Les rôles de commande d'une tuile. « state » est la commande info lue
     * pour « value » ; les autres sont des commandes action. */
    const ROLES = array('state', 'on', 'off', 'toggle', 'up', 'down', 'stop', 'set');

    /* Actions permises par type (contrat, POST exec). */
    const ACTIONS = array(
        'switch'  => array('on', 'off', 'toggle'),
        'shutter' => array('up', 'down', 'stop', 'set'),
        'slider'  => array('set'),
        'scene'   => array('run'),
        'info'    => array(),
    );

    /* Les rôles utiles à chaque type, dans l'ordre d'affichage de l'éditeur. */
    const TYPE_ROLES = array(
        'switch'  => array('state', 'on', 'off', 'toggle'),
        'shutter' => array('state', 'up', 'down', 'stop', 'set'),
        'slider'  => array('state', 'set'),
        'info'    => array('state'),
        'scene'   => array(),
    );

    /* Bornes par défaut quand ni la tuile ni la commande n'en donnent. */
    const DEFAULT_BOUNDS = array(
        'shutter' => array('min' => 0, 'max' => 100, 'step' => 10),
        'slider'  => array('min' => 0, 'max' => 100, 'step' => 1),
    );

    /* Consigne de thermostat générée : 15–25 °C au pas de 0,5 si la commande
     * de réglage ne porte pas ses propres bornes. */
    const THERMOSTAT_BOUNDS = array('min' => 15, 'max' => 25, 'step' => 0.5);

    /* Types génériques qui imposent une confirmation, et mots qui la cochent
     * dans un nom (comparés sans accents ni casse). */
    const SENSITIVE_PREFIXES = array('LOCK_', 'ALARM_', 'GB_', 'GARAGE_');
    const SENSITIVE_WORDS = array('portail', 'garage', 'verrou', 'alarme', 'panique');

    const MAX_PAGES = 50;
    const MAX_TILES = 200;
    const MAX_NAME = 64;

    /* ================================================================ outils */

    /* Un nombre JSON canonique : entier s'il est entier, sinon flottant arrondi
     * à 6 décimales. null si la valeur n'est pas un nombre. C'est ce qui rend
     * la révision stable : 15, 15.0 et "15" donnent le même hash. */
    public static function number($_value) {
        if (is_bool($_value) || $_value === null) {
            return null;
        }
        if (is_string($_value)) {
            $_value = str_replace(',', '.', trim($_value));
        }
        if (!is_numeric($_value)) {
            return null;
        }
        $float = round((float) $_value, 6);
        if (!is_finite($float)) {
            return null;
        }
        if ($float == floor($float) && abs($float) < 1e15) {
            return (int) $float;
        }
        return $float;
    }

    /* Un id de commande : entier positif, accepté aussi sous la forme « #123# ». */
    public static function cmdId($_value) {
        if (is_int($_value)) {
            return ($_value > 0) ? $_value : null;
        }
        if (!is_string($_value)) {
            return null;
        }
        $_value = trim($_value);
        if (preg_match('/^#?(\d{1,10})#?$/', $_value, $m) && (int) $m[1] > 0) {
            return (int) $m[1];
        }
        return null;
    }

    /* Minuscules sans accents, pour chercher un mot dans un nom. */
    public static function fold($_text) {
        $text = mb_strtolower((string) $_text, 'UTF-8');
        $from = array('à', 'â', 'ä', 'á', 'é', 'è', 'ê', 'ë', 'î', 'ï', 'í', 'ô', 'ö', 'ó', 'ù', 'û', 'ü', 'ú', 'ç', 'œ');
        $to   = array('a', 'a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'c', 'oe');
        return str_replace($from, $to, $text);
    }

    private static function cleanName($_name, $_default) {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $_name));
        if ($name === '') {
            $name = $_default;
        }
        if (mb_strlen($name, 'UTF-8') > self::MAX_NAME) {
            $name = mb_substr($name, 0, self::MAX_NAME, 'UTF-8');
        }
        return $name;
    }

    private static function cleanId($_id) {
        if (!is_string($_id) && !is_int($_id)) {
            return '';
        }
        $id = trim((string) $_id);
        return preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id) ? $id : '';
    }

    /* ========================================================= normalisation */

    /*
     * Les pages telles qu'enregistrées, nettoyées : types et icônes du contrat,
     * rôles connus seulement, bornes numériques, ids uniques pour toute la TV.
     *
     * Les ids existants sont conservés (c'est ce qui les rend « stables tant que
     * la configuration ne change pas ») ; un id absent, invalide ou en double
     * reçoit le suivant libre : p<n> pour une page, t<n> pour une tuile.
     *
     * Accepte un tableau ou sa forme JSON. Ne lève jamais d'exception : appelée
     * en preSave, elle ne doit pas pouvoir bloquer un enregistrement.
     *
     * $_previous (pages enregistrées jusque-là) et $_floor (plus grand numéro
     * de tuile jamais attribué) protègent une TV dont le layout est périmé :
     * une tuile sans id qui pilote exactement les mêmes commandes qu'une
     * ancienne reprend son id ; les autres reçoivent un numéro jamais servi.
     * Ainsi « t5 » ne désigne jamais, après une régénération, autre chose que
     * ce qu'il désignait — un exec envoyé avant rechargement ne peut pas
     * actionner l'équipement d'à côté.
     *
     * Même règle pour les pages ($_pageFloor) : une page sans id reprend celui
     * d'une ancienne page de même nom, sinon un numéro jamais servi. La
     * commande « Afficher <page> » (logicalId show_<id>) d'une page supprimée
     * ne désigne ainsi jamais une autre page.
     */
    public static function normalizePages($_pages, $_previous = null, $_floor = 0, $_pageFloor = 0) {
        if (is_string($_pages)) {
            $decoded = json_decode($_pages, true);
            $_pages = is_array($decoded) ? $decoded : array();
        }
        if (!is_array($_pages)) {
            return array();
        }
        $pages = array();
        $usedPages = array();
        $usedTiles = array();
        $tileCount = 0;
        foreach (array_values($_pages) as $rawPage) {
            if (!is_array($rawPage) || count($pages) >= self::MAX_PAGES) {
                continue;
            }
            $page = array(
                'id'    => self::cleanId(isset($rawPage['id']) ? $rawPage['id'] : ''),
                'name'  => self::cleanName(isset($rawPage['name']) ? $rawPage['name'] : '', 'Page ' . (count($pages) + 1)),
                'tiles' => array(),
            );
            if ($page['id'] !== '' && isset($usedPages[$page['id']])) {
                $page['id'] = '';
            }
            if ($page['id'] !== '') {
                $usedPages[$page['id']] = true;
            }
            $rawTiles = (isset($rawPage['tiles']) && is_array($rawPage['tiles'])) ? array_values($rawPage['tiles']) : array();
            foreach ($rawTiles as $rawTile) {
                if (!is_array($rawTile) || $tileCount >= self::MAX_TILES) {
                    continue;
                }
                $tile = self::normalizeTile($rawTile);
                if ($tile['id'] !== '' && isset($usedTiles[$tile['id']])) {
                    $tile['id'] = '';
                }
                if ($tile['id'] !== '') {
                    $usedTiles[$tile['id']] = true;
                }
                $page['tiles'][] = $tile;
                $tileCount++;
            }
            $pages[] = $page;
        }
        /* Second passage : les ids manquants, après avoir réservé tous ceux qui
         * existent — une tuile ajoutée en tête ne vole pas l'id d'une autre. */
        $carry = array();
        $previousIds = array();
        $previousPageIds = array();
        $pageCarry = array();
        if ($_previous !== null) {
            foreach (self::normalizePages($_previous) as $oldPage) {
                $previousPageIds[] = $oldPage['id'];
                $pageCarry[self::fold($oldPage['name'])][] = $oldPage['id'];
                foreach ($oldPage['tiles'] as $oldTile) {
                    $previousIds[] = $oldTile['id'];
                    $carry[self::signature($oldTile)][] = $oldTile['id'];
                }
            }
        }
        foreach ($pages as &$page) {
            $folded = self::fold($page['name']);
            if ($page['id'] === '' && isset($pageCarry[$folded])) {
                while (count($pageCarry[$folded]) > 0) {
                    $candidate = array_shift($pageCarry[$folded]);
                    if (!isset($usedPages[$candidate])) {
                        $page['id'] = $candidate;
                        $usedPages[$candidate] = true;
                        break;
                    }
                }
            }
            foreach ($page['tiles'] as &$tile) {
                $signature = self::signature($tile);
                if ($tile['id'] !== '' || !isset($carry[$signature])) {
                    continue;
                }
                while (count($carry[$signature]) > 0) {
                    $candidate = array_shift($carry[$signature]);
                    if (!isset($usedTiles[$candidate])) {
                        $tile['id'] = $candidate;
                        $usedTiles[$candidate] = true;
                        break;
                    }
                }
            }
            unset($tile);
        }
        unset($page);
        $nextPage = max(self::nextNumber(array_keys($usedPages), 'p'), self::nextNumber($previousPageIds, 'p'), (int) $_pageFloor + 1);
        $nextTile = max(self::nextNumber(array_keys($usedTiles), 't'), self::nextNumber($previousIds, 't'), (int) $_floor + 1);
        foreach ($pages as &$page) {
            if ($page['id'] === '') {
                $page['id'] = 'p' . $nextPage++;
            }
            foreach ($page['tiles'] as &$tile) {
                if ($tile['id'] === '') {
                    $tile['id'] = 't' . $nextTile++;
                }
            }
            unset($tile);
        }
        unset($page);
        return $pages;
    }

    /* Ce qu'une tuile pilote : type, rôles et scénario. */
    public static function signature($_tile) {
        $roles = self::roles($_tile);
        ksort($roles);
        return $_tile['type'] . '|' . json_encode($roles) . '|' . (isset($_tile['scenario_id']) ? (int) $_tile['scenario_id'] : 0);
    }

    /* Le plus grand numéro de page « p<n> ». */
    public static function maxPageNumber($_pages) {
        $ids = array();
        foreach (self::normalizePages($_pages) as $page) {
            $ids[] = $page['id'];
        }
        return self::nextNumber($ids, 'p') - 1;
    }

    /* Le plus grand numéro de tuile « t<n> » des pages. */
    public static function maxTileNumber($_pages) {
        $ids = array();
        foreach (self::normalizePages($_pages) as $page) {
            foreach ($page['tiles'] as $tile) {
                $ids[] = $tile['id'];
            }
        }
        return self::nextNumber($ids, 't') - 1;
    }

    private static function nextNumber($_ids, $_prefix) {
        $max = 0;
        foreach ($_ids as $id) {
            if (preg_match('/^' . $_prefix . '(\d{1,9})$/', (string) $id, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        return $max + 1;
    }

    public static function normalizeTile($_tile) {
        $type = (isset($_tile['type']) && in_array($_tile['type'], self::TYPES, true)) ? $_tile['type'] : 'info';
        $icon = (isset($_tile['icon']) && in_array($_tile['icon'], self::ICONS, true)) ? $_tile['icon'] : 'generic';
        $cmds = array();
        /* Tableau ou objet : une tuile déjà normalisée porte ses rôles en objet. */
        $rawCmds = (isset($_tile['cmds']) && (is_array($_tile['cmds']) || is_object($_tile['cmds']))) ? (array) $_tile['cmds'] : array();
        if (count($rawCmds) > 0) {
            foreach (self::ROLES as $role) {
                if (isset($rawCmds[$role])) {
                    $id = self::cmdId($rawCmds[$role]);
                    if ($id !== null) {
                        $cmds[$role] = $id;
                    }
                }
            }
        }
        $confirm = isset($_tile['confirm']) ? $_tile['confirm'] : false;
        $confirm = ($confirm === true || $confirm === 1 || $confirm === '1' || $confirm === 'true');
        $scenario = isset($_tile['scenario_id']) ? self::cmdId($_tile['scenario_id']) : null;
        $min  = isset($_tile['min']) ? self::number($_tile['min']) : null;
        $max  = isset($_tile['max']) ? self::number($_tile['max']) : null;
        $step = isset($_tile['step']) ? self::number($_tile['step']) : null;
        if ($min !== null && $max !== null && $min > $max) {
            list($min, $max) = array($max, $min);
        }
        if ($step !== null && $step <= 0) {
            $step = null;
        }
        return array(
            'id'          => self::cleanId(isset($_tile['id']) ? $_tile['id'] : ''),
            'type'        => $type,
            'name'        => self::cleanName(isset($_tile['name']) ? $_tile['name'] : '', 'Tuile'),
            'icon'        => $icon,
            'confirm'     => $confirm,
            'cmds'        => (object) $cmds,
            'scenario_id' => ($type === 'scene') ? $scenario : null,
            'min'         => $min,
            'max'         => $max,
            'step'        => $step,
        );
    }

    /* Le tableau de rôles d'une tuile normalisée (stockée en objet pour que
     * json_encode rende {} et non [] quand il est vide). */
    public static function roles($_tile) {
        return isset($_tile['cmds']) ? (array) $_tile['cmds'] : array();
    }

    /*
     * La révision : hash court de la configuration des pages normalisée. Elle
     * change dès qu'une page, une tuile ou une commande liée change, et pas
     * quand une valeur change.
     */
    public static function revision($_pages) {
        $json = json_encode(self::normalizePages($_pages), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return substr(sha1((string) $json), 0, 8);
    }

    /* ================================================================ layout */

    /*
     * Les bornes d'une tuile telles que servies, ou null si elle n'en a pas.
     * $_setCmd : ['minValue' => …, 'maxValue' => …] de la commande « set », ou null.
     *
     * - slider : toujours des bornes (tuile, sinon commande, sinon défaut) ;
     * - shutter : seulement s'il a une commande « set » (position) — un volet
     *   sans position n'a ni min ni max, et « set » y est refusé ;
     * - autres types : jamais.
     */
    public static function bounds($_tile, $_setCmd = null) {
        $type = $_tile['type'];
        if ($type !== 'slider' && $type !== 'shutter') {
            return null;
        }
        $roles = self::roles($_tile);
        if ($type === 'shutter' && !isset($roles['set'])) {
            return null;
        }
        $default = self::DEFAULT_BOUNDS[$type];
        $cmdMin = is_array($_setCmd) && isset($_setCmd['minValue']) ? self::number($_setCmd['minValue']) : null;
        $cmdMax = is_array($_setCmd) && isset($_setCmd['maxValue']) ? self::number($_setCmd['maxValue']) : null;
        $min  = ($_tile['min'] !== null) ? $_tile['min'] : (($cmdMin !== null) ? $cmdMin : $default['min']);
        $max  = ($_tile['max'] !== null) ? $_tile['max'] : (($cmdMax !== null) ? $cmdMax : $default['max']);
        $step = ($_tile['step'] !== null) ? $_tile['step'] : $default['step'];
        if ($min > $max) {
            list($min, $max) = array($max, $min);
        }
        return array('min' => $min, 'max' => $max, 'step' => $step);
    }

    /* Une valeur brute de commande, en chaîne (contrat : string ou null). */
    public static function valueString($_value) {
        if ($_value === null) {
            return null;
        }
        if (is_bool($_value)) {
            return $_value ? '1' : '0';
        }
        if (is_array($_value) || is_object($_value)) {
            return null;
        }
        return (string) $_value;
    }

    /*
     * Une tuile du layout.
     * $_resolve(int $cmdId) rend null si la commande n'existe plus, sinon
     * ['type' => 'info'|'action', 'value' => …, 'unit' => …, 'minValue' => …, 'maxValue' => …].
     */
    public static function buildTile($_tile, $_resolve) {
        $roles = self::roles($_tile);
        $state = (isset($roles['state']) && $_tile['type'] !== 'scene') ? $_resolve($roles['state']) : null;
        $set   = isset($roles['set']) ? $_resolve($roles['set']) : null;
        $value = (is_array($state) && (!isset($state['type']) || $state['type'] === 'info'))
            ? self::valueString(isset($state['value']) ? $state['value'] : null) : null;
        $unit = '';
        if (is_array($state) && isset($state['unit']) && $state['unit'] !== '') {
            $unit = (string) $state['unit'];
        } elseif (is_array($set) && isset($set['unit']) && $set['unit'] !== '' && $_tile['type'] === 'slider') {
            $unit = (string) $set['unit'];
        }
        $out = array(
            'id'      => $_tile['id'],
            'type'    => $_tile['type'],
            'name'    => $_tile['name'],
            'icon'    => $_tile['icon'],
            'confirm' => (bool) $_tile['confirm'],
            'value'   => $value,
            'unit'    => $unit,
        );
        $bounds = self::bounds($_tile, $set);
        if ($bounds !== null) {
            $out['min']  = $bounds['min'];
            $out['max']  = $bounds['max'];
            $out['step'] = $bounds['step'];
        }
        return $out;
    }

    public static function buildLayout($_pages, $_resolve) {
        $pages = self::normalizePages($_pages);
        $out = array();
        foreach ($pages as $page) {
            $tiles = array();
            foreach ($page['tiles'] as $tile) {
                $tiles[] = self::buildTile($tile, $_resolve);
            }
            $out[] = array('id' => $page['id'], 'name' => $page['name'], 'tiles' => $tiles);
        }
        return array('schema' => self::SCHEMA, 'revision' => self::revision($pages), 'pages' => $out);
    }

    /* La tuile d'id donné, ou null. */
    public static function findTile($_pages, $_tileId) {
        if (!is_string($_tileId) || $_tileId === '') {
            return null;
        }
        foreach (self::normalizePages($_pages) as $page) {
            foreach ($page['tiles'] as $tile) {
                if ($tile['id'] === $_tileId) {
                    return $tile;
                }
            }
        }
        return null;
    }

    /* Commande info suivie => ids des tuiles qui l'affichent (scènes exclues). */
    public static function stateMap($_pages) {
        $map = array();
        foreach (self::normalizePages($_pages) as $page) {
            foreach ($page['tiles'] as $tile) {
                $roles = self::roles($tile);
                if ($tile['type'] !== 'scene' && isset($roles['state'])) {
                    $map[$roles['state']][] = $tile['id'];
                }
            }
        }
        return $map;
    }

    /* ============================================================== exécution */

    /* Allumé ? « 1 » ou tout nombre > 0. */
    public static function isOn($_value) {
        if ($_value === null) {
            return false;
        }
        $number = self::number($_value);
        return $number !== null && $number > 0;
    }

    public static function clamp($_value, $_min, $_max) {
        return self::number(max($_min, min($_max, (float) $_value)));
    }

    /*
     * Ce que fait l'action $_action sur la tuile, sans rien exécuter.
     *
     * Rend l'un de :
     *   ['cmd' => id, 'options' => array(), 'action' => 'on'|…]
     *   ['scenario' => id]
     *   ['error' => 400|422, 'message' => '…']
     *
     * $_state : valeur actuelle de la commande « state » (pour toggle) ;
     * $_setCmd : ['minValue', 'maxValue'] de la commande « set » (pour bornes).
     */
    public static function resolveAction($_tile, $_action, $_value = null, $_state = null, $_setCmd = null) {
        $type = $_tile['type'];
        if (!is_string($_action) || $_action === '') {
            return array('error' => 400, 'message' => 'Paramètre « action » manquant');
        }
        if (!in_array($_action, self::ACTIONS[$type], true)) {
            return array('error' => 422, 'message' => ($type === 'info')
                ? 'Une tuile d\'information ne s\'actionne pas'
                : 'Action « ' . mb_substr($_action, 0, 20, 'UTF-8') . ' » non permise sur une tuile ' . $type);
        }
        $roles = self::roles($_tile);

        if ($type === 'scene') {
            if (empty($_tile['scenario_id'])) {
                return array('error' => 422, 'message' => 'Aucun scénario associé à cette tuile');
            }
            return array('scenario' => $_tile['scenario_id']);
        }

        if ($_action === 'set') {
            if (!isset($roles['set'])) {
                return array('error' => 422, 'message' => ($type === 'shutter')
                    ? 'Ce volet n\'a pas de position réglable'
                    : 'Aucune commande de réglage associée à cette tuile');
            }
            $number = self::number($_value);
            if ($number === null) {
                return array('error' => 400, 'message' => 'Paramètre « value » manquant ou non numérique');
            }
            $bounds = self::bounds($_tile, $_setCmd);
            $number = self::clamp($number, $bounds['min'], $bounds['max']);
            return array('cmd' => $roles['set'], 'action' => 'set', 'options' => array('slider' => $number), 'value' => $number);
        }

        if ($_action === 'toggle') {
            if (isset($roles['toggle'])) {
                return array('cmd' => $roles['toggle'], 'action' => 'toggle', 'options' => array());
            }
            if ($_state === null || !isset($roles['state'])) {
                return array('error' => 422, 'message' => 'Basculement impossible : état inconnu');
            }
            $_action = self::isOn($_state) ? 'off' : 'on';
        }

        if (!isset($roles[$_action])) {
            return array('error' => 422, 'message' => 'Aucune commande « ' . $_action . ' » associée à cette tuile');
        }
        return array('cmd' => $roles[$_action], 'action' => $_action, 'options' => array());
    }

    /* ============================================================= changements */

    /*
     * Les changements d'une TV à partir d'une liste d'événements cmd::update
     * [['cmd_id' => …, 'value' => …], …] dans l'ordre chronologique.
     * Plusieurs changements d'une même tuile sont fusionnés (dernière valeur),
     * à la place de leur première apparition.
     */
    public static function mergeChanges($_events, $_stateMap) {
        $changes = array();
        foreach ($_events as $event) {
            $cmdId = isset($event['cmd_id']) ? (int) $event['cmd_id'] : 0;
            if (!isset($_stateMap[$cmdId])) {
                continue;
            }
            foreach ($_stateMap[$cmdId] as $tileId) {
                $changes[$tileId] = self::valueString(isset($event['value']) ? $event['value'] : null);
            }
        }
        $out = array();
        foreach ($changes as $tileId => $value) {
            $out[] = array('tile' => (string) $tileId, 'value' => $value);
        }
        return $out;
    }

    /* Le curseur « since » reçu : null s'il est absent ou nul (premier appel),
     * false s'il est mal formé, sinon un flottant. */
    public static function parseSince($_since) {
        if ($_since === null || $_since === '' || $_since === '0' || $_since === 0) {
            return null;
        }
        if (!is_string($_since) && !is_int($_since) && !is_float($_since)) {
            return false;
        }
        if (!is_numeric($_since) || (float) $_since < 0) {
            return false;
        }
        $since = round((float) $_since, 6);
        return ($since == 0) ? null : $since;
    }

    /* ============================================== génération (types génériques) */

    private static function startsWithAny($_generic, $_prefixes) {
        foreach ($_prefixes as $prefix) {
            if (strpos((string) $_generic, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /* Un nom qui appelle une confirmation (portail, garage, verrou, alarme, panique). */
    public static function sensitiveName($_name) {
        $folded = self::fold($_name);
        foreach (self::SENSITIVE_WORDS as $word) {
            if (strpos($folded, $word) !== false) {
                return true;
            }
        }
        return false;
    }

    /*
     * Les tuiles proposées pour un équipement.
     *
     * $_eq = ['id', 'name', 'cmds' => [['id', 'type', 'subType', 'generic',
     *         'name', 'unit', 'minValue', 'maxValue'], …]]
     *
     * Correspondances :
     *   LIGHT_*   → switch (icône light)  : state, on, off, toggle
     *   ENERGY_*  → switch (icône plug)   : state, on, off, toggle
     *   FLAP_*    → shutter               : up, down, stop, set (FLAP_SLIDER),
     *                                       state (FLAP_STATE ou FLAP_BSO_STATE)
     *   THERMOSTAT_SET_SETPOINT + THERMOSTAT_SETPOINT → slider (15–25, pas 0,5
     *                                       si la commande n'a pas ses bornes)
     *   TEMPERATURE, THERMOSTAT_TEMPERATURE, THERMOSTAT_TEMPERATURE_OUTDOOR → info
     *
     * Une TEMPERATURE portée par un équipement qui a déjà une tuile actionnable
     * (la « température retenue » d'un groupe de volets, par exemple) n'est pas
     * reprise : ce n'est pas la température d'une pièce.
     */
    public static function tilesForEqLogic($_eq) {
        $byGeneric = array();
        $temperatures = array();
        $sensitive = self::sensitiveName(isset($_eq['name']) ? $_eq['name'] : '');
        $thermostat = false;
        foreach ((isset($_eq['cmds']) && is_array($_eq['cmds'])) ? $_eq['cmds'] : array() as $cmd) {
            $generic = isset($cmd['generic']) ? (string) $cmd['generic'] : '';
            if ($generic === '') {
                continue;
            }
            if (self::startsWithAny($generic, self::SENSITIVE_PREFIXES)) {
                $sensitive = true;
            }
            if (strpos($generic, 'THERMOSTAT_') === 0) {
                $thermostat = true;
            }
            if (!isset($byGeneric[$generic])) {
                $byGeneric[$generic] = $cmd;
            }
            if (in_array($generic, array('TEMPERATURE', 'THERMOSTAT_TEMPERATURE', 'THERMOSTAT_TEMPERATURE_OUTDOOR'), true)
                && (!isset($cmd['type']) || $cmd['type'] === 'info')) {
                $temperatures[] = $cmd;
            }
        }
        $pick = function ($_generics) use ($byGeneric) {
            foreach ($_generics as $generic) {
                if (isset($byGeneric[$generic])) {
                    return $byGeneric[$generic];
                }
            }
            return null;
        };
        $id = function ($_cmd) {
            return ($_cmd === null) ? null : self::cmdId((string) $_cmd['id']);
        };
        $name = isset($_eq['name']) ? (string) $_eq['name'] : '';
        $tiles = array();

        /* Lumière, puis prise : un switch chacun. */
        foreach (array(
            'light' => array('state' => array('LIGHT_STATE_BOOL', 'LIGHT_STATE'), 'on' => array('LIGHT_ON'),
                             'off' => array('LIGHT_OFF'), 'toggle' => array('LIGHT_TOGGLE')),
            'plug'  => array('state' => array('ENERGY_STATE'), 'on' => array('ENERGY_ON'),
                             'off' => array('ENERGY_OFF'), 'toggle' => array('ENERGY_TOGGLE')),
        ) as $icon => $map) {
            $cmds = array();
            foreach ($map as $role => $generics) {
                $cmdId = $id($pick($generics));
                if ($cmdId !== null) {
                    $cmds[$role] = $cmdId;
                }
            }
            /* Il faut au moins une action : un état seul ne se pilote pas. */
            if (isset($cmds['on']) || isset($cmds['off']) || isset($cmds['toggle'])) {
                /* La prise d'une clim ou d'un thermostat va avec le chauffage. */
                $group = ($icon === 'light') ? 'lights' : ($thermostat ? 'heating' : 'plugs');
                $tiles[] = array('type' => 'switch', 'name' => $name, 'icon' => $icon, 'cmds' => $cmds, 'group' => $group);
            }
        }

        /* Volet. */
        $cmds = array();
        foreach (array('up' => array('FLAP_UP', 'FLAP_BSO_UP'), 'down' => array('FLAP_DOWN', 'FLAP_BSO_DOWN'),
                       'stop' => array('FLAP_STOP'), 'set' => array('FLAP_SLIDER'),
                       'state' => array('FLAP_STATE', 'FLAP_BSO_STATE')) as $role => $generics) {
            $cmdId = $id($pick($generics));
            if ($cmdId !== null) {
                $cmds[$role] = $cmdId;
            }
        }
        if (isset($cmds['up']) || isset($cmds['down']) || isset($cmds['set'])) {
            $tile = array('type' => 'shutter', 'name' => $name, 'icon' => 'shutter', 'cmds' => $cmds, 'group' => 'shutters');
            if (isset($cmds['set'])) {
                $slider = $pick(array('FLAP_SLIDER'));
                $min = isset($slider['minValue']) ? self::number($slider['minValue']) : null;
                $max = isset($slider['maxValue']) ? self::number($slider['maxValue']) : null;
                $tile['min'] = ($min !== null) ? $min : 0;
                $tile['max'] = ($max !== null) ? $max : 100;
                $tile['step'] = 10;
            }
            $tiles[] = $tile;
        }

        /* Consigne de thermostat. */
        $setSetpoint = $pick(array('THERMOSTAT_SET_SETPOINT'));
        $setpoint = $pick(array('THERMOSTAT_SETPOINT'));
        if ($setSetpoint !== null && $setpoint !== null) {
            $min = isset($setSetpoint['minValue']) ? self::number($setSetpoint['minValue']) : null;
            $max = isset($setSetpoint['maxValue']) ? self::number($setSetpoint['maxValue']) : null;
            $tiles[] = array(
                'type' => 'slider', 'name' => 'Consigne ' . $name, 'icon' => 'thermostat',
                'cmds' => array('state' => $id($setpoint), 'set' => $id($setSetpoint)),
                'min' => ($min !== null) ? $min : self::THERMOSTAT_BOUNDS['min'],
                'max' => ($max !== null) ? $max : self::THERMOSTAT_BOUNDS['max'],
                'step' => self::THERMOSTAT_BOUNDS['step'],
                'group' => 'heating',
            );
        }

        /* Températures. */
        $actionable = count($tiles) > 0;
        $kept = array();
        foreach ($temperatures as $cmd) {
            if ($cmd['generic'] === 'TEMPERATURE' && $actionable) {
                continue;
            }
            $kept[] = $cmd;
        }
        $alone = (count($kept) === 1 && !$actionable);
        foreach ($kept as $cmd) {
            $cmdName = isset($cmd['name']) ? (string) $cmd['name'] : '';
            $tiles[] = array(
                'type' => 'info',
                'name' => $alone ? $name : trim($name . ' – ' . $cmdName, ' –'),
                'icon' => 'temperature',
                'cmds' => array('state' => $id($cmd)),
                'group' => 'temperatures',
            );
        }

        foreach ($tiles as &$tile) {
            $tile['confirm'] = $sensitive || self::sensitiveName($tile['name']);
        }
        unset($tile);
        return $tiles;
    }

    /* Ordre des tuiles dans une page générée : ce qu'on pilote d'abord. */
    const GENERATED_ORDER = array('switch' => 0, 'shutter' => 1, 'slider' => 2, 'scene' => 3, 'info' => 4);

    /* Pages par type, dans cet ordre ; une page vide est omise. */
    const GROUPS = array(
        'lights'       => 'Lumières',
        'shutters'     => 'Volets',
        'heating'      => 'Chauffage et clim',
        'temperatures' => 'Températures',
        'plugs'        => 'Prises',
        'scenes'       => 'Scénarios',
    );

    const MODES = array('type', 'room');

    /*
     * Le nom d'une tuile sans le nom de la pièce qu'il contient déjà :
     * « Plafond salon » dans « Salon » → « Plafond ». Comparaison mot à mot,
     * sans accents ni casse ni ponctuation autour des mots. Si rien ne reste,
     * le nom est rendu tel quel.
     */
    public static function stripRoom($_name, $_room) {
        $core = function ($_word) {
            return trim(self::fold($_word), "()[]{},.;:!?'\"-–—·");
        };
        $roomWords = array_values(array_filter(array_map($core, preg_split('/\s+/u', trim((string) $_room))), 'strlen'));
        $words = preg_split('/\s+/u', trim((string) $_name));
        $count = count($roomWords);
        if ($count === 0 || count($words) < $count) {
            return (string) $_name;
        }
        $folded = array_map($core, $words);
        for ($i = 0; $i + $count <= count($words); $i++) {
            if (array_slice($folded, $i, $count) === $roomWords) {
                array_splice($words, $i, $count);
                $rest = trim(preg_replace('/\s+/u', ' ', implode(' ', $words)), " \t-–—·,;:");
                return ($rest === '') ? (string) $_name : $rest;
            }
        }
        return (string) $_name;
    }

    /* « Salon · Plafond » : la pièce en préfixe, sans répéter son nom. */
    public static function roomTileName($_room, $_name) {
        $room = trim((string) $_room);
        return ($room === '') ? (string) $_name : $room . ' · ' . self::stripRoom($_name, $room);
    }

    /*
     * Les pages proposées pour $_objects = [['id', 'name', 'eqLogics' => [$eq, …]], …],
     * objets dans l'ordre de Jeedom.
     *
     * - mode « type » (défaut) : une page par type (GROUPS, dans cet ordre,
     *   pages vides omises) ; tuiles nommées « Pièce · Nom », rangées par
     *   pièce (ordre des objets) puis par nom ;
     * - mode « room » : une page par objet, tuiles rangées par type.
     *
     * Les ids sont laissés vides : normalizePages() les attribue à
     * l'enregistrement (en reprenant ceux des tuiles inchangées).
     */
    public static function generatePages($_objects, $_mode = 'type') {
        if ($_mode === 'room') {
            return self::generateByRoom($_objects);
        }
        $groups = array();
        foreach (array_values($_objects) as $objectRank => $object) {
            $room = isset($object['name']) ? (string) $object['name'] : '';
            foreach ((isset($object['eqLogics']) ? $object['eqLogics'] : array()) as $eq) {
                foreach (self::tilesForEqLogic($eq) as $tile) {
                    $group = isset($tile['group']) ? $tile['group'] : 'temperatures';
                    $short = self::stripRoom($tile['name'], $room);
                    $tile['name'] = ($room === '') ? $tile['name'] : $room . ' · ' . $short;
                    $tile['_room'] = $objectRank;
                    $tile['_sort'] = self::fold($short);
                    $groups[$group][] = $tile;
                }
            }
        }
        $pages = array();
        foreach (self::GROUPS as $group => $title) {
            if (empty($groups[$group])) {
                continue;
            }
            $tiles = $groups[$group];
            usort($tiles, function ($_a, $_b) {
                if ($_a['_room'] !== $_b['_room']) {
                    return $_a['_room'] - $_b['_room'];
                }
                return strnatcasecmp($_a['_sort'], $_b['_sort']);
            });
            $pages[] = array('id' => '', 'name' => $title, 'tiles' => self::cleanGenerated($tiles));
        }
        return $pages;
    }

    private static function cleanGenerated($_tiles) {
        $clean = array();
        foreach ($_tiles as $tile) {
            $normalized = self::normalizeTile($tile);
            $normalized['cmds'] = self::roles($normalized);
            $clean[] = $normalized;
        }
        return $clean;
    }

    private static function generateByRoom($_objects) {
        $pages = array();
        foreach ($_objects as $object) {
            $tiles = array();
            $rank = 0;
            foreach ((isset($object['eqLogics']) ? $object['eqLogics'] : array()) as $eq) {
                foreach (self::tilesForEqLogic($eq) as $tile) {
                    $tile['_rank'] = $rank++;
                    $tiles[] = $tile;
                }
            }
            usort($tiles, function ($_a, $_b) {
                $order = self::GENERATED_ORDER[$_a['type']] - self::GENERATED_ORDER[$_b['type']];
                return ($order !== 0) ? $order : ($_a['_rank'] - $_b['_rank']);
            });
            $pages[] = array('id' => '', 'name' => self::cleanName(isset($object['name']) ? $object['name'] : '', 'Page'), 'tiles' => self::cleanGenerated($tiles));
        }
        return $pages;
    }

    /* ======================================================= ordres Jeedom → TV */

    /* Un ordre non livré au bout de 60 s est abandonné (contrat). */
    const QUEUE_TTL = 60;
    /* Durée d'affichage par défaut d'un ordre « show » (s) ; 0 = sans retour. */
    const DEFAULT_DURATION = 30;
    const MAX_DURATION = 86400;
    /* Au-delà, la file refuse : rien ne la vide si la TV ne répond plus, la
     * purge à 60 s mise à part. */
    const QUEUE_MAX = 50;

    /* Les commandes fixes de l'équipement : logicalId => définition. Les
     * commandes « Afficher <page> » s'y ajoutent, logicalId show_<id de page>. */
    const FIXED_COMMANDS = array(
        'show_page' => array('name' => 'Afficher page', 'type' => 'action', 'subType' => 'message'),
        'notify'    => array('name' => 'Message', 'type' => 'action', 'subType' => 'message'),
        'exit'      => array('name' => 'Quitter', 'type' => 'action', 'subType' => 'other'),
        'ask'       => array('name' => 'Question', 'type' => 'action', 'subType' => 'message'),
        'online'    => array('name' => 'En ligne', 'type' => 'info', 'subType' => 'binary'),
        'visible'   => array('name' => 'Visible', 'type' => 'info', 'subType' => 'binary'),
        'screen'    => array('name' => 'Écran allumé', 'type' => 'info', 'subType' => 'binary'),
        'page'      => array('name' => 'Page affichée', 'type' => 'info', 'subType' => 'string'),
    );
    const PAGE_COMMAND_PREFIX = 'show_';

    /* Retire les ordres échus : QUEUE_TTL secondes, ou la durée de vie propre
     * de l'ordre (« ttl », plus courte pour une question). */
    public static function queuePurge($_queue, $_now) {
        $out = array();
        foreach (is_array($_queue) ? $_queue : array() as $entry) {
            $ttl = (is_array($entry) && isset($entry['ttl'])) ? min(self::QUEUE_TTL, (float) $entry['ttl']) : self::QUEUE_TTL;
            if (is_array($entry) && isset($entry['ts'], $entry['order']) && $_now - $entry['ts'] < $ttl) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /* Ajoute un ordre (qui porte déjà son id) ; rend la file purgée. */
    public static function queuePush($_queue, $_order, $_now, $_ttl = null) {
        $queue = self::queuePurge($_queue, $_now);
        $entry = array('ts' => $_now, 'order' => $_order);
        if ($_ttl !== null) {
            $entry['ttl'] = $_ttl;
        }
        $queue[] = $entry;
        if (count($queue) > self::QUEUE_MAX) {
            $queue = array_slice($queue, -self::QUEUE_MAX);
        }
        return $queue;
    }

    /* Les ordres à livrer (encore valables, dans l'ordre des id). La file est
     * vidée par l'appelant : livraison une seule fois. */
    public static function queueOrders($_queue, $_now) {
        $orders = array();
        foreach (self::queuePurge($_queue, $_now) as $entry) {
            $orders[] = $entry['order'];
        }
        usort($orders, function ($_a, $_b) {
            return $_a['id'] - $_b['id'];
        });
        return $orders;
    }

    /* La page désignée par son id, sinon par son nom ; casse ignorée dans les
     * deux cas (contrat), accents ignorés en dernier recours. null si aucune. */
    public static function resolvePage($_pages, $_ref) {
        if (!is_string($_ref) && !is_int($_ref)) {
            return null;
        }
        $ref = trim((string) $_ref);
        if ($ref === '') {
            return null;
        }
        $pages = self::normalizePages($_pages);
        foreach ($pages as $page) {
            if (strtolower($page['id']) === strtolower($ref)) {
                return $page;
            }
        }
        foreach ($pages as $page) {
            if (mb_strtolower($page['name'], 'UTF-8') === mb_strtolower($ref, 'UTF-8')) {
                return $page;
            }
        }
        foreach ($pages as $page) {
            if (self::fold($page['name']) === self::fold($ref)) {
                return $page;
            }
        }
        return null;
    }

    /* Durée configurée sur l'équipement, ramenée à un entier valide. */
    public static function defaultDuration($_configured) {
        $duration = self::parseDuration($_configured, self::DEFAULT_DURATION);
        return ($duration === false) ? self::DEFAULT_DURATION : $duration;
    }

    /* Durée saisie : vide → $_default, entier ≥ 0 → lui-même (borné),
     * autre chose → false. */
    public static function parseDuration($_raw, $_default) {
        if ($_raw === null || (is_string($_raw) && trim($_raw) === '')) {
            return (int) $_default;
        }
        $number = self::number($_raw);
        if ($number === null || $number < 0) {
            return false;
        }
        return (int) min(self::MAX_DURATION, round($number));
    }

    /* Le nom d'une commande tel que Jeedom le garde (cleanComponanteName). */
    public static function cleanCommandName($_name) {
        $name = strip_tags(str_replace(array('&', '#', ']', '[', '%', '\\', '/', "'", '"', '*'), '', (string) $_name));
        return trim(substr(preg_replace('/\s+/', ' ', $name), 0, 127));
    }

    /*
     * Les commandes « Afficher <page> » attendues : logicalId => nom. Un nom
     * déjà pris (commande fixe, autre page homonyme) reçoit l'id de page en
     * suffixe : Jeedom impose des noms uniques par équipement.
     */
    public static function pageCommands($_pages) {
        $taken = array();
        foreach (self::FIXED_COMMANDS as $def) {
            $taken[mb_strtolower($def['name'], 'UTF-8')] = true;
        }
        $out = array();
        foreach (self::normalizePages($_pages) as $page) {
            $name = self::cleanCommandName('Afficher ' . $page['name']);
            if ($name === 'Afficher' || isset($taken[mb_strtolower($name, 'UTF-8')])) {
                $name = self::cleanCommandName('Afficher ' . $page['name'] . ' (' . $page['id'] . ')');
            }
            $taken[mb_strtolower($name, 'UTF-8')] = true;
            $out[self::PAGE_COMMAND_PREFIX . $page['id']] = $name;
        }
        return $out;
    }

    /* Le nom à publier dans « Page affichée » pour l'id reçu de la TV :
     * nom de la page, '' pour null, l'id brut s'il est inconnu. */
    public static function shownPageName($_pages, $_pageId) {
        if ($_pageId === null || $_pageId === '') {
            return '';
        }
        foreach (self::normalizePages($_pages) as $page) {
            if ($page['id'] === (string) $_pageId) {
                return $page['name'];
            }
        }
        return mb_substr((string) $_pageId, 0, 32, 'UTF-8');
    }

    /* Un booléen d'état reçu de la TV : true/false ou 1/0, sinon null. */
    public static function stateBool($_value) {
        if ($_value === true || $_value === 1 || $_value === '1') {
            return 1;
        }
        if ($_value === false || $_value === 0 || $_value === '0') {
            return 0;
        }
        return null;
    }

    /* ======================================== questions (bloc « Demander ») */

    const ASK_DEFAULT_TIMEOUT = 300;
    const ASK_MAX_ANSWERS = 20;

    /* Les réponses proposées : chaînes non vides, sans doublon, dans l'ordre.
     * « * » (réponse libre du bloc Demander) n'est pas proposable à la
     * télécommande : il est retiré, comme le fait le cœur. */
    public static function askAnswers($_answers) {
        $out = array();
        foreach (is_array($_answers) ? $_answers : array() as $answer) {
            if (!is_scalar($answer)) {
                continue;
            }
            $answer = trim((string) $answer);
            if ($answer === '' || $answer === '*' || in_array($answer, $out, true)) {
                continue;
            }
            $out[] = $answer;
            if (count($out) >= self::ASK_MAX_ANSWERS) {
                break;
            }
        }
        return $out;
    }

    /* Le délai de la question, en secondes entières ≥ 1. */
    public static function askTimeout($_timeout) {
        $number = self::number($_timeout);
        if ($number === null || $number <= 0) {
            return self::ASK_DEFAULT_TIMEOUT;
        }
        return (int) max(1, round($number));
    }

    /* Durée de vie de l'ordre en file : min(délai, 60 s). */
    public static function askTtl($_timeout) {
        return min(self::askTimeout($_timeout), self::QUEUE_TTL);
    }

    /*
     * L'ordre « ask » (sans id, ajouté à la mise en file) à partir des options
     * du bloc Demander, ou null si elles ne portent aucune réponse proposable
     * (la commande se comporte alors comme « Message »).
     *
     * Le cœur passe la question à la fois en titre et en message : le titre
     * identique est vidé, pour ne pas l'afficher deux fois.
     */
    public static function askOrder($_options, $_token) {
        $answers = self::askAnswers(isset($_options['answer']) ? $_options['answer'] : null);
        if (count($answers) === 0) {
            return null;
        }
        $title = trim((string) (isset($_options['title']) && is_scalar($_options['title']) ? $_options['title'] : ''));
        $message = trim((string) (isset($_options['message']) && is_scalar($_options['message']) ? $_options['message'] : ''));
        if ($message === '') {
            $message = $title;
            $title = '';
        }
        if ($title === $message) {
            $title = '';
        }
        return array('type' => 'ask', 'ask' => (string) $_token, 'title' => $title, 'message' => $message,
                     'answers' => $answers, 'timeout' => self::askTimeout(isset($_options['timeout']) ? $_options['timeout'] : null));
    }

    /* La question en attente retenue pour la TV. */
    public static function askPending($_token, $_cmdId, $_answers, $_timeout, $_now) {
        return array('token' => (string) $_token, 'cmd_id' => (int) $_cmdId, 'answers' => array_values($_answers),
                     'endtime' => $_now + self::askTimeout($_timeout));
    }

    /*
     * Contrôle d'une réponse de la TV, avant de la passer au cœur :
     *   200 : à transmettre ; 404 : pas de question, mauvais jeton ou délai
     *   passé ; 422 : réponse hors liste ; 400 : requête mal formée.
     */
    public static function checkAnswer($_pending, $_token, $_answer, $_now) {
        if (!is_string($_token) || $_token === '' || !is_string($_answer) && !is_int($_answer) && !is_float($_answer)) {
            return array('code' => 400, 'message' => 'Paramètres « ask » et « answer » attendus');
        }
        if (!is_array($_pending) || !isset($_pending['token'], $_pending['endtime'], $_pending['answers'])
            || !hash_equals((string) $_pending['token'], $_token)) {
            return array('code' => 404, 'message' => 'Question inconnue, expirée ou déjà répondue');
        }
        if ($_now > $_pending['endtime']) {
            return array('code' => 404, 'message' => 'Question expirée');
        }
        if (!in_array((string) $_answer, $_pending['answers'], true)) {
            return array('code' => 422, 'message' => 'Réponse non proposée');
        }
        return array('code' => 200, 'answer' => (string) $_answer);
    }
}
