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
 * Commandes (MVP 3) : les ordres de Jeedom vers la TV (Afficher <page>,
 * Afficher page, Message, Quitter), mis en file et livrés par « changes », et
 * l'état que la TV signale (En ligne, Visible, Écran allumé, Page affichée).
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

    /* Au-delà de 60 s sans appel de la TV, « En ligne » repasse à 0. */
    const ONLINE_TIMEOUT = 60;

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

    /*
     * Chaque minute : « En ligne » repasse à 0 pour une TV qui n'a pas appelé
     * l'API depuis plus de ONLINE_TIMEOUT secondes.
     */
    public static function cron() {
        foreach (eqLogic::byType('jeetvbe') as $tv) {
            $online = $tv->getCmd('info', 'online');
            if (!is_object($online) || $online->getCache('value', 0) != 1) {
                continue;
            }
            if (time() - $tv->lastSeen() > self::ONLINE_TIMEOUT) {
                if ($tv->getIsEnable() == 1) {
                    $tv->checkAndUpdateCmd('online', 0);
                } else {
                    $online->event(0);
                }
            }
        }
    }

    /* ------------------------------------------------ file d'ordres vers la TV
     *
     * La file vit dans le cache de Jeedom, sous une clé propre à la TV
     * (et non dans getCache()/setCache() de l'équipement, qui réécrivent tout
     * le tableau d'attributs : une écriture concurrente — l'heure du dernier
     * appel, par exemple — pourrait effacer un ordre). Le compteur d'id est en
     * base (config du plugin) : il survit à un vidage du cache, et la TV, qui
     * ignore un id déjà traité, ne perd donc aucun ordre.
     *
     * Ajout (commande exécutée) et livraison (réponse « changes ») passent par
     * un verrou fichier : un ordre ajouté pendant une livraison est soit livré,
     * soit laissé pour la suivante, jamais perdu ni livré deux fois.
     */
    private static function queueKey($_id) {
        return 'jeetvbe::queue::' . (int) $_id;
    }

    private static function withQueueLock($_id, $_callback) {
        $handle = false;
        try {
            $handle = @fopen(jeedom::getTmpFolder('jeetvbe') . '/queue_' . (int) $_id . '.lock', 'c');
        } catch (Throwable $e) {
            $handle = false;
        }
        if ($handle !== false) {
            flock($handle, LOCK_EX);
        }
        try {
            return $_callback();
        } finally {
            if ($handle !== false) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    /* Met un ordre en file ; rend l'ordre avec son id. $_ttl : durée de vie
     * propre de l'ordre (s), plafonnée à 60 s. */
    public static function enqueue($_tvId, $_order, $_ttl = null) {
        return self::withQueueLock($_tvId, function () use ($_tvId, $_order, $_ttl) {
            $seq = (int) config::byKey('seq::' . (int) $_tvId, 'jeetvbe', 0) + 1;
            config::save('seq::' . (int) $_tvId, $seq, 'jeetvbe');
            $order = array_merge(array('id' => $seq), $_order);
            $queue = cache::byKey(self::queueKey($_tvId))->getValue(array());
            cache::set(self::queueKey($_tvId), jeetvbeLayout::queuePush($queue, $order, microtime(true), $_ttl));
            return $order;
        });
    }

    /* ------------------------------------------- question en attente (ask)
     *
     * Une seule par TV : une nouvelle remplace l'ancienne. Gardée dans le
     * cache, sous une clé propre à la TV, jusqu'à la réponse ou au délai. */
    private static function askKey($_id) {
        return 'jeetvbe::ask::' . (int) $_id;
    }

    public static function askPending($_tvId) {
        $pending = cache::byKey(self::askKey($_tvId))->getValue(null);
        return is_array($pending) ? $pending : null;
    }

    public static function rememberAsk($_tvId, $_pending) {
        cache::set(self::askKey($_tvId), $_pending, max(60, (int) $_pending['endtime'] - time() + 60));
    }

    public static function forgetAsk($_tvId) {
        cache::delete(self::askKey($_tvId));
    }

    /*
     * POST answer : rend ['code' => HTTP, 'body' => …]. Le jeton doit être
     * celui de la question en attente de CETTE TV ; la réponse est passée au
     * cœur (cmd::askResponse), qui la refuse lui-même hors délai ou hors liste.
     */
    public function answer($_token, $_answer) {
        $pending = self::askPending($this->getId());
        $check = jeetvbeLayout::checkAnswer($pending, $_token, $_answer, time());
        if ($check['code'] !== 200) {
            if ($check['code'] === 404 && is_array($pending) && time() > $pending['endtime']) {
                self::forgetAsk($this->getId());
            }
            return array('code' => $check['code'], 'body' => array('error' => $check['message']));
        }
        $cmd = cmd::byId($pending['cmd_id']);
        if (!is_object($cmd) || $cmd->getEqLogic_id() != $this->getId()) {
            self::forgetAsk($this->getId());
            return array('code' => 404, 'body' => array('error' => 'Question inconnue, expirée ou déjà répondue'));
        }
        if (!$cmd->askResponse($check['answer'])) {
            $endtime = $cmd->getCache('ask::endtime', null);
            if ($cmd->getCache('ask::variable', 'none') == 'none' || $endtime === null || $endtime < strtotime('now')) {
                self::forgetAsk($this->getId());
                return array('code' => 404, 'body' => array('error' => 'Question expirée ou déjà répondue'));
            }
            return array('code' => 422, 'body' => array('error' => 'Réponse refusée par Jeedom'));
        }
        self::forgetAsk($this->getId());
        log::add('jeetvbe', 'info', sprintf('%s : réponse « %s » à la question %s',
            $this->getHumanName(), $check['answer'], substr($pending['token'], 0, 8)));
        return array('code' => 200, 'body' => array('ok' => true));
    }

    /* Y a-t-il un ordre en attente ? Lecture sans verrou, pour l'attente longue. */
    public static function hasOrders($_tvId) {
        $queue = cache::byKey(self::queueKey($_tvId))->getValue(array());
        return is_array($queue) && count($queue) > 0;
    }

    /* Les ordres encore valables ; la file est vidée (livraison unique). */
    public static function takeOrders($_tvId) {
        if (!self::hasOrders($_tvId)) {
            return array();
        }
        return self::withQueueLock($_tvId, function () use ($_tvId) {
            $queue = cache::byKey(self::queueKey($_tvId))->getValue(array());
            $orders = jeetvbeLayout::queueOrders($queue, microtime(true));
            cache::set(self::queueKey($_tvId), array());
            return $orders;
        });
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

    /* Les objets dans l'ordre de l'arbre de Jeedom. Comme jeeObject::buildTree(),
     * sans son filtre de droits, qui dépend de la session (vide en CLI). */
    public static function objectsInOrder($_parent = null) {
        $children = ($_parent === null) ? jeeObject::rootObject(true, false) : $_parent->getChild(false);
        $out = array();
        foreach (is_array($children) ? $children : array() as $object) {
            $out[] = $object;
            $out = array_merge($out, self::objectsInOrder($object));
        }
        return $out;
    }

    /* Pages proposées pour une liste d'objets (pièces), par type ou par pièce.
     * Les objets sont pris dans l'ordre de Jeedom, quel que soit l'ordre reçu. */
    public static function generateForObjects($_objectIds, $_mode = 'type') {
        $wanted = array_map('intval', (array) $_objectIds);
        $objects = array();
        foreach (self::objectsInOrder() as $object) {
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
        $pageFloor = (int) $this->getConfiguration('pageSeq', 0);
        try {
            if ($this->getId() != '') {
                $stored = eqLogic::byId($this->getId());
                if (is_object($stored)) {
                    $previous = $stored->getConfiguration('pages', array());
                    $floor = max($floor, jeetvbeLayout::maxTileNumber($previous));
                    $pageFloor = max($pageFloor, (int) $stored->getConfiguration('pageSeq', 0), jeetvbeLayout::maxPageNumber($previous));
                }
            }
        } catch (Throwable $e) {
            $previous = null;
        }
        $pages = jeetvbeLayout::normalizePages($this->getConfiguration('pages', array()), $previous, $floor, $pageFloor);
        $this->setConfiguration('pages', $pages);
        $this->setConfiguration('tileSeq', max($floor, jeetvbeLayout::maxTileNumber($pages)));
        $this->setConfiguration('pageSeq', max($pageFloor, jeetvbeLayout::maxPageNumber($pages)));
        $this->setConfiguration('showDuration', jeetvbeLayout::defaultDuration($this->getConfiguration('showDuration', '')));
        $this->setConfiguration('scenarioGroup', trim((string) $this->getConfiguration('scenarioGroup', '')));
    }

    public function postSave() {
        $this->syncCommands();
    }

    /* Appelée par le coeur APRÈS qu'il a traité le tableau des commandes de la
     * page : c'est là que les commandes « Afficher <page> » d'une page ajoutée,
     * renommée ou supprimée dans le même enregistrement sont remises d'aplomb. */
    public function postAjax() {
        $this->syncCommands();
    }

    /*
     * Crée et tient à jour les commandes de l'équipement, de façon idempotente :
     * rien n'est réenregistré s'il n'y a rien à changer.
     */
    public function syncCommands() {
        if ($this->getId() == '') {
            return;
        }
        $existing = array();
        foreach ($this->getCmd() as $cmd) {
            $existing[$cmd->getLogicalId()] = $cmd;
        }
        $order = 0;
        $expected = jeetvbeLayout::pageCommands($this->allPages());

        /* Les « Afficher <page> » qui n'ont plus de page d'abord : leur nom se
         * libère pour une page renommée. */
        foreach ($existing as $logicalId => $cmd) {
            if (strpos($logicalId, jeetvbeLayout::PAGE_COMMAND_PREFIX) === 0 && $logicalId !== 'show_page' && !isset($expected[$logicalId])) {
                $cmd->remove();
                unset($existing[$logicalId]);
            }
        }

        foreach ($expected as $logicalId => $name) {
            $this->ensureCmd($existing, $logicalId, $name, 'action', 'other', $order++, true);
        }
        foreach (jeetvbeLayout::FIXED_COMMANDS as $logicalId => $def) {
            $this->ensureCmd($existing, $logicalId, $def['name'], $def['type'], $def['subType'], $order++, false);
        }
    }

    private function ensureCmd(&$_existing, $_logicalId, $_name, $_type, $_subType, $_order, $_followName) {
        try {
            $cmd = isset($_existing[$_logicalId]) ? $_existing[$_logicalId] : null;
            $changed = false;
            if (!is_object($cmd)) {
                $cmd = new jeetvbeCmd();
                $cmd->setEqLogic_id($this->getId());
                $cmd->setLogicalId($_logicalId);
                $cmd->setName($_name);
                $cmd->setIsVisible(1);
                $cmd->setOrder($_order);
                if ($_type == 'info') {
                    $cmd->setIsHistorized(0);
                }
                $changed = true;
            } elseif ($_followName && $cmd->getName() !== $_name) {
                $cmd->setName($_name);
                $changed = true;
            }
            if ($cmd->getType() !== $_type || $cmd->getSubType() !== $_subType) {
                $cmd->setType($_type);
                $cmd->setSubType($_subType);
                $changed = true;
            }
            $placeholders = array(
                'show_page' => array('title_placeholder' => 'Page (id ou nom)', 'message_placeholder' => 'Durée (s), vide = par défaut'),
                'notify'    => array('title_placeholder' => 'Titre (facultatif)', 'message_placeholder' => 'Message'),
                'ask'       => array('title_placeholder' => 'Titre (facultatif)', 'message_placeholder' => 'Question'),
            );
            if (isset($placeholders[$_logicalId])) {
                foreach ($placeholders[$_logicalId] as $key => $value) {
                    if ($cmd->getDisplay($key) !== $value) {
                        $cmd->setDisplay($key, $value);
                        $changed = true;
                    }
                }
            }
            if ($changed) {
                $cmd->save();
                $_existing[$_logicalId] = $cmd;
            }
        } catch (Throwable $e) {
            log::add('jeetvbe', 'warning', sprintf('%s : commande « %s » non synchronisée — %s',
                $this->getHumanName(), $_name, $e->getMessage()));
        }
    }

    /* La durée d'affichage par défaut (s), 0 = sans retour. */
    public function showDuration() {
        return jeetvbeLayout::defaultDuration($this->getConfiguration('showDuration', ''));
    }

    /* ------------------------------------------------------ état de la TV */

    private static function seenKey($_id) {
        return 'jeetvbe::seen::' . (int) $_id;
    }

    public function lastSeen() {
        return (int) cache::byKey(self::seenKey($this->getId()))->getValue(0);
    }

    /* Appelée à chaque requête authentifiée de l'API. */
    public function markSeen() {
        cache::set(self::seenKey($this->getId()), time());
        $online = $this->getCmd('info', 'online');
        if (is_object($online) && $online->getCache('value', null) != 1) {
            $this->checkAndUpdateCmd('online', 1);
        }
    }

    /* Ce que l'onglet TV du desktop affiche de la TV. */
    public function status() {
        $read = function ($_logicalId) {
            $cmd = $this->getCmd('info', $_logicalId);
            return is_object($cmd) ? $cmd->getCache('value', null) : null;
        };
        $seen = $this->lastSeen();
        return array(
            'online'     => $read('online'),
            'appVersion' => $read('appVersion'),
            'lastSeen'   => ($seen > 0) ? date('Y-m-d H:i:s', $seen) : null,
        );
    }

    /* POST state : rend null si tout va bien, sinon un message d'erreur 400. */
    public function applyState($_body) {
        $updates = array();
        foreach (array('visible' => 'visible', 'screenOn' => 'screen') as $field => $logicalId) {
            if (array_key_exists($field, $_body)) {
                $value = jeetvbeLayout::stateBool($_body[$field]);
                if ($value === null) {
                    return 'Champ « ' . $field . ' » : booléen attendu';
                }
                $updates[$logicalId] = $value;
            }
        }
        if (array_key_exists('page', $_body)) {
            if ($_body['page'] !== null && !is_string($_body['page'])) {
                return 'Champ « page » : id de page ou null attendu';
            }
            $updates['page'] = jeetvbeLayout::shownPageName($this->allPages(), $_body['page']);
        }
        if (array_key_exists('appVersion', $_body)) {
            $version = jeetvbeLayout::stateVersion($_body['appVersion']);
            if ($version === null) {
                return 'Champ « appVersion » : chaîne non vide attendue';
            }
            $updates['appVersion'] = $version;
        }
        foreach ($updates as $logicalId => $value) {
            $this->checkAndUpdateCmd($logicalId, $value);
        }
        return null;
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
        return jeetvbeLayout::revision($this->pages(), $this->scenesPage());
    }

    public function layout() {
        return jeetvbeLayout::buildLayout($this->pages(), array(__CLASS__, 'describeCmd'), $this->scenesPage());
    }

    /* Le groupe de scénarios de la page dynamique, vide = désactivé. */
    public function scenarioGroup() {
        return trim((string) $this->getConfiguration('scenarioGroup', ''));
    }

    /* La page dynamique « Scénarios » (ou « Ambiances »), ou null. */
    public function scenesPage() {
        $group = $this->scenarioGroup();
        if ($group === '') {
            return null;
        }
        $scenarios = array();
        foreach (scenario::all($group) as $scenario) {
            if ($scenario->getGroup() !== $group) {
                continue;
            }
            $scenarios[] = array('id' => (int) $scenario->getId(), 'name' => $scenario->getName(),
                                 'description' => (string) $scenario->getDescription(), 'isActive' => $scenario->getIsActive() == 1);
        }
        return jeetvbeLayout::scenesPage($this->pages(), $scenarios);
    }

    /* Pages manuelles et page dynamique, pour les commandes Afficher. */
    public function allPages() {
        return jeetvbeLayout::allPages($this->pages(), $this->scenesPage());
    }

    /*
     * Exécute une action de tuile. Rend ['code' => HTTP, 'body' => tableau].
     * Les refus (404, 400, 422) ne touchent à rien ; une exception de Jeedom
     * pendant l'exécution rend 500 avec son message.
     */
    public function execTile($_tileId, $_action, $_value) {
        $tile = jeetvbeLayout::findTile($this->pages(), $_tileId, $this->scenesPage());
        if ($tile === null) {
            /* s<id> d'un scénario du groupe, mais désactivé : 422 ; scénario
             * hors groupe ou inexistant : 404, comme une tuile inconnue. */
            if (is_string($_tileId) && preg_match('/^s(\d{1,10})$/', $_tileId, $m) && $this->scenarioGroup() !== '') {
                $scenario = scenario::byId((int) $m[1]);
                if (is_object($scenario) && $scenario->getGroup() === $this->scenarioGroup() && $scenario->getIsActive() != 1) {
                    return array('code' => 422, 'body' => array('error' => 'Le scénario associé est désactivé'));
                }
            }
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
            return array('since' => self::nowCursor(), 'revision' => $revision, 'changes' => array(),
                         'commands' => self::takeOrders($this->getId()));
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
            /* Un ordre en file réveille l'attente au tour suivant (0,5 s). */
            $commands = self::takeOrders($this->getId());
            if (count($changes) > 0 || count($commands) > 0) {
                return array('since' => $cursor, 'revision' => $revision, 'changes' => $changes, 'commands' => $commands);
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
                $freshRevision = $fresh->revision();
                if ($freshRevision !== $revision) {
                    return array('since' => $cursor, 'revision' => $freshRevision, 'changes' => array(),
                                 'commands' => self::takeOrders($this->getId()));
                }
            }
            usleep(self::POLL_INTERVAL_US);
        }
        return array('since' => $cursor, 'revision' => $revision, 'changes' => array(),
                     'commands' => self::takeOrders($this->getId()));
    }
}

class jeetvbeCmd extends cmd {

    /* Les commandes sont tenues par le plugin (syncCommands) : le coeur ne doit
     * pas supprimer une commande absente du tableau envoyé par la page — celle
     * d'une page ajoutée dans le même enregistrement, par exemple. */
    public function dontRemoveCmd() {
        return true;
    }

    /* Met l'ordre en file ; la TV le reçoit par « changes ». */
    public function execute($_options = array()) {
        $tv = $this->getEqLogic();
        if (!is_object($tv) || $this->getType() != 'action') {
            return;
        }
        $options = is_array($_options) ? $_options : array();
        $logicalId = $this->getLogicalId();
        $order = null;

        if ($logicalId === 'show_page') {
            $ref = isset($options['title']) ? $options['title'] : '';
            $page = jeetvbeLayout::resolvePage($tv->allPages(), $ref);
            if ($page === null) {
                throw new Exception(sprintf(__('Page inconnue sur %s : %s', __FILE__), $tv->getHumanName(), $ref));
            }
            $duration = jeetvbeLayout::parseDuration(isset($options['message']) ? $options['message'] : '', $tv->showDuration());
            if ($duration === false) {
                throw new Exception(__('Durée invalide : nombre de secondes attendu (vide = durée par défaut, 0 = sans retour)', __FILE__));
            }
            $order = array('type' => 'show', 'page' => $page['id'], 'duration' => $duration);
        } elseif (strpos($logicalId, jeetvbeLayout::PAGE_COMMAND_PREFIX) === 0) {
            $pageId = substr($logicalId, strlen(jeetvbeLayout::PAGE_COMMAND_PREFIX));
            $page = jeetvbeLayout::resolvePage($tv->allPages(), $pageId);
            if ($page === null || $page['id'] !== $pageId) {
                throw new Exception(sprintf(__('La page %s n\'existe plus sur %s', __FILE__), $pageId, $tv->getHumanName()));
            }
            $order = array('type' => 'show', 'page' => $page['id'], 'duration' => $tv->showDuration());
        } elseif ($logicalId === 'ask' && is_array(isset($options['answer']) ? $options['answer'] : null)
                  && ($ask = jeetvbeLayout::askOrder($options, $token = bin2hex(random_bytes(16)))) !== null) {
            /* Bloc « Demander » : le cœur a posé ask::variable, ask::endtime et
             * ask::answer sur cette commande, et attend la réponse. */
            jeetvbe::rememberAsk($tv->getId(), jeetvbeLayout::askPending($token, $this->getId(), $ask['answers'], $ask['timeout'], time()));
            $order = jeetvbe::enqueue($tv->getId(), $ask, jeetvbeLayout::askTtl($ask['timeout']));
            log::add('jeetvbe', 'info', sprintf('%s : question %s mise en file (ordre %s) — %s', $tv->getHumanName(),
                substr($token, 0, 8), $order['id'], json_encode($ask['answers'], JSON_UNESCAPED_UNICODE)));
            return;
        } elseif ($logicalId === 'notify' || $logicalId === 'ask') {
            /* « Question » sans réponses (hors bloc Demander) : comme « Message ». */
            $message = trim((string) (isset($options['message']) ? $options['message'] : ''));
            if ($message === '') {
                throw new Exception(__('Message vide', __FILE__));
            }
            $order = array('type' => 'notify', 'title' => trim((string) (isset($options['title']) ? $options['title'] : '')), 'message' => $message);
        } elseif ($logicalId === 'exit') {
            $order = array('type' => 'exit');
        }
        if ($order === null) {
            return;
        }
        $order = jeetvbe::enqueue($tv->getId(), $order);
        log::add('jeetvbe', 'info', sprintf('%s : ordre %s mis en file — %s', $tv->getHumanName(), $order['id'],
            json_encode($order, JSON_UNESCAPED_UNICODE)));
    }
}
