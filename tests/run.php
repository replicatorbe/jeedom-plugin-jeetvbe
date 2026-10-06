<?php
/*
 * Jeu d'essai hors ligne du plugin jeetvbe : la logique pure
 * (core/class/jeetvbeLayout.class.php), sans Jeedom.
 *
 *   php tests/run.php
 *
 * Code retour 0 si tout passe, 1 sinon.
 */

require_once __DIR__ . '/../core/class/jeetvbeLayout.class.php';

$total = 0;
$echecs = 0;

function verifie($_libelle, $_obtenu, $_attendu) {
    global $total, $echecs;
    $total++;
    if ($_obtenu === $_attendu) {
        return;
    }
    $echecs++;
    echo "ÉCHEC : $_libelle\n  attendu : " . json_encode($_attendu, JSON_UNESCAPED_UNICODE)
        . "\n  obtenu  : " . json_encode($_obtenu, JSON_UNESCAPED_UNICODE) . "\n";
}

/* --- Des commandes factices, comme describeCmd() les rendrait ------------- */
$commandes = array(
    10 => array('type' => 'info', 'value' => 1, 'unit' => '', 'minValue' => '', 'maxValue' => ''),
    11 => array('type' => 'action', 'value' => null, 'unit' => '', 'minValue' => '', 'maxValue' => ''),
    12 => array('type' => 'action', 'value' => null, 'unit' => '', 'minValue' => '', 'maxValue' => ''),
    20 => array('type' => 'info', 'value' => 100, 'unit' => '%', 'minValue' => '', 'maxValue' => ''),
    21 => array('type' => 'action', 'value' => null, 'unit' => '', 'minValue' => '0', 'maxValue' => '100'),
    30 => array('type' => 'info', 'value' => '20.5', 'unit' => '°C', 'minValue' => '', 'maxValue' => ''),
    31 => array('type' => 'action', 'value' => null, 'unit' => '°C', 'minValue' => '5', 'maxValue' => '30'),
    40 => array('type' => 'info', 'value' => '24', 'unit' => '°C', 'minValue' => '', 'maxValue' => ''),
);
$resoudre = function ($_id) use ($commandes) {
    return isset($commandes[$_id]) ? $commandes[$_id] : null;
};

$pages = array(
    array('id' => 'p1', 'name' => 'Salon', 'tiles' => array(
        array('id' => 't1', 'type' => 'switch', 'name' => 'Plafond salon', 'icon' => 'light', 'confirm' => false,
              'cmds' => array('state' => 10, 'on' => 11, 'off' => '#12#')),
        array('id' => 't2', 'type' => 'shutter', 'name' => 'Volets SUD séjour', 'icon' => 'shutter',
              'cmds' => array('state' => 20, 'set' => 21, 'up' => 11, 'down' => 12)),
        array('id' => 't3', 'type' => 'shutter', 'name' => 'volet 4', 'icon' => 'shutter',
              'cmds' => array('up' => 11, 'down' => 12, 'stop' => 11)),
        array('id' => 't4', 'type' => 'slider', 'name' => 'Consigne salon', 'icon' => 'thermostat',
              'cmds' => array('state' => 30, 'set' => 31), 'min' => 15, 'max' => '25', 'step' => '0,5'),
        array('id' => 't5', 'type' => 'info', 'name' => 'Température salon', 'icon' => 'temperature',
              'cmds' => array('state' => 40)),
        array('id' => 't6', 'type' => 'scene', 'name' => 'Bonne nuit', 'icon' => 'scene', 'confirm' => true,
              'scenario_id' => 7, 'cmds' => array('state' => 40)),
    )),
);

/* --- Normalisation --------------------------------------------------------- */
$n = jeetvbeLayout::normalizePages($pages);
verifie('ids conservés', array($n[0]['id'], $n[0]['tiles'][0]['id'], $n[0]['tiles'][5]['id']), array('p1', 't1', 't6'));
verifie('rôle « #12# » lu comme 12', jeetvbeLayout::roles($n[0]['tiles'][0])['off'], 12);
verifie('pas « 0,5 » lu comme 0.5', $n[0]['tiles'][3]['step'], 0.5);
verifie('max « 25 » lu comme entier', $n[0]['tiles'][3]['max'], 25);

$brut = array(
    array('name' => '', 'tiles' => array(
        array('type' => 'bidule', 'icon' => 'licorne', 'name' => '  ', 'cmds' => array('state' => 'x', 'on' => 5, 'pirate' => 9)),
        array('id' => 't3', 'type' => 'switch'),
        array('id' => 't3', 'type' => 'switch'),
        array('id' => 'p1', 'type' => 'slider', 'min' => 30, 'max' => 10, 'step' => -1),
    )),
    array('id' => 'p1', 'name' => 'Deux', 'tiles' => array()),
    array('id' => 'p1', 'name' => 'Doublon'),
    'pas une page',
);
$n = jeetvbeLayout::normalizePages($brut);
verifie('3 pages gardées', count($n), 3);
verifie('nom de page par défaut', $n[0]['name'], 'Page 1');
verifie('type inconnu → info', $n[0]['tiles'][0]['type'], 'info');
verifie('icône inconnue → generic', $n[0]['tiles'][0]['icon'], 'generic');
verifie('nom de tuile par défaut', $n[0]['tiles'][0]['name'], 'Tuile');
verifie('rôles inconnus ou invalides retirés', jeetvbeLayout::roles($n[0]['tiles'][0]), array('on' => 5));
$ids = array();
foreach ($n as $page) {
    foreach ($page['tiles'] as $tile) {
        $ids[] = $tile['id'];
    }
}
verifie('ids de tuile uniques pour la TV', count(array_unique($ids)), count($ids));
verifie('id libre suivant le plus grand', $n[0]['tiles'][0]['id'], 't4');
verifie('min > max inversés', array($n[0]['tiles'][3]['min'], $n[0]['tiles'][3]['max']), array(10, 30));
verifie('pas négatif ignoré', $n[0]['tiles'][3]['step'], null);
verifie('page en double renumérotée', array($n[0]['id'], $n[1]['id'], $n[2]['id']), array('p2', 'p1', 'p3'));
verifie('JSON accepté', count(jeetvbeLayout::normalizePages(json_encode($pages))), 1);
verifie('JSON invalide → vide', jeetvbeLayout::normalizePages('{pas du json'), array());
verifie('idempotent', json_encode(jeetvbeLayout::normalizePages(jeetvbeLayout::normalizePages($brut))), json_encode(jeetvbeLayout::normalizePages($brut)));
verifie('rôles vides encodés en objet', strpos(json_encode(jeetvbeLayout::normalizePages(array(array('tiles' => array(array()))))), '"cmds":{}') !== false, true);

