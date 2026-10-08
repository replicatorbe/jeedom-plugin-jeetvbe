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
require_once __DIR__ . '/jeetvbeOverlay.class.php';

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

    /* Les tableaux des trains sont recalculés au moins toutes les 30 s
     * pendant l'attente longue (contrat : au moins une fois par minute). */
    const BOARD_REFRESH_SECONDS = 30;

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
            if ($eqLogic->isBroadcast()) {
                continue;
            }
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
        self::purgeAllImages();
        $group = self::groupAsk();
        if (is_array($group) && isset($group['endtime']) && time() > $group['endtime'] + 60) {
            cache::delete(self::GROUP_ASK_KEY);
        }
        /* Barre d'état : indicateurs temporaires échus, et un recalcul de
         * sûreté (un événement manqué par le listener se rattrape ici). */
        foreach (eqLogic::byType('jeetvbe', true) as $tv) {
            if ($tv->isBroadcast()) {
                continue;
            }
            try {
                $tv->refreshStatus('cron');
            } catch (Throwable $e) {
                log::add('jeetvbe', 'warning', sprintf('%s : barre d\'état non recalculée — %s', $tv->getHumanName(), $e->getMessage()));
            }
        }
        self::refreshScreensOn();
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

    /* Chaque heure : les restes d'une TV disparue (compteur d'ordres,
     * indicateurs temporaires, sources vidéo, états en cache). */
    public static function cronHourly() {
        $ids = array();
        foreach (eqLogic::byType('jeetvbe') as $tv) {
            $ids[(int) $tv->getId()] = true;
        }
        foreach (array('seq::', 'indicators::', 'videos::') as $prefix) {
            foreach (config::searchKey($prefix, 'jeetvbe') as $row) {
                if (strpos($row['key'], $prefix) !== 0) {
                    continue;
                }
                $id = (int) substr($row['key'], strlen($prefix));
                if ($id > 0 && !isset($ids[$id])) {
                    config::remove($row['key'], 'jeetvbe');
                    foreach (array(self::statusKey($id), self::queueKey($id), self::historyKey($id), self::revisionKey($id), self::boardsKey($id)) as $key) {
                        cache::delete($key);
                    }
                    log::add('jeetvbe', 'debug', 'Restes d\'une TV supprimée effacés : ' . $row['key']);
                }
            }
        }
    }

    /* ------------------------------------------- images jointes aux ordres
     *
     * data/images/<id TV>/<id>.<jpg|png> et <id>.json (type, expiration).
     * data/ n'est ni versionné (.gitignore) ni déployé (.deployignore) : un
     * redéploiement (rsync --delete) ne touche pas aux images en cours. Le
     * seul accès est GET ?action=image : un .htaccess ferme le dossier.
     */
    public static function imagesRoot() {
        return dirname(__DIR__, 2) . '/data/images';
    }

    /* « Require all denied » (Apache 2.4) : la seule forme « Order/Deny »
     * n'est pas appliquée sur cette installation, et la règle de Jeedom sur
     * data/ laisse passer les .jpg et .png. */
    const IMAGES_HTACCESS = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";

    private static function ensureImagesRoot() {
        $root = self::imagesRoot();
        foreach (array(dirname($root), $root) as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (is_dir($dir) && @file_get_contents($dir . '/.htaccess') !== self::IMAGES_HTACCESS) {
                @file_put_contents($dir . '/.htaccess', self::IMAGES_HTACCESS);
            }
        }
        return $root;
    }

    public static function imageDir($_tvId) {
        return self::imagesRoot() . '/' . (int) $_tvId;
    }

    /* Les dossiers d'où une image peut venir : la racine de Jeedom et son
     * dossier temporaire. */
    public static function imageRoots() {
        $tmp = '';
        try {
            $tmp = jeedom::getTmpFolder();
        } catch (Throwable $e) {
        }
        return jeetvbeLayout::expandRoots(jeetvbeLayout::imageRootPatterns(dirname(__DIR__, 4), $tmp,
            (string) config::byKey('imageRoots', 'jeetvbe', '')));
    }

    /*
     * Copie l'image demandée pour un ordre de cette TV ; rend son identifiant,
     * ou null (fichier refusé : l'ordre part sans image, la raison va au
     * journal). Purge au passage les images expirées de la TV.
     */
    public static function attachImage($_tvId, $_path, $_orderLifetime) {
        if ($_path === null || $_path === '') {
            return null;
        }
        self::ensureImagesRoot();
        $dir = self::imageDir($_tvId);
        jeetvbeLayout::purgeImages($dir, time());
        $valid = jeetvbeLayout::validateImage($_path, self::imageRoots());
        if (!$valid['ok']) {
            log::add('jeetvbe', 'warning', sprintf('TV %s : image refusée, ordre envoyé sans elle — %s : %s',
                $_tvId, mb_substr($_path, 0, 200, 'UTF-8'), $valid['reason']));
            return null;
        }
        $id = jeetvbeLayout::storeImage($dir, $valid, jeetvbeLayout::imageExpiry(time(), $_orderLifetime), bin2hex(random_bytes(16)));
        if ($id === null) {
            log::add('jeetvbe', 'warning', sprintf('TV %s : copie de l\'image impossible dans %s, ordre envoyé sans elle', $_tvId, $dir));
        }
        return $id;
    }

    /* Purge de toutes les TV ; le dossier d'une TV supprimée disparaît. */
    public static function purgeAllImages() {
        $root = self::imagesRoot();
        if (!is_dir($root)) {
            return;
        }
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: array() as $dir) {
            $tvId = basename($dir);
            if (!ctype_digit($tvId)) {
                continue;
            }
            $tv = eqLogic::byId((int) $tvId);
            $now = (is_object($tv) && $tv->getEqType_name() == 'jeetvbe') ? time() : PHP_INT_MAX;
            jeetvbeLayout::purgeImages($dir, $now);
            if ($now === PHP_INT_MAX) {
                @rmdir($dir);
            }
        }
    }

    /* L'image d'identifiant donné pour CETTE TV, encore valable, ou null. */
    public function image($_id) {
        return jeetvbeLayout::findImage(self::imageDir($this->getId()), $_id, time());
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
        /* Une TV supprimée entre-temps : rien (pas de file ni de compteur
         * orphelins). */
        $tv = eqLogic::byId($_tvId);
        if (!is_object($tv) || $tv->getEqType_name() !== 'jeetvbe') {
            return null;
        }
        return self::withQueueLock($_tvId, function () use ($_tvId, $_order, $_ttl) {
            $seq = (int) config::byKey('seq::' . (int) $_tvId, 'jeetvbe', 0, true) + 1;
            config::save('seq::' . (int) $_tvId, $seq, 'jeetvbe');
            /* « id » est toujours celui de l'ordre : jamais repris de l'ordre
             * fourni (l'id d'une notification voyage dans « tag »). */
            unset($_order['id']);
            $order = array_merge(array('id' => $seq), $_order);
            $queue = cache::byKey(self::queueKey($_tvId))->getValue(array());
            cache::set(self::queueKey($_tvId), jeetvbeLayout::queuePush($queue, $order, microtime(true), $_ttl));
            /* Les 10 derniers ordres, pour l'onglet TV (adresses masquées,
             * jetons tronqués). */
            $history = cache::byKey(self::historyKey($_tvId))->getValue(array());
            $history = is_array($history) ? $history : array();
            $entry = jeetvbeOverlay::orderForLog($order);
            if (isset($entry['ask'])) {
                $entry['ask'] = substr((string) $entry['ask'], 0, 8);
            }
            $history[] = array('at' => date('Y-m-d H:i:s'), 'order' => $entry);
            cache::set(self::historyKey($_tvId), array_slice($history, -10));
            return $order;
        });
    }

    private static function historyKey($_id) {
        return 'jeetvbe::history::' . (int) $_id;
    }

    /* Les derniers ordres d'une TV (onglet TV). */
    public static function orderHistory($_tvId) {
        $history = cache::byKey(self::historyKey($_tvId))->getValue(array());
        return is_array($history) ? array_reverse($history) : array();
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
        /* La question de groupe (Toutes les TV) d'abord : même jeton sur
         * plusieurs TV. */
        $group = self::groupAsk();
        if (is_array($group) && is_string($_token) && isset($group['token']) && hash_equals((string) $group['token'], $_token)) {
            return $this->answerGroup($_token, $_answer);
        }
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

    /* ------------------------------------- attente longue la plus récente
     *
     * Seule la dernière requête « changes » arrivée pour une TV prend les
     * ordres. Une attente abandonnée par la TV (application relancée, boucle
     * redémarrée, coupure réseau) tourne encore jusqu'à 25 s côté serveur, et
     * PHP ne voit pas la déconnexion tant qu'il n'écrit rien : sans cette
     * règle, elle pouvait prendre l'ordre suivant et l'écrire dans une
     * connexion fermée — ordre perdu. */
    private static function pollKey($_id) {
        return 'jeetvbe::poll::' . (int) $_id;
    }

    public static function claimPoll($_tvId) {
        $token = bin2hex(random_bytes(8));
        cache::set(self::pollKey($_tvId), $token, self::LONGPOLL_SECONDS * 4);
        return $token;
    }

    public static function ownsPoll($_tvId, $_token) {
        return cache::byKey(self::pollKey($_tvId))->getValue('') === $_token;
    }

    /* Les ordres pour la requête $_poll : rien si une requête plus récente
     * de la même TV attend (elle les prendra). */
    public static function takeOrdersFor($_tvId, $_poll) {
        if (!self::hasOrders($_tvId) || !self::ownsPoll($_tvId, $_poll)) {
            return array();
        }
        return self::takeOrders($_tvId);
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
            'subType'  => $cmd->getSubType(),
            'value'    => $value,
            'unit'     => (string) $cmd->getUnite(),
            'minValue' => $cmd->getConfiguration('minValue', ''),
            'maxValue' => $cmd->getConfiguration('maxValue', ''),
            'listValue' => (string) $cmd->getConfiguration('listValue', ''),
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
        /* « Toutes les TV » dupliquée : la copie redevient une TV ordinaire. */
        $this->dropDuplicateBroadcast();
        /* Jamais d'exception ici : le coeur crée l'équipement avec son seul nom. */
        if (!$this->isBroadcast() && (!self::validToken($this->getConfiguration('token', '')) || $this->tokenTakenByOther())) {
            $this->setConfiguration('token', self::newToken());
        }
        /* « Toutes les TV » : ni clé (l'API la refuse), ni pages, ni barre. */
        if ($this->isBroadcast()) {
            $this->setConfiguration('token', '');
            foreach (array('pages', 'header', 'keys', 'statusBar', 'indicators', 'scenarioGroup', 'broadcast') as $key) {
                $this->setConfiguration($key, null);
            }
            return;
        }
        $this->setConfiguration('broadcast', jeetvbeOverlay::receivesBroadcast($this->getConfiguration('broadcast', '') === '' ? null : $this->getConfiguration('broadcast')) ? 1 : 0);
        /* L'équipement tel qu'enregistré jusque-là (null à la création). */
        $stored = null;
        try {
            if ($this->getId() != '') {
                $stored = eqLogic::byId($this->getId());
            }
        } catch (Throwable $e) {
            $stored = null;
        }
        /* Les ids des tuiles inchangées sont repris des pages enregistrées, et
         * un numéro de tuile n'est jamais réattribué (tileSeq). */
        $previous = null;
        $floor = (int) $this->getConfiguration('tileSeq', 0);
        $pageFloor = (int) $this->getConfiguration('pageSeq', 0);
        if (is_object($stored)) {
            $previous = $stored->getConfiguration('pages', array());
            $floor = max($floor, jeetvbeLayout::maxTileNumber($previous));
            $pageFloor = max($pageFloor, (int) $stored->getConfiguration('pageSeq', 0), jeetvbeLayout::maxPageNumber($previous));
        }
        $pages = jeetvbeLayout::normalizePages($this->getConfiguration('pages', array()), $previous, $floor, $pageFloor);
        $this->setConfiguration('pages', $pages);
        $this->setConfiguration('tileSeq', max($floor, jeetvbeLayout::maxTileNumber($pages)));
        $this->setConfiguration('pageSeq', max($pageFloor, jeetvbeLayout::maxPageNumber($pages)));
        $this->setConfiguration('showDuration', jeetvbeLayout::defaultDuration($this->getConfiguration('showDuration', '')));
        /* Bandeau : ids repris, numéro jamais réattribué (headerSeq). */
        $headerFloor = (int) $this->getConfiguration('headerSeq', 0);
        $previousHeader = null;
        if (is_object($stored)) {
            $previousHeader = $stored->getConfiguration('header', array());
            $headerFloor = max($headerFloor, (int) $stored->getConfiguration('headerSeq', 0), jeetvbeLayout::maxHeaderNumber($previousHeader));
        }
        $header = jeetvbeLayout::normalizeHeader($this->getConfiguration('header', array()), $previousHeader, $headerFloor);
        $this->setConfiguration('header', $header);
        $this->setConfiguration('headerSeq', max($headerFloor, jeetvbeLayout::maxHeaderNumber($header)));
        $this->setConfiguration('scenarioGroup', trim((string) $this->getConfiguration('scenarioGroup', '')));
        /* Touches de couleur : nettoyées seulement si elles ont déjà été
         * enregistrées (absentes = jamais réglées, l'éditeur propose alors
         * rouge = première page). */
        /* Copie depuis une autre TV : les touches suivent les pages par leur
         * nom, maintenant que les ids des pages sont attribués. */
        $keysByName = $this->getConfiguration('keysByName', null);
        if (is_array($keysByName)) {
            $this->setConfiguration('keys', (object) jeetvbeLayout::resolveKeysByName($keysByName, $pages));
            $this->setConfiguration('keysByName', null);
        }
        if ($this->getConfiguration('keys', null) !== null) {
            $this->setConfiguration('keys', (object) jeetvbeLayout::normalizeKeys($this->getConfiguration('keys')));
        }
        /* Barre d'état : réglages et indicateurs rangés sous leur forme
         * complète. Un indicateur mal décrit est gardé (on le corrige dans la
         * page) mais ne s'affiche pas ; postSave() le signale au journal. */
        $this->setConfiguration('statusBar', jeetvbeOverlay::normalizeBar($this->getConfiguration('statusBar', array())));
        $this->setConfiguration('indicators', jeetvbeOverlay::normalizeIndicators($this->getConfiguration('indicators', array())));
    }

    /* Une seule diffusion : la copie d'un « Toutes les TV » (sans id, ou
     * d'id plus récent) perd son rôle et redevient une TV ordinaire. */
    private function dropDuplicateBroadcast() {
        if (!$this->isBroadcast()) {
            return;
        }
        try {
            $others = array();
            foreach (eqLogic::byType('jeetvbe') as $other) {
                if ($other->isBroadcast()) {
                    $others[] = (int) $other->getId();
                }
            }
            if (jeetvbeOverlay::broadcastClash($this->getId(), $others)) {
                $this->setLogicalId('');
                $this->setConfiguration('role', null);
            }
        } catch (Throwable $e) {
        }
    }

    /*
     * La clé est-elle déjà celle d'une autre TV ? C'est le cas d'un équipement
     * « Dupliqué » : le coeur recopie toute la configuration, clé comprise.
     * Deux TV ne doivent jamais partager une clé (l'API servirait l'une à la
     * place de l'autre, et les ordres de l'une partiraient vers l'autre).
     * La copie est la nouvelle (sans id) ou la plus récente (id le plus
     * grand) : la clé d'une TV en service ne change jamais d'elle-même.
     */
    private function tokenTakenByOther() {
        try {
            $others = array();
            foreach (eqLogic::byType('jeetvbe') as $other) {
                $others[(int) $other->getId()] = (string) $other->getConfiguration('token', '');
            }
            return jeetvbeLayout::tokenClash((string) $this->getConfiguration('token', ''), $this->getId(), $others);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function postSave() {
        cache::delete(self::revisionKey($this->getId()));
        $this->syncCommands();
        if ($this->isBroadcast()) {
            return;
        }
        self::refreshScreensOn();
        try {
            $errors = jeetvbeOverlay::indicatorErrors($this->getConfiguration('indicators', array()));
            if (count($errors) > 0) {
                log::add('jeetvbe', 'warning', sprintf('%s : barre d\'état — %s', $this->getHumanName(), implode(' ', $errors)));
            }
            $this->updateStatusListener();
            $this->refreshStatus('save');
        } catch (Throwable $e) {
            log::add('jeetvbe', 'warning', sprintf('%s : barre d\'état — %s', $this->getHumanName(), $e->getMessage()));
        }
    }

    /* TV supprimée : sa file d'ordres, sa question en attente, son compteur
     * d'ordres et ses images partent avec elle. */
    public function preRemove() {
        $id = (int) $this->getId();
        /* « Toutes les TV » supprimée volontairement : ni le cron ni la mise à
         * jour ne la recréent (repère effacé si on la recrée à la main). */
        if ($this->isBroadcast()) {
            config::save('broadcastRemoved', 1, 'jeetvbe');
        }
        try {
            foreach (array(self::queueKey($id), self::askKey($id), self::seenKey($id), self::pollKey($id), self::statusKey($id),
                           self::historyKey($id), self::revisionKey($id), self::boardsKey($id)) as $key) {
                cache::delete($key);
            }
            config::remove('seq::' . $id, 'jeetvbe');
            config::remove(self::temporaryKey($id), 'jeetvbe');
            config::remove(self::videosKey($id), 'jeetvbe');
            $listener = listener::byClassAndFunction(__CLASS__, 'pullStatus', array('eqLogic_id' => $id));
            if (is_object($listener)) {
                $listener->remove();
            }
            jeetvbeLayout::purgeImages(self::imageDir($id), PHP_INT_MAX);
            @rmdir(self::imageDir($id));
        } catch (Throwable $e) {
            log::add('jeetvbe', 'warning', sprintf('%s : nettoyage incomplet à la suppression — %s', $this->getHumanName(), $e->getMessage()));
        }
    }

    /* Une TV supprimée sort aussitôt de « TV allumées ». */
    public function postRemove() {
        self::refreshScreensOn();
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
        if ($this->isBroadcast()) {
            $this->ensureCmd($existing, 'tv_on', 'TV allumées', 'info', 'numeric', $order++, false);
            foreach (jeetvbeOverlay::BROADCAST_COMMANDS as $logicalId) {
                $def = jeetvbeLayout::FIXED_COMMANDS[$logicalId];
                $this->ensureCmd($existing, $logicalId, $def['name'], $def['type'], $def['subType'], $order++, false);
            }
            return;
        }
        /* La page « Scénarios » garde sa commande tant qu'un groupe est réglé,
         * même si aucun de ses scénarios n'est actif en ce moment. */
        $expected = jeetvbeLayout::pageCommands(jeetvbeLayout::allPages($this->pages(), $this->scenesPage(), $this->scenarioGroup() !== ''));

        /* Les « Afficher <page> » qui n'ont plus de page d'abord : leur nom se
         * libère pour une page renommée. */
        foreach ($existing as $logicalId => $cmd) {
            if (strpos($logicalId, jeetvbeLayout::PAGE_COMMAND_PREFIX) === 0 && $logicalId !== 'show_page' && !isset($expected[$logicalId])) {
                $this->warnIfUsed($cmd);
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

    /* Une commande « Afficher <page> » supprimée avec sa page : si un
     * scénario, une autre commande ou un design s'en servait, le journal le
     * dit (la référence #id# y devient orpheline). */
    private function warnIfUsed($_cmd) {
        try {
            $users = array();
            foreach ($_cmd->getUsedBy() as $kind => $list) {
                foreach (is_array($list) ? $list : array() as $user) {
                    $users[] = is_object($user) && method_exists($user, 'getHumanName') ? $user->getHumanName() : $kind;
                }
            }
            if (count($users) > 0) {
                log::add('jeetvbe', 'warning', sprintf('%s : la page de « %s » a été supprimée, mais la commande servait encore à : %s',
                    $this->getHumanName(), $_cmd->getName(), implode(', ', array_slice($users, 0, 10))));
            }
        } catch (Throwable $e) {
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
                $cmd->setIsVisible(jeetvbeLayout::visibleByDefault($_logicalId) ? 1 : 0);
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
                'notify_json'  => array('title_disable' => '1', 'message_placeholder' => '{"id":"sonnette","title":"On sonne","video":"portier","duration":30}'),
                'fixed_json'   => array('title_disable' => '1', 'message_placeholder' => '{"id":"lampe","icon":"mdi:lightbulb","expiration":"30m"}'),
                'dismiss'      => array('title_disable' => '1', 'message_placeholder' => 'id de la notification'),
                'fixed_remove' => array('title_disable' => '1', 'message_placeholder' => 'id de l\'indicateur'),
            );
            if (isset($placeholders[$_logicalId])) {
                foreach ($placeholders[$_logicalId] as $key => $value) {
                    if ((string) $cmd->getDisplay($key) !== $value) {
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

    /* ================================================ « Toutes les TV »
     *
     * Un équipement jeetvbe spécial (logicalId « broadcast », configuration
     * role = broadcast), créé par le plugin : il n'est pas une TV (pas de clé,
     * l'API ne le connaît pas) et porte seulement les commandes de diffusion.
     * Chaque commande est rejouée sur les TV choisies par
     * jeetvbeOverlay::broadcastTargets(), avec leurs propres sources vidéo,
     * images et files d'ordres.
     */
    const BROADCAST_LOGICAL_ID = 'broadcast';
    const BROADCAST_NAME = 'Toutes les TV';

    public function isBroadcast() {
        return $this->getLogicalId() === self::BROADCAST_LOGICAL_ID || $this->getConfiguration('role', '') === 'broadcast';
    }

    /* Les TV telles que la diffusion les voit. */
    private static function broadcastView() {
        $tvs = array();
        foreach (eqLogic::byType('jeetvbe') as $tv) {
            if ($tv->isBroadcast()) {
                continue;
            }
            $screen = $tv->getCmd('info', 'screen');
            $tvs[] = array('id' => (int) $tv->getId(), 'enabled' => $tv->getIsEnable() == 1, 'receive' => $tv->getConfiguration('broadcast', ''),
                           'lastSeen' => $tv->lastSeen(), 'screen' => is_object($screen) ? $screen->getCache('value', null) : null);
        }
        return $tvs;
    }

    /* Info « TV allumées » de Toutes les TV : mise à jour quand une TV
     * signale son état, quand une TV est enregistrée, et chaque minute (une
     * TV qui ne répond plus sort du compte). */
    public static function refreshScreensOn() {
        try {
            $broadcast = eqLogic::byLogicalId(self::BROADCAST_LOGICAL_ID, 'jeetvbe');
            if (is_object($broadcast) && $broadcast->getIsEnable() == 1) {
                $broadcast->checkAndUpdateCmd('tv_on', jeetvbeOverlay::screensOn(self::broadcastView(), time(), self::ONLINE_TIMEOUT));
            }
        } catch (Throwable $e) {
            log::add('jeetvbe', 'warning', 'Toutes les TV : TV allumées non mises à jour — ' . $e->getMessage());
        }
    }

    /* Masque une fois les commandes techniques des équipements existants
     * (la création les masque déjà) ; un choix fait ensuite dans Jeedom est
     * respecté (repère « visibilityDefaults » dans la config du plugin). */
    public static function applyDefaultVisibility($_force = false) {
        if (!$_force && config::byKey('visibilityDefaults', 'jeetvbe', 0) == 1) {
            return 0;
        }
        $hidden = 0;
        foreach (eqLogic::byType('jeetvbe') as $eq) {
            foreach ($eq->getCmd() as $cmd) {
                if (!jeetvbeLayout::visibleByDefault($cmd->getLogicalId()) && $cmd->getIsVisible() == 1) {
                    $cmd->setIsVisible(0);
                    $cmd->save();
                    $hidden++;
                }
            }
        }
        config::save('visibilityDefaults', 1, 'jeetvbe');
        return $hidden;
    }

    /*
     * Copie la configuration d'une TV sur une autre (voir
     * jeetvbeLayout::copyConfiguration : pages, bandeau, touches, barre,
     * indicateurs ; ni clé, ni sources vidéo, ni option de diffusion, ni nom).
     *   jeetvbe::copyFromTv(<id TV source>, <id TV cible>);          enregistre la cible
     *   jeetvbe::copyFromTv(<id TV source>, <id TV cible>, false);   rend seulement la copie
     */
    public static function copyFromTv($_sourceId, $_targetId, $_save = true) {
        $source = eqLogic::byId($_sourceId);
        $target = eqLogic::byId($_targetId);
        foreach (array($source, $target) as $tv) {
            if (!is_object($tv) || $tv->getEqType_name() !== 'jeetvbe' || $tv->isBroadcast()) {
                throw new Exception(__('TV introuvable', __FILE__));
            }
        }
        if ((int) $source->getId() === (int) $target->getId()) {
            throw new Exception(__('Choisissez une autre TV que celle-ci.', __FILE__));
        }
        $copy = jeetvbeLayout::copyConfiguration(array(
            'pages' => $source->getConfiguration('pages', array()), 'header' => $source->getConfiguration('header', array()),
            'keys' => $source->getConfiguration('keys', array()), 'statusBar' => $source->getConfiguration('statusBar', array()),
            'indicators' => $source->getConfiguration('indicators', array()),
        ));
        if (!$_save) {
            return $copy;
        }
        foreach ($copy as $key => $value) {
            $target->setConfiguration($key, $value);
        }
        $target->save();
        log::add('jeetvbe', 'info', sprintf('%s : pages, bandeau, touches et barre copiés depuis %s', $target->getHumanName(), $source->getHumanName()));
        return $copy;
    }

    /* Crée l'équipement « Toutes les TV » s'il manque ; le rend. */
    public static function ensureBroadcast($_force = false) {
        $eq = eqLogic::byLogicalId(self::BROADCAST_LOGICAL_ID, 'jeetvbe');
        if (is_object($eq)) {
            return $eq;
        }
        if (!$_force && config::byKey('broadcastRemoved', 'jeetvbe', 0) == 1) {
            return null;
        }
        config::remove('broadcastRemoved', 'jeetvbe');
        $eq = new jeetvbe();
        $eq->setEqType_name('jeetvbe');
        $eq->setLogicalId(self::BROADCAST_LOGICAL_ID);
        $eq->setConfiguration('role', 'broadcast');
        $name = self::BROADCAST_NAME;
        $eq->setName($name);
        $eq->setIsEnable(1);
        $eq->setIsVisible(1);
        $eq->save();
        log::add('jeetvbe', 'info', 'Équipement « ' . $name . ' » créé pour les diffusions');
        return $eq;
    }

    /* Diffuse une commande ($_logicalId : notify, notify_json, dismiss,
     * fixed_json, fixed_remove) aux TV choisies. Une TV en échec n'arrête pas
     * les autres ; si toutes échouent, la première erreur remonte. */
    public static function broadcast($_logicalId, $_options, $_cmdId = 0) {
        if (!in_array($_logicalId, jeetvbeOverlay::BROADCAST_COMMANDS, true)) {
            return;
        }
        /* Bloc « Demander » : une question de groupe. Sans réponses, la
         * Question se diffuse comme un Message (plus bas). */
        $groupAsk = ($_logicalId === 'ask' && isset($_options['answer']) && is_array($_options['answer'])
                     && count(jeetvbeLayout::askAnswers($_options['answer'])) > 0);
        $token = $groupAsk ? bin2hex(random_bytes(16)) : null;
        $tvs = array();
        $byId = array();
        foreach (eqLogic::byType('jeetvbe') as $tv) {
            if ($tv->isBroadcast()) {
                continue;
            }
            $screen = $tv->getCmd('info', 'screen');
            $tvs[] = array('id' => (int) $tv->getId(), 'enabled' => $tv->getIsEnable() == 1, 'receive' => $tv->getConfiguration('broadcast', ''),
                           'lastSeen' => $tv->lastSeen(), 'screen' => is_object($screen) ? $screen->getCache('value', null) : null);
            $byId[(int) $tv->getId()] = $tv;
        }
        $targets = jeetvbeOverlay::broadcastTargets($tvs, $_logicalId, time(), self::ONLINE_TIMEOUT);
        $reached = array();
        $errors = array();
        if ($groupAsk) {
            /* La question précédente est remplacée dans tous les cas, même si
             * aucune TV n'est allumée : une réponse tardive à l'ancienne ne
             * doit jamais remplir la variable de la nouvelle (même commande).
             * Ses TV la ferment. La nouvelle est retenue AVANT la mise en
             * file : une TV qui répond très vite la trouve. */
            $names = array();
            foreach ($targets as $id) {
                $names[$id] = $byId[$id]->getName();
            }
            $answers = jeetvbeLayout::askAnswers($_options['answer']);
            $timeout = jeetvbeLayout::askTimeout(isset($_options['timeout']) ? $_options['timeout'] : null);
            $previous = self::withGroupAskLock(function () use ($token, $_cmdId, $answers, $timeout, $names) {
                $old = self::groupAsk();
                if (count($names) > 0) {
                    self::rememberGroupAsk(jeetvbeOverlay::groupAskPending($token, $_cmdId, $answers, $timeout, time(), $names));
                } else {
                    cache::delete(self::GROUP_ASK_KEY);
                }
                return $old;
            });
            foreach (jeetvbeOverlay::groupAskReplacedOrders($previous) as $oldId => $close) {
                self::enqueue($oldId, $close, jeetvbeLayout::QUEUE_TTL);
            }
        }
        if ($_logicalId === 'notify_json') {
            self::beginImageShare();
        }
        foreach ($targets as $id) {
            $tv = $byId[$id];
            $cmd = $tv->getCmd('action', $_logicalId);
            try {
                $order = jeetvbeCmd::run($tv, $_logicalId, $_options, $groupAsk ? $_cmdId : (is_object($cmd) ? $cmd->getId() : 0), true, $token);
                if (!$groupAsk || is_array($order)) {
                    $reached[(int) $tv->getId()] = $tv->getName();
                }
            } catch (Throwable $e) {
                $errors[] = $e;
                log::add('jeetvbe', 'warning', sprintf('Toutes les TV : « %s » refusé par %s — %s', $_logicalId, $tv->getHumanName(), $e->getMessage()));
            }
        }
        if ($_logicalId === 'notify_json') {
            self::endImageShare();
        }
        if ($groupAsk) {
            /* Aucune TV : rien en file, le cœur arrivera à « Aucune réponse » à
             * la fin du délai. */
            log::add('jeetvbe', 'info', sprintf('Toutes les TV : question %s posée à %d TV%s', substr($token, 0, 8), count($reached),
                count($reached) > 0 ? ' (' . implode(', ', $reached) . ')' : ''));
        } else {
            log::add('jeetvbe', 'info', sprintf('Toutes les TV : « %s » → %d TV atteinte(s) sur %d%s', $_logicalId, count($reached), count($tvs),
                count($reached) > 0 ? ' (' . implode(', ', $reached) . ')' : ''));
        }
        if (count($targets) > 0 && count($reached) === 0 && count($errors) > 0) {
            throw $errors[0];
        }
    }

    /* ------------------------------------------- question de groupe
     *
     * Une seule à la fois (une nouvelle remplace la précédente, comme sur une
     * TV). Gardée dans le cache jusqu'à la fin du délai, réponse comprise :
     * une réponse tardive d'une autre TV reçoit alors 409. */
    const GROUP_ASK_KEY = 'jeetvbe::groupask';

    public static function groupAsk() {
        $group = cache::byKey(self::GROUP_ASK_KEY)->getValue(null);
        return is_array($group) ? $group : null;
    }

    public static function rememberGroupAsk($_group) {
        cache::set(self::GROUP_ASK_KEY, $_group, max(60, (int) $_group['endtime'] - time() + 60));
    }

    /* Le verrou de la question de groupe : deux réponses quasi simultanées
     * passent l'une après l'autre ; la seconde trouve la question répondue. */
    private static function withGroupAskLock($_callback) {
        $handle = false;
        try {
            $handle = @fopen(jeedom::getTmpFolder('jeetvbe') . '/groupask.lock', 'c');
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

    /* Réponse de CETTE TV à la question de groupe (même contrat que
     * answer()) : askResponse au nom de la commande de Toutes les TV, puis
     * ask_close vers les autres TV visées. */
    private function answerGroup($_token, $_answer) {
        $tvId = (int) $this->getId();
        $name = $this->getName();
        return self::withGroupAskLock(function () use ($tvId, $name, $_token, $_answer) {
            $group = self::groupAsk();
            $check = jeetvbeOverlay::checkGroupAnswer($group, $tvId, $_token, $_answer, time());
            if ($check['code'] !== 200) {
                return array('code' => $check['code'], 'body' => array('error' => $check['message']));
            }
            $cmd = cmd::byId($group['cmd_id']);
            if (!is_object($cmd) || !$cmd->askResponse($check['answer'])) {
                $endtime = is_object($cmd) ? $cmd->getCache('ask::endtime', null) : null;
                if (!is_object($cmd) || $cmd->getCache('ask::variable', 'none') == 'none' || $endtime === null || $endtime < strtotime('now')) {
                    cache::delete(self::GROUP_ASK_KEY);
                    return array('code' => 404, 'body' => array('error' => 'Question expirée ou déjà répondue'));
                }
                return array('code' => 422, 'body' => array('error' => 'Réponse refusée par Jeedom'));
            }
            $group = jeetvbeOverlay::groupAskAnswered($group, $tvId, $check['answer']);
            self::rememberGroupAsk($group);
            foreach (jeetvbeOverlay::groupAskCloseOrders($group) as $otherId => $close) {
                /* enqueue() ignore une TV supprimée entre-temps. */
                self::enqueue($otherId, $close, jeetvbeLayout::QUEUE_TTL);
            }
            log::add('jeetvbe', 'info', sprintf('Toutes les TV : réponse « %s » de %s à la question %s, fermée sur %d autre(s) TV',
                $check['answer'], $name, substr($group['token'], 0, 8), count($group['targets']) - 1));
            return array('code' => 200, 'body' => array('ok' => true));
        });
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
            self::refreshScreensOn();
        }
    }

    /* Ce que l'onglet TV du desktop affiche de la TV. */
    public function status() {
        $read = function ($_logicalId) {
            $cmd = $this->getCmd('info', $_logicalId);
            return is_object($cmd) ? $cmd->getCache('value', null) : null;
        };
        $seen = $this->lastSeen();
        $view = array('enabled' => $this->getIsEnable() == 1, 'receive' => $this->getConfiguration('broadcast', ''), 'lastSeen' => $seen, 'screen' => $read('screen'));
        return array(
            'online'     => $read('online'),
            'appVersion' => $read('appVersion'),
            'lastSeen'   => ($seen > 0) ? date('Y-m-d H:i:s', $seen) : null,
            /* Compte-t-elle dans « TV allumées », et sinon pourquoi. */
            'screensOn'  => jeetvbeOverlay::screenOnReason($view, time(), self::ONLINE_TIMEOUT),
            'orders'     => self::orderHistory($this->getId()),
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
        if (isset($updates['screen'])) {
            self::refreshScreensOn();
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

    private static function revisionKey($_id) {
        return 'jeetvbe::revision::' . (int) $_id;
    }

    /*
     * La révision, mémorisée : recalculée seulement si l'empreinte légère
     * change — configuration de la TV, scénarios du groupe (id, nom, actif,
     * description) et listes des commandes « select ». L'attente longue la
     * relit toutes les 2 s. Effacée à l'enregistrement de la TV.
     */
    public function revision() {
        $parts = array(json_encode(array($this->getConfiguration('pages', array()), $this->getConfiguration('header', array()),
                                         $this->getConfiguration('keys', null), $this->getConfiguration('scenarioGroup', ''))));
        $group = $this->scenarioGroup();
        if ($group !== '') {
            $parts[] = json_encode(DB::Prepare('SELECT id, name, isActive, description FROM scenario WHERE `group` = :g ORDER BY id',
                array('g' => $group), DB::FETCH_TYPE_ALL));
        }
        $selects = array();
        foreach ($this->pages() as $page) {
            foreach ($page['tiles'] as $tile) {
                $roles = jeetvbeLayout::roles($tile);
                if ($tile['type'] === 'select' && isset($roles['set'])) {
                    $selects[] = (int) $roles['set'];
                }
            }
        }
        if (count($selects) > 0) {
            $parts[] = json_encode(DB::Prepare('SELECT id, configuration FROM cmd WHERE id IN (' . implode(',', $selects) . ') ORDER BY id', array(), DB::FETCH_TYPE_ALL));
        }
        $fingerprint = md5(implode('|', $parts));
        $cached = cache::byKey(self::revisionKey($this->getId()))->getValue(null);
        if (is_array($cached) && isset($cached['fp'], $cached['rev']) && $cached['fp'] === $fingerprint) {
            return $cached['rev'];
        }
        $revision = jeetvbeLayout::revision($this->pages(), $this->scenesPage(), $this->colorKeys(), $this->header(), array(__CLASS__, 'describeCmd'));
        cache::set(self::revisionKey($this->getId()), array('fp' => $fingerprint, 'rev' => $revision), 3600);
        return $revision;
    }

    public function layout() {
        /* Les tableaux des trains, recalculés et mémorisés : « changes » ne
         * renverra ensuite que ceux qui auront changé. */
        $boards = $this->refreshBoards('layout');
        $layout = jeetvbeLayout::buildLayout($this->pages(), array(__CLASS__, 'describeCmd'), $this->scenesPage(), $this->colorKeys(), $this->header(), $boards);
        /* La barre d'état, hors révision (contrat), avant les pages. */
        $status = $this->refreshStatus('layout');
        if ($status !== null) {
            $pages = $layout['pages'];
            unset($layout['pages']);
            $layout['status'] = $status;
            $layout['pages'] = $pages;
        }
        return $layout;
    }

    /* Le bandeau d'infos enregistré : [[id, cmd, label, icon], …]. */
    public function header() {
        return jeetvbeLayout::normalizeHeader($this->getConfiguration('header', array()));
    }

    /* Les touches de couleur enregistrées (couleur => id de page). */
    public function colorKeys() {
        return jeetvbeLayout::normalizeKeys($this->getConfiguration('keys', array()));
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

    /* ========================================== tableaux des trains (board)
     *
     * Les pages « board » montrent les départs de trajets du plugin SNCB/NMBS
     * (sncbnmbs), lus dans son cache par sa méthode board() : ni ce plugin ni
     * la TV n'interrogent iRail. Le plugin SNCB est facultatif : absent,
     * inactif, ou son équipement supprimé ou désactivé, la section le dit
     * dans ses notes.
     *
     * Comme la barre d'état, l'état servi vit dans le cache, clé
     * jeetvbe::boards::<id> : pour chaque page, le tableau, son empreinte et
     * l'instant (curseur « since ») de son dernier changement, plus l'heure
     * du dernier calcul. L'attente longue recalcule toutes les
     * BOARD_REFRESH_SECONDS et ne renvoie un tableau que s'il a changé.
     */
    private static function boardsKey($_id) {
        return 'jeetvbe::boards::' . (int) $_id;
    }

    public static function boardsCache($_tvId) {
        $state = cache::byKey(self::boardsKey($_tvId))->getValue(null);
        return (is_array($state) && isset($state['pages'], $state['computed'])) ? $state : null;
    }

    /* Le plugin SNCB/NMBS est-il installé et actif ? */
    public static function sncbAvailable() {
        try {
            /* plugin::byId() d'un plugin absent n'est pas une simple lecture :
             * le cœur le « désactive de force » (écriture en base), à chaque
             * appel, donc toutes les 30 s pendant l'attente longue. */
            if (!file_exists(plugin::getPathById('sncbnmbs'))) {
                return false;
            }
            $plugin = plugin::byId('sncbnmbs');
            if (!is_object($plugin) || !$plugin->isActive()) {
                return false;
            }
        } catch (Throwable $e) {
            return false;
        }
        return class_exists('sncbnmbs') && method_exists('sncbnmbs', 'board');
    }

    /* Les équipements du plugin SNCB/NMBS pour l'éditeur de pages, ou null
     * si le plugin n'est pas disponible. */
    public static function sncbEqLogics() {
        if (!self::sncbAvailable()) {
            return null;
        }
        $out = array();
        foreach (eqLogic::byType('sncbnmbs') as $eq) {
            $out[] = array(
                'id'      => (int) $eq->getId(),
                'name'    => $eq->getHumanName(),
                'route'   => method_exists($eq, 'routeLabel') ? $eq->routeLabel() : '',
                'enabled' => $eq->getIsEnable() == 1,
            );
        }
        return $out;
    }

    /* La source d'une section : ce que rend l'équipement SNCB, ou la raison
     * de son absence (voir jeetvbeLayout, tableau des trains). */
    public static function sncbSource($_eqId, $_available) {
        if (!$_available) {
            return array('state' => 'absent');
        }
        try {
            $eq = eqLogic::byId($_eqId);
            if (!is_object($eq) || $eq->getEqType_name() !== 'sncbnmbs') {
                return array('state' => 'missing');
            }
            if ($eq->getIsEnable() != 1) {
                return array('state' => 'disabled', 'route' => method_exists($eq, 'routeLabel') ? $eq->routeLabel() : '');
            }
            return array('state' => 'ok', 'board' => $eq->board(),
                         'journeys' => method_exists($eq, 'getJourneys') ? $eq->getJourneys() : array());
        } catch (Throwable $e) {
            log::add('jeetvbe', 'debug', sprintf('Tableau des trains : équipement SNCB %d illisible — %s', (int) $_eqId, $e->getMessage()));
            return array('state' => 'error');
        }
    }

    /* Les tableaux des pages « board » de cette TV, calculés maintenant. */
    public function boards() {
        $pages = $this->pages();
        $ids = jeetvbeLayout::boardEqLogics($pages);
        $sources = array();
        if (count($ids) > 0) {
            $available = self::sncbAvailable();
            foreach ($ids as $eqId) {
                $sources[$eqId] = self::sncbSource($eqId, $available);
            }
        }
        return jeetvbeLayout::buildBoards($pages, $sources, time());
    }

    /* Recalcule les tableaux et mémorise leur état ; rend les tableaux. Rien
     * n'est écrit pour une TV sans page « board ». */
    public function refreshBoards($_reason) {
        if ($this->getId() == '') {
            return array();
        }
        $boards = $this->boards();
        $previous = self::boardsCache($this->getId());
        if (count($boards) === 0) {
            /* Plus de page « board » : l'état mémorisé part. */
            if ($previous !== null) {
                cache::delete(self::boardsKey($this->getId()));
            }
            return $boards;
        }
        $state = jeetvbeLayout::boardsState($previous, $boards, self::nowCursor(), time());
        cache::set(self::boardsKey($this->getId()), $state);
        $changed = array();
        foreach ($state['pages'] as $pageId => $entry) {
            if (!is_array($previous) || !isset($previous['pages'][$pageId]) || $previous['pages'][$pageId]['sig'] !== $entry['sig']) {
                $changed[] = $pageId;
            }
        }
        if (count($changed) > 0) {
            log::add('jeetvbe', 'debug', sprintf('%s : tableau(x) des trains (%s) — changé(s) : %s', $this->getHumanName(), $_reason, implode(', ', $changed)));
        }
        return $boards;
    }

    /* Le recalcul est-il dû ? Jamais calculé, ou plus vieux que
     * BOARD_REFRESH_SECONDS. L'appelant ne demande que pour une TV qui a
     * au moins une page « board ». */
    public static function boardsDue($_state) {
        return !is_array($_state) || time() - (int) $_state['computed'] >= self::BOARD_REFRESH_SECONDS;
    }

    public function hasBoardPage() {
        foreach ($this->pages() as $page) {
            if (jeetvbeLayout::isBoard($page)) {
                return true;
            }
        }
        return false;
    }

    /* ================================================== barre d'état
     *
     * Réglages (configuration statusBar) et indicateurs automatiques
     * (configuration indicators, format auto_fixed de tvoverlaybe), plus les
     * indicateurs temporaires de « Indicateur (JSON) » (config du plugin,
     * clé indicators::<id TV> : ils survivent à un redémarrage).
     *
     * L'état servi vit dans le cache, clé jeetvbe::status::<id> :
     *   status   la barre complète (ou null : désactivée) ;
     *   sig      son empreinte ;
     *   at       l'instant (curseur « since ») de son dernier changement ;
     *   snoozed  indicateurs retirés à la main (id => empreinte).
     * Un recalcul n'écrit que si l'empreinte change : l'attente longue, qui
     * relit cette clé à chaque tour, ne se réveille que sur un vrai
     * changement. Un verrou par TV met en file les recalculs simultanés
     * (listener, cron, commandes, layout).
     */
    private static function statusKey($_id) {
        return 'jeetvbe::status::' . (int) $_id;
    }

    private static function temporaryKey($_id) {
        return 'indicators::' . (int) $_id;
    }

    private static function videosKey($_id) {
        return 'videos::' . (int) $_id;
    }

    public static function statusState($_tvId) {
        $state = cache::byKey(self::statusKey($_tvId))->getValue(null);
        return (is_array($state) && array_key_exists('status', $state) && isset($state['at'])) ? $state : null;
    }

    private function withStatusLock($_callback) {
        $handle = false;
        try {
            $handle = @fopen(jeedom::getTmpFolder('jeetvbe') . '/status_' . (int) $this->getId() . '.lock', 'c');
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

    public function statusBar() {
        return jeetvbeOverlay::normalizeBar($this->getConfiguration('statusBar', array()));
    }

    public function indicators() {
        return jeetvbeOverlay::normalizeIndicators($this->getConfiguration('indicators', array()));
    }

    public function temporaryIndicators() {
        /* Lecture en base, pas dans le cache du processus : une attente longue
         * dure 25 s, un autre processus a pu ajouter un indicateur. */
        $list = config::byKey(self::temporaryKey($this->getId()), 'jeetvbe', array(), true);
        return is_array($list) ? $list : array();
    }

    /* Valeur d'une commande info, lue dans le cache : rien ici n'exécute une
     * commande. */
    public static function infoValue($_cmdId) {
        $cmd = cmd::byId($_cmdId);
        if (!is_object($cmd) || $cmd->getType() !== 'info') {
            return null;
        }
        try {
            return $cmd->getCache('value', null);
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function decimalSeparator() {
        return (strpos((string) config::byKey('language', 'core', 'fr_FR'), 'fr') === 0) ? ',' : '.';
    }

    /* Recalcule la barre ; rend la barre servie (ou null). $_mutate(&$temporary,
     * &$snoozed, $valueOf) : changement à appliquer sous le verrou (indicateur
     * temporaire ajouté ou retiré, retrait à la main). */
    public function refreshStatus($_reason, $_mutate = null) {
        if ($this->getId() == '') {
            return null;
        }
        return $this->withStatusLock(function () use ($_reason, $_mutate) {
            $now = time();
            $values = array();
            $valueOf = function ($_id) use (&$values) {
                if (!array_key_exists($_id, $values)) {
                    $values[$_id] = jeetvbe::infoValue($_id);
                }
                return $values[$_id];
            };
            $stored = $this->temporaryIndicators();
            $temporary = jeetvbeOverlay::temporaryPurge($stored, $now);
            $state = self::statusState($this->getId());
            $snoozed = (is_array($state) && isset($state['snoozed']) && is_array($state['snoozed'])) ? $state['snoozed'] : array();
            if (is_callable($_mutate)) {
                call_user_func_array($_mutate, array(&$temporary, &$snoozed, $valueOf));
            }
            if ($temporary !== $stored) {
                if (count($temporary) > 0) {
                    config::save(self::temporaryKey($this->getId()), $temporary, 'jeetvbe');
                } else {
                    config::remove(self::temporaryKey($this->getId()), 'jeetvbe');
                }
            }
            list($items, $snoozed) = jeetvbeOverlay::statusItems($this->indicators(), $temporary, $snoozed, $valueOf, $now, self::decimalSeparator());
            $status = jeetvbeOverlay::buildStatus($this->statusBar(), $items);
            $sig = jeetvbeOverlay::statusSignature($status);
            if (is_array($state) && $state['sig'] === $sig && $state['snoozed'] === $snoozed
                && (isset($state['expires']) ? $state['expires'] : null) === jeetvbeOverlay::nextExpiry($temporary)) {
                return $status;
            }
            $changed = !is_array($state) || $state['sig'] !== $sig;
            cache::set(self::statusKey($this->getId()), array(
                'status'  => $status,
                'sig'     => $sig,
                'at'      => $changed ? self::nowCursor() : $state['at'],
                'snoozed' => $snoozed,
                /* Prochaine expiration d'un temporaire : l'attente longue
                 * recalcule à cet instant, sans attendre le cron. */
                'expires' => jeetvbeOverlay::nextExpiry($temporary),
            ));
            if ($changed) {
                log::add('jeetvbe', 'debug', sprintf('%s : barre d\'état (%s) — %s', $this->getHumanName(), $_reason,
                    ($status === null) ? 'désactivée' : count($status['items']) . ' indicateur(s)'));
            }
            return $status;
        });
    }

    /* Le listener : une commande citée par un indicateur a changé. */
    public static function pullStatus($_options) {
        $tv = eqLogic::byId(isset($_options['eqLogic_id']) ? $_options['eqLogic_id'] : 0);
        if (!is_object($tv) || $tv->getEqType_name() !== 'jeetvbe' || $tv->getIsEnable() != 1) {
            return;
        }
        /* Anti-rafale : au plus un recalcul toutes les 2 s par TV, le dernier
         * état gagnant. Un seul processus attend (verrou non bloquant) ; il
         * libère le verrou AVANT de lire les valeurs, si bien qu'un événement
         * arrivé pendant le recalcul relance un recalcul suivant : aucune
         * dernière valeur n'est perdue. */
        $handle = false;
        try {
            $handle = @fopen(jeedom::getTmpFolder('jeetvbe') . '/statusburst_' . (int) $tv->getId() . '.lock', 'c');
        } catch (Throwable $e) {
            $handle = false;
        }
        if ($handle !== false && !flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return;
        }
        $key = 'jeetvbe::statusburst::' . (int) $tv->getId();
        $wait = self::STATUS_BURST_SECONDS - (microtime(true) - (float) cache::byKey($key)->getValue(0));
        if ($wait > 0) {
            usleep((int) ($wait * 1000000));
        }
        cache::set($key, microtime(true), 60);
        if ($handle !== false) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        try {
            $fresh = eqLogic::byId($tv->getId());
            if (is_object($fresh)) {
                $fresh->refreshStatus('événement');
            }
        } catch (Throwable $e) {
            log::add('jeetvbe', 'warning', sprintf('%s : barre d\'état — %s', $tv->getHumanName(), $e->getMessage()));
        }
    }

    const STATUS_BURST_SECONDS = 2;

    /* L'écouteur suit les commandes citées par les indicateurs actifs ; rien à
     * écouter (barre désactivée, aucun indicateur lié à une commande) : pas
     * d'écouteur. */
    public function updateStatusListener() {
        $options = array('eqLogic_id' => (int) $this->getId());
        $ids = array();
        if ($this->getIsEnable() == 1 && $this->statusBar()['enabled'] === 1) {
            $ids = jeetvbeOverlay::cmdIds($this->indicators());
        }
        $listener = listener::byClassAndFunction(__CLASS__, 'pullStatus', $options);
        if (count($ids) === 0) {
            if (is_object($listener)) {
                $listener->remove();
            }
            return;
        }
        if (!is_object($listener)) {
            $listener = new listener();
            $listener->setClass(__CLASS__);
            $listener->setFunction('pullStatus');
            $listener->setOption($options);
        }
        $listener->emptyEvent();
        foreach ($ids as $id) {
            $listener->addEvent($id);
        }
        $listener->save();
    }

    /* « Indicateur (JSON) » : ajoute, remplace ou retire un indicateur
     * temporaire ; visible:false retire aussi un indicateur automatique (comme
     * « Retirer un indicateur »). */
    public function applyTemporaryIndicator($_data) {
        $parsed = jeetvbeOverlay::temporaryFromJson($_data, time());
        if (isset($parsed['error'])) {
            throw new Exception($parsed['error']);
        }
        if ($parsed['remove']) {
            $this->removeIndicator($parsed['id']);
            return;
        }
        $this->refreshStatus('indicateur ' . $parsed['id'], function (&$_temporary, &$_snoozed, $_valueOf) use ($parsed) {
            $_temporary = jeetvbeOverlay::temporaryPut($_temporary, $parsed, time());
            unset($_snoozed[$parsed['id']]);
        });
        log::add('jeetvbe', 'info', sprintf('%s : indicateur « %s » ajouté%s', $this->getHumanName(), $parsed['id'],
            ($parsed['expires'] === null) ? ' (sans expiration)' : ' jusqu\'à ' . date('Y-m-d H:i:s', $parsed['expires'])));
    }

    /* « Retirer un indicateur » : un temporaire part ; un indicateur
     * automatique reste retiré jusqu'à ce que ce qu'il affiche change. */
    public function removeIndicator($_id) {
        $id = jeetvbeOverlay::cleanId($_id);
        if ($id === '') {
            throw new Exception(__('Indiquez l\'identifiant (id) de l\'indicateur dans le message.', __FILE__));
        }
        $indicators = $this->indicators();
        $this->refreshStatus('retrait ' . $id, function (&$_temporary, &$_snoozed, $_valueOf) use ($id, $indicators) {
            unset($_temporary[$id]);
            $_snoozed = jeetvbeOverlay::snooze($_snoozed, $indicators, $id, $_valueOf, jeetvbe::decimalSeparator());
        });
    }

    /* ---------------------------------------- importation depuis tvoverlaybe */

    /*
     * Recopie les indicateurs automatiques (configuration auto_fixed) d'un
     * équipement tvoverlaybe dans la barre d'état d'une TV. Rien n'est modifié
     * côté tvoverlaybe. $_save : enregistre la TV (remplace sa liste) ; sinon
     * rend seulement la liste (la page la met dans l'éditeur).
     *
     *   jeetvbe::importTvOverlayIndicators(<id TV jeetvbe>, <id équipement tvoverlaybe>);
     */
    public static function importTvOverlayIndicators($_tvId, $_sourceId, $_save = true) {
        $source = eqLogic::byId($_sourceId);
        if (!is_object($source) || $source->getEqType_name() !== 'tvoverlaybe') {
            throw new Exception(__('Équipement TvOverlay introuvable', __FILE__) . ' : ' . (int) $_sourceId);
        }
        $indicators = jeetvbeOverlay::normalizeIndicators($source->getConfiguration('auto_fixed', array()));
        if (!$_save) {
            return $indicators;
        }
        $tv = eqLogic::byId($_tvId);
        if (!is_object($tv) || $tv->getEqType_name() !== 'jeetvbe') {
            throw new Exception(__('TV introuvable', __FILE__) . ' : ' . (int) $_tvId);
        }
        $tv->setConfiguration('indicators', $indicators);
        $tv->save();
        log::add('jeetvbe', 'info', sprintf('%s : %d indicateur(s) importé(s) depuis %s', $tv->getHumanName(), count($indicators), $source->getHumanName()));
        return $indicators;
    }

    /* Les équipements tvoverlaybe (si le plugin est là), pour la page. */
    public static function tvOverlayCandidates() {
        $out = array();
        try {
            foreach (eqLogic::byType('tvoverlaybe') as $eqLogic) {
                $auto = $eqLogic->getConfiguration('auto_fixed', array());
                $out[] = array('id' => (int) $eqLogic->getId(), 'name' => $eqLogic->getHumanName(), 'count' => is_array($auto) ? count($auto) : 0);
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /* ---------------------------------------------------------- sources vidéo
     *
     * Gardées dans la config du plugin (clé videos::<id TV>), pas dans la
     * configuration de l'équipement : la page de l'équipement ne reçoit ainsi
     * jamais les adresses, seulement leur forme masquée. */
    /* Rangées chiffrées (utils::encrypt du cœur, « crypt: ») : les adresses,
     * identifiants compris, n'apparaissent en clair ni en base ni dans les
     * sauvegardes. L'ancien format (en clair) est lu, puis réécrit chiffré. */
    public function videoSources() {
        $stored = config::byKey(self::videosKey($this->getId()), 'jeetvbe', '', true);
        list($sources, $migrate) = jeetvbeOverlay::openSources($stored, array('utils', 'decrypt'));
        if ($migrate) {
            $this->storeVideoSources($sources);
            log::add('jeetvbe', 'info', sprintf('%s : sources vidéo chiffrées au repos', $this->getHumanName()));
        }
        return $sources;
    }

    private function storeVideoSources($_sources) {
        $sealed = jeetvbeOverlay::sealSources($_sources, array('utils', 'encrypt'));
        if ($sealed === '') {
            config::remove(self::videosKey($this->getId()), 'jeetvbe');
        } else {
            config::save(self::videosKey($this->getId()), $sealed, 'jeetvbe');
        }
    }

    public function saveVideoSource($_name, $_url) {
        $name = jeetvbeOverlay::cleanSourceName($_name);
        if ($name === '') {
            throw new Exception(__('Nom de source invalide : lettres, chiffres, « _ », « - » ou « . », 32 caractères au plus.', __FILE__));
        }
        $url = is_string($_url) ? trim($_url) : '';
        if (!jeetvbeOverlay::validVideoUrl($url)) {
            throw new Exception(__('Adresse invalide : rtsp://, rtsps://, http:// ou https:// attendu.', __FILE__));
        }
        $sources = array();
        foreach ($this->videoSources() as $source) {
            if (mb_strtolower($source['name'], 'UTF-8') !== mb_strtolower($name, 'UTF-8')) {
                $sources[] = $source;
            }
        }
        $sources[] = array('name' => $name, 'url' => $url);
        if (count($sources) > jeetvbeOverlay::MAX_SOURCES) {
            throw new Exception(__('Trop de sources vidéo.', __FILE__));
        }
        $this->storeVideoSources($sources);
        log::add('jeetvbe', 'info', sprintf('%s : source vidéo « %s » enregistrée (%s)', $this->getHumanName(), $name, jeetvbeOverlay::maskUrl($url)));
    }

    public function removeVideoSource($_name) {
        $sources = array();
        foreach ($this->videoSources() as $source) {
            if (mb_strtolower($source['name'], 'UTF-8') !== mb_strtolower(trim((string) $_name), 'UTF-8')) {
                $sources[] = $source;
            }
        }
        $this->storeVideoSources($sources);
    }

    /* ------------------------------------------- image d'un JSON TvOverlay */

    const DOWNLOAD_TIMEOUT = 5;

    /*
     * L'image d'un « Notifier (JSON) » : chemin, adresse http(s) du réseau
     * local (téléchargée) ou base64 (décodé), copiée comme les autres images
     * jointes. Rend son identifiant ou null ; jamais l'adresse au journal sans
     * masque (elle peut porter des identifiants).
     */
    public static function attachImageSource($_tvId, $_image, $_lifetime) {
        if (!is_array($_image)) {
            return null;
        }
        if ($_image['kind'] === 'path') {
            return self::attachImage($_tvId, $_image['value'], $_lifetime);
        }
        /* Diffusion : téléchargée ou décodée une seule fois, puis copiée pour
         * chaque TV (même instantané partout, pas N téléchargements). */
        $shareKey = $_image['kind'] . ':' . md5((string) $_image['value']);
        if (is_array(self::$_imageShare) && array_key_exists($shareKey, self::$_imageShare)) {
            return (self::$_imageShare[$shareKey] === null) ? null : self::attachImage($_tvId, self::$_imageShare[$shareKey], $_lifetime);
        }
        $data = null;
        if ($_image['kind'] === 'base64') {
            $data = jeetvbeOverlay::decodeBase64Image($_image['value'], jeetvbeLayout::IMAGE_MAX_BYTES);
            if ($data === null) {
                log::add('jeetvbe', 'warning', sprintf('TV %s : image base64 illisible ou trop grande, ordre envoyé sans elle', $_tvId));
            }
        } elseif ($_image['kind'] === 'url') {
            $data = self::downloadLocalImage($_tvId, $_image['value']);
        }
        $tmp = jeedom::getTmpFolder('jeetvbe') . '/dl_' . bin2hex(random_bytes(8));
        if ($data === null || strlen($data) > jeetvbeLayout::IMAGE_MAX_BYTES || @file_put_contents($tmp, $data) === false) {
            if (is_array(self::$_imageShare)) {
                self::$_imageShare[$shareKey] = null;
            }
            return null;
        }
        if (is_array(self::$_imageShare)) {
            self::$_imageShare[$shareKey] = $tmp;
            return self::attachImage($_tvId, $tmp, $_lifetime);
        }
        try {
            return self::attachImage($_tvId, $tmp, $_lifetime);
        } finally {
            @unlink($tmp);
        }
    }

    /* Images partagées d'une diffusion en cours (null hors diffusion). */
    private static $_imageShare = null;

    private static function beginImageShare() {
        self::$_imageShare = array();
    }

    private static function endImageShare() {
        foreach (is_array(self::$_imageShare) ? self::$_imageShare : array() as $path) {
            if (is_string($path)) {
                @unlink($path);
            }
        }
        self::$_imageShare = null;
    }

    /* Une adresse http(s) du réseau local seulement (caméra, NVR, Jeedom) :
     * pas de redirection, 5 s, 5 Mo au plus. */
    private static function downloadLocalImage($_tvId, $_url) {
        $masked = jeetvbeOverlay::maskUrl($_url);
        $host = parse_url($_url, PHP_URL_HOST);
        $host = is_string($host) ? trim($host, '[]') : '';
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? array($host) : (($host !== '') ? @gethostbynamel($host) : false);
        $local = is_array($ips) && count($ips) > 0;
        foreach (is_array($ips) ? $ips : array() as $ip) {
            $local = $local && jeetvbeOverlay::isLocalIp($ip);
        }
        if (!$local) {
            log::add('jeetvbe', 'warning', sprintf('TV %s : image %s refusée (hors du réseau local), ordre envoyé sans elle', $_tvId, $masked));
            return null;
        }
        $max = jeetvbeLayout::IMAGE_MAX_BYTES;
        $ch = curl_init($_url);
        /* L'adresse contrôlée est celle utilisée : pas de seconde résolution
         * DNS qui pourrait viser autre chose (rebinding). */
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            $port = parse_url($_url, PHP_URL_PORT);
            $port = $port ? (int) $port : ((strtolower((string) parse_url($_url, PHP_URL_SCHEME)) === 'https') ? 443 : 80);
            curl_setopt($ch, CURLOPT_RESOLVE, array($host . ':' . $port . ':' . $ips[0]));
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        }
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::DOWNLOAD_TIMEOUT,
            CURLOPT_TIMEOUT => self::DOWNLOAD_TIMEOUT,
            CURLOPT_PROXY => '',
            CURLOPT_HTTPAUTH => CURLAUTH_ANY,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => function ($_ch, $_total, $_done) use ($max) {
                return ($_total > $max || $_done > $max) ? 1 : 0;
            },
        ));
        $data = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($data) || $code !== 200 || $data === '') {
            log::add('jeetvbe', 'warning', sprintf('TV %s : image %s non téléchargée (HTTP %d), ordre envoyé sans elle', $_tvId, $masked, $code));
            return null;
        }
        return $data;
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
        $pressCmd = ($tile['type'] === 'button' && isset($roles['press'])) ? self::describeCmd($roles['press']) : null;
        $plan = jeetvbeLayout::resolveAction($tile, $_action, $_value, $state, $setCmd, $pressCmd);
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
     * libère la requête, avec la nouvelle révision. Les tableaux des trains
     * sont recalculés toutes les 30 s ; un tableau changé libère la requête
     * (champ « boards »).
     */
    public function waitChanges($_since) {
        $revision = $this->revision();
        $poll = self::claimPoll($this->getId());
        if ($_since === null) {
            /* Les tableaux des trains sont calculés AVANT de prendre le
             * curseur : leur instant de changement le précède, l'appel
             * suivant ne les renvoie pas, et aucun événement de tuile n'est
             * sauté. */
            $boards = $this->hasBoardPage() ? $this->refreshBoards('reprise') : null;
            $out = array('since' => self::nowCursor(), 'revision' => $revision, 'changes' => array(),
                         'commands' => self::takeOrdersFor($this->getId(), $poll));
            /* Premier appel : la barre complète, si elle existe — un
             * changement survenu entre layout et ce premier appel ne se perd
             * pas. */
            $status = $this->refreshStatus('reprise');
            if ($status !== null) {
                $out['status'] = $status;
            }
            /* De même, les tableaux des trains complets. */
            if ($boards !== null) {
                $out['boards'] = (object) $boards;
            }
            return $out;
        }
        $now = self::nowCursor();
        if ($_since > $now) {
            $_since = $now;
        }
        $stateMap = jeetvbeLayout::stateMap($this->pages(), $this->header());
        $deadline = microtime(true) + self::LONGPOLL_SECONDS;
        $cursor = $_since;
        $round = 0;
        $tvId = $this->getId();
        $since = $_since;
        /* La réponse, avec la barre si elle a changé depuis « since » (état
         * complet) ; le curseur rendu couvre ce changement, pour qu'il ne soit
         * pas renvoyé à l'appel suivant. */
        /* Sortie sans changement de tuile (révision changée, délai écoulé) :
         * la barre est lue d'abord, puis les événements une dernière fois —
         * sinon le curseur poussé jusqu'au changement de la barre sauterait
         * un événement de tuile écrit entre-temps. */
        /* Les tableaux des trains, lus avec la barre : même règle de curseur. */
        $withBoards = $this->hasBoardPage();
        $finalRead = function ($_cursor) use ($tvId, $stateMap, $withBoards) {
            $state = self::statusState($tvId);
            $boardsState = $withBoards ? self::boardsCache($tvId) : null;
            list($events, $last) = self::eventsSince($_cursor);
            return array($state, jeetvbeLayout::mergeChanges($events, $stateMap), $last, $boardsState);
        };
        $respond = function ($_revision, $_changes, $_commands, $_cursor, $_state, $_boardsState) use ($since) {
            $out = array('since' => $_cursor, 'revision' => $_revision, 'changes' => $_changes, 'commands' => $_commands);
            if (is_array($_state) && $_state['at'] > $since) {
                $out['status'] = $_state['status'];
                $out['since'] = max($out['since'], round((float) $_state['at'], 6));
            }
            /* « boards » seulement pour les tableaux changés (contrat). */
            list($boards, $boardsAt) = jeetvbeLayout::boardsSince($_boardsState, $since);
            if (count($boards) > 0) {
                $out['boards'] = (object) $boards;
                $out['since'] = max($out['since'], round((float) $boardsAt, 6));
            }
            return $out;
        };
        /* Tableaux des trains : recalculés à l'entrée s'ils sont périmés,
         * puis toutes les BOARD_REFRESH_SECONDS pendant l'attente. */
        if ($withBoards && self::boardsDue(self::boardsCache($tvId))) {
            $this->refreshBoards('attente');
        }
        while (true) {
            /* La barre d'abord (une lecture de cache) : un événement écrit
             * entre-temps sera lu par eventsSince() de ce tour. */
            $statusState = self::statusState($tvId);
            if (is_array($statusState) && !empty($statusState['expires']) && time() >= $statusState['expires']) {
                /* L'équipement relu : sa configuration a pu changer pendant
                 * l'attente. */
                $fresh = eqLogic::byId($tvId);
                if (is_object($fresh)) {
                    $fresh->refreshStatus('expiration');
                }
                $statusState = self::statusState($tvId);
            }
            /* Une TV sans tableau ne lit pas ce cache toutes les 0,5 s. */
            $boardsState = $withBoards ? self::boardsCache($tvId) : null;
            if ($withBoards && self::boardsDue($boardsState)) {
                /* L'équipement relu, comme pour la barre. */
                $fresh = eqLogic::byId($tvId);
                if (is_object($fresh)) {
                    $fresh->refreshBoards('minute');
                }
                $boardsState = self::boardsCache($tvId);
            }
            list($events, $last) = self::eventsSince($cursor);
            $cursor = $last;
            $changes = jeetvbeLayout::mergeChanges($events, $stateMap);
            /* Un ordre en file réveille l'attente au tour suivant (0,5 s). */
            $commands = self::takeOrdersFor($this->getId(), $poll);
            $statusChanged = is_array($statusState) && $statusState['at'] > $since;
            list($changedBoards) = jeetvbeLayout::boardsSince($boardsState, $since);
            if (count($changes) > 0 || count($commands) > 0 || $statusChanged || count($changedBoards) > 0) {
                return $respond($revision, $changes, $commands, $cursor, $statusState, $boardsState);
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
                    list($state, $changes, $cursor, $boardsState) = $finalRead($cursor);
                    return $respond($freshRevision, $changes, self::takeOrdersFor($this->getId(), $poll), $cursor, $state, $boardsState);
                }
            }
            usleep(self::POLL_INTERVAL_US);
        }
        list($state, $changes, $cursor, $boardsState) = $finalRead($cursor);
        return $respond($revision, $changes, self::takeOrdersFor($this->getId(), $poll), $cursor, $state, $boardsState);
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
        /* « Toutes les TV » : la même commande, rejouée sur chaque TV choisie. */
        if ($tv->isBroadcast()) {
            jeetvbe::broadcast($this->getLogicalId(), $options, $this->getId());
            return;
        }
        self::run($tv, $this->getLogicalId(), $options, $this->getId(), false);
    }

    /*
     * L'effet d'une commande action sur une TV : ordre mis en file, indicateur
     * temporaire… $_cmdId : la commande exécutée (question en attente).
     * $_broadcast : appel de « Toutes les TV » — une vidéo inconnue de CETTE TV
     * n'empêche pas la notification (elle part sans vidéo, avec son image).
     */
    public static function run($tv, $logicalId, $options, $_cmdId, $_broadcast, $_askToken = null) {
        $order = null;
        /* Texte et image jointe (Message et Question) : [image=…], files, ou
         * « title=… | files=… ». */
        /* [video=<nom ou adresse>] d'abord (Message et Question), retiré du
         * texte ; le nom d'une source de la TV devient son adresse. */
        list($videoTitle, $videoMessage, $videoRef) = jeetvbeOverlay::extractVideo(isset($options['title']) ? $options['title'] : '',
            isset($options['message']) ? $options['message'] : '');
        $text = jeetvbeLayout::extractImage($videoTitle, $videoMessage, isset($options['files']) ? $options['files'] : null);
        $video = null;
        if ($videoRef !== null && in_array($logicalId, array('notify', 'ask'), true)) {
            $video = jeetvbeOverlay::resolveVideo($videoRef, $tv->videoSources());
            if ($video === null) {
                log::add('jeetvbe', 'warning', sprintf('%s : vidéo « %s » inconnue (ni source de la TV, ni adresse), ordre envoyé sans elle',
                    $tv->getHumanName(), jeetvbeOverlay::videoRefForLog($videoRef)));
            }
        }

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
                  && ($ask = jeetvbeLayout::askOrder(array_merge($options, array('title' => $text['title'], 'message' => $text['message'])),
                                                     $token = ($_askToken !== null ? $_askToken : bin2hex(random_bytes(16))))) !== null) {
            /* Bloc « Demander » : le cœur a posé ask::variable, ask::endtime et
             * ask::answer sur cette commande, et attend la réponse. Question
             * de groupe ($_askToken) : retenue par Toutes les TV, pas ici. */
            if ($_askToken === null) {
                jeetvbe::rememberAsk($tv->getId(), jeetvbeLayout::askPending($token, $_cmdId, $ask['answers'], $ask['timeout'], time()));
            }
            $image = jeetvbe::attachImage($tv->getId(), $text['path'], $ask['timeout']);
            if ($image !== null) {
                $ask['image'] = $image;
            }
            if ($video !== null) {
                $ask['video'] = $video;
            }
            $order = jeetvbe::enqueue($tv->getId(), $ask, jeetvbeLayout::askTtl($ask['timeout']));
            log::add('jeetvbe', 'info', sprintf('%s : question %s mise en file (ordre %s) — %s', $tv->getHumanName(),
                substr($token, 0, 8), $order['id'], json_encode($ask['answers'], JSON_UNESCAPED_UNICODE)));
            return $order;
        } elseif ($logicalId === 'notify' || $logicalId === 'ask') {
            /* « Question » sans réponses (hors bloc Demander) : comme « Message ». */
            if ($text['message'] === '' && $text['path'] === null) {
                throw new Exception(__('Message vide', __FILE__));
            }
            $order = array('type' => 'notify', 'title' => $text['title'], 'message' => $text['message']);
            /* [durée=<s>] : seulement pour « Message » ; « Question » le retire
             * du texte sans en tenir compte. */
            if ($logicalId === 'notify' && $text['duration'] !== null) {
                $order['duration'] = $text['duration'];
            }
            $image = jeetvbe::attachImage($tv->getId(), $text['path'], jeetvbeLayout::QUEUE_TTL);
            if ($image !== null) {
                $order['image'] = $image;
            }
            if ($video !== null) {
                $order['video'] = $video;
            }
        } elseif ($logicalId === 'exit') {
            $order = array('type' => 'exit');
        } elseif ($logicalId === 'notify_json') {
            /* Format TvOverlay → notify (voir jeetvbeOverlay::notifyFromJson). */
            $data = self::jsonOption($options);
            $converted = jeetvbeOverlay::notifyFromJson($data, $tv->videoSources(),
                jeetvbeLayout::NOTIFY_MIN_DURATION, jeetvbeLayout::NOTIFY_MAX_DURATION);
            if ($_broadcast && isset($converted['error']) && isset($data['video'])) {
                unset($data['video']);
                $converted = jeetvbeOverlay::notifyFromJson($data, $tv->videoSources(),
                    jeetvbeLayout::NOTIFY_MIN_DURATION, jeetvbeLayout::NOTIFY_MAX_DURATION);
            }
            if (isset($converted['error'])) {
                throw new Exception($converted['error']);
            }
            $order = $converted['order'];
            $image = jeetvbe::attachImageSource($tv->getId(), $converted['image'], jeetvbeLayout::QUEUE_TTL);
            if ($image !== null) {
                $order['image'] = $image;
            }
        } elseif ($logicalId === 'fixed_json') {
            $tv->applyTemporaryIndicator(self::jsonOption($options));
            return;
        } elseif ($logicalId === 'dismiss') {
            $order = array('type' => 'dismiss', 'target' => self::idOption($options, __('Indiquez l\'identifiant (id) de la notification dans le message.', __FILE__)));
        } elseif ($logicalId === 'fixed_remove') {
            $tv->removeIndicator(self::idOption($options, __('Indiquez l\'identifiant (id) de l\'indicateur dans le message.', __FILE__)));
            return;
        }
        if ($order === null) {
            return;
        }
        $order = jeetvbe::enqueue($tv->getId(), $order);
        /* Adresses masquées : une vidéo RTSP porte ses identifiants. */
        log::add('jeetvbe', 'info', sprintf('%s : ordre %s mis en file — %s', $tv->getHumanName(), $order['id'],
            json_encode(jeetvbeOverlay::orderForLog($order), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }

    /* Le message d'une commande « … (JSON) » en tableau (objet reçu tel quel,
     * ou texte JSON) ; les #id# d'un objet sont remplacés par leur valeur. */
    private static function jsonOption($_options) {
        $replace = (method_exists('cmd', 'cmdToValue')) ? function ($_text) {
            return cmd::cmdToValue($_text);
        } : null;
        $data = jeetvbeOverlay::jsonMessage(isset($_options['message']) ? $_options['message'] : '', $replace);
        if ($data === null) {
            throw new Exception(__('Le message doit être un objet JSON, par exemple', __FILE__) . ' {"title":"Sonnette","smallIcon":"mdi:bell"}');
        }
        return $data;
    }

    /* Un identifiant pris dans le message, à défaut le titre. */
    private static function idOption($_options, $_error) {
        foreach (array('message', 'title') as $key) {
            $id = jeetvbeOverlay::cleanId(isset($_options[$key]) ? $_options[$key] : '');
            if ($id !== '') {
                return $id;
            }
        }
        throw new Exception($_error);
    }
}
