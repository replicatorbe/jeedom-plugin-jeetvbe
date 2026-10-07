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
 * Ce qui remplace TvOverlay, en logique pure (sans Jeedom ni réseau) :
 *
 *   - la barre d'état : réglages, indicateurs automatiques au format
 *     « auto_fixed » du plugin tvoverlaybe (mêmes champs, même sémantique :
 *     conditions OU/ET, texte fixe ou issu d'une commande, icône fixe ou issue
 *     d'une commande avec repli, couleurs, forme), indicateurs temporaires de
 *     « Indicateur (JSON) » avec leur expiration, retrait à la main ;
 *   - la conversion des JSON au format TvOverlay (Notifier, Indicateur) ;
 *   - les sources vidéo nommées, le marqueur [video=…] et le masquage des
 *     adresses (identifiants) dans les journaux et la page.
 *
 * Les valeurs des commandes arrivent par une fonction passée en paramètre,
 * l'heure aussi : tests/run.php vérifie tout hors ligne.
 *
 * Contrat : docs/api.md, sections « Barre d'état », « Notifications riches »,
 * « Côté Jeedom : compatibilité TvOverlay ».
 */
class jeetvbeOverlay {

    /* ============================================================ réglages */

    const BAR_CORNERS = array('bottom_start', 'bottom_end', 'top_start', 'top_end');
    const DEFAULT_BAR_CORNER = 'bottom_start';
    const DEFAULT_OPACITY = 85;

    /* Coins d'un bandeau notify : top_end par défaut côté TV. */
    const NOTIFY_CORNERS = array('top_end', 'top_start', 'bottom_end', 'bottom_start');

    const OPERATORS = array('==', '!=', '>', '>=', '<', '<=');
    const SHAPES = array('circle', 'rounded', 'rectangular');
    /* Les couleurs d'un indicateur, noms TvOverlay. */
    const COLORS = array('iconColor', 'messageColor', 'borderColor', 'backgroundColor');

    /* Valeurs servies quand l'indicateur n'en dit rien : icône et texte
     * blancs, ni bordure ni fond (transparents), cercle. */
    const DEFAULT_ICON = 'mdi:information-outline';
    const DEFAULT_SHAPE = 'circle';
    const DEFAULT_ITEM_COLORS = array('iconColor' => '#FFFFFF', 'textColor' => '#FFFFFF',
                                      'borderColor' => '#00000000', 'backgroundColor' => '#00000000');

    /* Gardée telle quelle pour l'importation depuis tvoverlaybe ; sans effet
     * ici (la barre est recalculée en permanence, rien à renouveler). */
    const DEFAULT_EXPIRATION = '12h';

    const MAX_INDICATORS = 30;
    const MAX_TEMPORARY = 20;
    const MAX_ITEM_TEXT = 40;
    const MAX_ID = 64;

    /* Réglages de la barre : activée ou non (non par défaut : une TV existante
     * ne voit pas apparaître une barre qu'on n'a pas demandée), coin, horloge,
     * opacité 0–100. */
    public static function normalizeBar($_bar) {
        if (is_string($_bar)) {
            $decoded = json_decode($_bar, true);
            $_bar = is_array($decoded) ? $decoded : array();
        }
        $bar = is_array($_bar) ? $_bar : array();
        $flag = function ($_key, $_default) use ($bar) {
            if (!array_key_exists($_key, $bar) || $bar[$_key] === '' || $bar[$_key] === null) {
                return $_default;
            }
            return in_array($bar[$_key], array(1, '1', true, 'true'), true) ? 1 : 0;
        };
        $opacity = (isset($bar['opacity']) && is_numeric($bar['opacity'])) ? (int) round((float) $bar['opacity']) : self::DEFAULT_OPACITY;
        return array(
            'enabled' => $flag('enabled', 0),
            'corner'  => (isset($bar['corner']) && in_array($bar['corner'], self::BAR_CORNERS, true)) ? $bar['corner'] : self::DEFAULT_BAR_CORNER,
            'clock'   => $flag('clock', 1),
            'opacity' => max(0, min(100, $opacity)),
        );
    }

    /* ==================================================== lectures simples */

    /* Une durée « 1y2w3d4h5m6s » ou des secondes → secondes, ou null. */
    public static function seconds($_expiration) {
        if (!is_scalar($_expiration) || is_bool($_expiration)) {
            return null;
        }
        $text = strtolower(trim((string) $_expiration));
        if ($text === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $text)) {
            $value = (int) $text;
            return ($value > 0 && $value < 1000000000) ? $value : null;
        }
        if (!preg_match('/^(?:(\d+)y)?(?:(\d+)w)?(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $text, $m)) {
            return null;
        }
        $units = array(1 => 31536000, 2 => 604800, 3 => 86400, 4 => 3600, 5 => 60, 6 => 1);
        $seconds = 0;
        foreach ($units as $index => $unit) {
            $seconds += (isset($m[$index]) && $m[$index] !== '') ? (int) $m[$index] * $unit : 0;
        }
        return ($seconds > 0) ? $seconds : null;
    }

    /*
     * L'instant d'expiration d'un indicateur temporaire (format TvOverlay) :
     * des secondes, une durée « 1y2w3d4h5m6s », ou une date epoch (un nombre
     * d'au moins un milliard : 2001 et après). null : pas d'expiration ;
     * false : illisible.
     */
    public static function expiresAt($_expiration, $_now) {
        if ($_expiration === null || (is_string($_expiration) && trim($_expiration) === '')) {
            return null;
        }
        if (!is_scalar($_expiration) || is_bool($_expiration)) {
            return false;
        }
        $text = strtolower(trim((string) $_expiration));
        if (preg_match('/^\d+$/', $text) && (int) $text >= 1000000000) {
            return (int) $text;
        }
        $seconds = self::seconds($text);
        return ($seconds === null) ? false : (int) $_now + $seconds;
    }

    /* « #123# » (ou « 123 ») → 123, sinon 0. */
    public static function cmdId($_ref) {
        if (!is_scalar($_ref)) {
            return 0;
        }
        return preg_match('/^#?(\d{1,10})#?$/', trim((string) $_ref), $m) ? (int) $m[1] : 0;
    }

    private static function text($_array, $_key, $_default = '') {
        return (isset($_array[$_key]) && is_scalar($_array[$_key]) && !is_bool($_array[$_key])) ? trim((string) $_array[$_key]) : $_default;
    }

    /* Un identifiant d'indicateur ou de notification : texte non vide d'au
     * plus 64 caractères, sans caractère de contrôle ; '' sinon. */
    public static function cleanId($_id) {
        if (!is_scalar($_id) || is_bool($_id)) {
            return '';
        }
        $id = trim((string) $_id);
        if ($id === '' || mb_strlen($id, 'UTF-8') > self::MAX_ID || preg_match('/[\x00-\x1F\x7F]/', $id)) {
            return '';
        }
        return $id;
    }

    /* Une couleur #RGB, #RRGGBB ou #AARRGGBB → en majuscules sur 6 ou 8
     * chiffres ; $_default sinon. */
    public static function color($_value, $_default) {
        $value = is_string($_value) ? strtoupper(trim($_value)) : '';
        if (preg_match('/^#([0-9A-F])([0-9A-F])([0-9A-F])$/', $value, $m)) {
            return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }
        return preg_match('/^#([0-9A-F]{6}|[0-9A-F]{8})$/', $value) ? $value : $_default;
    }

    /*
     * Une icône Material Design : « mdi:nom » ou « nom » → « mdi:nom » ; ''
     * pour tout le reste (adresse, base64, texte libre). Le contrat lit une
     * valeur sans préfixe comme mdi:<valeur> ; le plugin l'écrit en entier.
     */
    public static function mdiIcon($_value) {
        if (!is_scalar($_value) || is_bool($_value)) {
            return '';
        }
        $icon = strtolower(trim((string) $_value));
        if (strpos($icon, 'mdi:') === 0) {
            $icon = substr($icon, 4);
        }
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $icon) && strlen($icon) <= 64 ? 'mdi:' . $icon : '';
    }

    /* ========================================= indicateurs automatiques */

    /* Un indicateur tel que saisi → complet, chaque champ à une valeur connue
     * (forme et valeurs par défaut de tvoverlaybeAuto::normalize()). */
    public static function normalizeIndicator($_indicator) {
        $i = is_array($_indicator) ? $_indicator : array();
        $conditions = array();
        foreach ((isset($i['conditions']) && is_array($i['conditions'])) ? $i['conditions'] : array() as $condition) {
            if (!is_array($condition)) {
                continue;
            }
            $operator = self::text($condition, 'operator', '==');
            $conditions[] = array(
                'cmd'      => self::text($condition, 'cmd'),
                'operator' => in_array($operator, self::OPERATORS, true) ? $operator : '==',
                'value'    => self::text($condition, 'value'),
            );
        }
        $decimals = self::text($i, 'decimals');
        $textMode = self::text($i, 'text_mode');
        $expiration = strtolower(self::text($i, 'expiration'));
        $normalized = array(
            'enable'     => (isset($i['enable']) && in_array($i['enable'], array(0, '0', false), true)) ? 0 : 1,
            'id'         => self::text($i, 'id'),
            'name'       => self::text($i, 'name'),
            'visibility' => (self::text($i, 'visibility') === 'conditions') ? 'conditions' : 'always',
            'combine'    => (self::text($i, 'combine') === 'all') ? 'all' : 'any',
            'conditions' => $conditions,
            'text_mode'  => in_array($textMode, array('fixed', 'cmd'), true) ? $textMode : 'none',
            'text'       => (isset($i['text']) && is_scalar($i['text'])) ? (string) $i['text'] : '',
            'text_cmd'   => self::text($i, 'text_cmd'),
            'decimals'   => ($decimals !== '' && ctype_digit($decimals)) ? (string) min(6, (int) $decimals) : '',
            'suffix'     => (isset($i['suffix']) && is_scalar($i['suffix'])) ? (string) $i['suffix'] : '',
            'icon_mode'  => (self::text($i, 'icon_mode') === 'cmd') ? 'cmd' : 'fixed',
            'icon'       => self::text($i, 'icon'),
            'icon_cmd'   => self::text($i, 'icon_cmd'),
            'shape'      => in_array(self::text($i, 'shape'), self::SHAPES, true) ? self::text($i, 'shape') : '',
            'expiration' => (self::seconds($expiration) !== null) ? $expiration : self::DEFAULT_EXPIRATION,
        );
        foreach (self::COLORS as $color) {
            $normalized[$color] = self::text($i, $color);
        }
        return $normalized;
    }

    /* La liste, normalisée (texte JSON accepté), 30 au plus. */
    public static function normalizeIndicators($_list) {
        if (is_string($_list)) {
            $_list = json_decode($_list, true);
        }
        $list = array();
        foreach (is_array($_list) ? $_list : array() as $indicator) {
            if (is_array($indicator) && count($list) < self::MAX_INDICATORS) {
                $list[] = self::normalizeIndicator($indicator);
            }
        }
        return $list;
    }

    /* Ce qui empêcherait un indicateur de fonctionner, en clair (id manquant
     * ou en double, « Visible si » sans condition, commande non choisie). */
    public static function indicatorErrors($_list) {
        $errors = array();
        $seen = array();
        foreach (self::normalizeIndicators($_list) as $index => $i) {
            $label = 'Indicateur n°' . ($index + 1) . ($i['name'] !== '' ? ' (' . $i['name'] . ')' : '');
            if (self::cleanId($i['id']) === '') {
                $errors[] = $label . ' : id obligatoire (64 caractères au plus).';
                continue;
            }
            if (isset($seen[$i['id']])) {
                $errors[] = $label . ' : l\'id « ' . $i['id'] . ' » est déjà pris.';
            }
            $seen[$i['id']] = true;
            if ($i['visibility'] === 'conditions') {
                if (count($i['conditions']) === 0) {
                    $errors[] = $label . ' : « Visible si » sans aucune condition.';
                }
                foreach ($i['conditions'] as $condition) {
                    if (self::cmdId($condition['cmd']) === 0) {
                        $errors[] = $label . ' : une condition ne désigne pas de commande.';
                        break;
                    }
                }
            }
            if ($i['text_mode'] === 'cmd' && self::cmdId($i['text_cmd']) === 0) {
                $errors[] = $label . ' : texte issu d\'une commande, mais aucune commande choisie.';
            }
            if ($i['icon_mode'] === 'cmd' && self::cmdId($i['icon_cmd']) === 0) {
                $errors[] = $label . ' : icône issue d\'une commande, mais aucune commande choisie.';
            }
        }
        return $errors;
    }

    /* Les indicateurs actifs, par id (le premier l'emporte sur un doublon). */
    public static function activeIndicators($_list) {
        $active = array();
        foreach (self::normalizeIndicators($_list) as $indicator) {
            $id = self::cleanId($indicator['id']);
            if ($indicator['enable'] == 1 && $id !== '' && !isset($active[$id])) {
                $active[$id] = $indicator;
            }
        }
        return $active;
    }

    /* Les commandes dont un changement peut changer la barre : celles des
     * indicateurs actifs réellement utilisées (ce qu'écoute le listener). */
    public static function cmdIds($_list) {
        $ids = array();
        foreach (self::activeIndicators($_list) as $i) {
            if ($i['visibility'] === 'conditions') {
                foreach ($i['conditions'] as $condition) {
                    $ids[] = self::cmdId($condition['cmd']);
                }
            }
            if ($i['text_mode'] === 'cmd') {
                $ids[] = self::cmdId($i['text_cmd']);
            }
            if ($i['icon_mode'] === 'cmd') {
                $ids[] = self::cmdId($i['icon_cmd']);
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids);
        return $ids;
    }

    /*
     * Une condition. Valeur absente : faux, même pour « != ». Deux nombres se
     * comparent en nombres ; sinon == et != comparent le texte sans la casse,
     * les autres opérateurs sont faux.
     */
    public static function compare($_actual, $_operator, $_expected) {
        if ($_actual === null || is_array($_actual) || is_object($_actual)) {
            return false;
        }
        $a = trim(is_bool($_actual) ? ($_actual ? '1' : '0') : (string) $_actual);
        $e = trim((string) $_expected);
        if ($a === '') {
            return false;
        }
        $numeric = is_numeric($a) && is_numeric($e);
        switch ($_operator) {
            case '==':
                return $numeric ? (float) $a == (float) $e : strcasecmp($a, $e) === 0;
            case '!=':
                return $numeric ? (float) $a != (float) $e : strcasecmp($a, $e) !== 0;
            case '>':
                return $numeric && (float) $a > (float) $e;
            case '>=':
                return $numeric && (float) $a >= (float) $e;
            case '<':
                return $numeric && (float) $a < (float) $e;
            case '<=':
                return $numeric && (float) $a <= (float) $e;
        }
        return false;
    }

    /* $_valueOf : fonction (id de commande) → valeur, ou null. */
    public static function isVisible($_indicator, $_valueOf) {
        $i = self::normalizeIndicator($_indicator);
        if ($i['visibility'] !== 'conditions') {
            return true;
        }
        if (count($i['conditions']) === 0) {
            return false;
        }
        foreach ($i['conditions'] as $condition) {
            $id = self::cmdId($condition['cmd']);
            $ok = $id > 0 && self::compare(call_user_func($_valueOf, $id), $condition['operator'], $condition['value']);
            if ($i['combine'] === 'any' && $ok) {
                return true;
            }
            if ($i['combine'] === 'all' && !$ok) {
                return false;
            }
        }
        return $i['combine'] === 'all';
    }

    /* Valeur → texte : arrondi si des décimales sont données (virgule à la
     * française par défaut), « -0 » devient « 0 », suffixe seulement après
     * une valeur. */
    public static function formatText($_value, $_decimals = '', $_suffix = '', $_separator = ',') {
        if ($_value === null || is_array($_value) || is_object($_value)) {
            return '';
        }
        $text = trim((string) $_value);
        if ($text === '') {
            return '';
        }
        if ((string) $_decimals !== '' && is_numeric($text)) {
            $decimals = max(0, (int) $_decimals);
            $rounded = round((float) $text, $decimals);
            if ($rounded == 0) {
                $rounded = 0.0;
            }
            $text = number_format($rounded, $decimals, $_separator, '');
        }
        return $text . (string) $_suffix;
    }

    /* Un élément de la barre, complet (contrat) : icône mdi, texte, couleurs
     * #RRGGBB/#AARRGGBB, forme. Les noms TvOverlay sont acceptés
     * (messageColor pour la couleur du texte). */
    public static function item($_id, $_icon, $_text, $_colors, $_shape) {
        $icon = self::mdiIcon($_icon);
        $colors = is_array($_colors) ? $_colors : array();
        if (!isset($colors['textColor']) && isset($colors['messageColor'])) {
            $colors['textColor'] = $colors['messageColor'];
        }
        $text = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', is_scalar($_text) ? (string) $_text : ''));
        $item = array(
            'id'   => (string) $_id,
            'icon' => ($icon !== '') ? $icon : self::DEFAULT_ICON,
            'text' => mb_substr($text, 0, self::MAX_ITEM_TEXT, 'UTF-8'),
        );
        foreach (self::DEFAULT_ITEM_COLORS as $key => $default) {
            $item[$key] = self::color(isset($colors[$key]) ? $colors[$key] : '', $default);
        }
        $item['shape'] = in_array($_shape, self::SHAPES, true) ? $_shape : self::DEFAULT_SHAPE;
        return $item;
    }

    /* L'élément d'un indicateur automatique, ou null s'il est caché. */
    public static function indicatorItem($_indicator, $_valueOf, $_separator = ',') {
        $i = self::normalizeIndicator($_indicator);
        if (!self::isVisible($i, $_valueOf)) {
            return null;
        }
        $text = '';
        if ($i['text_mode'] === 'fixed') {
            $text = $i['text'];
        } elseif ($i['text_mode'] === 'cmd') {
            $id = self::cmdId($i['text_cmd']);
            $text = ($id > 0) ? self::formatText(call_user_func($_valueOf, $id), $i['decimals'], $i['suffix'], $_separator) : '';
        }
        $icon = '';
        if ($i['icon_mode'] === 'cmd') {
            $id = self::cmdId($i['icon_cmd']);
            $icon = ($id > 0) ? self::mdiIcon(call_user_func($_valueOf, $id)) : '';
        }
        /* L'icône fixe sert de repli tant que la commande n'a rien publié de
         * lisible. */
        if ($icon === '') {
            $icon = $i['icon'];
        }
        return self::item($i['id'], $icon, $text, $i, $i['shape']);
    }

    public static function itemSignature($_item) {
        if (!is_array($_item)) {
            return '';
        }
        ksort($_item);
        return md5(json_encode($_item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /* ====================================== indicateurs temporaires (JSON) */

    /*
     * « Indicateur (JSON) » au format TvOverlay → ['id', 'remove' => bool,
     * 'item', 'expires' (instant ou null)], ou ['error' => message].
     */
    public static function temporaryFromJson($_data, $_now) {
        if (!is_array($_data)) {
            return array('error' => 'Le message doit être un objet JSON, par exemple {"id":"lampe","icon":"mdi:lightbulb"}');
        }
        $id = self::cleanId(isset($_data['id']) ? $_data['id'] : '');
        if ($id === '') {
            return array('error' => 'Un indicateur doit avoir un id, sans quoi il ne pourrait plus être retiré. Exemple : {"id":"lampe","icon":"mdi:lightbulb","message":"Salon"}');
        }
        if (array_key_exists('visible', $_data) && filter_var($_data['visible'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false) {
            return array('id' => $id, 'remove' => true);
        }
        $expires = self::expiresAt(isset($_data['expiration']) ? $_data['expiration'] : null, $_now);
        if ($expires === false) {
            return array('error' => 'Expiration illisible. Formats acceptés : secondes (90), durée (30m, 12h, 1d2h) ou date epoch.');
        }
        $shape = isset($_data['shape']) && is_string($_data['shape']) ? trim($_data['shape']) : '';
        return array('id' => $id, 'remove' => false, 'expires' => $expires,
                     'item' => self::item($id, isset($_data['icon']) ? $_data['icon'] : '', isset($_data['message']) ? $_data['message'] : '', $_data, $shape));
    }

    /* Les temporaires encore valables (id => ['item', 'expires', 'added']). */
    public static function temporaryPurge($_temporary, $_now) {
        $out = array();
        foreach (is_array($_temporary) ? $_temporary : array() as $id => $entry) {
            if (!is_array($entry) || !isset($entry['item']) || !is_array($entry['item'])) {
                continue;
            }
            $expires = isset($entry['expires']) ? $entry['expires'] : null;
            if ($expires === null || (int) $expires > $_now) {
                $out[(string) $id] = $entry;
            }
        }
        return $out;
    }

    /* La prochaine expiration des temporaires, ou null. */
    public static function nextExpiry($_temporary) {
        $next = null;
        foreach (is_array($_temporary) ? $_temporary : array() as $entry) {
            if (is_array($entry) && isset($entry['expires']) && $entry['expires'] !== null) {
                $next = ($next === null) ? (int) $entry['expires'] : min($next, (int) $entry['expires']);
            }
        }
        return $next;
    }

    /* Ajoute ou remplace un temporaire (le plus ancien part au-delà de 20). */
    public static function temporaryPut($_temporary, $_parsed, $_now) {
        $list = self::temporaryPurge($_temporary, $_now);
        $added = isset($list[$_parsed['id']]['added']) ? $list[$_parsed['id']]['added'] : $_now;
        unset($list[$_parsed['id']]);
        $list[$_parsed['id']] = array('item' => $_parsed['item'], 'expires' => $_parsed['expires'], 'added' => $added);
        uasort($list, function ($_a, $_b) {
            return ($_a['added'] == $_b['added']) ? 0 : (($_a['added'] < $_b['added']) ? -1 : 1);
        });
        while (count($list) > self::MAX_TEMPORARY) {
            array_shift($list);
        }
        return $list;
    }

    /* =============================================================== barre */

    /*
     * Les éléments de la barre, dans l'ordre : indicateurs automatiques (ordre
     * de la configuration ; un temporaire de même id prend sa place), puis
     * temporaires (ordre d'arrivée). $_snoozed : id => empreinte de ce qui a
     * été retiré à la main ; un indicateur automatique reste retiré tant que
     * ce qu'il afficherait ne change pas (sémantique de tvoverlaybe), et
     * l'oubli se fait dès qu'il change ou devient caché.
     * Rend [éléments, retraits à retenir].
     */
    public static function statusItems($_indicators, $_temporary, $_snoozed, $_valueOf, $_now, $_separator = ',') {
        $temporary = self::temporaryPurge($_temporary, $_now);
        $snoozed = is_array($_snoozed) ? $_snoozed : array();
        $items = array();
        $kept = array();
        foreach (self::activeIndicators($_indicators) as $id => $indicator) {
            if (isset($temporary[$id])) {
                $items[] = $temporary[$id]['item'];
                unset($temporary[$id]);
                continue;
            }
            $item = self::indicatorItem($indicator, $_valueOf, $_separator);
            if ($item === null) {
                continue;
            }
            $sig = self::itemSignature($item);
            if (isset($snoozed[$id]) && $snoozed[$id] === $sig) {
                $kept[$id] = $sig;
                continue;
            }
            $items[] = $item;
        }
        foreach ($temporary as $entry) {
            $items[] = $entry['item'];
        }
        return array($items, $kept);
    }

    /* Retrait à la main d'un indicateur automatique : on retient ce qu'il
     * affichait. Rend les retraits mis à jour. */
    public static function snooze($_snoozed, $_indicators, $_id, $_valueOf, $_separator = ',') {
        $snoozed = is_array($_snoozed) ? $_snoozed : array();
        $active = self::activeIndicators($_indicators);
        if (!isset($active[$_id])) {
            return $snoozed;
        }
        $item = self::indicatorItem($active[$_id], $_valueOf, $_separator);
        if ($item !== null) {
            $snoozed[$_id] = self::itemSignature($item);
        }
        return $snoozed;
    }

    /* La barre servie (contrat), ou null si elle est désactivée. */
    public static function buildStatus($_bar, $_items) {
        $bar = self::normalizeBar($_bar);
        if ($bar['enabled'] !== 1) {
            return null;
        }
        return array('corner' => $bar['corner'], 'clock' => $bar['clock'] === 1, 'opacity' => $bar['opacity'],
                     'items' => array_values(is_array($_items) ? $_items : array()));
    }

    /* L'empreinte de la barre : ne change que si ce qui est affiché change. */
    public static function statusSignature($_status) {
        return md5(json_encode($_status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /* ========================================================= sources vidéo */

    const VIDEO_SCHEMES = array('rtsp', 'rtsps', 'http', 'https');
    const MAX_SOURCES = 30;
    const MAX_SOURCE_NAME = 32;

    /* Une adresse de flux acceptable (rtsp, rtsps, http, https, avec un hôte). */
    public static function validVideoUrl($_url) {
        if (!is_string($_url) || strlen($_url) > 2048 || preg_match('/[\s\x00-\x1F\x7F]/', $_url)) {
            return false;
        }
        $parts = parse_url(trim($_url));
        return is_array($parts) && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower($parts['scheme']), self::VIDEO_SCHEMES, true) && $parts['host'] !== '';
    }

    /* Un nom de source : lettres, chiffres, « _ », « - », « . », 32 au plus. */
    public static function cleanSourceName($_name) {
        if (!is_scalar($_name)) {
            return '';
        }
        $name = trim((string) $_name);
        return preg_match('/^[\p{L}\p{N}_.-]{1,32}$/u', $name) ? $name : '';
    }

    /* La liste des sources : [{name, url}], noms uniques (casse ignorée),
     * adresses valables, 30 au plus. */
    public static function normalizeSources($_sources) {
        if (is_string($_sources)) {
            $decoded = json_decode($_sources, true);
            $_sources = is_array($decoded) ? $decoded : array();
        }
        $out = array();
        $seen = array();
        foreach (is_array($_sources) ? $_sources : array() as $source) {
            if (!is_array($source) || count($out) >= self::MAX_SOURCES) {
                continue;
            }
            $name = self::cleanSourceName(isset($source['name']) ? $source['name'] : '');
            $url = isset($source['url']) && is_string($source['url']) ? trim($source['url']) : '';
            $key = mb_strtolower($name, 'UTF-8');
            if ($name === '' || !self::validVideoUrl($url) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = array('name' => $name, 'url' => $url);
        }
        return $out;
    }

    /* Une vidéo désignée par un nom de source (casse ignorée) ou une adresse
     * complète → l'adresse ; null si ni l'un ni l'autre. */
    public static function resolveVideo($_ref, $_sources) {
        if (!is_scalar($_ref) || is_bool($_ref)) {
            return null;
        }
        $ref = trim((string) $_ref);
        if ($ref === '') {
            return null;
        }
        foreach (self::normalizeSources($_sources) as $source) {
            if (mb_strtolower($source['name'], 'UTF-8') === mb_strtolower($ref, 'UTF-8')) {
                return $source['url'];
            }
        }
        return self::validVideoUrl($ref) ? $ref : null;
    }

    /* Clés de paramètres d'adresse qui portent un secret. */
    const SECRET_PARAMS = '/^(pass(word)?|pwd|passwd|token|key|apikey|api_key|auth|secret|user(name)?|login|sig(nature)?)$/i';

    /*
     * Une adresse sans ses secrets, pour les journaux et la page :
     * identifiants « user:mot@ » → « *** », valeurs des paramètres sensibles
     * → « *** ». Ce qui ne se lit pas comme une adresse est masqué en entier.
     */
    public static function maskUrl($_url) {
        if (!is_string($_url) || trim($_url) === '') {
            return '';
        }
        $url = trim($_url);
        if (!preg_match('#^([a-z][a-z0-9+.-]*://)([^/?\#]*)(.*)$#is', $url, $m)) {
            return '***';
        }
        $authority = $m[2];
        $at = strrpos($authority, '@');
        if ($at !== false) {
            $authority = '***@' . substr($authority, $at + 1);
        }
        $rest = preg_replace_callback('/([?&;])([^=&;#]+)=([^&;#]*)/', function ($_p) {
            return $_p[1] . $_p[2] . '=' . (preg_match(self::SECRET_PARAMS, $_p[2]) ? '***' : $_p[3]);
        }, $m[3]);
        return $m[1] . $authority . $rest;
    }

    /* Les sources telles que la page les montre : nom et adresse masquée. */
    public static function maskedSources($_sources) {
        $out = array();
        foreach (self::normalizeSources($_sources) as $source) {
            $out[] = array('name' => $source['name'], 'url' => self::maskUrl($source['url']));
        }
        return $out;
    }

    /* Un ordre tel qu'on peut l'écrire au journal : vidéo et image masquées. */
    public static function orderForLog($_order) {
        if (!is_array($_order)) {
            return $_order;
        }
        foreach (array('video', 'image') as $key) {
            if (isset($_order[$key]) && is_string($_order[$key]) && strpos($_order[$key], '://') !== false) {
                $_order[$key] = self::maskUrl($_order[$key]);
            }
        }
        return $_order;
    }

    const VIDEO_MARKER = '/\[video=([^\]]*)\]/i';

    /* [video=<nom ou adresse>] dans le titre ou le message : retiré du texte ;
     * le premier non vide l'emporte (titre d'abord). Rend [titre, message,
     * référence ou null]. */
    public static function extractVideo($_title, $_message) {
        $title = is_scalar($_title) ? (string) $_title : '';
        $message = is_scalar($_message) ? (string) $_message : '';
        $ref = null;
        foreach (array(&$title, &$message) as &$text) {
            if (preg_match_all(self::VIDEO_MARKER, $text, $m)) {
                foreach ($m[1] as $candidate) {
                    if ($ref === null && trim($candidate) !== '') {
                        $ref = trim($candidate);
                    }
                }
                $text = trim(preg_replace('/[ \t]{2,}/', ' ', preg_replace(self::VIDEO_MARKER, '', $text)));
            }
        }
        unset($text);
        return array($title, $message, $ref);
    }

    /* ==================================================== JSON TvOverlay */

    /*
     * Le message d'une commande « … (JSON) » → tableau, ou null s'il n'est pas
     * un objet JSON. Un formulaire du cœur enregistré depuis l'interface (règle
     * dahua, scénario, plugin hygeabe) change toute valeur qui commence par
     * « { » en objet : le message arrive alors en tableau. Il est réencodé,
     * puis $_replace (cmd::cmdToValue côté Jeedom) remplace ses #id# de
     * commande, comme le moteur de scénario le fait dans un texte.
     */
    public static function jsonMessage($_message, $_replace = null) {
        $message = $_message;
        if (is_object($message)) {
            $message = (array) $message;
        }
        if (is_array($message)) {
            $message = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_callable($_replace)) {
                $message = call_user_func($_replace, $message);
            }
        }
        if (!is_scalar($message)) {
            return null;
        }
        $data = json_decode(trim((string) $message), true);
        return (is_array($data) && !self::isList($data)) ? $data : null;
    }

    /* Une image ou une icône TvOverlay : 'mdi' (nom d'icône), 'url' (http,
     * https), 'base64' (data: ou base64 brut), 'path' (chemin absolu), ou
     * 'none'. */
    public static function imageKind($_value) {
        if (!is_string($_value) || trim($_value) === '') {
            return 'none';
        }
        $value = trim($_value);
        if (preg_match('#^https?://#i', $value)) {
            return 'url';
        }
        if (preg_match('#^data:image/[a-z+.-]+;base64,#i', $value)) {
            return 'base64';
        }
        if ($value[0] === '/') {
            return 'path';
        }
        if (self::mdiIcon($value) !== '' && strlen($value) <= 68) {
            return 'mdi';
        }
        if (strlen($value) >= 64 && preg_match('#^[A-Za-z0-9+/\r\n]+={0,2}$#', $value)) {
            return 'base64';
        }
        return 'none';
    }

    /* Le contenu d'une image base64 (data: ou brut), ou null. */
    public static function decodeBase64Image($_value, $_maxBytes) {
        $value = preg_replace('#^data:image/[a-z+.-]+;base64,#i', '', trim((string) $_value));
        if (strlen($value) > (int) ceil($_maxBytes * 4 / 3) + 8) {
            return null;
        }
        $data = base64_decode(preg_replace('/\s+/', '', $value), true);
        return ($data === false || $data === '') ? null : $data;
    }

    /*
     * « Notifier (JSON) » au format TvOverlay → notre ordre notify (sans id
     * d'ordre), et l'image à joindre (chemin, adresse ou base64, à copier par
     * le plugin). Rend ['order' => …, 'image' => null|['kind', 'value']] ou
     * ['error' => message].
     *
     *   title, message → title, message ; id → tag ; corner → corner (s'il est
     *   connu) ; duration → duration (3 à 120 s) ; video → adresse (nom de
     *   source ou adresse complète) ; image, sinon largeIcon / smallIcon s'ils
     *   sont une adresse ou du base64 → image ; smallIcon, sinon largeIcon,
     *   s'ils sont une icône mdi → icon ; smallIconColor → iconColor. source :
     *   ignoré (pas d'équivalent sur la TV).
     */
    public static function notifyFromJson($_data, $_sources, $_minDuration = 3, $_maxDuration = 120) {
        if (!is_array($_data)) {
            return array('error' => 'Le message doit être un objet JSON, par exemple {"title":"Sonnette","smallIcon":"mdi:bell"}');
        }
        $text = function ($_key) use ($_data) {
            return (isset($_data[$_key]) && is_scalar($_data[$_key]) && !is_bool($_data[$_key])) ? trim((string) $_data[$_key]) : '';
        };
        $order = array('type' => 'notify', 'title' => $text('title'), 'message' => $text('message'));
        /* L'id TvOverlay voyage dans « tag » : « id » est celui de l'ordre
         * (entier croissant, contrat « Transport »). */
        $tag = self::cleanId(isset($_data['id']) ? $_data['id'] : '');
        if ($tag !== '') {
            $order['tag'] = $tag;
        }
        if (isset($_data['duration']) && is_numeric($_data['duration']) && (float) $_data['duration'] > 0) {
            $order['duration'] = (int) round(max($_minDuration, min($_maxDuration, (float) $_data['duration'])));
        }
        if (in_array($text('corner'), self::NOTIFY_CORNERS, true)) {
            $order['corner'] = $text('corner');
        }
        $icon = '';
        foreach (array('smallIcon', 'largeIcon') as $key) {
            if ($icon === '' && self::imageKind($text($key)) === 'mdi') {
                $icon = self::mdiIcon($text($key));
            }
        }
        if ($icon !== '') {
            $order['icon'] = $icon;
            $color = self::color($text('smallIconColor'), '');
            if ($color !== '') {
                $order['iconColor'] = $color;
            }
        }
        $image = null;
        foreach (array('image', 'largeIcon', 'smallIcon') as $key) {
            $kind = self::imageKind($text($key));
            if ($image === null && in_array($kind, array('url', 'base64', 'path'), true)) {
                $image = array('kind' => $kind, 'value' => $text($key));
            }
        }
        $videoRef = $text('video');
        if ($videoRef !== '') {
            $video = self::resolveVideo($videoRef, $_sources);
            if ($video === null) {
                return array('error' => 'Vidéo inconnue : ni une source nommée de la TV, ni une adresse rtsp/http(s).');
            }
            $order['video'] = $video;
        }
        if ($order['title'] === '' && $order['message'] === '' && $image === null && !isset($order['video'])) {
            return array('error' => 'Notification vide : renseignez un titre, un message, une image ou une vidéo.');
        }
        return array('order' => $order, 'image' => $image);
    }

    /* Une liste JSON (clés 0..n-1, non vide) plutôt qu'un objet. */
    private static function isList($_array) {
        $i = 0;
        foreach ($_array as $key => $unused) {
            if ($key !== $i++) {
                return false;
            }
        }
        return count($_array) > 0;
    }

    /* ===================================================== « Toutes les TV » */

    /* Commandes de l'équipement de diffusion. « Question » : la même question
     * (même jeton) à chaque TV allumée, la première réponse l'emporte, les
     * autres TV reçoivent ask_close (contrat « Question à plusieurs TV »). */
    const BROADCAST_COMMANDS = array('notify', 'notify_json', 'dismiss', 'fixed_json', 'fixed_remove', 'ask');
    /* Ce qui est un état de barre : à toutes les TV, allumées ou non. */
    const BROADCAST_STATE_COMMANDS = array('fixed_json', 'fixed_remove');

    /* L'option « Recevoir les diffusions » : cochée par défaut (absente). */
    public static function receivesBroadcast($_value) {
        return !($_value === 0 || $_value === '0' || $_value === false);
    }

    /*
     * Les TV qu'atteint une diffusion. $_tvs = [['id', 'enabled', 'receive',
     * 'lastSeen', 'screen'], …]. Toujours : TV activée et qui reçoit les
     * diffusions. Une notification (ou son retrait) exige en plus une TV en
     * ligne (appel de l'API depuis au plus $_timeout s) et écran allumé
     * (dernier screenOn reçu = 1) : une TV éteinte ne doit pas trouver des
     * notifications périmées à son réveil. Rend les id, dans l'ordre reçu.
     */
    public static function broadcastTargets($_tvs, $_logicalId, $_now, $_timeout = 60) {
        $state = in_array($_logicalId, self::BROADCAST_STATE_COMMANDS, true);
        $out = array();
        foreach (is_array($_tvs) ? $_tvs : array() as $tv) {
            if (!is_array($tv) || empty($tv['enabled']) || !self::receivesBroadcast(isset($tv['receive']) ? $tv['receive'] : null)) {
                continue;
            }
            if (!$state) {
                $seen = isset($tv['lastSeen']) ? (int) $tv['lastSeen'] : 0;
                $screen = isset($tv['screen']) ? $tv['screen'] : null;
                if ($seen <= 0 || $_now - $seen > $_timeout || !($screen === 1 || $screen === '1' || $screen === true)) {
                    continue;
                }
            }
            $out[] = $tv['id'];
        }
        return $out;
    }

    /* ======================================= question à plusieurs TV */

    /* La question retenue pour le groupe : jeton, commande « Question » de
     * Toutes les TV (celle qu'attend le bloc Demander), réponses, fin du
     * délai, TV visées (id => nom), réponse donnée (null tant qu'il n'y en a
     * pas). */
    public static function groupAskPending($_token, $_cmdId, $_answers, $_timeout, $_now, $_targets) {
        return array('token' => (string) $_token, 'cmd_id' => (int) $_cmdId, 'answers' => array_values($_answers),
                     'endtime' => (int) $_now + (int) $_timeout, 'targets' => $_targets, 'answered' => null);
    }

    /*
     * Contrôle d'une réponse à la question du groupe, venue de la TV $_tvId :
     *   400 requête mal formée ; 404 pas de question, autre jeton, délai passé
     *   ou TV non visée ; 409 déjà répondue (depuis une autre TV ou celle-ci) ;
     *   422 réponse hors liste ; 200 à transmettre.
     */
    public static function checkGroupAnswer($_group, $_tvId, $_token, $_answer, $_now) {
        if (!is_string($_token) || $_token === '' || !is_string($_answer) && !is_int($_answer) && !is_float($_answer)) {
            return array('code' => 400, 'message' => 'Paramètres « ask » et « answer » attendus');
        }
        if (!is_array($_group) || !isset($_group['token'], $_group['endtime'], $_group['answers'], $_group['targets'])
            || !hash_equals((string) $_group['token'], $_token) || !isset($_group['targets'][(int) $_tvId])) {
            return array('code' => 404, 'message' => 'Question inconnue, expirée ou déjà répondue');
        }
        if ($_now > $_group['endtime']) {
            return array('code' => 404, 'message' => 'Question expirée');
        }
        if (!empty($_group['answered'])) {
            return array('code' => 409, 'message' => 'Déjà répondu sur ' . $_group['answered']['by']);
        }
        if (!in_array((string) $_answer, $_group['answers'], true)) {
            return array('code' => 422, 'message' => 'Réponse non proposée');
        }
        return array('code' => 200, 'answer' => (string) $_answer);
    }

    /* La question du groupe, répondue par $_tvId. */
    public static function groupAskAnswered($_group, $_tvId, $_answer) {
        $_group['answered'] = array('tv' => (int) $_tvId, 'by' => (string) $_group['targets'][(int) $_tvId], 'answer' => (string) $_answer);
        return $_group;
    }

    /* Les ordres ask_close pour les autres TV visées : id de TV => ordre. */
    public static function groupAskCloseOrders($_group) {
        $out = array();
        if (!is_array($_group) || empty($_group['answered'])) {
            return $out;
        }
        foreach ($_group['targets'] as $tvId => $name) {
            if ((int) $tvId === (int) $_group['answered']['tv']) {
                continue;
            }
            $out[(int) $tvId] = array('type' => 'ask_close', 'ask' => $_group['token'], 'answer' => $_group['answered']['answer'], 'by' => $_group['answered']['by']);
        }
        return $out;
    }

    /* ============================================== hôtes du réseau local */

    /* Une adresse IP du réseau local (privée, lien local ou boucle locale). */
    public static function isLocalIp($_ip) {
        if (!is_string($_ip) || !filter_var($_ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (filter_var($_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_match('/^(10\.|127\.|192\.168\.|169\.254\.|172\.(1[6-9]|2\d|3[01])\.)/', $_ip) === 1;
        }
        $ip = strtolower($_ip);
        return $ip === '::1' || strpos($ip, 'fe80:') === 0 || preg_match('/^f[cd][0-9a-f]{2}:/', $ip) === 1;
    }
}