/* --- Révision -------------------------------------------------------------- */
$r1 = jeetvbeLayout::revision($pages);
verifie('révision : 8 caractères hexadécimaux', preg_match('/^[0-9a-f]{8}$/', $r1), 1);
verifie('révision stable après aller-retour JSON', jeetvbeLayout::revision(json_decode(json_encode(jeetvbeLayout::normalizePages($pages)), true)), $r1);
$autre = $pages;
$autre[0]['tiles'][0]['name'] = 'Plafond';
verifie('révision change avec la configuration', jeetvbeLayout::revision($autre) !== $r1, true);
$nombres = $pages;
$nombres[0]['tiles'][3]['min'] = '15.0';
verifie('révision insensible à 15 / "15.0"', jeetvbeLayout::revision($nombres), $r1);

/* --- Layout ---------------------------------------------------------------- */
$layout = jeetvbeLayout::buildLayout($pages, $resoudre);
verifie('schéma 1', $layout['schema'], 1);
verifie('révision du layout', $layout['revision'], $r1);
$t = $layout['pages'][0]['tiles'];
verifie('switch', $t[0], array('id' => 't1', 'type' => 'switch', 'name' => 'Plafond salon', 'icon' => 'light',
                              'confirm' => false, 'value' => '1', 'unit' => ''));
verifie('volet avec position', $t[1], array('id' => 't2', 'type' => 'shutter', 'name' => 'Volets SUD séjour', 'icon' => 'shutter',
                              'confirm' => false, 'value' => '100', 'unit' => '%', 'min' => 0, 'max' => 100, 'step' => 10));
verifie('volet sans état ni position (rfxcom)', $t[2], array('id' => 't3', 'type' => 'shutter', 'name' => 'volet 4', 'icon' => 'shutter',
                              'confirm' => false, 'value' => null, 'unit' => ''));
verifie('slider', $t[3], array('id' => 't4', 'type' => 'slider', 'name' => 'Consigne salon', 'icon' => 'thermostat',
                              'confirm' => false, 'value' => '20.5', 'unit' => '°C', 'min' => 15, 'max' => 25, 'step' => 0.5));
verifie('info', $t[4]['value'] . $t[4]['unit'], '24°C');
verifie('info sans bornes', isset($t[4]['min']), false);
verifie('scène : value null même avec un état', $t[5]['value'], null);
verifie('scène : confirm', $t[5]['confirm'], true);
verifie('JSON : step 0.5 et value en chaîne', strpos(json_encode($t[3]), '"value":"20.5"') !== false && strpos(json_encode($t[3]), '"step":0.5') !== false, true);
$sansBornes = array(array('tiles' => array(array('type' => 'slider', 'cmds' => array('set' => 31)))));
$l = jeetvbeLayout::buildLayout($sansBornes, $resoudre);
verifie('slider : bornes de la commande', array($l['pages'][0]['tiles'][0]['min'], $l['pages'][0]['tiles'][0]['max'], $l['pages'][0]['tiles'][0]['step']), array(5, 30, 1));
verifie('slider sans état : unité de la commande « set »', $l['pages'][0]['tiles'][0]['unit'], '°C');
$disparue = array(array('tiles' => array(array('type' => 'switch', 'cmds' => array('state' => 999)))));
verifie('commande disparue → null', jeetvbeLayout::buildLayout($disparue, $resoudre)['pages'][0]['tiles'][0]['value'], null);

/* --- Recherche de tuile, carte des états ------------------------------------ */
verifie('findTile', jeetvbeLayout::findTile($pages, 't4')['name'], 'Consigne salon');
verifie('findTile inconnue', jeetvbeLayout::findTile($pages, 't99'), null);
verifie('findTile non chaîne', jeetvbeLayout::findTile($pages, array('t1')), null);
verifie('stateMap (scène exclue)', jeetvbeLayout::stateMap($pages), array(10 => array('t1'), 20 => array('t2'), 30 => array('t4'), 40 => array('t5')));

/* --- Résolution des actions --------------------------------------------------- */
$tuile = function ($_id) use ($pages) {
    return jeetvbeLayout::findTile($pages, $_id);
};
verifie('switch on', jeetvbeLayout::resolveAction($tuile('t1'), 'on'), array('cmd' => 11, 'action' => 'on', 'options' => array()));
verifie('switch off', jeetvbeLayout::resolveAction($tuile('t1'), 'off')['cmd'], 12);
verifie('toggle sans commande, allumé → off', jeetvbeLayout::resolveAction($tuile('t1'), 'toggle', null, '1')['action'], 'off');
verifie('toggle sans commande, éteint → on', jeetvbeLayout::resolveAction($tuile('t1'), 'toggle', null, '0')['action'], 'on');
verifie('toggle sans commande, 42 → off', jeetvbeLayout::resolveAction($tuile('t1'), 'toggle', null, 42)['action'], 'off');
verifie('toggle état inconnu → 422', jeetvbeLayout::resolveAction($tuile('t1'), 'toggle', null, null)['error'], 422);
$avecToggle = $pages[0]['tiles'][0];
$avecToggle['cmds']['toggle'] = 13;
verifie('toggle avec commande', jeetvbeLayout::resolveAction(jeetvbeLayout::normalizeTile($avecToggle), 'toggle', null, '1')['cmd'], 13);
verifie('on sur info → 422', jeetvbeLayout::resolveAction($tuile('t5'), 'on')['error'], 422);
verifie('set sur switch → 422', jeetvbeLayout::resolveAction($tuile('t1'), 'set', 4)['error'], 422);
verifie('run sur volet → 422', jeetvbeLayout::resolveAction($tuile('t2'), 'run')['error'], 422);
verifie('action vide → 400', jeetvbeLayout::resolveAction($tuile('t1'), '')['error'], 400);
verifie('volet set', jeetvbeLayout::resolveAction($tuile('t2'), 'set', 40, null, $commandes[21])['options'], array('slider' => 40));
verifie('volet set borné', jeetvbeLayout::resolveAction($tuile('t2'), 'set', 140, null, $commandes[21])['options'], array('slider' => 100));
verifie('volet sans position : set → 422', jeetvbeLayout::resolveAction($tuile('t3'), 'set', 40)['error'], 422);
verifie('volet stop', jeetvbeLayout::resolveAction($tuile('t3'), 'stop')['cmd'], 11);
verifie('volet up', jeetvbeLayout::resolveAction($tuile('t2'), 'up')['cmd'], 11);
verifie('volet stop absent → 422', jeetvbeLayout::resolveAction($tuile('t2'), 'stop')['error'], 422);
verifie('slider set borné bas', jeetvbeLayout::resolveAction($tuile('t4'), 'set', 3, null, $commandes[31])['options'], array('slider' => 15));
verifie('slider set borné haut', jeetvbeLayout::resolveAction($tuile('t4'), 'set', '99', null, $commandes[31])['options'], array('slider' => 25));
verifie('slider set décimal', jeetvbeLayout::resolveAction($tuile('t4'), 'set', 21.5, null, $commandes[31])['options'], array('slider' => 21.5));
verifie('slider set sans valeur → 400', jeetvbeLayout::resolveAction($tuile('t4'), 'set', null)['error'], 400);
verifie('slider set non numérique → 400', jeetvbeLayout::resolveAction($tuile('t4'), 'set', 'chaud')['error'], 400);
verifie('scène run', jeetvbeLayout::resolveAction($tuile('t6'), 'run'), array('scenario' => 7));
verifie('scène sans scénario → 422', jeetvbeLayout::resolveAction(jeetvbeLayout::normalizeTile(array('type' => 'scene')), 'run')['error'], 422);

