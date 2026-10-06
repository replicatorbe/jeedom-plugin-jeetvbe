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
 * Le point d'entrée de l'application Android TV. Il met en oeuvre, à la lettre,
 * le contrat docs/api.md (schéma 1) :
 *
 *   GET  ?action=ping                 → vérifie la clé
 *   GET  ?action=layout               → pages, tuiles et valeurs actuelles
 *   POST ?action=exec                 → {"tile", "action", "value"?}
 *   GET  ?action=changes&since=<n>    → attente longue, 25 s au plus, et
 *                                       les ordres de Jeedom (« commands »)
 *   POST ?action=state                → {"visible", "screenOn", "page"}
 *
 * Authentification : en-tête X-JEETVBE-KEY (repli : paramètre key=). La clé
 * désigne la TV ; l'équipement doit être activé.
 *
 * Erreurs : 401 clé invalide, 400 requête mal formée, 404 tuile inconnue,
 * 422 action non permise, 500 erreur Jeedom. Toujours {"error": "…"} en JSON,
 * message en français affichable tel quel.
 *
 * Aucun .htaccess ne doit fermer ce dossier, et rien ici ne dépend d'une
 * session : l'appelant est une TV, pas un utilisateur connecté.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';
/* L'autochargeur du coeur ne résout pas la classe hors d'une page du plugin. */
require_once __DIR__ . '/../class/jeetvbe.class.php';

function jeetvbeApiSend($_code, $_payload) {
    $body = json_encode($_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
    if ($body === false) {
        $_code = 500;
        $body = '{"error":"Réponse impossible à encoder"}';
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($_code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Content-Length: ' . strlen($body));
    echo $body;
    die();
}

function jeetvbeApiError($_code, $_message, $_log = '', $_level = 'debug') {
    if ($_log !== '') {
        log::add('jeetvbe', $_level, sprintf('API : HTTP %s depuis %s — %s', $_code,
            isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?',
            preg_replace('/[\x00-\x1F\x7F]+/', ' ', mb_substr($_log, 0, 300, 'UTF-8'))));
    }
    jeetvbeApiSend($_code, array('error' => $_message));
}

/* Un en-tête personnalisé arrive préfixé de HTTP_ ; getallheaders() couvre les
 * configurations où cette réécriture n'a pas lieu. */
function jeetvbeApiKey() {
    if (isset($_SERVER['HTTP_X_JEETVBE_KEY'])) {
        return $_SERVER['HTTP_X_JEETVBE_KEY'];
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'X-JEETVBE-KEY') === 0) {
                return $value;
            }
        }
    }
    return isset($_GET['key']) ? $_GET['key'] : (isset($_POST['key']) ? $_POST['key'] : '');
}

try {
    $key = jeetvbeApiKey();
    $tv = is_string($key) ? jeetvbe::byToken(trim($key)) : null;
    if (!is_object($tv)) {
        jeetvbeApiError(401, 'Clé invalide', ($key === '' || $key === null)
            ? 'aucune clé fournie'
            : (is_string($key) ? sprintf('clé commençant par %.6s…', $key) : 'clé mal formée'));
    }

    /* « En ligne » : toute requête authentifiée compte, quelle que soit l'action. */
    $tv->markSeen();

    $action = isset($_GET['action']) ? $_GET['action'] : '';
    if (!is_string($action) || !in_array($action, array('ping', 'layout', 'exec', 'changes', 'state'), true)) {
        jeetvbeApiError(400, ($action === '') ? 'Action absente' : 'Action inconnue');
    }

    if ($action === 'ping') {
        jeetvbeApiSend(200, array(
            'ok'     => true,
            'schema' => jeetvbeLayout::SCHEMA,
            'tv'     => array('id' => (int) $tv->getId(), 'name' => $tv->getName()),
            'jeedom' => (string) jeedom::version(),
            'plugin' => jeetvbe::pluginVersion(),
        ));
    }

    if ($action === 'layout') {
        jeetvbeApiSend(200, $tv->layout());
    }

    if ($action === 'exec') {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            jeetvbeApiError(400, 'exec attend une requête POST');
        }
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            jeetvbeApiError(400, 'JSON invalide');
        }
        if (!isset($body['tile']) || !is_string($body['tile']) || $body['tile'] === '') {
            jeetvbeApiError(400, 'Paramètre « tile » manquant');
        }
        if (!isset($body['action']) || !is_string($body['action']) || $body['action'] === '') {
            jeetvbeApiError(400, 'Paramètre « action » manquant');
        }
        try {
            $result = $tv->execTile($body['tile'], $body['action'], isset($body['value']) ? $body['value'] : null);
        } catch (Throwable $e) {
            log::add('jeetvbe', 'error', sprintf('%s : échec de « %s » sur la tuile %s — %s',
                $tv->getHumanName(), $body['action'], $body['tile'], $e->getMessage()));
            jeetvbeApiSend(500, array('error' => 'Erreur Jeedom : ' . $e->getMessage()));
        }
        jeetvbeApiSend($result['code'], $result['body']);
    }

    if ($action === 'state') {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            jeetvbeApiError(400, 'state attend une requête POST');
        }
        $raw = trim((string) file_get_contents('php://input'));
        $body = ($raw === '') ? array() : json_decode($raw, true);
        if (!is_array($body) || ($raw !== '' && substr($raw, 0, 1) !== '{')) {
            jeetvbeApiError(400, 'JSON invalide');
        }
        $error = $tv->applyState($body);
        if ($error !== null) {
            jeetvbeApiError(400, $error);
        }
        jeetvbeApiSend(200, array('ok' => true));
    }

    /* --- changes ---------------------------------------------------------- */
    $since = jeetvbeLayout::parseSince(isset($_GET['since']) ? $_GET['since'] : null);
    if ($since === false) {
        jeetvbeApiError(400, 'Paramètre « since » invalide');
    }
    /* L'attente ne doit bloquer personne : pas de session retenue (un client
     * qui en porterait une bloquerait ses autres requêtes), et un délai
     * d'exécution qui couvre les 25 s. */
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    @set_time_limit(jeetvbe::LONGPOLL_SECONDS + 15);
    ignore_user_abort(false);
    jeetvbeApiSend(200, $tv->waitChanges($since));

} catch (Throwable $e) {
    /* Throwable : en PHP 8, une Error n'hérite pas d'Exception. */
    log::add('jeetvbe', 'error', 'API : erreur interne — ' . $e->getMessage());
    jeetvbeApiSend(500, array('error' => 'Erreur interne du plugin'));
}
