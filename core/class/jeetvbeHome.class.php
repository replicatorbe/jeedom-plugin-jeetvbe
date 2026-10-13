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
 * Centre d'info : la logique pure, sans Jeedom (testée par tests/run.php).
 * Contrat : docs/api.md, « Présence humaine et allumage ».
 *
 * Partage des rôles. La TV mesure si un humain s'en sert et choisit la forme
 * d'un ordre au moment de l'afficher (mode auto) ; le plugin décide s'il a le
 * droit de la réveiller — priorité de l'info, condition de la maison
 * (présence, alarme…), heures calmes — et quand la rendormir.
 */
class jeetvbeHome {

    const PRIORITY_URGENT = 'urgente';
    const PRIORITY_IMPORTANT = 'importante';
    const PRIORITY_INFO = 'info';

    /* Durée de validité d'une info sans [pendant=…] ni [jusqu'à=…] (s). */
    const DEFAULT_VALIDITY = 600;
    /* Au plus un jour : une info oubliée ne doit pas garder la TV pour elle. */
    const MAX_VALIDITY = 86400;
    /* Une page d'info reste affichée au moins ce temps, même si l'info expire avant. */
    const MIN_SHOW = 30;
    /* Bandeau d'une info sans page (s) ; le contrat borne notify à 120 s. */
    const NOTIFY_DURATION = array('urgente' => 60, 'importante' => 30, 'info' => 15);
    /* Version discrète d'une page (mode auto, TV en usage). */
    const FALLBACK_DURATION = 15;
    /* Au plus N infos actives par TV : un scénario en boucle ne remplit pas le cache. */
    const MAX_INFOS = 20;

    const ICONS = array('urgente' => 'mdi:alert', 'importante' => 'mdi:bell-ring', 'info' => 'mdi:information-outline');

    /* ============================================================ état reçu */

    /*
     * Les champs de présence d'un POST state (contrat). Rend
     * ['values' => [champ => valeur normalisée], 'ignored' => [message…]].
     * Seuls les champs présents et lisibles sont rendus : un état partiel ne
     * doit rien effacer, et un champ illisible est écarté sans faire refuser
     * l'état — « Écran allumé » et les autres champs du même envoi doivent
     * passer quoi qu'il arrive. null vaut « inconnu ».
     */
    public static function parsePresence($_body) {
        $values = array();
        $ignored = array();
        foreach (array('inUse', 'playing', 'dreaming', 'humanTookOver') as $field) {
            if (!array_key_exists($field, $_body)) {
                continue;
            }
            if ($_body[$field] === null || is_bool($_body[$field])) {
                $values[$field] = $_body[$field];
            } else {
                $ignored[] = $field . ' : booléen attendu';
            }
        }
        if (array_key_exists('idleSeconds', $_body)) {
            $idle = $_body['idleSeconds'];
            if (is_float($idle) && $idle == floor($idle)) {
                $idle = (int) $idle;
            }
            if ($idle === null || (is_int($idle) && $idle >= 0)) {
                $values['idleSeconds'] = $idle;
            } else {
                $ignored[] = 'idleSeconds : entier positif attendu';
            }
        }
        if (array_key_exists('foreground', $_body)) {
            $fg = $_body['foreground'];
            if ($fg === null || (is_string($fg) && preg_match('/^[A-Za-z0-9_.]{1,200}$/', $fg))) {
                $values['foreground'] = $fg;
            } else {
                $ignored[] = 'foreground : nom de paquet attendu';
            }
        }
        if (array_key_exists('wokeBy', $_body)) {
            if (in_array($_body['wokeBy'], array('jeedom', 'human', null), true)) {
                $values['wokeBy'] = $_body['wokeBy'];
            } else {
                // Valeur inconnue : « inconnu », jamais « Jeedom ».
                $values['wokeBy'] = null;
                $ignored[] = 'wokeBy : jeedom, human ou null attendu';
            }
        }
        if (array_key_exists('capabilities', $_body) && $_body['capabilities'] !== null) {
            if (is_array($_body['capabilities'])) {
                $caps = array();
                foreach (array('keys', 'foreground', 'media', 'wake', 'sleep') as $cap) {
                    $caps[$cap] = isset($_body['capabilities'][$cap]) && $_body['capabilities'][$cap] === true;
                }
                $values['capabilities'] = $caps;
            } else {
                $ignored[] = 'capabilities : objet attendu';
            }
        }
        if (array_key_exists('lastSleep', $_body) && $_body['lastSleep'] !== null) {
            $last = $_body['lastSleep'];
            if (is_array($last) && isset($last['id']) && is_int($last['id'])
                && isset($last['result']) && in_array($last['result'], array('done', 'refused', 'failed'), true)) {
                $values['lastSleep'] = array('id' => $last['id'], 'result' => $last['result']);
            } else {
                $ignored[] = 'lastSleep : {"id": entier, "result": done, refused ou failed} attendu';
            }
        }
        return array('values' => $values, 'ignored' => $ignored);
    }