/* --- Changements ---------------------------------------------------------------- */
$carte = jeetvbeLayout::stateMap($pages);
$evenements = array(
    array('cmd_id' => 10, 'value' => 1),
    array('cmd_id' => 999, 'value' => 5),
    array('cmd_id' => 30, 'value' => '21'),
    array('cmd_id' => 10, 'value' => 0),
);
verifie('changements fusionnés, dernière valeur', jeetvbeLayout::mergeChanges($evenements, $carte),
        array(array('tile' => 't1', 'value' => '0'), array('tile' => 't4', 'value' => '21')));
verifie('aucun changement suivi', jeetvbeLayout::mergeChanges(array(array('cmd_id' => 999, 'value' => 1)), $carte), array());
verifie('since absent', jeetvbeLayout::parseSince(null), null);
verifie('since 0', jeetvbeLayout::parseSince('0'), null);
verifie('since décimal', jeetvbeLayout::parseSince('1791364425.381'), 1791364425.381);
verifie('since invalide', jeetvbeLayout::parseSince('hier'), false);
verifie('since tableau', jeetvbeLayout::parseSince(array('1')), false);
verifie('since négatif', jeetvbeLayout::parseSince('-3'), false);

/* --- Génération depuis les types génériques --------------------------------------- */
$lumiere = array('id' => 96, 'name' => 'Plafond salon', 'cmds' => array(
    array('id' => 1666, 'type' => 'info', 'generic' => 'LIGHT_STATE', 'name' => 'État'),
    array('id' => 1670, 'type' => 'action', 'generic' => 'LIGHT_ON', 'name' => 'Allumer'),
    array('id' => 1671, 'type' => 'action', 'generic' => 'LIGHT_OFF', 'name' => 'Éteindre'),
    array('id' => 1672, 'type' => 'action', 'generic' => 'LIGHT_TOGGLE', 'name' => 'Basculer'),
    array('id' => 1665, 'type' => 'info', 'generic' => 'ONLINE', 'name' => 'Connecté'),
));
$g = jeetvbeLayout::tilesForEqLogic($lumiere);
verifie('lumière → un switch', count($g), 1);
verifie('lumière : rôles', $g[0]['cmds'], array('state' => 1666, 'on' => 1670, 'off' => 1671, 'toggle' => 1672));
verifie('lumière : icône', $g[0]['icon'], 'light');
verifie('lumière : pas de confirmation', $g[0]['confirm'], false);

$wled = array('id' => 474, 'name' => 'Plafond wled', 'cmds' => array(
    array('id' => 5674, 'type' => 'info', 'generic' => 'LIGHT_BRIGHTNESS'),
    array('id' => 5673, 'type' => 'info', 'generic' => 'LIGHT_STATE_BOOL'),
    array('id' => 5688, 'type' => 'action', 'generic' => 'LIGHT_ON'),
    array('id' => 5689, 'type' => 'action', 'generic' => 'LIGHT_OFF'),
));
verifie('LIGHT_STATE_BOOL préféré', jeetvbeLayout::tilesForEqLogic($wled)[0]['cmds']['state'], 5673);

$prise = array('id' => 1, 'name' => 'Prise TV', 'cmds' => array(
    array('id' => 1, 'type' => 'info', 'generic' => 'ENERGY_STATE'),
    array('id' => 2, 'type' => 'action', 'generic' => 'ENERGY_ON'),
    array('id' => 3, 'type' => 'action', 'generic' => 'ENERGY_OFF'),
));
$g = jeetvbeLayout::tilesForEqLogic($prise);
verifie('prise → switch plug', array($g[0]['type'], $g[0]['icon']), array('switch', 'plug'));

$groupeVolets = array('id' => 547, 'name' => 'Volets SUD (séjour)', 'cmds' => array(
    array('id' => 7139, 'type' => 'action', 'generic' => 'FLAP_UP'),
    array('id' => 7140, 'type' => 'action', 'generic' => 'FLAP_DOWN'),
    array('id' => 7141, 'type' => 'action', 'generic' => 'FLAP_STOP'),
    array('id' => 7142, 'type' => 'action', 'generic' => 'FLAP_SLIDER', 'minValue' => '0', 'maxValue' => '100'),
    array('id' => 7143, 'type' => 'info', 'generic' => 'FLAP_STATE'),
    array('id' => 7144, 'type' => 'info', 'generic' => 'TEMPERATURE', 'name' => 'Température retenue'),
));
$g = jeetvbeLayout::tilesForEqLogic($groupeVolets);
verifie('volets groupés → un seul shutter (température retenue ignorée)', count($g), 1);
verifie('volet : rôles', $g[0]['cmds'], array('up' => 7139, 'down' => 7140, 'stop' => 7141, 'set' => 7142, 'state' => 7143));
verifie('volet : bornes', array($g[0]['min'], $g[0]['max'], $g[0]['step']), array(0, 100, 10));

$bso = array('id' => 2, 'name' => 'BSO', 'cmds' => array(
    array('id' => 1, 'type' => 'action', 'generic' => 'FLAP_BSO_UP'),
    array('id' => 2, 'type' => 'action', 'generic' => 'FLAP_BSO_DOWN'),
    array('id' => 3, 'type' => 'info', 'generic' => 'FLAP_BSO_STATE'),
));
verifie('FLAP_BSO_STATE → state', jeetvbeLayout::tilesForEqLogic($bso)[0]['cmds']['state'], 3);

$rfxcom = array('id' => 538, 'name' => 'volet 4', 'cmds' => array(
    array('id' => 7038, 'type' => 'action', 'generic' => 'FLAP_UP'),
    array('id' => 7039, 'type' => 'action', 'generic' => 'FLAP_DOWN'),
    array('id' => 7040, 'type' => 'action', 'generic' => 'FLAP_STOP'),
));
$g = jeetvbeLayout::tilesForEqLogic($rfxcom);
verifie('volet rfxcom : pas d\'état', isset($g[0]['cmds']['state']), false);
verifie('volet rfxcom : pas de bornes', isset($g[0]['min']), false);
$l = jeetvbeLayout::buildLayout(jeetvbeLayout::generatePages(array(array('name' => 'Salon', 'eqLogics' => array($rfxcom)))), $resoudre);
verifie('volet rfxcom dans le layout : value null, sans min/max', array($l['pages'][0]['tiles'][0]['value'], isset($l['pages'][0]['tiles'][0]['min'])), array(null, false));

