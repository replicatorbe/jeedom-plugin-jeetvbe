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
 * La logique pure du plugin, sans dépendance au coeur de Jeedom : elle se
 * teste hors ligne (tests/run.php). Squelette : le contenu arrive avec le MVP.
 */
class jeetvbeLayout {
    const SCHEMA = 1;
    const TYPES = array('switch', 'shutter', 'slider', 'info', 'scene');
    const ICONS = array('light', 'plug', 'shutter', 'thermostat', 'temperature', 'scene', 'fan', 'lock', 'alarm', 'generic');
    const ROLES = array('state', 'on', 'off', 'toggle', 'up', 'down', 'stop', 'set');
}
