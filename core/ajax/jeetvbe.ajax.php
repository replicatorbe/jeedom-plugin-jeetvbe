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
 * Le contrôleur de la page de configuration. L'appelant est un administrateur
 * connecté ; le point d'entrée des TV, lui, est core/php/api.php.
 */

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

    /* eqLogic::byId() rend n'importe quel équipement : on vérifie son type. */
    $getTv = function ($_id) {
        $eqLogic = eqLogic::byId($_id);
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'jeetvbe') {
            throw new Exception(__('TV introuvable : enregistrez d\'abord l\'équipement', __FILE__));
        }
        return $eqLogic;
    };

    /* Nouvelle clé, enregistrée aussitôt : l'ancienne cesse de fonctionner. */
    if (init('action') == 'regenerateToken') {
        $tv = $getTv(init('id'));
        $token = $tv->regenerateToken();
        log::add('jeetvbe', 'info', sprintf(__('%s : clé régénérée', __FILE__), $tv->getHumanName()));
        ajax::success(array('token' => $token));
    }

    /* Pages proposées depuis les types génériques des objets choisis. */
    if (init('action') == 'generate') {
        $ids = json_decode(init('object_ids', '[]'), true);
        if (!is_array($ids) || count($ids) == 0) {
            throw new Exception(__('Choisissez au moins un objet', __FILE__));
        }
        ajax::success(jeetvbe::generateForObjects($ids, init('mode', 'type')));
    }

    /* Noms lisibles des commandes et scénarios d'une configuration. */
    if (init('action') == 'describe') {
        $cmdIds = json_decode(init('cmd_ids', '[]'), true);
        $scenarioIds = json_decode(init('scenario_ids', '[]'), true);
        $out = array('cmds' => array(), 'scenarios' => array());
        foreach (is_array($cmdIds) ? $cmdIds : array() as $id) {
            $cmd = cmd::byId((int) $id);
            $out['cmds'][(int) $id] = is_object($cmd)
                ? array('human' => $cmd->getHumanName(), 'type' => $cmd->getType(), 'subType' => $cmd->getSubType(), 'generic' => $cmd->getGeneric_type())
                : null;
        }
        foreach (is_array($scenarioIds) ? $scenarioIds : array() as $id) {
            $scenario = scenario::byId((int) $id);
            $out['scenarios'][(int) $id] = is_object($scenario) ? array('human' => $scenario->getHumanName()) : null;
        }
        ajax::success($out);
    }

    /* Version de l'application, dernier appel, en ligne. */
    if (init('action') == 'status') {
        $tv = $getTv(init('id'));
        ajax::success($tv->status());
    }

    /* Le layout tel que la TV le recevra. */
    if (init('action') == 'preview') {
        $tv = $getTv(init('id'));
        ajax::success($tv->layout());
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));

} catch (Throwable $e) {
    ajax::error(displayException($e), $e->getCode());
}