$thermostat = array('id' => 514, 'name' => 'Thermostat salon', 'cmds' => array(
    array('id' => 6751, 'type' => 'info', 'generic' => 'THERMOSTAT_TEMPERATURE', 'name' => 'Température'),
    array('id' => 6752, 'type' => 'info', 'generic' => 'THERMOSTAT_SETPOINT', 'name' => 'Consigne chauffe'),
    array('id' => 6753, 'type' => 'action', 'generic' => 'THERMOSTAT_SET_SETPOINT', 'minValue' => '', 'maxValue' => ''),
    array('id' => 6769, 'type' => 'info', 'generic' => 'THERMOSTAT_TEMPERATURE_OUTDOOR', 'name' => 'Température extérieure'),
));
$g = jeetvbeLayout::tilesForEqLogic($thermostat);
verifie('thermostat → slider + 2 infos', array_map(function ($_t) { return $_t['type']; }, $g), array('slider', 'info', 'info'));
verifie('consigne : 15–25 pas 0,5 par défaut', array($g[0]['min'], $g[0]['max'], $g[0]['step']), array(15, 25, 0.5));
verifie('consigne : rôles', $g[0]['cmds'], array('state' => 6752, 'set' => 6753));
verifie('température du thermostat nommée', $g[1]['name'], 'Thermostat salon – Température');
verifie('température extérieure reprise', $g[2]['cmds'], array('state' => 6769));
$sansConsigne = array('id' => 9, 'name' => 'T', 'cmds' => array(array('id' => 1, 'type' => 'action', 'generic' => 'THERMOSTAT_SET_SETPOINT')));
verifie('réglage sans info de consigne : rien', jeetvbeLayout::tilesForEqLogic($sansConsigne), array());

$sonde = array('id' => 3, 'name' => 'Sonde salon', 'cmds' => array(
    array('id' => 7424, 'type' => 'info', 'generic' => 'TEMPERATURE', 'name' => 'Température'),
    array('id' => 7425, 'type' => 'info', 'generic' => 'HUMIDITY', 'name' => 'Humidité'),
));
$g = jeetvbeLayout::tilesForEqLogic($sonde);
verifie('sonde → info nommée comme l\'équipement', array($g[0]['type'], $g[0]['name'], $g[0]['icon']), array('info', 'Sonde salon', 'temperature'));

$portail = array('id' => 4, 'name' => 'Portail entrée', 'cmds' => array(
    array('id' => 1, 'type' => 'action', 'generic' => 'ENERGY_ON'),
    array('id' => 2, 'type' => 'action', 'generic' => 'ENERGY_OFF'),
));
verifie('confirmation : nom « portail »', jeetvbeLayout::tilesForEqLogic($portail)[0]['confirm'], true);
$verrou = array('id' => 5, 'name' => 'Lumière cave', 'cmds' => array(
    array('id' => 1, 'type' => 'action', 'generic' => 'LIGHT_ON'),
    array('id' => 2, 'type' => 'info', 'generic' => 'LOCK_STATE'),
));
verifie('confirmation : type LOCK_*', jeetvbeLayout::tilesForEqLogic($verrou)[0]['confirm'], true);
foreach (array('Bouton PANIQUE', 'Alarme maison', 'Porte de Garage', 'Verrou porte') as $nom) {
    verifie('nom sensible : ' . $nom, jeetvbeLayout::sensitiveName($nom), true);
}
verifie('nom anodin', jeetvbeLayout::sensitiveName('Plafond salon'), false);
foreach (array('ALARM_ENABLE', 'GB_OPEN', 'GARAGE_OPEN') as $type) {
    $eq = array('id' => 6, 'name' => 'X', 'cmds' => array(array('id' => 1, 'type' => 'action', 'generic' => 'ENERGY_ON'), array('id' => 2, 'type' => 'action', 'generic' => $type)));
    verifie('confirmation : type ' . $type, jeetvbeLayout::tilesForEqLogic($eq)[0]['confirm'], true);
}
verifie('équipement sans type générique : rien', jeetvbeLayout::tilesForEqLogic(array('id' => 7, 'name' => 'X', 'cmds' => array())), array());

$generees = jeetvbeLayout::generatePages(array(
    array('name' => 'Salon', 'eqLogics' => array($sonde, $thermostat, $rfxcom, $lumiere)),
    array('name' => 'Vide', 'eqLogics' => array()),
), 'room');
verifie('une page par objet', array_map(function ($_p) { return $_p['name']; }, $generees), array('Salon', 'Vide'));
verifie('ordre : switch, volet, curseur, infos', array_map(function ($_t) { return $_t['type']; }, $generees[0]['tiles']),
        array('switch', 'shutter', 'slider', 'info', 'info', 'info'));
$existantes = jeetvbeLayout::normalizePages($pages);
$fusion = jeetvbeLayout::normalizePages(array_merge($existantes, $generees));
verifie('pages générées numérotées à la suite', array($fusion[1]['id'], $fusion[1]['tiles'][0]['id']), array('p2', 't7'));


/* --- Génération par type (mode par défaut) ------------------------------------------ */
$clim = array('id' => 60, 'name' => 'Climatisation', 'cmds' => array(
    array('id' => 6465, 'type' => 'info', 'generic' => 'ENERGY_STATE'),
    array('id' => 6466, 'type' => 'action', 'generic' => 'ENERGY_ON'),
    array('id' => 6467, 'type' => 'action', 'generic' => 'ENERGY_OFF'),
    array('id' => 6468, 'type' => 'info', 'generic' => 'THERMOSTAT_SETPOINT'),
    array('id' => 6469, 'type' => 'action', 'generic' => 'THERMOSTAT_SET_SETPOINT', 'minValue' => '16', 'maxValue' => '31'),
    array('id' => 6470, 'type' => 'info', 'generic' => 'THERMOSTAT_TEMPERATURE', 'name' => 'Température'),
));
$spot = array('id' => 91, 'name' => 'Spot plafond salle a manger', 'cmds' => $lumiere['cmds']);
$meuble = array('id' => 92, 'name' => 'Meuble', 'cmds' => $lumiere['cmds']);
$objets = array(
    array('name' => 'Salon', 'eqLogics' => array($sonde, $thermostat, $rfxcom, $lumiere, $prise)),
    array('name' => 'Salle à manger', 'eqLogics' => array($spot, $clim, $meuble)),
    array('name' => 'Automatisme', 'eqLogics' => array($groupeVolets)),
    array('name' => 'Vide', 'eqLogics' => array()),
);
$parType = jeetvbeLayout::generatePages($objets);
verifie('par type : pages dans l\'ordre, vides omises', array_map(function ($_p) { return $_p['name']; }, $parType),
        array('Lumières', 'Volets', 'Chauffage et clim', 'Températures', 'Prises'));
$noms = function ($_page) { return array_map(function ($_t) { return $_t['name']; }, $_page['tiles']); };
verifie('lumières : par pièce puis par nom, pièce non répétée', $noms($parType[0]),
        array('Salon · Plafond', 'Salle à manger · Meuble', 'Salle à manger · Spot plafond'));
