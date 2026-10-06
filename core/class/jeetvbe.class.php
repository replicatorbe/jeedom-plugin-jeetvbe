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

require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/jeetvbeLayout.class.php';

/*
 * Un équipement jeetvbe = une TV. Contrat : docs/api.md (schéma 1).
 *
 * Configuration :
 *   token : clé de la TV, 32 caractères hexadécimaux, générée si vide ;
 *   pages : les pages et leurs tuiles (voir jeetvbeLayout::normalizePages).
 *
 * L'équipement n'a aucune commande : la TV n'est pas pilotée, elle pilote.
 *
 * ⚠ Deux pièges du coeur (STRUCTURE-PLUGIN-JEEDOM.md, section 8) : aucune
 * propriété sans souligné dans cette classe, et aucune méthode « set » + clé
 * de formulaire (setCmd, setConfiguration…).
 */
class jeetvbe extends eqLogic {

    /* Durée maximale de l'attente longue de « changes » (contrat : 25 s). */
    const LONGPOLL_SECONDS = 25;
    /* Intervalle d'interrogation interne pendant l'attente. */
    const POLL_INTERVAL_US = 500000;
    /* La révision est relue toutes les N itérations (2 s) pendant l'attente. */
    const REVISION_EVERY = 4;

    /* ============================================================ statiques */

    public static function newToken() {
        return bin2hex(random_bytes(16));
    }

    public static function validToken($_token) {
        return is_string($_token) && preg_match('/^[0-9a-f]{32}$/', $_token) === 1;
    }

    /*
     * La TV désignée par une clé : équipement jeetvbe ACTIVÉ dont le token
     * correspond, comparé en temps constant. null sinon.
     */
    public static function byToken($_key) {
        if (!is_string($_key) || $_key === '' || strlen($_key) > 128) {
            return null;
        }
        $found = null;
        foreach (eqLogic::byType('jeetvbe', true) as $eqLogic) {
            $token = (string) $eqLogic->getConfiguration('token', '');
            if ($token !== '' && hash_equals($token, $_key) && $found === null) {
                $found = $eqLogic;
            }
        }
        return $found;
    }

    /* L'URL de l'API telle qu'un appareil du réseau local la joint. */
    public static function apiUrl() {
        $base = '';
        try {
            $base = network::getNetworkAccess('internal');
        } catch (Throwable $e) {
            $base = '';
        }
        return rtrim((string) $base, '/') . '/plugins/jeetvbe/core/php/api.php';
    }

    public static function pluginVersion() {
        $info = json_decode((string) @file_get_contents(__DIR__ . '/../../plugin_info/info.json'), true);
        return (is_array($info) && isset($info['pluginVersion'])) ? (string) $info['pluginVersion'] : '';
    }

    /* Une commande telle que la logique pure la voit, ou null. */
    public static function describeCmd($_cmdId) {
        $cmd = cmd::byId($_cmdId);
        if (!is_object($cmd)) {
            return null;
        }
        /* Valeur lue dans le cache, et seulement pour une info : rien ici ne
         * doit jamais exécuter une commande action. */
        $value = null;
        if ($cmd->getType() == 'info') {
            try {
                $value = $cmd->getCache('value', null);
            } catch (Throwable $e) {
                $value = null;
            }
        }
        return array(
            'type'     => $cmd->getType(),
            'value'    => $value,
            'unit'     => (string) $cmd->getUnite(),
            'minValue' => $cmd->getConfiguration('minValue', ''),
            'maxValue' => $cmd->getConfiguration('maxValue', ''),
        );
    }

    /*
     * Ce que la génération voit d'un équipement : son nom et ses commandes
     * typées. Les équipements désactivés ne sont pas proposés.
     */
    public static function eqLogicDescriptor($_eqLogic) {
        $cmds = array();
        foreach ($_eqLogic->getCmd() as $cmd) {
            if ($cmd->getGeneric_type() == '') {
                continue;
            }
            $cmds[] = array(
                'id'       => (int) $cmd->getId(),
                'type'     => $cmd->getType(),
                'subType'  => $cmd->getSubType(),
                'generic'  => $cmd->getGeneric_type(),
                'name'     => $cmd->getName(),
                'unit'     => $cmd->getUnite(),
                'minValue' => $cmd->getConfiguration('minValue', ''),
                'maxValue' => $cmd->getConfiguration('maxValue', ''),
            );
        }
        return array('id' => (int) $_eqLogic->getId(), 'name' => $_eqLogic->getName(), 'cmds' => $cmds);
    }

