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
    const TYPES = array('switch', 'shutter', 'slider', 'info', 'scene', 'button', 'select');
    const ICONS = array('light', 'plug', 'shutter', 'thermostat', 'temperature', 'scene', 'fan', 'lock', 'alarm', 'camera', 'sun', 'rain', 'trash', 'power', 'generic');

    /* Les rôles de commande d'une tuile. « state » est la commande info lue
     * pour « value » ; les autres sont des commandes action. */
    const ROLES = array('state', 'on', 'off', 'toggle', 'up', 'down', 'stop', 'set', 'press');

    /* Actions permises par type (contrat, POST exec). */
    const ACTIONS = array(
        'switch'  => array('on', 'off', 'toggle'),
        'shutter' => array('up', 'down', 'stop', 'set'),
        'slider'  => array('set'),
        'scene'   => array('run'),
        'button'  => array('press'),
        'select'  => array('set'),
        'info'    => array(),
    );

    /* Les rôles utiles à chaque type, dans l'ordre d'affichage de l'éditeur. */
    const TYPE_ROLES = array(
        'switch'  => array('state', 'on', 'off', 'toggle'),
        'shutter' => array('state', 'up', 'down', 'stop', 'set'),
        'slider'  => array('state', 'set'),
        'info'    => array('state'),
        'scene'   => array(),
        'button'  => array('press', 'state'),
        'select'  => array('set', 'state'),
    );

    /* Options fixes d'un bouton, passées à sa commande « press ». Seules les
     * clés utiles au sous-type de la commande partent à l'exécution. */
    const BUTTON_OPTIONS = array('title', 'message', 'slider', 'select', 'color');
    const BUTTON_SUBTYPE_OPTIONS = array(
        'message' => array('title', 'message'),
        'slider'  => array('slider'),
        'select'  => array('select'),
        'color'   => array('color'),
        'other'   => array(),
    );
    const MAX_OPTION = 4096;

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

    /* Ce qu'une tuile pilote : type, rôles et scénario (et options d'un bouton :
     * deux boutons sur la même commande ne font pas la même chose). */
    public static function signature($_tile) {
        $roles = self::roles($_tile);
        ksort($roles);
        $signature = $_tile['type'] . '|' . json_encode($roles) . '|' . (isset($_tile['scenario_id']) ? (int) $_tile['scenario_id'] : 0);
        if ($_tile['type'] === 'button' && isset($_tile['options'])) {
            $options = (array) $_tile['options'];
            ksort($options);
            $signature .= '|' . json_encode($options, JSON_UNESCAPED_UNICODE);
        }
        return $signature;
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
        $tile = array(
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
        /* Unité imposée (facultative) : présente seulement si elle est
         * renseignée, pour que la révision des configurations sans unité ne
         * change pas. */
        $unit = (isset($_tile['unit']) && is_string($_tile['unit'])) ? trim($_tile['unit']) : '';
        if ($unit !== '') {
            $tile['unit'] = mb_substr($unit, 0, 8, 'UTF-8');
        }
        /* Options d'un bouton : seulement sur un bouton (la révision des autres
         * tuiles ne change pas), en objet pour que {} reste {}. */
        if ($type === 'button') {
            $tile['options'] = (object) self::buttonOptions(isset($_tile['options']) ? $_tile['options'] : null);
        }
        return $tile;
    }

    /* Les options fixes d'un bouton, conservées telles quelles : clés connues,
     * valeurs scalaires en chaîne, vides retirées. */
    public static function buttonOptions($_options) {
        if (is_object($_options)) {
            $_options = (array) $_options;
        }
        if (is_string($_options)) {
            $decoded = json_decode($_options, true);
            $_options = is_array($decoded) ? $decoded : array();
        }
        $out = array();
        foreach (self::BUTTON_OPTIONS as $key) {
            if (!is_array($_options) || !isset($_options[$key]) || !is_scalar($_options[$key]) || is_bool($_options[$key])) {
                continue;
            }
            $value = (string) $_options[$key];
            if (trim($value) === '') {
                continue;
            }
            $out[$key] = mb_substr($value, 0, self::MAX_OPTION, 'UTF-8');
        }
        return $out;
    }

    /* Les options à passer à la commande « press » selon son sous-type :
     * message → title et message (vides par défaut) ; slider → slider ;
     * select → select ; color → color ; other (ou inconnu) → rien. */
    public static function pressOptions($_options, $_subType) {
        $options = self::buttonOptions($_options);
        $keys = isset(self::BUTTON_SUBTYPE_OPTIONS[$_subType]) ? self::BUTTON_SUBTYPE_OPTIONS[$_subType] : array();
        $out = array();
        foreach ($keys as $key) {
            if (isset($options[$key])) {
                $out[$key] = ($key === 'slider' && self::number($options[$key]) !== null) ? self::number($options[$key]) : $options[$key];
            } elseif ($_subType === 'message') {
                $out[$key] = '';
            }
        }
        return $out;
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
    public static function revision($_pages, $_scenesPage = null, $_keys = null, $_header = null, $_resolve = null) {
        $json = json_encode(self::normalizePages($_pages), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        /* La page dynamique des scénarios compte aussi : un scénario ajouté au
         * groupe, renommé ou (dés)activé change la révision. Sans elle, le
         * calcul est celui d'avant (révisions existantes inchangées). */
        if (is_array($_scenesPage)) {
            $json .= '|' . json_encode($_scenesPage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        /* Les touches de couleur effectives aussi ; aucune touche : calcul
         * d'avant, révisions existantes inchangées. */
        $keys = self::layoutKeys($_keys, $_pages, $_scenesPage);
        if (count($keys) > 0) {
            $json .= '|keys' . json_encode($keys);
        }
        /* Le bandeau aussi (sa configuration, pas ses valeurs) ; vide :
         * calcul d'avant. */
        $header = self::normalizeHeader($_header);
        if (count($header) > 0) {
            $json .= '|header' . json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        /* Les listes de choix des tuiles select, lues dans leurs commandes
         * (seulement si on sait les lire) ; aucune tuile select : calcul
         * d'avant. */
        if ($_resolve !== null) {
            $choices = self::selectChoices($_pages, $_resolve);
            if (count($choices) > 0) {
                $json .= '|choices' . json_encode($choices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        return substr(sha1((string) $json), 0, 8);
    }

    /* ========================================================= bandeau d'infos */

    const HEADER_MAX = 6;
    const HEADER_LABEL_MAX = 24;

    /* Un id d'élément du bandeau : « h<n> », sinon ''. */
    private static function headerId($_id) {
        $id = self::cleanId($_id);
        return preg_match('/^h[1-9]\d{0,8}$/', $id) ? $id : '';
    }

    /*
     * Le bandeau tel qu'enregistré : au plus 6 éléments [id, cmd, label, icon],
     * dans l'ordre. Un élément sans commande valide est retiré ; un libellé
     * est ramené à 24 caractères ; une icône inconnue devient « generic ».
     *
     * Comme pour les tuiles, les ids existants sont conservés et un id absent,
     * invalide ou en double reçoit un numéro jamais servi : au-delà de
     * $_floor (plus grand numéro attribué) et de ceux de $_previous. « h3 »
     * ne désigne ainsi jamais une autre info qu'avant.
     */
    public static function normalizeHeader($_header, $_previous = null, $_floor = 0) {
        if (is_string($_header)) {
            $decoded = json_decode($_header, true);
            $_header = is_array($decoded) ? $decoded : array();
        }
        if (!is_array($_header)) {
            return array();
        }
        $items = array();
        $used = array();
        foreach (array_values($_header) as $raw) {
            if (is_object($raw)) {
                $raw = (array) $raw;
            }
            if (!is_array($raw) || count($items) >= self::HEADER_MAX) {
                continue;
            }
            $cmd = isset($raw['cmd']) ? self::cmdId($raw['cmd']) : null;
            if ($cmd === null) {
                continue;
            }
            $id = self::headerId(isset($raw['id']) ? $raw['id'] : '');
            if ($id !== '' && isset($used[$id])) {
                $id = '';
            }
            if ($id !== '') {
                $used[$id] = true;
            }
            $label = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', isset($raw['label']) && is_scalar($raw['label']) ? (string) $raw['label'] : ''));
            $items[] = array(
                'id'    => $id,
                'cmd'   => $cmd,
                'label' => mb_substr($label, 0, self::HEADER_LABEL_MAX, 'UTF-8'),
                'icon'  => (isset($raw['icon']) && in_array($raw['icon'], self::ICONS, true)) ? $raw['icon'] : 'generic',
            );
        }
        $previousIds = array();
        if ($_previous !== null) {
            foreach (self::normalizeHeader($_previous) as $old) {
                $previousIds[] = $old['id'];
            }
        }
        $next = max(self::nextNumber(array_keys($used), 'h'), self::nextNumber($previousIds, 'h'), (int) $_floor + 1);
        foreach ($items as &$item) {
            if ($item['id'] === '') {
                $item['id'] = 'h' . $next++;
            }
        }
        unset($item);
        return $items;
    }

    /* Le plus grand numéro « h<n> » du bandeau. */
    public static function maxHeaderNumber($_header) {
        $ids = array();
        foreach (self::normalizeHeader($_header) as $item) {
            $ids[] = $item['id'];
        }
        return self::nextNumber($ids, 'h') - 1;
    }

    /* Le bandeau servi : value et unit comme une tuile info ; un élément dont
     * la commande n'existe plus est retiré, sans erreur. */
    public static function buildHeader($_header, $_resolve) {
        $out = array();
        foreach (self::normalizeHeader($_header) as $item) {
            $cmd = $_resolve($item['cmd']);
            if (!is_array($cmd)) {
                continue;
            }
            $isInfo = !isset($cmd['type']) || $cmd['type'] === 'info';
            $out[] = array(
                'id'    => $item['id'],
                'label' => $item['label'],
                'icon'  => $item['icon'],
                'value' => $isInfo ? self::valueString(isset($cmd['value']) ? $cmd['value'] : null) : null,
                'unit'  => (isset($cmd['unit']) && $cmd['unit'] !== null) ? (string) $cmd['unit'] : '',
            );
        }
        return $out;
    }

    /* ======================================================= touches de couleur */

    const KEY_COLORS = array('red', 'green', 'yellow', 'blue');

    /* Les touches telles qu'enregistrées : couleur connue => id de page valide,
     * dans l'ordre rouge, vert, jaune, bleu. Une touche vide est retirée. */
    public static function normalizeKeys($_keys) {
        if (is_string($_keys)) {
            $decoded = json_decode($_keys, true);
            $_keys = is_array($decoded) ? $decoded : array();
        }
        if (is_object($_keys)) {
            $_keys = (array) $_keys;
        }
        $out = array();
        foreach (self::KEY_COLORS as $color) {
            if (is_array($_keys) && isset($_keys[$color])) {
                $id = self::cleanId($_keys[$color]);
                if ($id !== '') {
                    $out[$color] = $id;
                }
            }
        }
        return $out;
    }

    /* Les touches servies : seulement celles dont la page existe (pages
     * manuelles ou page dynamique des scénarios). Une page supprimée retire
     * sa couleur, sans erreur. */
    public static function layoutKeys($_keys, $_pages, $_scenesPage = null) {
        $ids = array();
        foreach (self::allPages($_pages, $_scenesPage) as $page) {
            $ids[$page['id']] = true;
        }
        $out = array();
        foreach (self::normalizeKeys($_keys) as $color => $id) {
            if (isset($ids[$id])) {
                $out[$color] = $id;
            }
        }
        return $out;
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
        if (isset($_tile['unit']) && $_tile['unit'] !== '') {
            $unit = (string) $_tile['unit'];
        } elseif (is_array($state) && isset($state['unit']) && $state['unit'] !== '') {
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
        /* Liste de choix : relue à chaque appel dans la commande « set ». */
        if ($_tile['type'] === 'select') {
            $out['choices'] = self::parseChoices(is_array($set) && isset($set['listValue']) ? $set['listValue'] : '');
        }
        return $out;
    }

    /* ======================================================= liste de choix */

    const MAX_CHOICES = 50;

    /*
     * Les choix d'une commande select, d'après son listValue
     * « valeur|Libellé;valeur|Libellé… », dans l'ordre. Un élément sans « | »
     * sert de valeur et de libellé ; un élément vide est ignoré, une valeur
     * déjà vue aussi (la première l'emporte).
     */
    public static function parseChoices($_listValue) {
        if (!is_string($_listValue) || trim($_listValue) === '') {
            return array();
        }
        $out = array();
        $seen = array();
        foreach (explode(';', $_listValue) as $item) {
            if (trim($item) === '') {
                continue;
            }
            $parts = explode('|', $item, 2);
            $value = trim($parts[0]);
            $label = isset($parts[1]) ? trim($parts[1]) : $value;
            if ($value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $out[] = array('value' => $value, 'label' => ($label === '') ? $value : $label);
            if (count($out) >= self::MAX_CHOICES) {
                break;
            }
        }
        return $out;
    }

    /* Les listes de choix des tuiles select (id de tuile => choix), pour la
     * révision : une liste modifiée dans l'autre plugin la change. */
    public static function selectChoices($_pages, $_resolve) {
        $out = array();
        foreach (self::normalizePages($_pages) as $page) {
            foreach ($page['tiles'] as $tile) {
                if ($tile['type'] !== 'select') {
                    continue;
                }
                $roles = self::roles($tile);
                $set = isset($roles['set']) ? $_resolve($roles['set']) : null;
                $out[$tile['id']] = self::parseChoices(is_array($set) && isset($set['listValue']) ? $set['listValue'] : '');
            }
        }
        return $out;
    }

    public static function buildLayout($_pages, $_resolve, $_scenesPage = null, $_keys = null, $_header = null) {
        $pages = self::normalizePages($_pages);
        $out = array();
        foreach ($pages as $page) {
            $tiles = array();
            foreach ($page['tiles'] as $tile) {
                $tiles[] = self::buildTile($tile, $_resolve);
            }
            $out[] = array('id' => $page['id'], 'name' => $page['name'], 'tiles' => $tiles);
        }
        if (is_array($_scenesPage)) {
            $tiles = array();
            foreach ($_scenesPage['tiles'] as $tile) {
                $tiles[] = self::buildTile($tile, $_resolve);
            }
            $out[] = array('id' => $_scenesPage['id'], 'name' => $_scenesPage['name'], 'tiles' => $tiles);
        }
        $layout = array('schema' => self::SCHEMA, 'revision' => self::revision($pages, $_scenesPage, $_keys, $_header, $_resolve));
        /* « keys » omis quand aucune touche n'est active (contrat). */
        $keys = self::layoutKeys($_keys, $pages, $_scenesPage);
        if (count($keys) > 0) {
            $layout['keys'] = $keys;
        }
        /* « header » omis quand le bandeau est vide. */
        $header = self::buildHeader($_header, $_resolve);
        if (count($header) > 0) {
            $layout['header'] = $header;
        }
        $layout['pages'] = $out;
        return $layout;
    }

    /* La tuile d'id donné, ou null : pages manuelles d'abord, puis la page
     * dynamique des scénarios. */
    public static function findTile($_pages, $_tileId, $_scenesPage = null) {
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
        if (is_array($_scenesPage)) {
            foreach ($_scenesPage['tiles'] as $tile) {
                if ($tile['id'] === $_tileId) {
                    return $tile;
                }
            }
        }
        return null;
    }

    /* ======================================== page dynamique des scénarios */

    const SCENES_PAGE_ID = 'scenes';
    const SCENES_PAGE_NAME = 'Scénarios';
    const SCENES_PAGE_ALT_NAME = 'Ambiances';
    const SCENES_CONFIRM_TAG = '[confirmer]';

    /* Confirmation d'un scénario : « [confirmer] » dans sa description, ou un
     * nom sensible (portail, garage, verrou, alarme, panique). */
    public static function sceneConfirm($_name, $_description) {
        return stripos((string) $_description, self::SCENES_CONFIRM_TAG) !== false || self::sensitiveName($_name);
    }

    /*
     * La page « Scénarios » d'un groupe : une tuile scene par scénario ACTIF,
     * triée par nom, d'id s<id du scénario>. null s'il n'y en a aucun.
     * $_scenarios = [['id', 'name', 'description', 'isActive'], …]
     * La page s'appelle « Ambiances » si une page manuelle s'appelle déjà
     * « Scénarios ».
     */
    public static function scenesPage($_pages, $_scenarios) {
        $tiles = array();
        foreach (is_array($_scenarios) ? $_scenarios : array() as $scenario) {
            if (!isset($scenario['id'], $scenario['name']) || empty($scenario['isActive'])) {
                continue;
            }
            $tiles[] = array(
                'id' => 's' . (int) $scenario['id'], 'type' => 'scene', 'name' => self::cleanName($scenario['name'], 'Scénario'),
                'icon' => 'scene', 'confirm' => self::sceneConfirm($scenario['name'], isset($scenario['description']) ? $scenario['description'] : ''),
                'cmds' => (object) array(), 'scenario_id' => (int) $scenario['id'], 'min' => null, 'max' => null, 'step' => null,
            );
        }
        if (count($tiles) === 0) {
            return null;
        }
        usort($tiles, function ($_a, $_b) {
            $order = strnatcasecmp(self::fold($_a['name']), self::fold($_b['name']));
            return ($order !== 0) ? $order : ($_a['scenario_id'] - $_b['scenario_id']);
        });
        $name = self::SCENES_PAGE_NAME;
        foreach (self::normalizePages($_pages) as $page) {
            if (self::fold($page['name']) === self::fold(self::SCENES_PAGE_NAME)) {
                $name = self::SCENES_PAGE_ALT_NAME;
            }
        }
        return array('id' => self::SCENES_PAGE_ID, 'name' => $name, 'tiles' => $tiles);
    }

    /* Pages manuelles suivies de la page dynamique (pour les commandes et la
     * résolution d'une page par id ou par nom). */
    public static function allPages($_pages, $_scenesPage = null) {
        $pages = self::normalizePages($_pages);
        if (is_array($_scenesPage)) {
            $pages[] = array('id' => $_scenesPage['id'], 'name' => $_scenesPage['name'], 'tiles' => array());
        }
        return $pages;
    }

    /* Commande info suivie => ids des tuiles qui l'affichent (scènes exclues),
     * puis ids des éléments du bandeau. */
    public static function stateMap($_pages, $_header = null) {
        $map = array();
        foreach (self::normalizePages($_pages) as $page) {
            foreach ($page['tiles'] as $tile) {
                $roles = self::roles($tile);
                if ($tile['type'] !== 'scene' && isset($roles['state'])) {
                    $map[$roles['state']][] = $tile['id'];
                }
            }
        }
        foreach (self::normalizeHeader($_header) as $item) {
            $map[$item['cmd']][] = $item['id'];
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
     * $_setCmd : ['minValue', 'maxValue'] de la commande « set » (pour bornes) ;
     * $_pressCmd : ['subType' => …] de la commande « press » (bouton).
     */
    public static function resolveAction($_tile, $_action, $_value = null, $_state = null, $_setCmd = null, $_pressCmd = null) {
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

        if ($type === 'button') {
            if (!isset($roles['press'])) {
                return array('error' => 422, 'message' => 'Aucune commande associée à ce bouton');
            }
            $subType = (is_array($_pressCmd) && isset($_pressCmd['subType'])) ? (string) $_pressCmd['subType'] : 'other';
            return array('cmd' => $roles['press'], 'action' => 'press',
                         'options' => self::pressOptions(isset($_tile['options']) ? $_tile['options'] : null, $subType));
        }

        if ($type === 'select') {
            if (!isset($roles['set'])) {
                return array('error' => 422, 'message' => 'Aucune commande de choix associée à cette tuile');
            }
            if ($_value === null || !is_scalar($_value) || is_bool($_value) || trim((string) $_value) === '') {
                return array('error' => 400, 'message' => 'Paramètre « value » manquant');
            }
            $value = trim((string) $_value);
            foreach (self::parseChoices(is_array($_setCmd) && isset($_setCmd['listValue']) ? $_setCmd['listValue'] : '') as $choice) {
                if ($choice['value'] === $value) {
                    return array('cmd' => $roles['set'], 'action' => 'set', 'options' => array('select' => $value), 'value' => $value);
                }
            }
            return array('error' => 422, 'message' => 'Choix « ' . mb_substr($value, 0, 20, 'UTF-8') . ' » absent de la liste');
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
     *   THERMOSTAT_SET_MODE (action/select) → select « <nom> · Mode »,
     *                                       state THERMOSTAT_MODE
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

        /* Lumière variable : LIGHT_SLIDER, avec une info de luminosité
         * (LIGHT_BRIGHTNESS, sinon un LIGHT_STATE numérique), donne en plus un
         * curseur « <nom> (luminosité) », en %. */
        $lightSlider = $pick(array('LIGHT_SLIDER'));
        $brightness = $pick(array('LIGHT_BRIGHTNESS'));
        if ($brightness === null) {
            $lightState = $pick(array('LIGHT_STATE'));
            if ($lightState !== null && isset($lightState['subType']) && $lightState['subType'] === 'numeric') {
                $brightness = $lightState;
            }
        }
        if ($lightSlider !== null && $brightness !== null
            && (!isset($lightSlider['type']) || $lightSlider['type'] === 'action')) {
            $min = isset($lightSlider['minValue']) ? self::number($lightSlider['minValue']) : null;
            $max = isset($lightSlider['maxValue']) ? self::number($lightSlider['maxValue']) : null;
            $tiles[] = array(
                'type' => 'slider', 'name' => $name . ' (luminosité)', 'icon' => 'light',
                'cmds' => array('state' => $id($brightness), 'set' => $id($lightSlider)),
                'min' => ($min !== null) ? $min : 0, 'max' => ($max !== null) ? $max : 100, 'step' => 10,
                'unit' => '%', 'group' => 'lights',
            );
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

        /* Mode de thermostat ou de clim : une liste de choix. */
        $setMode = $pick(array('THERMOSTAT_SET_MODE'));
        if ($setMode !== null && (!isset($setMode['type']) || $setMode['type'] === 'action')
            && (!isset($setMode['subType']) || $setMode['subType'] === 'select')) {
            $cmds = array('set' => $id($setMode));
            $mode = $pick(array('THERMOSTAT_MODE'));
            if ($mode !== null && (!isset($mode['type']) || $mode['type'] === 'info')) {
                $cmds['state'] = $id($mode);
            }
            $tiles[] = array('type' => 'select', 'name' => $name . ' · Mode', 'icon' => 'thermostat', 'cmds' => $cmds, 'group' => 'heating');
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
    const GENERATED_ORDER = array('switch' => 0, 'shutter' => 1, 'slider' => 2, 'select' => 2, 'scene' => 3, 'button' => 3, 'info' => 4);

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
        'appVersion' => array('name' => 'Version app', 'type' => 'info', 'subType' => 'string'),
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

    /* La version de l'application reçue de la TV : chaîne non vide d'au plus
     * 64 caractères imprimables, sinon null. */
    public static function stateVersion($_value) {
        if (!is_string($_value)) {
            return null;
        }
        $value = trim($_value);
        if ($value === '' || strlen($value) > 64 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return null;
        }
        return $value;
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

    /* ================================= images jointes (notify et ask) */

    const IMAGE_MAX_BYTES = 5242880;
    /* Une image vit au moins 5 min : la TV la télécharge après avoir reçu
     * l'ordre, et peut la réafficher. */
    const IMAGE_MIN_LIFETIME = 300;
    const IMAGE_MIMES = array('image/jpeg' => 'jpg', 'image/png' => 'png');

    /* Extension d'image plausible (choix du « premier fichier image » d'une liste). */
    public static function looksLikeImage($_path) {
        return is_string($_path) && preg_match('/\.(jpe?g|png)$/i', trim($_path)) === 1;
    }

    /* Le premier fichier image d'une liste (tableau, ou chaîne séparée par des virgules). */
    public static function firstImage($_files) {
        if (is_string($_files)) {
            $_files = explode(',', $_files);
        }
        foreach (is_array($_files) ? $_files : array() as $file) {
            if (is_string($file) && self::looksLikeImage($file)) {
                return trim($file);
            }
        }
        return null;
    }

    private static function tidyText($_text) {
        return trim(preg_replace('/[ \t]{2,}/', ' ', (string) $_text));
    }

    /*
     * Le texte à afficher et l'image demandée, d'après les options d'une
     * commande Message ou Question. Priorité (contrat) :
     *   1. [image=<chemin>] dans le titre ou le message (marqueurs retirés) ;
     *   2. $_options['files'] : le premier fichier image ;
     *   3. files=<chemin>[,…] dans un titre « title=… | files=… ».
     * La syntaxe « title=… | files=… » donne toujours son vrai titre.
     * Le marqueur [durée=<s>] est lu (ramené entre 3 et 120 s) et retiré.
     * Rend ['title', 'message', 'path' (null si aucune image),
     *       'duration' (null si aucun marqueur valide)].
     */
    public static function extractImage($_title, $_message, $_files = null) {
        $title = is_scalar($_title) ? (string) $_title : '';
        $message = is_scalar($_message) ? (string) $_message : '';
        $path = null;
        $duration = null;
        foreach (array(&$title, &$message) as &$text) {
            if (preg_match_all(self::DURATION_MARKER, $text, $m)) {
                foreach ($m[1] as $candidate) {
                    $seconds = self::number($candidate);
                    if ($duration === null && $seconds !== null) {
                        $duration = (int) round(max(self::NOTIFY_MIN_DURATION, min(self::NOTIFY_MAX_DURATION, $seconds)));
                    }
                }
                $text = preg_replace(self::DURATION_MARKER, '', $text);
            }
        }
        unset($text);
        foreach (array(&$title, &$message) as &$text) {
            if (preg_match_all('/\[image=([^\]]*)\]/i', $text, $m)) {
                foreach ($m[1] as $candidate) {
                    if ($path === null && trim($candidate) !== '') {
                        $path = trim($candidate);
                    }
                }
                $text = preg_replace('/\[image=[^\]]*\]/i', '', $text);
            }
        }
        unset($text);
        if ($path === null) {
            $path = self::firstImage($_files);
        }
        /* title=… | files=… */
        if (preg_match('/(^|\|)\s*(title|files)\s*=/i', $title)) {
            $parsedTitle = '';
            $parsedFiles = null;
            foreach (explode('|', $title) as $segment) {
                if (preg_match('/^\s*(title|files)\s*=(.*)$/is', $segment, $m)) {
                    if (strtolower($m[1]) === 'title') {
                        $parsedTitle = trim($m[2]);
                    } else {
                        $parsedFiles = $m[2];
                    }
                }
            }
            $title = $parsedTitle;
            if ($path === null && $parsedFiles !== null) {
                $path = self::firstImage($parsedFiles);
            }
        }
        return array('title' => self::tidyText($title), 'message' => self::tidyText($message), 'path' => $path, 'duration' => $duration);
    }

    /* Durée d'un bandeau « notify » : [durée=<s>] (ou [duree=…]), 3 à 120 s. */
    const DURATION_MARKER = '/\[dur(?:é|e|É|E)e\s*=\s*([^\]]*)\]/iu';
    const NOTIFY_MIN_DURATION = 3;
    const NOTIFY_MAX_DURATION = 120;

    /*
     * Un fichier image acceptable : chemin réel sous l'une des racines (liens
     * symboliques et « ../ » résolus par realpath), fichier ordinaire, JPEG ou
     * PNG d'après son contenu, 5 Mo au plus.
     * Rend ['ok' => true, 'real', 'mime', 'ext'] ou ['ok' => false, 'reason'].
     */
    public static function validateImage($_path, $_roots, $_maxBytes = self::IMAGE_MAX_BYTES) {
        if (!is_string($_path) || trim($_path) === '' || strpos($_path, "\0") !== false) {
            return array('ok' => false, 'reason' => 'chemin vide');
        }
        $real = realpath(trim($_path));
        if ($real === false || !is_file($real)) {
            return array('ok' => false, 'reason' => 'fichier introuvable');
        }
        $inside = false;
        foreach ((array) $_roots as $root) {
            $rootReal = is_string($root) ? realpath($root) : false;
            if ($rootReal !== false && strpos($real, rtrim($rootReal, '/') . '/') === 0) {
                $inside = true;
                break;
            }
        }
        if (!$inside) {
            return array('ok' => false, 'reason' => 'hors des dossiers autorisés');
        }
        $size = filesize($real);
        if ($size === false || $size <= 0 || $size > $_maxBytes) {
            return array('ok' => false, 'reason' => 'taille refusée (' . (int) $size . ' o)');
        }
        $mime = '';
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string) $finfo->file($real);
        }
        if (!isset(self::IMAGE_MIMES[$mime])) {
            return array('ok' => false, 'reason' => 'type refusé (' . ($mime === '' ? 'inconnu' : $mime) . ')');
        }
        return array('ok' => true, 'real' => $real, 'mime' => $mime, 'ext' => self::IMAGE_MIMES[$mime]);
    }

    /* Fin de vie d'une image : celle de l'ordre, au plus tôt 5 min. */
    public static function imageExpiry($_now, $_orderLifetime) {
        return (int) ($_now + max(self::IMAGE_MIN_LIFETIME, (int) $_orderLifetime));
    }

    public static function validImageId($_id) {
        return is_string($_id) && preg_match('/^[0-9a-f]{32}$/', $_id) === 1;
    }

    /* --- magasin d'images d'une TV : <id>.<jpg|png> et <id>.json {mime, expires} */

    /* Copie l'image validée ; rend l'identifiant, ou null. */
    public static function storeImage($_dir, $_valid, $_expires, $_id) {
        if (!self::validImageId($_id) || empty($_valid['ok'])) {
            return null;
        }
        if (!is_dir($_dir) && !@mkdir($_dir, 0775, true)) {
            return null;
        }
        $file = $_dir . '/' . $_id . '.' . $_valid['ext'];
        if (!@copy($_valid['real'], $file)) {
            return null;
        }
        $meta = json_encode(array('mime' => $_valid['mime'], 'ext' => $_valid['ext'], 'expires' => (int) $_expires));
        if (@file_put_contents($_dir . '/' . $_id . '.json', $meta) === false) {
            @unlink($file);
            return null;
        }
        return $_id;
    }

    /* L'image d'identifiant donné, encore valable : ['path', 'mime'] ou null. */
    public static function findImage($_dir, $_id, $_now) {
        if (!self::validImageId($_id)) {
            return null;
        }
        $meta = json_decode((string) @file_get_contents($_dir . '/' . $_id . '.json'), true);
        if (!is_array($meta) || !isset($meta['mime'], $meta['ext'], $meta['expires']) || $meta['expires'] <= $_now
            || !isset(self::IMAGE_MIMES[$meta['mime']]) || self::IMAGE_MIMES[$meta['mime']] !== $meta['ext']) {
            return null;
        }
        $path = $_dir . '/' . $_id . '.' . $meta['ext'];
        return is_file($path) ? array('path' => $path, 'mime' => $meta['mime']) : null;
    }

    /* Supprime les images expirées (et les fichiers orphelins) ; rend le nombre supprimé. */
    public static function purgeImages($_dir, $_now) {
        if (!is_dir($_dir)) {
            return 0;
        }
        $removed = 0;
        foreach (glob($_dir . '/*.{jpg,png,json}', GLOB_BRACE) ?: array() as $file) {
            $id = substr(basename($file), 0, strpos(basename($file), '.'));
            if (!self::validImageId($id)) {
                continue;
            }
            if (self::findImage($_dir, $id, $_now) === null) {
                @unlink($file);
                $removed++;
            }
        }
        return $removed;
    }
}