verifie('volets : pièce en préfixe', $noms($parType[1]), array('Salon · volet 4', 'Automatisme · Volets SUD (séjour)'));
verifie('chauffage : consignes et switch de la clim', array_map(function ($_t) { return $_t['type'] . ' ' . $_t['name']; }, $parType[2]['tiles']),
        array('slider Salon · Consigne Thermostat', 'switch Salle à manger · Climatisation', 'slider Salle à manger · Consigne Climatisation'));
verifie('températures', $noms($parType[3]), array('Salon · Sonde', 'Salon · Thermostat – Température',
        'Salon · Thermostat – Température extérieure', 'Salle à manger · Climatisation – Température'));
verifie('prises : la prise ordinaire seulement', $noms($parType[4]), array('Salon · Prise TV'));
verifie('par type : rôles conservés', $parType[2]['tiles'][1]['cmds'], array('state' => 6465, 'on' => 6466, 'off' => 6467));
$n = jeetvbeLayout::normalizePages($parType);
$ids = array();
foreach ($n as $page) {
    foreach ($page['tiles'] as $tile) {
        $ids[] = $tile['id'];
    }
}
verifie('par type : ids de page p1…p5', array_map(function ($_p) { return $_p['id']; }, $n), array('p1', 'p2', 'p3', 'p4', 'p5'));
verifie('par type : ids de tuile uniques', count(array_unique($ids)), count($ids));
verifie('par type : confirmation conservée (pas déduite du nom de pièce)',
        jeetvbeLayout::generatePages(array(array('name' => 'Garage', 'eqLogics' => array($lumiere))))[0]['tiles'][0]['confirm'], false);
verifie('mode par pièce inchangé', array_map(function ($_p) { return $_p['name']; }, jeetvbeLayout::generatePages($objets, 'room')),
        array('Salon', 'Salle à manger', 'Automatisme', 'Vide'));

/* --- Retrait du nom de la pièce ------------------------------------------------------ */
verifie('stripRoom simple', jeetvbeLayout::stripRoom('Plafond salon', 'Salon'), 'Plafond');
verifie('stripRoom accents', jeetvbeLayout::stripRoom('Baie vitrée salle a manger', 'Salle à manger'), 'Baie vitrée');
verifie('stripRoom au milieu', jeetvbeLayout::stripRoom('Thermostat salon – Température', 'Salon'), 'Thermostat – Température');
verifie('stripRoom entre parenthèses', jeetvbeLayout::stripRoom('Volets (séjour)', 'Séjour'), 'Volets');
verifie('stripRoom : mot partiel non retiré', jeetvbeLayout::stripRoom('Plafond wled salon', 'Salle à manger'), 'Plafond wled salon');
verifie('stripRoom : pas de sous-mot', jeetvbeLayout::stripRoom('Salons', 'Salon'), 'Salons');
verifie('stripRoom : nom réduit à rien', jeetvbeLayout::stripRoom('Salon', 'Salon'), 'Salon');
verifie('roomTileName', jeetvbeLayout::roomTileName('Cuisine', 'Lampe plafond'), 'Cuisine · Lampe plafond');

/* --- Reprise des ids à la régénération ---------------------------------------------- */
$avant = jeetvbeLayout::normalizePages(jeetvbeLayout::generatePages($objets, 'room'));
$apres = jeetvbeLayout::normalizePages(jeetvbeLayout::generatePages($objets), $avant, 0);
$parSignature = function ($_pages) {
    $map = array();
    foreach ($_pages as $page) {
        foreach ($page['tiles'] as $tile) {
            $map[jeetvbeLayout::signature($tile)] = $tile['id'];
        }
    }
    ksort($map);
    return $map;
};
verifie('régénération : chaque tuile garde son id', $parSignature($apres), $parSignature($avant));
$nouvelle = array(array('tiles' => array(array('type' => 'switch', 'cmds' => array('on' => 4242)))));
$suite = jeetvbeLayout::normalizePages($nouvelle, $avant, 0);
verifie('tuile nouvelle : numéro après le plus grand ancien', $suite[0]['tiles'][0]['id'], 't' . (jeetvbeLayout::maxTileNumber($avant) + 1));
verifie('tuile nouvelle : numéro après le plancher', jeetvbeLayout::normalizePages($nouvelle, null, 40)[0]['tiles'][0]['id'], 't41');
$doublon = array(array('tiles' => array(array('type' => 'switch', 'cmds' => array('on' => 1670, 'off' => 1671, 'state' => 1666, 'toggle' => 1672)),
                                        array('type' => 'switch', 'cmds' => array('on' => 1670, 'off' => 1671, 'state' => 1666, 'toggle' => 1672)))));
$d = jeetvbeLayout::normalizePages($doublon, $avant, 0);
verifie('deux tuiles identiques : une seule reprend l\'id', $d[0]['tiles'][0]['id'] !== $d[0]['tiles'][1]['id'], true);
verifie('maxTileNumber', jeetvbeLayout::maxTileNumber(array(array('tiles' => array(array('id' => 't7'), array('id' => 'x'))))), 7);


/* --- Ordres Jeedom → TV : file ------------------------------------------------------- */
$q = array();
$q = jeetvbeLayout::queuePush($q, array('id' => 1, 'type' => 'show', 'page' => 'p2', 'duration' => 30), 1000.0);
$q = jeetvbeLayout::queuePush($q, array('id' => 2, 'type' => 'exit'), 1010.0);
verifie('file : deux ordres', count($q), 2);
verifie('file : livraison dans l\'ordre des id, sans horodatage', jeetvbeLayout::queueOrders($q, 1020.0),
        array(array('id' => 1, 'type' => 'show', 'page' => 'p2', 'duration' => 30), array('id' => 2, 'type' => 'exit')));
verifie('file : ordre de 60 s abandonné', jeetvbeLayout::queueOrders($q, 1060.0), array(array('id' => 2, 'type' => 'exit')));
verifie('file : 59,9 s encore livré', count(jeetvbeLayout::queueOrders($q, 1059.9)), 2);
verifie('file : tout abandonné après 70 s', jeetvbeLayout::queueOrders($q, 1080.0), array());
$q2 = jeetvbeLayout::queuePush($q, array('id' => 3, 'type' => 'exit'), 1065.0);
verifie('file : purge à l\'ajout', array_map(function ($_e) { return $_e['order']['id']; }, $q2), array(2, 3));
verifie('file : entrée mal formée ignorée', jeetvbeLayout::queuePurge(array('x', array('ts' => 1)), 1000.0), array());
$grande = array();
for ($i = 1; $i <= 60; $i++) {
    $grande = jeetvbeLayout::queuePush($grande, array('id' => $i, 'type' => 'exit'), 1000.0);
}
verifie('file : bornée, les plus récents gardés', array(count($grande), $grande[0]['order']['id']), array(jeetvbeLayout::QUEUE_MAX, 11));