    /* Pages proposées pour une liste d'objets (pièces), par type ou par pièce.
     * Les objets sont pris dans l'ordre de Jeedom, quel que soit l'ordre reçu. */
    public static function generateForObjects($_objectIds, $_mode = 'type') {
        $wanted = array_map('intval', (array) $_objectIds);
        $objects = array();
        foreach (jeeObject::buildTree(null, false) as $object) {
            if (!in_array((int) $object->getId(), $wanted, true)) {
                continue;
            }
            $eqLogics = array();
            foreach (eqLogic::byObjectId($object->getId(), true) as $eqLogic) {
                if ($eqLogic->getEqType_name() == 'jeetvbe') {
                    continue;
                }
                $eqLogics[] = self::eqLogicDescriptor($eqLogic);
            }
            usort($eqLogics, function ($_a, $_b) {
                return strnatcasecmp($_a['name'], $_b['name']);
            });
            $objects[] = array('id' => (int) $object->getId(), 'name' => $object->getName(), 'eqLogics' => $eqLogics);
        }
        return jeetvbeLayout::generatePages($objects, in_array($_mode, jeetvbeLayout::MODES, true) ? $_mode : 'type');
    }

    /* ============================================================ instance */

    public function preSave() {
        /* Jamais d'exception ici : le coeur crée l'équipement avec son seul nom. */
        if (!self::validToken($this->getConfiguration('token', ''))) {
            $this->setConfiguration('token', self::newToken());
        }
        /* Les ids des tuiles inchangées sont repris des pages enregistrées, et
         * un numéro de tuile n'est jamais réattribué (tileSeq). */
        $previous = null;
        $floor = (int) $this->getConfiguration('tileSeq', 0);
        try {
            if ($this->getId() != '') {
                $stored = eqLogic::byId($this->getId());
                if (is_object($stored)) {
                    $previous = $stored->getConfiguration('pages', array());
                    $floor = max($floor, jeetvbeLayout::maxTileNumber($previous));
                }
            }
        } catch (Throwable $e) {
            $previous = null;
        }
        $pages = jeetvbeLayout::normalizePages($this->getConfiguration('pages', array()), $previous, $floor);
        $this->setConfiguration('pages', $pages);
        $this->setConfiguration('tileSeq', max($floor, jeetvbeLayout::maxTileNumber($pages)));
    }

    public function regenerateToken() {
        $this->setConfiguration('token', self::newToken());
        $this->save(true);
        return $this->getConfiguration('token');
    }

    public function pages() {
        return jeetvbeLayout::normalizePages($this->getConfiguration('pages', array()));
    }

    public function revision() {
        return jeetvbeLayout::revision($this->pages());
    }

    public function layout() {
        return jeetvbeLayout::buildLayout($this->pages(), array(__CLASS__, 'describeCmd'));
    }

    /*
     * Exécute une action de tuile. Rend ['code' => HTTP, 'body' => tableau].
     * Les refus (404, 400, 422) ne touchent à rien ; une exception de Jeedom
     * pendant l'exécution rend 500 avec son message.
     */
    public function execTile($_tileId, $_action, $_value) {
        $tile = jeetvbeLayout::findTile($this->pages(), $_tileId);
        if ($tile === null) {
            return array('code' => 404, 'body' => array('error' => 'Tuile inconnue'));
        }
        $roles = jeetvbeLayout::roles($tile);
        $state = null;
        if (isset($roles['state'])) {
            $described = self::describeCmd($roles['state']);
            $state = is_array($described) ? $described['value'] : null;
        }
        $setCmd = isset($roles['set']) ? self::describeCmd($roles['set']) : null;
        $plan = jeetvbeLayout::resolveAction($tile, $_action, $_value, $state, $setCmd);
        if (isset($plan['error'])) {
            return array('code' => $plan['error'], 'body' => array('error' => $plan['message']));
        }

        if (isset($plan['scenario'])) {
            $scenario = scenario::byId($plan['scenario']);
            if (!is_object($scenario)) {
                return array('code' => 422, 'body' => array('error' => 'Le scénario associé n\'existe plus'));
            }
            if ($scenario->getIsActive() != 1) {
                return array('code' => 422, 'body' => array('error' => 'Le scénario associé est désactivé'));
            }
            log::add('jeetvbe', 'info', sprintf('%s : tuile %s (%s) → scénario %s',
                $this->getHumanName(), $tile['id'], $tile['name'], $scenario->getHumanName()));
            $scenario->addTag('trigger', 'jeetvbe');
            $scenario->launch();
            return array('code' => 200, 'body' => array('ok' => true, 'value' => null));
        }

        $cmd = cmd::byId($plan['cmd']);
        if (!is_object($cmd)) {
            return array('code' => 422, 'body' => array('error' => 'La commande associée n\'existe plus'));
        }
        if ($cmd->getType() != 'action') {
            return array('code' => 422, 'body' => array('error' => 'La commande associée n\'est pas une action'));
        }
        log::add('jeetvbe', 'info', sprintf('%s : tuile %s (%s), %s%s → %s',
            $this->getHumanName(), $tile['id'], $tile['name'], $plan['action'],
            isset($plan['value']) ? ' ' . $plan['value'] : '', $cmd->getHumanName()));
        $cmd->execCmd($plan['options']);

        $value = null;
        if (isset($roles['state'])) {
            $described = self::describeCmd($roles['state']);
            $value = is_array($described) ? jeetvbeLayout::valueString($described['value']) : null;
        }
        return array('code' => 200, 'body' => array('ok' => true, 'value' => $value));
    }

