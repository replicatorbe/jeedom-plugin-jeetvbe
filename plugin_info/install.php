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

require_once __DIR__ . '/../../../core/php/core.inc.php';

/*
 * Rien à créer à l'installation : une TV s'ajoute à la main, et sa clé est
 * générée au premier enregistrement. La mise à jour réenregistre chaque TV
 * pour normaliser ses pages (ids, types, bornes) selon la version installée.
 */
function jeetvbe_install() {
    log::add('jeetvbe', 'info', 'Installation du plugin Jeedom TV');
    config::save('installedAt', date('Y-m-d H:i:s'), 'jeetvbe');
}

function jeetvbe_update() {
    require_once __DIR__ . '/../core/class/jeetvbe.class.php';
    foreach (eqLogic::byType('jeetvbe') as $eqLogic) {
        $eqLogic->save();
    }
    config::save('updatedAt', date('Y-m-d H:i:s'), 'jeetvbe');
}

function jeetvbe_remove() {
}