/* --- Résolution de page, durée --------------------------------------------------------- */
$pagesTv = array(
    array('id' => 'p1', 'name' => 'Lumières', 'tiles' => array()),
    array('id' => 'p2', 'name' => 'Volets', 'tiles' => array()),
    array('id' => 'p3', 'name' => 'p1', 'tiles' => array()),
);
verifie('page par id', jeetvbeLayout::resolvePage($pagesTv, 'p2')['id'], 'p2');
verifie('page par id, casse ignorée', jeetvbeLayout::resolvePage($pagesTv, 'P2')['id'], 'p2');
verifie('page par nom', jeetvbeLayout::resolvePage($pagesTv, 'Volets')['id'], 'p2');
verifie('page par nom, casse ignorée', jeetvbeLayout::resolvePage($pagesTv, 'LUMIÈRES')['id'], 'p1');
verifie('page par nom, accents ignorés', jeetvbeLayout::resolvePage($pagesTv, 'lumieres')['id'], 'p1');
verifie('page : l\'id prime sur un nom identique', jeetvbeLayout::resolvePage($pagesTv, 'p1')['name'], 'Lumières');
verifie('page : espaces ignorés', jeetvbeLayout::resolvePage($pagesTv, '  Volets ')['id'], 'p2');
verifie('page inconnue', jeetvbeLayout::resolvePage($pagesTv, 'Garage'), null);
verifie('page vide', jeetvbeLayout::resolvePage($pagesTv, ''), null);
verifie('page non chaîne', jeetvbeLayout::resolvePage($pagesTv, array('p1')), null);
verifie('durée vide → défaut', jeetvbeLayout::parseDuration('', 45), 45);
verifie('durée null → défaut', jeetvbeLayout::parseDuration(null, 45), 45);
verifie('durée 0 = sans retour', jeetvbeLayout::parseDuration('0', 45), 0);
verifie('durée 12', jeetvbeLayout::parseDuration(' 12 ', 45), 12);
verifie('durée décimale arrondie', jeetvbeLayout::parseDuration('7,6', 45), 8);
verifie('durée bornée', jeetvbeLayout::parseDuration('999999', 45), jeetvbeLayout::MAX_DURATION);
verifie('durée négative refusée', jeetvbeLayout::parseDuration('-5', 45), false);
verifie('durée texte refusée', jeetvbeLayout::parseDuration('longtemps', 45), false);
verifie('durée par défaut absente → 30', jeetvbeLayout::defaultDuration(''), 30);
verifie('durée par défaut invalide → 30', jeetvbeLayout::defaultDuration('abc'), 30);
verifie('durée par défaut 0', jeetvbeLayout::defaultDuration('0'), 0);
verifie('durée par défaut 90', jeetvbeLayout::defaultDuration(90), 90);

/* --- Commandes de l'équipement -------------------------------------------------------- */
verifie('une commande Afficher par page, logicalId show_<id>', jeetvbeLayout::pageCommands($pagesTv),
        array('show_p1' => 'Afficher Lumières', 'show_p2' => 'Afficher Volets', 'show_p3' => 'Afficher p1'));
$homonymes = array(array('id' => 'p1', 'name' => 'Salon'), array('id' => 'p2', 'name' => 'salon'), array('id' => 'p3', 'name' => 'page'));
verifie('noms uniques : homonyme et commande fixe suffixés', jeetvbeLayout::pageCommands($homonymes),
        array('show_p1' => 'Afficher Salon', 'show_p2' => 'Afficher salon (p2)', 'show_p3' => 'Afficher page (p3)'));
verifie('nom nettoyé comme le fait Jeedom', jeetvbeLayout::cleanCommandName("Afficher L'entrée & [cour] #1"), 'Afficher Lentrée cour 1');
verifie('commandes fixes', array_keys(jeetvbeLayout::FIXED_COMMANDS), array('show_page', 'notify', 'exit', 'ask', 'online', 'visible', 'screen', 'page', 'appVersion'));
verifie('info Version app', jeetvbeLayout::FIXED_COMMANDS['appVersion'], array('name' => 'Version app', 'type' => 'info', 'subType' => 'string'));
verifie('appVersion valide', jeetvbeLayout::stateVersion(' 0.4.0 '), '0.4.0');
verifie('appVersion vide refusée', jeetvbeLayout::stateVersion(''), null);
verifie('appVersion nombre refusé', jeetvbeLayout::stateVersion(4), null);
verifie('appVersion trop longue refusée', jeetvbeLayout::stateVersion(str_repeat('9', 65)), null);
verifie('appVersion avec saut de ligne refusée', jeetvbeLayout::stateVersion("0.4\n1"), null);
verifie('commande Question : action / message', array(jeetvbeLayout::FIXED_COMMANDS['ask']['name'], jeetvbeLayout::FIXED_COMMANDS['ask']['subType']), array('Question', 'message'));
verifie('page affichée : nom', jeetvbeLayout::shownPageName($pagesTv, 'p2'), 'Volets');
verifie('page affichée : null → vide', jeetvbeLayout::shownPageName($pagesTv, null), '');
verifie('page affichée : id inconnu gardé', jeetvbeLayout::shownPageName($pagesTv, 'p9'), 'p9');
verifie('état : true', jeetvbeLayout::stateBool(true), 1);
verifie('état : 0', jeetvbeLayout::stateBool(0), 0);
verifie('état : texte refusé', jeetvbeLayout::stateBool('oui'), null);


/* --- Ids de page jamais réattribués ------------------------------------------------- */
$avantPages = jeetvbeLayout::normalizePages(array(array('name' => 'Alpha'), array('name' => 'Bêta')));
$apresPages = jeetvbeLayout::normalizePages(array(array('id' => 'p1', 'name' => 'Alpha'), array('name' => 'Gamma')), $avantPages);
verifie('page nouvelle : pas l\'id d\'une page supprimée', $apresPages[1]['id'], 'p3');
$regen = jeetvbeLayout::normalizePages(array(array('name' => 'Gamma'), array('name' => 'beta')), $avantPages);
verifie('page régénérée : même nom → même id', array($regen[0]['id'], $regen[1]['id']), array('p3', 'p2'));
verifie('page : plancher respecté', jeetvbeLayout::normalizePages(array(array('name' => 'X')), null, 0, 7)[0]['id'], 'p8');
verifie('maxPageNumber', jeetvbeLayout::maxPageNumber($apresPages), 3);


/* --- Questions (bloc « Demander ») ---------------------------------------------------- */
$optionsCoeur = array('title' => 'Fermer les volets ?', 'message' => 'Fermer les volets ?', 'answer' => array('Oui', 'Non'), 'timeout' => 120, 'variable' => 'rep');
$ordre = jeetvbeLayout::askOrder($optionsCoeur, 'abc123');
verifie('ordre ask construit', $ordre, array('type' => 'ask', 'ask' => 'abc123', 'title' => '', 'message' => 'Fermer les volets ?',
                                             'answers' => array('Oui', 'Non'), 'timeout' => 120));