    /* L'état de présence mémorisé, complété par un état reçu. */
    public static function mergePresence($_stored, $_values, $_now) {
        $presence = is_array($_stored) ? $_stored : array();
        foreach ($_values as $field => $value) {
            $presence[$field] = $value;
        }
        if (array_key_exists('idleSeconds', $_values)) {
            $presence['lastInputAt'] = ($_values['idleSeconds'] === null) ? null : $_now - $_values['idleSeconds'];
        }
        $presence['at'] = $_now;
        return $presence;
    }

    public static function capability($_presence, $_cap) {
        return is_array($_presence) && isset($_presence['capabilities'][$_cap]) && $_presence['capabilities'][$_cap] === true;
    }

    /*
     * Règle d'or vue du plugin : Jeedom a allumé l'écran, personne n'y a
     * touché depuis, aucune question n'attend. Un état inconnu vaut « non » :
     * dans le doute, on n'éteint pas.
     */
    public static function goldenRule($_presence, $_askPending) {
        return is_array($_presence)
            && isset($_presence['wokeBy']) && $_presence['wokeBy'] === 'jeedom'
            && array_key_exists('humanTookOver', $_presence) && $_presence['humanTookOver'] === false
            && !$_askPending;
    }

    /*
     * Par où mettre en veille : 'app' (ordre sleep, la TV applique elle-même
     * la règle d'or), 'power' (l'équipement Google TV associé, règle d'or
     * vérifiée ici), ou 'none'.
     *
     * Sans force, couper par Google TV suppose un état de présence digne de
     * foi : la TV joignable (elle aurait signalé une reprise en main) et ses
     * touches observées (sinon humanTookOver ne passe jamais à vrai). Une TV
     * hors ligne garde peut-être quelqu'un devant elle.
     */
    public static function sleepRoute($_presence, $_online, $_hasPower, $_force, $_askPending) {
        if ($_online && self::capability($_presence, 'sleep')) {
            return 'app';
        }
        if (!$_hasPower) {
            return 'none';
        }
        if ($_force) {
            return 'power';
        }
        return ($_online && self::capability($_presence, 'keys') && self::goldenRule($_presence, $_askPending)) ? 'power' : 'none';
    }

    /* ================================================================ infos */