    /*
     * Événements cmd::update postérieurs à $_since (table `event` du coeur,
     * celle que l'interface de Jeedom interroge elle-même), dans l'ordre.
     * Rend [événements, plus grand horodatage vu].
     */
    public static function eventsSince($_since) {
        $rows = DB::Prepare('SELECT `datetime`, `option` FROM `event` WHERE `name` = :name AND `datetime` > :since ORDER BY `datetime`',
            array('name' => 'cmd::update', 'since' => sprintf('%.6F', $_since)), DB::FETCH_TYPE_ALL);
        $events = array();
        $last = $_since;
        foreach (is_array($rows) ? $rows : array() as $row) {
            $last = max($last, (float) $row['datetime']);
            $option = json_decode((string) $row['option'], true);
            if (is_array($option) && isset($option['cmd_id'])) {
                $events[] = array('cmd_id' => (int) $option['cmd_id'], 'value' => isset($option['value']) ? $option['value'] : null);
            }
        }
        return array($events, $last);
    }

    public static function nowCursor() {
        return round(microtime(true), 6);
    }

    /*
     * L'attente longue de « changes ». Rend la réponse du contrat.
     *
     * Interrogation interne toutes les 0,5 s de la table des événements,
     * filtrée sur les commandes « state » des tuiles de cette TV. La révision
     * est relue toutes les 2 s : une configuration modifiée pendant l'attente
     * libère la requête, avec la nouvelle révision.
     */
    public function waitChanges($_since) {
        $revision = $this->revision();
        if ($_since === null) {
            return array('since' => self::nowCursor(), 'revision' => $revision, 'changes' => array());
        }
        $now = self::nowCursor();
        if ($_since > $now) {
            $_since = $now;
        }
        $stateMap = jeetvbeLayout::stateMap($this->pages());
        $deadline = microtime(true) + self::LONGPOLL_SECONDS;
        $cursor = $_since;
        $round = 0;
        while (true) {
            list($events, $last) = self::eventsSince($cursor);
            $cursor = $last;
            $changes = jeetvbeLayout::mergeChanges($events, $stateMap);
            if (count($changes) > 0) {
                return array('since' => $cursor, 'revision' => $revision, 'changes' => $changes);
            }
            if (microtime(true) >= $deadline || connection_aborted()) {
                break;
            }
            $round++;
            if ($round % self::REVISION_EVERY === 0) {
                $fresh = eqLogic::byId($this->getId());
                if (!is_object($fresh) || $fresh->getIsEnable() != 1) {
                    break;
                }
                $freshRevision = jeetvbeLayout::revision($fresh->getConfiguration('pages', array()));
                if ($freshRevision !== $revision) {
                    return array('since' => $cursor, 'revision' => $freshRevision, 'changes' => array());
                }
            }
            usleep(self::POLL_INTERVAL_US);
        }
        return array('since' => $cursor, 'revision' => $revision, 'changes' => array());
    }
}

class jeetvbeCmd extends cmd {
    /* L'équipement n'a pas de commande ; la classe est exigée par le coeur. */
    public function execute($_options = array()) {
    }
}