verifie('ask : titre distinct gardé', jeetvbeLayout::askOrder(array('title' => 'Maison', 'message' => 'Q ?', 'answer' => array('A')), 't')['title'], 'Maison');
verifie('ask : message vide → le titre devient le message', jeetvbeLayout::askOrder(array('title' => 'Q ?', 'message' => '', 'answer' => array('A')), 't')['message'], 'Q ?');
verifie('ask : réponses nettoyées (vides, « * », doublons)', jeetvbeLayout::askAnswers(array(' Oui ', '', '*', 'Non', 'Oui', array('x'))), array('Oui', 'Non'));
verifie('ask : sans réponse proposable → null (comme Message)', jeetvbeLayout::askOrder(array('message' => 'Q', 'answer' => array('*')), 't'), null);
verifie('ask : réponses absentes → null', jeetvbeLayout::askOrder(array('message' => 'Q'), 't'), null);
verifie('ask : délai absent → 300', jeetvbeLayout::askTimeout(null), 300);
verifie('ask : délai texte numérique', jeetvbeLayout::askTimeout('45'), 45);
verifie('ask : durée de vie en file = min(délai, 60)', array(jeetvbeLayout::askTtl(20), jeetvbeLayout::askTtl(300)), array(20, 60));
$qa = jeetvbeLayout::queuePush(array(), array('id' => 1, 'type' => 'ask'), 1000.0, 20);
$qa = jeetvbeLayout::queuePush($qa, array('id' => 2, 'type' => 'exit'), 1000.0);
verifie('file : ordre ask abandonné à la fin de son délai', array_map(function ($_o) { return $_o['id']; }, jeetvbeLayout::queueOrders($qa, 1021.0)), array(2));
verifie('file : ordre ask livré avant la fin de son délai', count(jeetvbeLayout::queueOrders($qa, 1019.0)), 2);
verifie('file : ttl d\'un ordre plafonné à 60 s', jeetvbeLayout::queueOrders(jeetvbeLayout::queuePush(array(), array('id' => 1), 1000.0, 300), 1061.0), array());
$attente = jeetvbeLayout::askPending('jeton', 7667, array('Oui', 'Non'), 120, 1000);
verifie('question retenue', $attente, array('token' => 'jeton', 'cmd_id' => 7667, 'answers' => array('Oui', 'Non'), 'endtime' => 1120));
verifie('réponse acceptée', jeetvbeLayout::checkAnswer($attente, 'jeton', 'Non', 1100), array('code' => 200, 'answer' => 'Non'));
verifie('mauvais jeton → 404', jeetvbeLayout::checkAnswer($attente, 'autre', 'Oui', 1100)['code'], 404);
verifie('pas de question → 404', jeetvbeLayout::checkAnswer(null, 'jeton', 'Oui', 1100)['code'], 404);
verifie('délai passé → 404', jeetvbeLayout::checkAnswer($attente, 'jeton', 'Oui', 1121)['code'], 404);
verifie('réponse hors liste → 422', jeetvbeLayout::checkAnswer($attente, 'jeton', 'Peut-être', 1100)['code'], 422);
verifie('réponse : casse respectée', jeetvbeLayout::checkAnswer($attente, 'jeton', 'oui', 1100)['code'], 422);
verifie('jeton manquant → 400', jeetvbeLayout::checkAnswer($attente, null, 'Oui', 1100)['code'], 400);
verifie('réponse tableau → 400', jeetvbeLayout::checkAnswer($attente, 'jeton', array('Oui'), 1100)['code'], 400);


/* --- Lumières variables -------------------------------------------------------------- */
$wled474 = array('id' => 474, 'name' => 'Plafond wled salon', 'cmds' => array(
    array('id' => 5673, 'type' => 'info', 'subType' => 'binary', 'generic' => 'LIGHT_STATE_BOOL'),
    array('id' => 5674, 'type' => 'info', 'subType' => 'numeric', 'generic' => 'LIGHT_BRIGHTNESS', 'unit' => '%', 'minValue' => '0', 'maxValue' => '100'),
    array('id' => 5688, 'type' => 'action', 'subType' => 'other', 'generic' => 'LIGHT_ON'),
    array('id' => 5689, 'type' => 'action', 'subType' => 'other', 'generic' => 'LIGHT_OFF'),
    array('id' => 5690, 'type' => 'action', 'subType' => 'other', 'generic' => 'LIGHT_TOGGLE'),
    array('id' => 5691, 'type' => 'action', 'subType' => 'slider', 'generic' => 'LIGHT_SLIDER', 'minValue' => '0', 'maxValue' => '100'),
));
$g = jeetvbeLayout::tilesForEqLogic($wled474);
verifie('WLED : switch + curseur de luminosité', array_map(function ($_t) { return $_t['type'] . ' ' . $_t['name']; }, $g),
        array('switch Plafond wled salon', 'slider Plafond wled salon (luminosité)'));
verifie('luminosité : rôles', $g[1]['cmds'], array('state' => 5674, 'set' => 5691));
verifie('luminosité : bornes, pas, unité, icône', array($g[1]['min'], $g[1]['max'], $g[1]['step'], $g[1]['unit'], $g[1]['icon']), array(0, 100, 10, '%', 'light'));
verifie('luminosité : page Lumières en mode par type', $g[1]['group'], 'lights');
$variateur = array('id' => 9, 'name' => 'Variateur', 'cmds' => array(
    array('id' => 1, 'type' => 'info', 'subType' => 'numeric', 'generic' => 'LIGHT_STATE'),
    array('id' => 2, 'type' => 'action', 'subType' => 'slider', 'generic' => 'LIGHT_SLIDER', 'minValue' => '0', 'maxValue' => '99'),
));
$g = jeetvbeLayout::tilesForEqLogic($variateur);
verifie('variateur sans on/off : curseur seul, état numérique', array(count($g), $g[0]['cmds'], $g[0]['max']), array(1, array('state' => 1, 'set' => 2), 99));
$sansInfo = array('id' => 10, 'name' => 'X', 'cmds' => array(
    array('id' => 1, 'type' => 'info', 'subType' => 'binary', 'generic' => 'LIGHT_STATE'),
    array('id' => 2, 'type' => 'action', 'subType' => 'other', 'generic' => 'LIGHT_ON'),
    array('id' => 3, 'type' => 'action', 'subType' => 'slider', 'generic' => 'LIGHT_SLIDER'),
));
verifie('LIGHT_SLIDER sans info de luminosité : pas de curseur', count(jeetvbeLayout::tilesForEqLogic($sansInfo)), 1);
$pt = jeetvbeLayout::generatePages(array(array('name' => 'Salle à manger', 'eqLogics' => array($wled474))));
verifie('par type : curseur juste après son interrupteur', array_map(function ($_t) { return $_t['name']; }, $pt[0]['tiles']),
        array('Salle à manger · Plafond wled salon', 'Salle à manger · Plafond wled salon (luminosité)'));