    /*
     * « Info maison » : titre + message, réglages en marqueurs dans le texte —
     * [priorité=urgente|importante|info], [clé=…], [pendant=<min>] ou
     * [jusqu'à=HH:MM], [page=<nom ou id>]. Rend l'info, ou ['error' => …].
     */
    public static function parseInfo($_title, $_message, $_now) {
        $title = trim((string) $_title);
        $text = (string) $_message;
        $markers = array();
        $text = preg_replace_callback('/\[\s*([^=\]]+?)\s*=\s*([^\]]*)\]/u', function ($_m) use (&$markers) {
            $name = self::markerName($_m[1]);
            if ($name === null) {
                return $_m[0];
            }
            $markers[$name] = trim($_m[2]);
            return '';
        }, $text);
        $message = trim(preg_replace('/\s{2,}/u', ' ', $text));
        if ($title === '' && $message === '') {
            return array('error' => 'Info vide : un titre ou un message est attendu');
        }

        $priority = self::PRIORITY_INFO;
        if (isset($markers['priority'])) {
            $priority = self::priorityName($markers['priority']);
            if ($priority === null) {
                return array('error' => 'Priorité inconnue : urgente, importante ou info');
            }
        }

        $until = $_now + self::DEFAULT_VALIDITY;
        if (isset($markers['until'])) {
            if (!preg_match('/^(\d{1,2})[:hH](\d{2})$/', $markers['until'], $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
                return array('error' => 'Heure illisible dans [jusqu\'à=…] : HH:MM attendu');
            }
            $until = mktime((int) $m[1], (int) $m[2], 0, (int) date('n', $_now), (int) date('j', $_now), (int) date('Y', $_now));
            if ($until <= $_now) {
                // 07:00 demandé à 23:00 : demain matin.
                $until = mktime((int) $m[1], (int) $m[2], 0, (int) date('n', $_now), (int) date('j', $_now) + 1, (int) date('Y', $_now));
            }
        } elseif (isset($markers['during'])) {
            if (!preg_match('/^\d{1,4}$/', $markers['during']) || (int) $markers['during'] < 1) {
                return array('error' => 'Durée illisible dans [pendant=…] : nombre de minutes attendu');
            }
            $until = $_now + (int) $markers['during'] * 60;
        }
        $until = min($until, $_now + self::MAX_VALIDITY);

        $key = self::cleanKey(isset($markers['key']) ? $markers['key'] : ($title !== '' ? $title : $message));
        if ($key === '') {
            return array('error' => 'Clé vide : lettres, chiffres, - ou _ attendus dans [clé=…]');
        }
        return array(
            'key'      => $key,
            'title'    => mb_substr($title, 0, 120),
            'message'  => mb_substr($message, 0, 500),
            'priority' => $priority,
            'page'     => isset($markers['page']) && $markers['page'] !== '' ? $markers['page'] : null,
            'until'    => $until,
        );
    }

    private static function markerName($_raw) {
        $name = self::fold($_raw);
        $names = array('priorite' => 'priority', 'cle' => 'key', 'pendant' => 'during',
                       'jusqua' => 'until', 'jusque' => 'until', 'page' => 'page');
        return isset($names[$name]) ? $names[$name] : null;
    }

    private static function priorityName($_raw) {
        $value = self::fold($_raw);
        $names = array('urgente' => self::PRIORITY_URGENT, 'urgent' => self::PRIORITY_URGENT,
                       'importante' => self::PRIORITY_IMPORTANT, 'important' => self::PRIORITY_IMPORTANT,
                       'info' => self::PRIORITY_INFO);
        return isset($names[$value]) ? $names[$value] : null;
    }

    const ACCENTS = array('à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
                          'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c');

    /* Minuscules, sans accents ni apostrophes ni espaces : « Jusqu'à » → « jusqua ». */
    private static function fold($_text) {
        return preg_replace('/[^a-z]/', '', strtr(mb_strtolower(trim((string) $_text)), self::ACCENTS));
    }

    /*
     * Une clé d'info : « Poubelles PMC ! » → « poubelles-pmc ». Un texte sans
     * lettre latine (« 🔥 ») reçoit une clé tirée de son empreinte : elle
     * reste stable d'un envoi à l'autre.
     */
    public static function cleanKey($_raw) {
        $raw = trim((string) $_raw);
        $text = strtr(mb_strtolower($raw), self::ACCENTS);
        $key = trim(substr(trim(preg_replace('/[^a-z0-9_]+/', '-', $text), '-'), 0, 40), '-');
        return ($key === '' && $raw !== '') ? 'k' . substr(md5($raw), 0, 8) : $key;
    }

    /*
     * Ajoute ou remplace une info (même clé). Rend [infos, nouvelle ?] :
     * « nouvelle » est faux quand une info identique (même texte, priorité,
     * page) était déjà active — sa validité est seulement prolongée, rien
     * n'est réaffiché. Un scénario qui la renvoie chaque minute ne doit pas
     * faire clignoter la TV.
     */
    public static function putInfo($_infos, $_info, $_now) {
        $infos = self::purge($_infos, $_now)[0];
        $key = $_info['key'];
        if (isset($infos[$key])) {
            $old = $infos[$key];
            $same = $old['title'] === $_info['title'] && $old['message'] === $_info['message']
                && $old['priority'] === $_info['priority'] && $old['page'] === $_info['page'];
            if ($same) {
                $infos[$key]['until'] = $_info['until'];
                // Encore en attente : renvoyée, elle tente à nouveau sa chance.
                return array($infos, empty($old['shown']));
            }
        }
        $infos[$key] = array_merge($_info, array('added' => $_now, 'shown' => false));
        if (count($infos) > self::MAX_INFOS) {
            uasort($infos, function ($_a, $_b) {
                return $_a['added'] - $_b['added'];
            });
            $infos = array_slice($infos, -self::MAX_INFOS, null, true);
        }
        return array($infos, true);
    }

    /* [infos encore valables, infos échues]. */
    public static function purge($_infos, $_now) {
        $kept = array();
        $expired = array();
        foreach (is_array($_infos) ? $_infos : array() as $key => $info) {
            if (!is_array($info) || !isset($info['until'])) {
                continue;
            }
            if ($info['until'] > $_now) {
                $kept[$key] = $info;
            } else {
                $expired[$key] = $info;
            }
        }
        return array($kept, $expired);
    }

    /* Heures calmes « HH:MM » → « HH:MM », éventuellement à cheval sur minuit. */
    public static function quiet($_from, $_to, $_now) {
        $parse = function ($_v) {
            return preg_match('/^(\d{1,2}):(\d{2})$/', trim((string) $_v), $m) && (int) $m[1] < 24 && (int) $m[2] < 60
                ? (int) $m[1] * 60 + (int) $m[2] : null;
        };
        $from = $parse($_from);
        $to = $parse($_to);
        if ($from === null || $to === null || $from === $to) {
            return false;
        }
        $minute = (int) date('G', $_now) * 60 + (int) date('i', $_now);
        return ($from < $to) ? ($minute >= $from && $minute < $to) : ($minute >= $from || $minute < $to);
    }

    /*
     * Que faire d'une info, maintenant. $_ctx :
     *   screenOn  : la TV est joignable et son écran allumé ;
     *   appWake   : la TV est joignable et sait allumer son écran ;
     *   power     : un équipement Google TV est associé ;
     *   condition : la condition de la maison est vraie (ou absente) ;
     *   quiet     : heures calmes.
     * Rend ['display' => bool, 'wake' => 'none'|'app'|'power'].
     *
     * Urgente : réveille toujours, heures calmes comprises. Importante :
     * seulement si la condition est vraie et hors heures calmes. Info :
     * jamais — elle attend que quelqu'un allume la TV.
     */
    public static function plan($_info, $_ctx) {
        if (!empty($_ctx['screenOn'])) {
            return array('display' => true, 'wake' => 'none');
        }
        $allowed = ($_info['priority'] === self::PRIORITY_URGENT)
            || ($_info['priority'] === self::PRIORITY_IMPORTANT && !empty($_ctx['condition']) && empty($_ctx['quiet']));
        if (!$allowed) {
            return array('display' => false, 'wake' => 'none');
        }
        if (!empty($_ctx['appWake'])) {
            return array('display' => true, 'wake' => 'app');
        }
        if (!empty($_ctx['power'])) {
            return array('display' => true, 'wake' => 'power');
        }
        return array('display' => false, 'wake' => 'none');
    }

    /*
     * L'ordre qui montre une info. Avec une page ($_pageId) : show en mode
     * auto, la TV choisissant entre plein écran et bandeau ; sinon un notify
     * étiqueté (retirable par « Retirer info »).
     */
    public static function infoOrder($_info, $_pageId, $_wake, $_now) {
        if ($_pageId !== null) {
            $order = array('type' => 'show', 'page' => $_pageId,
                           'duration' => max(self::MIN_SHOW, min(self::MAX_VALIDITY, $_info['until'] - $_now)),
                           'mode' => 'auto',
                           'fallback' => array('title' => $_info['title'], 'message' => $_info['message'],
                                               'icon' => self::ICONS[$_info['priority']], 'duration' => self::FALLBACK_DURATION));
        } else {
            $order = array('type' => 'notify', 'title' => $_info['title'], 'message' => $_info['message'],
                           'duration' => self::NOTIFY_DURATION[$_info['priority']], 'tag' => self::tag($_info['key']));
        }
        if ($_wake) {
            $order['wake'] = true;
        }
        return $order;
    }

    /* L'indicateur de la barre d'état qui rappelle une info sans page tant qu'elle vaut. */
    public static function infoIndicator($_info) {
        return array('id' => self::tag($_info['key']), 'icon' => self::ICONS[$_info['priority']],
                     'message' => $_info['title'] !== '' ? $_info['title'] : $_info['message'],
                     'expiration' => (int) $_info['until']);
    }

    public static function tag($_key) {
        return 'info-' . $_key;
    }
}