$lt = jeetvbeLayout::buildLayout(array(array('tiles' => array($pt[0]['tiles'][1]))), function ($_id) {
    return array('type' => 'info', 'value' => 40, 'unit' => '', 'minValue' => '', 'maxValue' => '');
});
verifie('layout : unité imposée % et valeur', array($lt['pages'][0]['tiles'][0]['unit'], $lt['pages'][0]['tiles'][0]['value']), array('%', '40'));
verifie('unité absente : révision inchangée', jeetvbeLayout::revision($pages), $r1);


/* --- Page dynamique des scénarios ------------------------------------------------------ */
$groupe = array(
    array('id' => 36, 'name' => 'Je pars', 'description' => 'Départ', 'isActive' => true),
    array('id' => 34, 'name' => 'Cinéma', 'description' => 'Ambiance cinéma', 'isActive' => true),
    array('id' => 37, 'name' => 'Ancien', 'description' => '', 'isActive' => false),
    array('id' => 35, 'name' => 'Bonne nuit', 'description' => 'Tout fermer [confirmer]', 'isActive' => true),
    array('id' => 38, 'name' => 'Alarme totale', 'description' => '', 'isActive' => true),
);
$sp = jeetvbeLayout::scenesPage(array(array('id' => 'p1', 'name' => 'Lumières')), $groupe);
verifie('page scènes : id fixe et nom', array($sp['id'], $sp['name']), array('scenes', 'Scénarios'));
verifie('page scènes : actifs seulement, triés par nom', array_map(function ($_t) { return $_t['id'] . ' ' . $_t['name']; }, $sp['tiles']),
        array('s38 Alarme totale', 's35 Bonne nuit', 's34 Cinéma', 's36 Je pars'));
verifie('page scènes : type, icône, scénario', array($sp['tiles'][2]['type'], $sp['tiles'][2]['icon'], $sp['tiles'][2]['scenario_id']), array('scene', 'scene', 34));
verifie('confirmation : [confirmer] ou nom sensible', array_map(function ($_t) { return $_t['confirm']; }, $sp['tiles']), array(true, true, false, false));
verifie('confirmation : [CONFIRMER] insensible à la casse', jeetvbeLayout::sceneConfirm('X', 'à [CONFIRMER] ici'), true);
verifie('page scènes : « Ambiances » si une page manuelle s\'appelle Scénarios',
        jeetvbeLayout::scenesPage(array(array('id' => 'p5', 'name' => 'Scénarios')), $groupe)['name'], 'Ambiances');
verifie('page scènes : aucun scénario actif → pas de page', jeetvbeLayout::scenesPage(array(), array($groupe[2])), null);
$ls = jeetvbeLayout::buildLayout($pages, $resoudre, $sp);
$derniere = end($ls['pages']);
verifie('layout : page scènes à la fin', array($derniere['id'], count($derniere['tiles'])), array('scenes', 4));
verifie('layout : tuile scène sans valeur', $derniere['tiles'][2], array('id' => 's34', 'type' => 'scene', 'name' => 'Cinéma', 'icon' => 'scene', 'confirm' => false, 'value' => null, 'unit' => ''));
verifie('révision sans page scènes : inchangée', jeetvbeLayout::revision($pages, null), $r1);
$rs = jeetvbeLayout::revision($pages, $sp);
verifie('révision : la page scènes compte', $rs !== $r1, true);
$renomme = $groupe; $renomme[1]['name'] = 'Cinéma maison';
verifie('révision : renommage d\'un scénario', jeetvbeLayout::revision($pages, jeetvbeLayout::scenesPage($pages, $renomme)) !== $rs, true);
$desactive = $groupe; $desactive[0]['isActive'] = false;
verifie('révision : désactivation d\'un scénario', jeetvbeLayout::revision($pages, jeetvbeLayout::scenesPage($pages, $desactive)) !== $rs, true);
$ajout = $groupe; $ajout[] = array('id' => 40, 'name' => 'Lecture', 'description' => '', 'isActive' => true);
verifie('révision : ajout d\'un scénario', jeetvbeLayout::revision($pages, jeetvbeLayout::scenesPage($pages, $ajout)) !== $rs, true);
verifie('révision : stable', jeetvbeLayout::revision($pages, jeetvbeLayout::scenesPage($pages, $groupe)), $rs);
verifie('findTile : tuile scène', jeetvbeLayout::findTile($pages, 's35', $sp)['scenario_id'], 35);
verifie('findTile : scénario désactivé absent', jeetvbeLayout::findTile($pages, 's37', $sp), null);
verifie('exec run sur une tuile scène', jeetvbeLayout::resolveAction(jeetvbeLayout::findTile($pages, 's34', $sp), 'run'), array('scenario' => 34));
verifie('exec on sur une tuile scène → 422', jeetvbeLayout::resolveAction(jeetvbeLayout::findTile($pages, 's34', $sp), 'on')['error'], 422);
verifie('stateMap ignore la page scènes', jeetvbeLayout::stateMap($pages), array(10 => array('t1'), 20 => array('t2'), 30 => array('t4'), 40 => array('t5')));
$tp = jeetvbeLayout::allPages(array(array('id' => 'p1', 'name' => 'Lumières')), $sp);
verifie('commandes : Afficher Scénarios (show_scenes)', jeetvbeLayout::pageCommands($tp), array('show_p1' => 'Afficher Lumières', 'show_scenes' => 'Afficher Scénarios'));
verifie('Afficher page : page scènes par nom', jeetvbeLayout::resolvePage($tp, 'scénarios')['id'], 'scenes');
verifie('Page affichée : nom de la page scènes', jeetvbeLayout::shownPageName($tp, 'scenes'), 'Scénarios');

/* --- Les deux pièges du coeur, en lecture du source ------------------------------ */
$source = file_get_contents(__DIR__ . '/../core/class/jeetvbe.class.php');
preg_match_all('/^\s*(?:public|protected|private|var)\s+(?:static\s+)?\$(\w+)/m', $source, $m);
verifie('aucune propriété sans souligné', array_values(array_filter($m[1], function ($_n) { return $_n[0] !== '_'; })), array());
verifie('aucune méthode setCmd / set+clé de formulaire', preg_match('/function\s+set(Id|Name|LogicalId|Generic_type|Object_id|EqType_name|IsVisible|IsEnable|Configuration|Timeout|Category|Display|Order|Comment|Tags|Cmd)\s*\(/i', $source), 0);
verifie('preSave ne lève pas d\'exception', preg_match('/function preSave\(\)\s*\{(?:(?!\n    \}).)*throw/s', $source), 0);
verifie('pas de .htaccess devant l\'API', file_exists(__DIR__ . '/../core/php/.htaccess'), false);

echo ($echecs === 0) ? "OK : $total vérifications passent.\n" : "$echecs échec(s) sur $total vérifications.\n";
exit($echecs === 0 ? 0 : 1);
