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
require_once __DIR__ . '/../core/class/jeetvbeOverlay.class.php';

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

/* --- Tuile button ----------------------------------------------------------------- */
$boutons = array(array('id' => 'p1', 'name' => 'Caméras', 'tiles' => array(
    array('id' => 't1', 'type' => 'button', 'name' => 'Portier', 'icon' => 'camera', 'confirm' => false,
          'cmds' => array('press' => '#50#', 'state' => 10),
          'options' => array('title' => '', 'message' => '{"camera":"INTERCOM","duration":60}', 'slider' => '40', 'color' => '#ff0000', 'pirate' => 'x', 'select' => array('a'))),
    array('id' => 't2', 'type' => 'button', 'name' => 'Grille', 'icon' => 'camera', 'cmds' => array('press' => 51)),
    array('id' => 't3', 'type' => 'button', 'name' => 'Sans commande', 'options' => 'pas un objet'),
    array('id' => 't4', 'type' => 'switch', 'name' => 'Interrupteur', 'cmds' => array('on' => 11), 'options' => array('message' => 'ignoré')),
)));
$nb = jeetvbeLayout::normalizePages($boutons);
verifie('button : type gardé', $nb[0]['tiles'][0]['type'], 'button');
verifie('icône camera acceptée', $nb[0]['tiles'][0]['icon'], 'camera');
verifie('button : rôles press et state', jeetvbeLayout::roles($nb[0]['tiles'][0]), array('state' => 10, 'press' => 50));
verifie('button : options connues gardées telles quelles, vides retirées',
        (array) $nb[0]['tiles'][0]['options'], array('message' => '{"camera":"INTERCOM","duration":60}', 'slider' => '40', 'color' => '#ff0000'));
verifie('button sans options : objet vide', json_encode($nb[0]['tiles'][1]['options']), '{}');
verifie('button : options invalides → vides', json_encode($nb[0]['tiles'][2]['options']), '{}');
verifie('options absentes des autres types', array_key_exists('options', $nb[0]['tiles'][3]), false);
verifie('button : idempotent', json_encode(jeetvbeLayout::normalizePages(json_decode(json_encode($nb), true))), json_encode($nb));
$rb = jeetvbeLayout::revision($boutons);
$autresOptions = $boutons;
$autresOptions[0]['tiles'][0]['options']['message'] = '{"camera":"NORD"}';
verifie('révision change avec les options', jeetvbeLayout::revision($autresOptions) !== $rb, true);
verifie('signature : options comprises', jeetvbeLayout::signature($nb[0]['tiles'][0]) !== jeetvbeLayout::signature(jeetvbeLayout::normalizeTile($autresOptions[0]['tiles'][0])), true);
$lb = jeetvbeLayout::buildLayout($boutons, $resoudre)['pages'][0]['tiles'];
verifie('layout button : value de state, ni options ni commandes', $lb[0],
        array('id' => 't1', 'type' => 'button', 'name' => 'Portier', 'icon' => 'camera', 'confirm' => false, 'value' => '1', 'unit' => ''));
verifie('layout button sans state : value null', $lb[1]['value'], null);
verifie('layout button : rien de privé dans le JSON', preg_match('/INTERCOM|options|cmds|press/', json_encode($lb)), 0);
verifie('stateMap : état d\'un bouton suivi', jeetvbeLayout::stateMap($boutons)[10], array('t1'));
$bt = jeetvbeLayout::findTile($boutons, 't1');
verifie('press, sous-type message : title et message', jeetvbeLayout::resolveAction($bt, 'press', null, null, null, array('subType' => 'message')),
        array('cmd' => 50, 'action' => 'press', 'options' => array('title' => '', 'message' => '{"camera":"INTERCOM","duration":60}')));
verifie('press, sous-type slider', jeetvbeLayout::resolveAction($bt, 'press', null, null, null, array('subType' => 'slider'))['options'], array('slider' => 40));
verifie('press, sous-type color', jeetvbeLayout::resolveAction($bt, 'press', null, null, null, array('subType' => 'color'))['options'], array('color' => '#ff0000'));
verifie('press, sous-type select absent : rien', jeetvbeLayout::resolveAction($bt, 'press', null, null, null, array('subType' => 'select'))['options'], array());
verifie('press, sous-type other : rien', jeetvbeLayout::resolveAction($bt, 'press', null, null, null, array('subType' => 'other'))['options'], array());
verifie('press, sous-type inconnu : rien', jeetvbeLayout::resolveAction($bt, 'press')['options'], array());
verifie('press sans options, message : vides', jeetvbeLayout::resolveAction(jeetvbeLayout::findTile($boutons, 't2'), 'press', null, null, null, array('subType' => 'message'))['options'],
        array('title' => '', 'message' => ''));
verifie('press sans commande → 422', jeetvbeLayout::resolveAction(jeetvbeLayout::findTile($boutons, 't3'), 'press')['error'], 422);
foreach (array('run', 'on', 'set', 'toggle') as $interdite) {
    verifie('button ' . $interdite . ' → 422', jeetvbeLayout::resolveAction($bt, $interdite, 5)['error'], 422);
}
verifie('press sur scène → 422', jeetvbeLayout::resolveAction($tuile('t6'), 'press')['error'], 422);
verifie('press sur switch → 422', jeetvbeLayout::resolveAction($tuile('t1'), 'press')['error'], 422);
verifie('button : pas de bornes', jeetvbeLayout::bounds($bt), null);

/* --- Tuile select ----------------------------------------------------------------- */
verifie('choices : valeur|Libellé, dans l\'ordre', jeetvbeLayout::parseChoices('auto|Auto;cold|Froid;heat|Chauffage'),
        array(array('value' => 'auto', 'label' => 'Auto'), array('value' => 'cold', 'label' => 'Froid'), array('value' => 'heat', 'label' => 'Chauffage')));
verifie('choices : sans « | », vides, doublons, libellé vide', jeetvbeLayout::parseChoices(' eco ;;|Rien; cold|Froid;cold|Encore;fan|; a|b|c'),
        array(array('value' => 'eco', 'label' => 'eco'), array('value' => 'cold', 'label' => 'Froid'), array('value' => 'fan', 'label' => 'fan'), array('value' => 'a', 'label' => 'b|c')));
verifie('choices : liste absente', array(jeetvbeLayout::parseChoices(''), jeetvbeLayout::parseChoices(null), jeetvbeLayout::parseChoices(array('x'))), array(array(), array(), array()));
$cmdsSelect = $commandes + array(
    60 => array('type' => 'info', 'subType' => 'string', 'value' => 'cold', 'unit' => '', 'minValue' => '', 'maxValue' => ''),
    61 => array('type' => 'action', 'subType' => 'select', 'value' => null, 'unit' => '', 'minValue' => '', 'maxValue' => '',
                'listValue' => 'auto|Auto;cold|Froid;heat|Chauffage'),
);
$resoudreSelect = function ($_id) use (&$cmdsSelect) {
    return isset($cmdsSelect[$_id]) ? $cmdsSelect[$_id] : null;
};
$pagesSelect = array(array('id' => 'p1', 'name' => 'Clim', 'tiles' => array(
    array('id' => 't50', 'type' => 'select', 'name' => 'Salle à manger · Mode clim', 'icon' => 'thermostat', 'cmds' => array('set' => 61, 'state' => 60)),
    array('id' => 't51', 'type' => 'select', 'name' => 'Sans état', 'icon' => 'thermostat', 'cmds' => array('set' => 61)),
    array('id' => 't52', 'type' => 'select', 'name' => 'Sans commande', 'cmds' => array('state' => 60)),
)));
verifie('select : type gardé', jeetvbeLayout::normalizePages($pagesSelect)[0]['tiles'][0]['type'], 'select');
$ls = jeetvbeLayout::buildLayout($pagesSelect, $resoudreSelect);
verifie('layout select : exemple du contrat', $ls['pages'][0]['tiles'][0], array('id' => 't50', 'type' => 'select', 'name' => 'Salle à manger · Mode clim',
        'icon' => 'thermostat', 'confirm' => false, 'value' => 'cold', 'unit' => '',
        'choices' => array(array('value' => 'auto', 'label' => 'Auto'), array('value' => 'cold', 'label' => 'Froid'), array('value' => 'heat', 'label' => 'Chauffage'))));
verifie('layout select sans état : value null', $ls['pages'][0]['tiles'][1]['value'], null);
verifie('layout select sans commande : choices vide', $ls['pages'][0]['tiles'][2]['choices'], array());
verifie('layout select : ni bornes ni commandes', array_key_exists('min', $ls['pages'][0]['tiles'][0]) || strpos(json_encode($ls), '"cmds"') !== false, false);
$rs = $ls['revision'];
verifie('révision du layout select = révision avec lecteur', $rs, jeetvbeLayout::revision($pagesSelect, null, null, null, $resoudreSelect));
$cmdsSelect[61]['listValue'] = 'auto|Auto;cold|Froid;heat|Chauffage;fan|Ventilation';
$ls2 = jeetvbeLayout::buildLayout($pagesSelect, $resoudreSelect);
verifie('select : liste relue à chaque appel', count($ls2['pages'][0]['tiles'][0]['choices']), 4);
verifie('select : liste modifiée → révision changée', $ls2['revision'] !== $rs, true);
verifie('révision sans tuile select inchangée par le lecteur', jeetvbeLayout::revision($pages, null, null, null, $resoudre), jeetvbeLayout::revision($pages));
$ts = jeetvbeLayout::findTile($pagesSelect, 't50');
verifie('select set valide', jeetvbeLayout::resolveAction($ts, 'set', 'heat', null, $cmdsSelect[61]),
        array('cmd' => 61, 'action' => 'set', 'options' => array('select' => 'heat'), 'value' => 'heat'));
verifie('select set absent de la liste → 422', jeetvbeLayout::resolveAction($ts, 'set', 'turbo', null, $cmdsSelect[61])['error'], 422);
verifie('select set libellé au lieu de la valeur → 422', jeetvbeLayout::resolveAction($ts, 'set', 'Froid', null, $cmdsSelect[61])['error'], 422);
verifie('select set sans liste → 422', jeetvbeLayout::resolveAction($ts, 'set', 'heat', null, null)['error'], 422);
verifie('select set sans valeur → 400', jeetvbeLayout::resolveAction($ts, 'set', null, null, $cmdsSelect[61])['error'], 400);
verifie('select set valeur tableau → 400', jeetvbeLayout::resolveAction($ts, 'set', array('heat'), null, $cmdsSelect[61])['error'], 400);
verifie('select sans commande set → 422', jeetvbeLayout::resolveAction(jeetvbeLayout::findTile($pagesSelect, 't52'), 'set', 'heat')['error'], 422);
foreach (array('on', 'toggle', 'press', 'run') as $interdite) {
    verifie('select ' . $interdite . ' → 422', jeetvbeLayout::resolveAction($ts, $interdite)['error'], 422);
}
verifie('stateMap : état d\'une tuile select suivi', jeetvbeLayout::stateMap($pagesSelect)[60], array('t50', 't52'));
$climMode = array('id' => 496, 'name' => 'Climatisation', 'cmds' => array(
    array('id' => 6466, 'type' => 'action', 'subType' => 'other', 'generic' => 'ENERGY_ON'),
    array('id' => 6471, 'type' => 'info', 'subType' => 'string', 'generic' => 'THERMOSTAT_MODE'),
    array('id' => 6472, 'type' => 'action', 'subType' => 'select', 'generic' => 'THERMOSTAT_SET_MODE'),
));
$genMode = array_values(array_filter(jeetvbeLayout::tilesForEqLogic($climMode), function ($_t) { return $_t['type'] === 'select'; }));
verifie('génération : THERMOSTAT_SET_MODE → select « <nom> · Mode »', $genMode, array(array('type' => 'select', 'name' => 'Climatisation · Mode',
        'icon' => 'thermostat', 'cmds' => array('set' => 6472, 'state' => 6471), 'group' => 'heating', 'confirm' => false)));
$sansEtatMode = array('id' => 1, 'name' => 'T', 'cmds' => array(array('id' => 5, 'type' => 'action', 'subType' => 'select', 'generic' => 'THERMOSTAT_SET_MODE')));
verifie('génération : mode sans état', jeetvbeLayout::tilesForEqLogic($sansEtatMode)[0]['cmds'], array('set' => 5));
$modeNonListe = array('id' => 1, 'name' => 'T', 'cmds' => array(array('id' => 5, 'type' => 'action', 'subType' => 'other', 'generic' => 'THERMOSTAT_SET_MODE')));
verifie('génération : mode qui n\'est pas une liste ignoré', jeetvbeLayout::tilesForEqLogic($modeNonListe), array());
$genPages = jeetvbeLayout::generatePages(array(array('name' => 'Salle à manger', 'eqLogics' => array($climMode))));
verifie('génération par type : le mode va dans « Chauffage et clim »', array($genPages[0]['name'], $genPages[0]['tiles'][1]['type'], $genPages[0]['tiles'][1]['name']),
        array('Chauffage et clim', 'select', 'Salle à manger · Climatisation · Mode'));

/* --- Durée des messages ----------------------------------------------------------- */
verifie('[durée=20] lu et retiré', jeetvbeLayout::extractImage('Info', 'Lave-linge terminé [durée=20]'),
        array('title' => 'Info', 'message' => 'Lave-linge terminé', 'path' => null, 'duration' => 20));
verifie('[durée=…] dans le titre, avec [image=…]', jeetvbeLayout::extractImage('[durée=45] Portier', 'On sonne [image=/a/p.jpg]'),
        array('title' => 'Portier', 'message' => 'On sonne', 'path' => '/a/p.jpg', 'duration' => 45));
verifie('[durée=…] ramené à 3 au moins', jeetvbeLayout::extractImage('', 'M [durée=1]')['duration'], 3);
verifie('[durée=…] ramené à 120 au plus', jeetvbeLayout::extractImage('', 'M [durée=600]')['duration'], 120);
verifie('[duree=…] sans accent, casse ignorée', jeetvbeLayout::extractImage('', 'M [DUREE = 12]')['duration'], 12);
verifie('[durée=7,5] arrondi', jeetvbeLayout::extractImage('', 'M [durée=7,5]')['duration'], 8);
verifie('[durée=abc] retiré, sans durée', jeetvbeLayout::extractImage('', 'M [durée=abc]'), array('title' => '', 'message' => 'M', 'path' => null, 'duration' => null));
verifie('le titre passe avant le message', jeetvbeLayout::extractImage('T [durée=10]', 'M [durée=30]')['duration'], 10);

/* --- Bandeau d'infos ---------------------------------------------------------------- */
foreach (array('sun', 'rain', 'trash', 'power') as $icone) {
    verifie('icône ' . $icone . ' acceptée (tuile)', jeetvbeLayout::normalizeTile(array('icon' => $icone))['icon'], $icone);
}
$bandeau = array(
    array('id' => 'h2', 'cmd' => 40, 'label' => 'Extérieur', 'icon' => 'temperature'),
    array('cmd' => '#30#', 'label' => 'Un libellé beaucoup trop long pour le bandeau', 'icon' => 'licorne'),
    array('id' => 'h2', 'cmd' => 20, 'label' => "Doublon\n", 'icon' => 'sun'),
    array('id' => 'x9', 'cmd' => 'pas une commande', 'label' => 'Sans commande'),
    'pas un élément',
    array('id' => 't1', 'cmd' => 999, 'label' => 'Disparue', 'icon' => 'trash'),
);
$hb = jeetvbeLayout::normalizeHeader($bandeau);
verifie('bandeau : éléments sans commande retirés', count($hb), 4);
verifie('bandeau : id conservé', $hb[0], array('id' => 'h2', 'cmd' => 40, 'label' => 'Extérieur', 'icon' => 'temperature'));
verifie('bandeau : libellé 24 caractères, icône inconnue → generic, id attribué',
        $hb[1], array('id' => 'h3', 'cmd' => 30, 'label' => 'Un libellé beaucoup trop', 'icon' => 'generic'));
verifie('bandeau : id en double et id de forme autre renumérotés', array($hb[2]['id'], $hb[3]['id']), array('h4', 'h5'));
verifie('bandeau : libellé nettoyé', $hb[2]['label'], 'Doublon');
verifie('bandeau : idempotent', jeetvbeLayout::normalizeHeader($hb), $hb);
verifie('bandeau : JSON accepté', jeetvbeLayout::normalizeHeader(json_encode($bandeau)), $hb);
verifie('bandeau : invalide → vide', array(jeetvbeLayout::normalizeHeader(null), jeetvbeLayout::normalizeHeader('x')), array(array(), array()));
$sept = array();
for ($i = 1; $i <= 7; $i++) {
    $sept[] = array('cmd' => $i, 'label' => 'L' . $i);
}
verifie('bandeau : 6 éléments au plus', count(jeetvbeLayout::normalizeHeader($sept)), 6);
verifie('bandeau : numéro d\'une ligne supprimée jamais réattribué (plancher)',
        jeetvbeLayout::normalizeHeader(array(array('id' => 'h1', 'cmd' => 40), array('cmd' => 30)), null, 3)[1]['id'], 'h4');
verifie('bandeau : numéro d\'une ligne supprimée jamais réattribué (précédent)',
        jeetvbeLayout::normalizeHeader(array(array('cmd' => 30)), array(array('id' => 'h1', 'cmd' => 40), array('id' => 'h2', 'cmd' => 20)))[0]['id'], 'h3');
verifie('bandeau : plus grand numéro', jeetvbeLayout::maxHeaderNumber($hb), 5);
$lh = jeetvbeLayout::buildLayout($pages, $resoudre, null, null, $bandeau);
verifie('layout : header, value et unit comme une info, commande disparue retirée', $lh['header'], array(
    array('id' => 'h2', 'label' => 'Extérieur', 'icon' => 'temperature', 'value' => '24', 'unit' => '°C'),
    array('id' => 'h3', 'label' => 'Un libellé beaucoup trop', 'icon' => 'generic', 'value' => '20.5', 'unit' => '°C'),
    array('id' => 'h4', 'label' => 'Doublon', 'icon' => 'sun', 'value' => '100', 'unit' => '%'),
));
verifie('layout : header après keys, avant pages', array_keys(jeetvbeLayout::buildLayout($pages, $resoudre, null, array('red' => 'p1'), $bandeau)),
        array('schema', 'revision', 'keys', 'header', 'pages'));
verifie('layout : header d\'une commande action → value null', jeetvbeLayout::buildHeader(array(array('cmd' => 11)), $resoudre)[0]['value'], null);
verifie('layout sans bandeau : header omis', array_key_exists('header', jeetvbeLayout::buildLayout($pages, $resoudre, null, null, array())), false);
verifie('layout : bandeau dont toutes les commandes ont disparu → header omis',
        array_key_exists('header', jeetvbeLayout::buildLayout($pages, $resoudre, null, null, array(array('cmd' => 999)))), false);
verifie('révision inchangée sans bandeau', jeetvbeLayout::revision($pages, null, null, array()), jeetvbeLayout::revision($pages));
verifie('révision change avec le bandeau', jeetvbeLayout::revision($pages, null, null, $bandeau) !== jeetvbeLayout::revision($pages), true);
$bandeau2 = $bandeau;
$bandeau2[0]['icon'] = 'sun';
verifie('révision change avec une icône du bandeau', jeetvbeLayout::revision($pages, null, null, $bandeau2) !== jeetvbeLayout::revision($pages, null, null, $bandeau), true);
verifie('révision du layout avec bandeau', $lh['revision'], jeetvbeLayout::revision($pages, null, null, $bandeau));
$carteH = jeetvbeLayout::stateMap($pages, $bandeau);
verifie('stateMap : commandes du bandeau suivies', array($carteH[40], $carteH[30], $carteH[20], $carteH[999]), array(array('t5', 'h2'), array('t4', 'h3'), array('t2', 'h4'), array('h5')));
verifie('changes : élément du bandeau livré, fusion habituelle',
        jeetvbeLayout::mergeChanges(array(array('cmd_id' => 40, 'value' => 17), array('cmd_id' => 999, 'value' => 'demain'), array('cmd_id' => 40, 'value' => 18)), $carteH),
        array(array('tile' => 't5', 'value' => '18'), array('tile' => 'h2', 'value' => '18'), array('tile' => 'h5', 'value' => 'demain')));
verifie('exec : un id de bandeau n\'est pas une tuile', jeetvbeLayout::findTile($pages, 'h2'), null);

/* --- Touches de couleur ------------------------------------------------------------ */
verifie('keys : couleurs connues, ids valides, ordre rouge vert jaune bleu',
        jeetvbeLayout::normalizeKeys(array('blue' => 'p2', 'red' => 'p1', 'violet' => 'p1', 'green' => '', 'yellow' => '../x')),
        array('red' => 'p1', 'blue' => 'p2'));
verifie('keys : JSON et objet acceptés', array(jeetvbeLayout::normalizeKeys('{"green":"scenes"}'), jeetvbeLayout::normalizeKeys((object) array('red' => 'p1'))),
        array(array('green' => 'scenes'), array('red' => 'p1')));
verifie('keys : invalide → vide', array(jeetvbeLayout::normalizeKeys(null), jeetvbeLayout::normalizeKeys('x'), jeetvbeLayout::normalizeKeys(array())), array(array(), array(), array()));
$scenes = jeetvbeLayout::scenesPage($pages, array(array('id' => 3, 'name' => 'Cinéma', 'isActive' => true)));
verifie('keys : page supprimée retirée sans erreur, scenes gardée',
        jeetvbeLayout::layoutKeys(array('red' => 'p1', 'green' => 'scenes', 'yellow' => 'p9'), $pages, $scenes), array('red' => 'p1', 'green' => 'scenes'));
verifie('keys : scenes sans page dynamique retirée', jeetvbeLayout::layoutKeys(array('green' => 'scenes'), $pages, null), array());
$lk = jeetvbeLayout::buildLayout($pages, $resoudre, $scenes, array('blue' => 'scenes', 'red' => 'p1', 'yellow' => 'p9'));
verifie('layout : keys servies', $lk['keys'], array('red' => 'p1', 'blue' => 'scenes'));
verifie('layout : ordre des champs', array_keys($lk), array('schema', 'revision', 'keys', 'pages'));
verifie('layout sans touche : keys omis', array_key_exists('keys', jeetvbeLayout::buildLayout($pages, $resoudre, $scenes, array('red' => 'p9'))), false);
verifie('layout sans configuration de touches : keys omis', array_key_exists('keys', jeetvbeLayout::buildLayout($pages, $resoudre)), false);
verifie('révision inchangée sans touche active', jeetvbeLayout::revision($pages, null, array('red' => 'p9')), jeetvbeLayout::revision($pages));
verifie('révision change avec les touches', jeetvbeLayout::revision($pages, null, array('red' => 'p1')) !== jeetvbeLayout::revision($pages), true);
verifie('révision : couleur différente, révision différente',
        jeetvbeLayout::revision($pages, null, array('red' => 'p1')) !== jeetvbeLayout::revision($pages, null, array('blue' => 'p1')), true);
verifie('révision du layout avec touches', $lk['revision'], jeetvbeLayout::revision($pages, $scenes, array('red' => 'p1', 'blue' => 'scenes')));

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
verifie('commandes fixes', array_keys(jeetvbeLayout::FIXED_COMMANDS), array('show_page', 'notify', 'exit', 'ask', 'notify_json', 'fixed_json', 'dismiss', 'fixed_remove', 'online', 'visible', 'screen', 'page', 'appVersion'));
verifie('commandes TvOverlay : noms et types', array_map(function ($_d) { return $_d['name'] . ' ' . $_d['type'] . '/' . $_d['subType']; },
        array_intersect_key(jeetvbeLayout::FIXED_COMMANDS, array_flip(array('notify_json', 'fixed_json', 'dismiss', 'fixed_remove')))),
        array('notify_json' => 'Notifier (JSON) action/message', 'fixed_json' => 'Indicateur (JSON) action/message',
              'dismiss' => 'Retirer une notification action/message', 'fixed_remove' => 'Retirer un indicateur action/message'));
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


/* --- Images jointes : extraction ------------------------------------------------------- */
$x = jeetvbeLayout::extractImage('On sonne', 'Ouvrir le portail ? [image=/var/www/html/plugins/dahuavtobe/data/snapshots/vto79.jpg]');
verifie('[image=…] dans le message : chemin et texte nettoyé', $x,
        array('title' => 'On sonne', 'message' => 'Ouvrir le portail ?', 'path' => '/var/www/html/plugins/dahuavtobe/data/snapshots/vto79.jpg', 'duration' => null));
$x = jeetvbeLayout::extractImage('[image=/a/titre.jpg] Sonnette', 'Q ? [image=/a/message.jpg]');
verifie('[image=…] : le titre passe avant le message, tous les marqueurs retirés', $x, array('title' => 'Sonnette', 'message' => 'Q ?', 'path' => '/a/titre.jpg', 'duration' => null));
verifie('[image=…] prime sur files', jeetvbeLayout::extractImage('', 'Q [image=/a/x.png]', array('/b/y.jpg'))['path'], '/a/x.png');
verifie('files : premier fichier image', jeetvbeLayout::extractImage('T', 'M', array('/r/rapport.pdf', '/r/capture.PNG', '/r/autre.jpg'))['path'], '/r/capture.PNG');
verifie('files en chaîne', jeetvbeLayout::extractImage('T', 'M', '/r/a.txt, /r/b.jpeg')['path'], '/r/b.jpeg');
verifie('files sans image', jeetvbeLayout::extractImage('T', 'M', array('/r/a.pdf'))['path'], null);
$x = jeetvbeLayout::extractImage('title=Sonnette | files=/s/a.mp4,/s/b.jpg', 'Quelqu\'un sonne');
verifie('title=… | files=… : titre et premier fichier image', $x, array('title' => 'Sonnette', 'message' => 'Quelqu\'un sonne', 'path' => '/s/b.jpg', 'duration' => null));
verifie('title=… | files=… : files des options prioritaire', jeetvbeLayout::extractImage('title=T | files=/s/b.jpg', 'M', array('/o/a.jpg'))['path'], '/o/a.jpg');
verifie('title=… sans files', jeetvbeLayout::extractImage('title=Seulement le titre', 'M'), array('title' => 'Seulement le titre', 'message' => 'M', 'path' => null, 'duration' => null));
verifie('aucune image', jeetvbeLayout::extractImage('Titre', 'Message'), array('title' => 'Titre', 'message' => 'Message', 'path' => null, 'duration' => null));
verifie('[image=] vide ignoré', jeetvbeLayout::extractImage('', 'Q [image=]', array('/a/x.jpg')), array('title' => '', 'message' => 'Q', 'path' => '/a/x.jpg', 'duration' => null));
verifie('options non chaînes', jeetvbeLayout::extractImage(array('x'), null), array('title' => '', 'message' => '', 'path' => null, 'duration' => null));

/* --- Images jointes : validation, magasin, purge (fichiers réels temporaires) ----------- */
$base = sys_get_temp_dir() . '/jeetvbe-essai-' . bin2hex(random_bytes(4));
mkdir($base . '/racine/sous', 0777, true);
mkdir($base . '/dehors', 0777, true);
$jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00" . str_repeat("\x00", 200) . "\xFF\xD9";
$png = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0DIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1F\x15\xC4\x89" . str_repeat("\x00", 64);
file_put_contents($base . '/racine/sous/photo.jpg', $jpeg);
file_put_contents($base . '/racine/image.png', $png);
file_put_contents($base . '/racine/faux.jpg', "<?php echo 'pas une image';");
file_put_contents($base . '/dehors/secret.jpg', $jpeg);
@symlink($base . '/dehors/secret.jpg', $base . '/racine/lien.jpg');
$racines = array($base . '/racine');
$v = jeetvbeLayout::validateImage($base . '/racine/sous/photo.jpg', $racines);
verifie('JPEG accepté', array($v['ok'], $v['mime'], $v['ext']), array(true, 'image/jpeg', 'jpg'));
verifie('PNG accepté', jeetvbeLayout::validateImage($base . '/racine/image.png', $racines)['mime'], 'image/png');
verifie('« ../ » vers l\'extérieur refusé', jeetvbeLayout::validateImage($base . '/racine/sous/../../dehors/secret.jpg', $racines)['ok'], false);
verifie('« ../ » resté dedans accepté', jeetvbeLayout::validateImage($base . '/racine/sous/../image.png', $racines)['ok'], true);
verifie('hors racine refusé', jeetvbeLayout::validateImage($base . '/dehors/secret.jpg', $racines)['ok'], false);
verifie('lien symbolique vers l\'extérieur refusé', jeetvbeLayout::validateImage($base . '/racine/lien.jpg', $racines)['ok'], false);
verifie('préfixe de racine trompeur refusé', jeetvbeLayout::validateImage($base . '/racine/sous/photo.jpg', array($base . '/racine/so'))['ok'], false);
verifie('type non image refusé (extension .jpg)', jeetvbeLayout::validateImage($base . '/racine/faux.jpg', $racines)['ok'], false);
verifie('taille au-delà du plafond refusée', jeetvbeLayout::validateImage($base . '/racine/sous/photo.jpg', $racines, 100)['ok'], false);
verifie('fichier absent refusé', jeetvbeLayout::validateImage($base . '/racine/absent.jpg', $racines)['ok'], false);
verifie('dossier refusé', jeetvbeLayout::validateImage($base . '/racine/sous', $racines)['ok'], false);
verifie('chemin vide refusé', jeetvbeLayout::validateImage('', $racines)['ok'], false);
verifie('expiration : au plus tôt 5 min', jeetvbeLayout::imageExpiry(1000, 60), 1300);
verifie('expiration : celle de l\'ordre si plus longue', jeetvbeLayout::imageExpiry(1000, 600), 1600);
$magasin = $base . '/images/579';
$id1 = str_repeat('a', 32);
$id2 = str_repeat('b', 32);
verifie('copie de l\'image', jeetvbeLayout::storeImage($magasin, $v, 2000, $id1), $id1);
verifie('copie identique', sha1_file($magasin . '/' . $id1 . '.jpg'), sha1_file($base . '/racine/sous/photo.jpg'));
jeetvbeLayout::storeImage($magasin, jeetvbeLayout::validateImage($base . '/racine/image.png', $racines), 1500, $id2);
verifie('identifiant invalide refusé à la copie', jeetvbeLayout::storeImage($magasin, $v, 2000, '../x'), null);
verifie('image trouvée avant expiration', jeetvbeLayout::findImage($magasin, $id1, 1999), array('path' => $magasin . '/' . $id1 . '.jpg', 'mime' => 'image/jpeg'));
verifie('image expirée introuvable', jeetvbeLayout::findImage($magasin, $id1, 2000), null);
verifie('identifiant mal formé introuvable', jeetvbeLayout::findImage($magasin, '../' . $id1, 1000), null);
verifie('identifiant d\'une autre TV introuvable', jeetvbeLayout::findImage($base . '/images/573', $id1, 1000), null);
verifie('purge : seules les images expirées partent', array(jeetvbeLayout::purgeImages($magasin, 1600), is_file($magasin . '/' . $id1 . '.jpg'), is_file($magasin . '/' . $id2 . '.png')), array(2, true, false));
verifie('purge complète', array(jeetvbeLayout::purgeImages($magasin, 3000), count(glob($magasin . '/*'))), array(2, 0));
foreach (array('/racine/sous/photo.jpg', '/racine/image.png', '/racine/faux.jpg', '/racine/lien.jpg', '/dehors/secret.jpg') as $f) {
    @unlink($base . $f);
}
@rmdir($magasin); @rmdir($base . '/images'); @rmdir($base . '/racine/sous'); @rmdir($base . '/racine'); @rmdir($base . '/dehors'); @rmdir($base);
verifie('fichiers d\'essai nettoyés', is_dir($base), false);

/* --- 0.9.1 : purge d'images concurrente ------------------------------------------- */
$base = sys_get_temp_dir() . '/jeetvbe-purge-' . getmypid();
@mkdir($base, 0775, true);
$orphelin = str_repeat('a', 32);
file_put_contents($base . '/' . $orphelin . '.jpg', 'x');
verifie('purge : image en cours de copie (sans .json) gardée', array(jeetvbeLayout::purgeImages($base, time()), is_file($base . '/' . $orphelin . '.jpg')), array(0, true));
verifie('purge : orphelin ancien supprimé', array(jeetvbeLayout::purgeImages($base, time(), time() + 120), is_file($base . '/' . $orphelin . '.jpg')), array(1, false));
file_put_contents($base . '/' . $orphelin . '.jpg', 'x');
verifie('purge : TV supprimée, tout part', array(jeetvbeLayout::purgeImages($base, PHP_INT_MAX), count(glob($base . '/*'))), array(1, 0));
$expire = str_repeat('b', 32);
file_put_contents($base . '/' . $expire . '.jpg', 'x');
file_put_contents($base . '/' . $expire . '.json', json_encode(array('mime' => 'image/jpeg', 'ext' => 'jpg', 'expires' => 100)));
verifie('purge : image expirée supprimée sans délai de grâce', array(jeetvbeLayout::purgeImages($base, 200), count(glob($base . '/*'))), array(2, 0));
@rmdir($base);

/* --- 0.9.1 : commandes « Afficher » ------------------------------------------------- */
verifie('commandes : noms égaux aux accents près suffixés (collation de la table cmd)',
    jeetvbeLayout::pageCommands(array(array('id' => 'p1', 'name' => 'Écran'), array('id' => 'p2', 'name' => 'Ecran'), array('id' => 'p3', 'name' => 'MESSAGE'))),
    array('show_p1' => 'Afficher Écran', 'show_p2' => 'Afficher Ecran (p2)', 'show_p3' => 'Afficher MESSAGE'));
verifie('commandes : la page Scénarios garde sa commande sans scénario actif',
    jeetvbeLayout::pageCommands(jeetvbeLayout::allPages(array(array('id' => 'p1', 'name' => 'Lumières')), null, true)),
    array('show_p1' => 'Afficher Lumières', 'show_scenes' => 'Afficher Scénarios'));
verifie('commandes : nom « Ambiances » gardé de même',
    jeetvbeLayout::allPages(array(array('id' => 'p1', 'name' => 'Scénarios')), null, true)[1]['name'], 'Ambiances');
verifie('commandes : sans groupe, pas de page Scénarios', count(jeetvbeLayout::allPages(array(array('id' => 'p1', 'name' => 'A')), null, false)), 1);

/* --- 0.9.1 : clé recopiée par « Dupliquer » ------------------------------------------ */
$cle = str_repeat('c', 32);
verifie('clé : copie neuve d\'une TV → nouvelle clé', jeetvbeLayout::tokenClash($cle, '', array(573 => $cle)), true);
verifie('clé : la plus récente des deux change', jeetvbeLayout::tokenClash($cle, 600, array(573 => $cle, 600 => $cle)), true);
verifie('clé : la plus ancienne garde la sienne', jeetvbeLayout::tokenClash($cle, 573, array(573 => $cle, 600 => $cle)), false);
verifie('clé : unique, gardée', jeetvbeLayout::tokenClash($cle, 573, array(573 => $cle, 579 => str_repeat('d', 32))), false);

/* --- Barre d'état : réglages ------------------------------------------------------- */
verifie('barre : défauts (désactivée, bas gauche, horloge, 85 %)', jeetvbeOverlay::normalizeBar(null),
        array('enabled' => 0, 'corner' => 'bottom_start', 'clock' => 1, 'opacity' => 85));
verifie('barre : valeurs lues et bornées', jeetvbeOverlay::normalizeBar(array('enabled' => '1', 'corner' => 'top_end', 'clock' => '0', 'opacity' => '140')),
        array('enabled' => 1, 'corner' => 'top_end', 'clock' => 0, 'opacity' => 100));
verifie('barre : coin inconnu, opacité négative', jeetvbeOverlay::normalizeBar('{"corner":"milieu","opacity":-5,"enabled":true}'),
        array('enabled' => 1, 'corner' => 'bottom_start', 'clock' => 1, 'opacity' => 0));

/* --- Barre d'état : outils ----------------------------------------------------------- */
verifie('durées', array(jeetvbeOverlay::seconds('90'), jeetvbeOverlay::seconds('1y2w3d4h5m6s'), jeetvbeOverlay::seconds('30m'), jeetvbeOverlay::seconds('12H'),
        jeetvbeOverlay::seconds(''), jeetvbeOverlay::seconds('0'), jeetvbeOverlay::seconds('demain'), jeetvbeOverlay::seconds(true)),
        array(90, 31536000 + 2 * 604800 + 3 * 86400 + 4 * 3600 + 5 * 60 + 6, 1800, 43200, null, null, null, null));
verifie('expiration : durée, secondes, epoch, absente, illisible', array(jeetvbeOverlay::expiresAt('30m', 1000), jeetvbeOverlay::expiresAt(90, 1000),
        jeetvbeOverlay::expiresAt('1791400000', 1000), jeetvbeOverlay::expiresAt(null, 1000), jeetvbeOverlay::expiresAt('', 1000),
        jeetvbeOverlay::expiresAt('bientôt', 1000), jeetvbeOverlay::expiresAt(array(), 1000)),
        array(2800, 1090, 1791400000, null, null, false, false));
verifie('couleurs', array(jeetvbeOverlay::color('#ff9800', 'x'), jeetvbeOverlay::color('#66000000', 'x'), jeetvbeOverlay::color('#fff', 'x'),
        jeetvbeOverlay::color('rouge', 'x'), jeetvbeOverlay::color('', '#FFFFFF')), array('#FF9800', '#66000000', '#FFFFFF', 'x', '#FFFFFF'));
verifie('icônes mdi', array(jeetvbeOverlay::mdiIcon('mdi:weather-rainy'), jeetvbeOverlay::mdiIcon('weather-rainy'), jeetvbeOverlay::mdiIcon('MDI:Lightbulb'),
        jeetvbeOverlay::mdiIcon('http://x/y.png'), jeetvbeOverlay::mdiIcon('mdi:'), jeetvbeOverlay::mdiIcon(null)),
        array('mdi:weather-rainy', 'mdi:weather-rainy', 'mdi:lightbulb', '', '', ''));
verifie('cmdId', array(jeetvbeOverlay::cmdId('#123#'), jeetvbeOverlay::cmdId('45'), jeetvbeOverlay::cmdId('#[Salon][Lampe][Etat]#'), jeetvbeOverlay::cmdId(array())), array(123, 45, 0, 0));
verifie('compare : nombres', array(jeetvbeOverlay::compare('1.0', '==', '1'), jeetvbeOverlay::compare('9', '<', '10'), jeetvbeOverlay::compare(21.6, '>=', '21.6')), array(true, true, true));
verifie('compare : texte sans casse, autres opérateurs faux', array(jeetvbeOverlay::compare('ON', '==', 'on'), jeetvbeOverlay::compare('abc', '>', '3'),
        jeetvbeOverlay::compare('Armé', '!=', 'Mode nuit')), array(true, false, true));
verifie('compare : valeur absente toujours fausse, même avec !=', array(jeetvbeOverlay::compare(null, '!=', '1'), jeetvbeOverlay::compare('', '!=', '1')), array(false, false));
verifie('formatText', array(jeetvbeOverlay::formatText('21.6', '0', '°'), jeetvbeOverlay::formatText('-0.4', '0', '°'), jeetvbeOverlay::formatText('17.25', '1', ' °C'),
        jeetvbeOverlay::formatText('17.25', '1', '', '.'), jeetvbeOverlay::formatText(null, '0', '°'), jeetvbeOverlay::formatText('abc', '0', '!')),
        array('22°', '0°', '17,3 °C', '17.3', '', 'abc!'));

/* --- Barre d'état : indicateurs automatiques (modèle auto_fixed) --------------------- */
$valeurs = array(1361 => '17.6', 6930 => 'weather-rainy', 1572 => '0', 1666 => '1', 1625 => null, 6907 => 'Armé', 1709 => '1', 50 => 'http://pas/une/icone.png');
$lire = function ($_id) use (&$valeurs) {
    return array_key_exists($_id, $valeurs) ? $valeurs[$_id] : null;
};
$meteo = array('enable' => 1, 'id' => 'meteo', 'name' => 'Météo', 'visibility' => 'always', 'text_mode' => 'cmd', 'text_cmd' => '#1361#',
               'decimals' => '0', 'suffix' => '°', 'icon_mode' => 'cmd', 'icon_cmd' => '#6930#', 'icon' => 'mdi:weather-cloudy', 'shape' => 'circle', 'expiration' => '12h');
$lampe = array('enable' => 1, 'id' => 'lampe', 'visibility' => 'conditions', 'combine' => 'any',
               'conditions' => array(array('cmd' => '#1572#', 'operator' => '==', 'value' => '1'), array('cmd' => '#1666#', 'operator' => '==', 'value' => '1'),
                                     array('cmd' => '#1625#', 'operator' => '==', 'value' => '1')),
               'text_mode' => 'none', 'icon_mode' => 'fixed', 'icon' => 'mdi:lightbulb', 'iconColor' => '#ff9800', 'borderColor' => '#ff9800', 'shape' => 'circle');
$alarme = array('id' => 'alarme_armee', 'visibility' => 'conditions', 'combine' => 'all', 'conditions' => array(array('cmd' => '#6907#', 'operator' => '==', 'value' => 'Armé')),
                'icon' => 'mdi:shield-lock', 'iconColor' => '#ef5350');
$porte = array('id' => 'porte', 'visibility' => 'conditions', 'combine' => 'all', 'conditions' => array(array('cmd' => '#1709#', 'operator' => '==', 'value' => '0')),
               'icon' => 'mdi:lock-open-variant');
$indicateurs = array($meteo, $lampe, $alarme, $porte);
verifie('indicateur : forme complète (défauts tvoverlaybe)', jeetvbeOverlay::normalizeIndicator(array('id' => 'x')), array(
    'enable' => 1, 'id' => 'x', 'name' => '', 'visibility' => 'always', 'combine' => 'any', 'conditions' => array(), 'text_mode' => 'none', 'text' => '',
    'text_cmd' => '', 'decimals' => '', 'suffix' => '', 'icon_mode' => 'fixed', 'icon' => '', 'icon_cmd' => '', 'shape' => '', 'expiration' => '12h',
    'iconColor' => '', 'messageColor' => '', 'borderColor' => '', 'backgroundColor' => ''));
verifie('indicateur : idempotent', jeetvbeOverlay::normalizeIndicators(jeetvbeOverlay::normalizeIndicators($indicateurs)), jeetvbeOverlay::normalizeIndicators($indicateurs));
verifie('indicateur : liste en JSON', count(jeetvbeOverlay::normalizeIndicators(json_encode($indicateurs))), 4);
verifie('indicateur : opérateur inconnu → ==', jeetvbeOverlay::normalizeIndicator(array('conditions' => array(array('cmd' => '#1#', 'operator' => '~'))))['conditions'][0]['operator'], '==');
verifie('indicateur : décimales bornées à 6', jeetvbeOverlay::normalizeIndicator(array('decimals' => '9'))['decimals'], '6');
verifie('erreurs : aucune pour une liste correcte', jeetvbeOverlay::indicatorErrors($indicateurs), array());
verifie('erreurs : id manquant, doublon, conditions, commandes', count(jeetvbeOverlay::indicatorErrors(array(
    array('name' => 'Sans id'), array('id' => 'a'), array('id' => 'a'), array('id' => 'b', 'visibility' => 'conditions'),
    array('id' => 'c', 'text_mode' => 'cmd'), array('id' => 'd', 'icon_mode' => 'cmd')))), 5);
verifie('commandes écoutées (indicateurs actifs, champs utilisés)', jeetvbeOverlay::cmdIds(array_merge($indicateurs,
        array(array('enable' => 0, 'id' => 'off', 'text_mode' => 'cmd', 'text_cmd' => '#999#'), array('id' => 'fixe', 'text_mode' => 'fixed', 'text_cmd' => '#888#')))),
        array(1361, 1572, 1625, 1666, 1709, 6907, 6930));
verifie('météo : texte arrondi + suffixe, icône de la commande complétée en mdi:', jeetvbeOverlay::indicatorItem($meteo, $lire),
        array('id' => 'meteo', 'icon' => 'mdi:weather-rainy', 'text' => '18°', 'iconColor' => '#FFFFFF', 'textColor' => '#FFFFFF',
              'borderColor' => '#00000000', 'backgroundColor' => '#00000000', 'shape' => 'circle'));
$valeurs[6930] = null;
verifie('météo : icône fixe en repli tant que la commande n\'a rien publié', jeetvbeOverlay::indicatorItem($meteo, $lire)['icon'], 'mdi:weather-cloudy');
$valeurs[6930] = 'http://pas/une/icone.png';
verifie('météo : une adresse n\'est pas une icône, repli', jeetvbeOverlay::indicatorItem($meteo, $lire)['icon'], 'mdi:weather-cloudy');
verifie('lampe : OU, une lampe allumée suffit, couleurs reprises', jeetvbeOverlay::indicatorItem($lampe, $lire),
        array('id' => 'lampe', 'icon' => 'mdi:lightbulb', 'text' => '', 'iconColor' => '#FF9800', 'textColor' => '#FFFFFF',
              'borderColor' => '#FF9800', 'backgroundColor' => '#00000000', 'shape' => 'circle'));
$valeurs[1666] = '0';
verifie('lampe : toutes éteintes (une sans valeur) → cachée', jeetvbeOverlay::indicatorItem($lampe, $lire), null);
verifie('alarme : ET, texte égal sans casse', jeetvbeOverlay::indicatorItem($alarme, $lire)['icon'], 'mdi:shield-lock');
verifie('porte : condition fausse → cachée', jeetvbeOverlay::indicatorItem($porte, $lire), null);
verifie('« Visible si » sans condition → jamais visible', jeetvbeOverlay::indicatorItem(array('id' => 'v', 'visibility' => 'conditions'), $lire), null);
verifie('ET sans aucune condition vraie', jeetvbeOverlay::isVisible(array('visibility' => 'conditions', 'combine' => 'all',
        'conditions' => array(array('cmd' => '#6907#', 'value' => 'Armé'), array('cmd' => '#1709#', 'value' => '0'))), $lire), false);
verifie('texte fixe, couleur du texte (messageColor), fond', jeetvbeOverlay::indicatorItem(array('id' => 'f', 'text_mode' => 'fixed', 'text' => 'Salon',
        'messageColor' => '#00ff00', 'backgroundColor' => '#66000000', 'shape' => 'rounded'), $lire),
        array('id' => 'f', 'icon' => 'mdi:information-outline', 'text' => 'Salon', 'iconColor' => '#FFFFFF', 'textColor' => '#00FF00',
              'borderColor' => '#00000000', 'backgroundColor' => '#66000000', 'shape' => 'rounded'));

/* --- Barre d'état : temporaires, retraits, barre complète ----------------------------- */
$t = jeetvbeOverlay::temporaryFromJson(array('id' => 'lessive', 'icon' => 'mdi:washing-machine', 'message' => 'Fini', 'iconColor' => '#2196f3',
        'messageColor' => '#ffffff', 'shape' => 'rounded', 'expiration' => '30m'), 1000);
verifie('Indicateur (JSON) → temporaire', $t, array('id' => 'lessive', 'remove' => false, 'expires' => 2800, 'item' => array('id' => 'lessive',
        'icon' => 'mdi:washing-machine', 'text' => 'Fini', 'iconColor' => '#2196F3', 'textColor' => '#FFFFFF', 'borderColor' => '#00000000',
        'backgroundColor' => '#00000000', 'shape' => 'rounded')));
verifie('Indicateur (JSON) : sans id → erreur', isset(jeetvbeOverlay::temporaryFromJson(array('icon' => 'mdi:x'), 1)['error']), true);
verifie('Indicateur (JSON) : expiration illisible → erreur', isset(jeetvbeOverlay::temporaryFromJson(array('id' => 'x', 'expiration' => 'bientôt'), 1)['error']), true);
verifie('Indicateur (JSON) : visible:false (booléen ou texte) → retrait', array(jeetvbeOverlay::temporaryFromJson(array('id' => 'x', 'visible' => false), 1),
        jeetvbeOverlay::temporaryFromJson(array('id' => 'x', 'visible' => 'false'), 1)['remove']), array(array('id' => 'x', 'remove' => true), true));
verifie('Indicateur (JSON) : pas d\'expiration → permanent', jeetvbeOverlay::temporaryFromJson(array('id' => 'x'), 1)['expires'], null);
verifie('Indicateur (JSON) : pas un objet → erreur', isset(jeetvbeOverlay::temporaryFromJson('x', 1)['error']), true);
$temp = jeetvbeOverlay::temporaryPut(array(), $t, 1000);
$temp = jeetvbeOverlay::temporaryPut($temp, jeetvbeOverlay::temporaryFromJson(array('id' => 'colis', 'icon' => 'mdi:package'), 1001), 1001);
$temp = jeetvbeOverlay::temporaryPut($temp, jeetvbeOverlay::temporaryFromJson(array('id' => 'lessive', 'icon' => 'mdi:washing-machine', 'message' => 'Encore', 'expiration' => 60), 1002), 1002);
verifie('temporaires : remplacé sur place, ordre d\'arrivée gardé', array(array_keys($temp), $temp['lessive']['item']['text'], $temp['lessive']['expires']),
        array(array('lessive', 'colis'), 'Encore', 1062));
verifie('temporaires : prochaine expiration', array(jeetvbeOverlay::nextExpiry($temp), jeetvbeOverlay::nextExpiry(array('x' => array('expires' => null))), jeetvbeOverlay::nextExpiry(null)), array(1062, null, null));
verifie('temporaires : purge à l\'expiration', array_keys(jeetvbeOverlay::temporaryPurge($temp, 1062)), array('colis'));
verifie('temporaires : entrées abîmées ignorées', jeetvbeOverlay::temporaryPurge(array('x' => 'abîmé', 'y' => array('expires' => null)), 1), array());
$plein = array();
for ($i = 1; $i <= 25; $i++) {
    $plein = jeetvbeOverlay::temporaryPut($plein, jeetvbeOverlay::temporaryFromJson(array('id' => 'i' . $i), $i), $i);
}
verifie('temporaires : 20 au plus, les plus anciens partent', array(count($plein), array_keys($plein)[0]), array(20, 'i6'));

$valeurs = array(1361 => '17.6', 6930 => 'weather-rainy', 1572 => '1', 6907 => 'Armé', 1709 => '1');
list($items, $retraits) = jeetvbeOverlay::statusItems($indicateurs, $temp, array(), $lire, 1010);
verifie('barre : automatiques dans l\'ordre, puis temporaires', array_map(function ($_i) { return $_i['id']; }, $items), array('meteo', 'lampe', 'alarme_armee', 'lessive', 'colis'));
list($items) = jeetvbeOverlay::statusItems($indicateurs, jeetvbeOverlay::temporaryPut($temp, jeetvbeOverlay::temporaryFromJson(array('id' => 'lampe', 'icon' => 'mdi:fire'), 1010), 1010), array(), $lire, 1010);
verifie('barre : un temporaire de même id prend la place de l\'automatique', array_map(function ($_i) { return $_i['id'] . ' ' . $_i['icon']; }, $items),
        array('meteo mdi:weather-rainy', 'lampe mdi:fire', 'alarme_armee mdi:shield-lock', 'lessive mdi:washing-machine', 'colis mdi:package'));
$retraits = jeetvbeOverlay::snooze(array(), $indicateurs, 'meteo', $lire);
list($items, $retraits) = jeetvbeOverlay::statusItems($indicateurs, array(), $retraits, $lire, 1010);
verifie('retrait à la main : météo retirée tant que rien ne change', array(array_map(function ($_i) { return $_i['id']; }, $items), array_keys($retraits)),
        array(array('lampe', 'alarme_armee'), array('meteo')));
$valeurs[1361] = '19.2';
list($items, $retraits) = jeetvbeOverlay::statusItems($indicateurs, array(), $retraits, $lire, 1010);
verifie('retrait à la main : revient dès que son contenu change, et s\'oublie', array(array_map(function ($_i) { return $_i['id']; }, $items), $retraits),
        array(array('meteo', 'lampe', 'alarme_armee'), array()));
$retraits = jeetvbeOverlay::snooze(array(), $indicateurs, 'lampe', $lire);
$valeurs[1572] = '0';
list($items, $retraits) = jeetvbeOverlay::statusItems($indicateurs, array(), $retraits, $lire, 1010);
verifie('retrait à la main : oublié quand il devient caché', $retraits, array());
$valeurs[1572] = '1';
list($items) = jeetvbeOverlay::statusItems($indicateurs, array(), $retraits, $lire, 1010);
verifie('retrait à la main : rallumée, la lampe revient', in_array('lampe', array_map(function ($_i) { return $_i['id']; }, $items), true), true);
verifie('retrait d\'un id inconnu : rien', jeetvbeOverlay::snooze(array(), $indicateurs, 'inconnu', $lire), array());
verifie('indicateur désactivé : absent', count(jeetvbeOverlay::statusItems(array(array('enable' => 0, 'id' => 'x')), array(), array(), $lire, 1)[0]), 0);

verifie('barre désactivée → null', jeetvbeOverlay::buildStatus(array('enabled' => 0), $items), null);
$barre = jeetvbeOverlay::buildStatus(array('enabled' => 1, 'corner' => 'bottom_start', 'clock' => 1, 'opacity' => 85), array_slice($items, 0, 1));
verifie('barre complète (contrat)', $barre, array('corner' => 'bottom_start', 'clock' => true, 'opacity' => 85, 'items' => array(array('id' => 'meteo',
        'icon' => 'mdi:weather-rainy', 'text' => '19°', 'iconColor' => '#FFFFFF', 'textColor' => '#FFFFFF', 'borderColor' => '#00000000',
        'backgroundColor' => '#00000000', 'shape' => 'circle'))));
verifie('barre : heure seule', jeetvbeOverlay::buildStatus(array('enabled' => 1), array())['items'], array());
verifie('JSON de la barre : items en liste, clock booléen', json_encode(jeetvbeOverlay::buildStatus(array('enabled' => 1, 'clock' => 0), array())),
        '{"corner":"bottom_start","clock":false,"opacity":85,"items":[]}');
verifie('empreinte : stable, change avec le contenu', array(jeetvbeOverlay::statusSignature($barre) === jeetvbeOverlay::statusSignature($barre),
        jeetvbeOverlay::statusSignature($barre) !== jeetvbeOverlay::statusSignature(jeetvbeOverlay::buildStatus(array('enabled' => 1, 'opacity' => 50), array_slice($items, 0, 1)))),
        array(true, true));
$valeurs[1361] = '19.4';
verifie('empreinte : 19,2 puis 19,4 arrondis à 19° → pas de changement', jeetvbeOverlay::statusSignature(jeetvbeOverlay::buildStatus(array('enabled' => 1, 'opacity' => 85),
        array(jeetvbeOverlay::indicatorItem($meteo, $lire)))), jeetvbeOverlay::statusSignature($barre));

/* --- Sources vidéo, masquage ------------------------------------------------------------ */
$sources = array(array('name' => 'portier', 'url' => 'rtsp://demo:demo@192.0.2.10/stream'), array('name' => 'Portier', 'url' => 'rtsp://192.0.2.11/x'),
                 array('name' => 'nvr', 'url' => 'https://192.0.2.20/live.m3u8?user=admin&password=s3cret&channel=1'),
                 array('name' => 'mauvais nom', 'url' => 'rtsp://192.0.2.12/'), array('name' => 'ftp', 'url' => 'ftp://192.0.2.13/'), 'abîmé');
verifie('sources : noms valides, uniques (casse), adresses rtsp/http(s)', array_map(function ($_s) { return $_s['name']; }, jeetvbeOverlay::normalizeSources($sources)),
        array('portier', 'nvr'));
verifie('vidéo par nom (casse ignorée)', jeetvbeOverlay::resolveVideo('PORTIER', $sources), 'rtsp://demo:demo@192.0.2.10/stream');
verifie('vidéo par adresse complète', jeetvbeOverlay::resolveVideo('rtsp://192.0.2.30/live', $sources), 'rtsp://192.0.2.30/live');
verifie('vidéo inconnue', array(jeetvbeOverlay::resolveVideo('jardin', $sources), jeetvbeOverlay::resolveVideo('', $sources), jeetvbeOverlay::resolveVideo('file:///etc/passwd', $sources)),
        array(null, null, null));
verifie('masque : identifiants', jeetvbeOverlay::maskUrl('rtsp://demo:demo@192.0.2.10/stream'), 'rtsp://***@192.0.2.10/stream');
verifie('masque : paramètres sensibles seulement', jeetvbeOverlay::maskUrl('https://192.0.2.20/live.m3u8?user=admin&password=s3cret&channel=1'),
        'https://192.0.2.20/live.m3u8?user=***&password=***&channel=1');
verifie('masque : « @ » dans le mot de passe', jeetvbeOverlay::maskUrl('rtsp://a:b@c@192.0.2.10:554/x'), 'rtsp://***@192.0.2.10:554/x');
verifie('masque : sans identifiants, inchangée', jeetvbeOverlay::maskUrl('rtsp://192.0.2.10/stream'), 'rtsp://192.0.2.10/stream');
verifie('masque : pas une adresse → tout masqué', array(jeetvbeOverlay::maskUrl('portier'), jeetvbeOverlay::maskUrl('')), array('***', ''));
$masquees = jeetvbeOverlay::maskedSources($sources);
verifie('sources masquées pour la page : aucun secret', array(count($masquees), preg_match('/demo|s3cret|admin/', json_encode($masquees))), array(2, 0));
verifie('ordre pour le journal : vidéo et image masquées', jeetvbeOverlay::orderForLog(array('type' => 'notify', 'video' => 'rtsp://demo:demo@192.0.2.10/stream', 'image' => 'abc')),
        array('type' => 'notify', 'video' => 'rtsp://***@192.0.2.10/stream', 'image' => 'abc'));
verifie('[video=…] lu et retiré', jeetvbeOverlay::extractVideo('Portier [video=portier]', 'On sonne [video=autre]'), array('Portier', 'On sonne', 'portier'));
verifie('[video=…] dans le message seulement', jeetvbeOverlay::extractVideo('', 'On sonne [VIDEO=rtsp://192.0.2.10/x] [image=/a.jpg]'), array('', 'On sonne [image=/a.jpg]', 'rtsp://192.0.2.10/x'));
verifie('[video=] vide ignoré, sans marqueur', array(jeetvbeOverlay::extractVideo('T', 'M [video=]'), jeetvbeOverlay::extractVideo('T', 'M')), array(array('T', 'M', null), array('T', 'M', null)));

/* --- JSON TvOverlay ---------------------------------------------------------------------- */
verifie('message JSON en texte', jeetvbeOverlay::jsonMessage('{"title":"Sonnette"}'), array('title' => 'Sonnette'));
verifie('message reçu en objet (formulaire du cœur, hygeabe), #id# remplacés', jeetvbeOverlay::jsonMessage(array('title' => 'T #12#', 'duration' => 10),
        function ($_t) { return str_replace('#12#', '21', $_t); }), array('title' => 'T 21', 'duration' => 10));
verifie('message : pas un objet JSON', array(jeetvbeOverlay::jsonMessage('Sonnette'), jeetvbeOverlay::jsonMessage('[1,2]'), jeetvbeOverlay::jsonMessage(null)), array(null, null, null));
verifie('message : objet vide accepté (vide ensuite)', jeetvbeOverlay::jsonMessage('{}'), array());
verifie('nature d\'une image', array(jeetvbeOverlay::imageKind('mdi:bell'), jeetvbeOverlay::imageKind('bell'), jeetvbeOverlay::imageKind('http://192.0.2.5/snap.jpg'),
        jeetvbeOverlay::imageKind('data:image/png;base64,iVBORw0KGgo='), jeetvbeOverlay::imageKind(str_repeat('QUJD', 30)), jeetvbeOverlay::imageKind('/var/www/html/x.jpg'),
        jeetvbeOverlay::imageKind('ftp://x'), jeetvbeOverlay::imageKind('')), array('mdi', 'mdi', 'url', 'base64', 'base64', 'path', 'none', 'none'));
verifie('base64 décodé, borné', array(jeetvbeOverlay::decodeBase64Image('data:image/png;base64,QUJD', 100), jeetvbeOverlay::decodeBase64Image(str_repeat('QUJD', 100), 10),
        jeetvbeOverlay::decodeBase64Image('pas*du*base64', 100)), array('ABC', null, null));
$n = jeetvbeOverlay::notifyFromJson(array('id' => 'sonnette', 'title' => 'On sonne', 'message' => 'Porte d\'entrée', 'video' => 'portier', 'corner' => 'top_start',
        'duration' => 30, 'smallIcon' => 'mdi:bell', 'smallIconColor' => '#2196f3', 'largeIcon' => 'http://192.0.2.5/snap.jpg', 'source' => 'Jeedom'), $sources);
verifie('Notifier (JSON) → notify (id TvOverlay → tag)', $n, array('order' => array('type' => 'notify', 'title' => 'On sonne', 'message' => 'Porte d\'entrée', 'tag' => 'sonnette',
        'duration' => 30, 'corner' => 'top_start', 'icon' => 'mdi:bell', 'iconColor' => '#2196F3', 'video' => 'rtsp://demo:demo@192.0.2.10/stream'),
        'image' => array('kind' => 'url', 'value' => 'http://192.0.2.5/snap.jpg')));
verifie('Notifier (JSON) : image prime sur largeIcon, largeIcon mdi en icône', jeetvbeOverlay::notifyFromJson(array('title' => 'T', 'image' => '/var/www/html/a.jpg',
        'largeIcon' => 'mdi:washing-machine'), array()), array('order' => array('type' => 'notify', 'title' => 'T', 'message' => '', 'icon' => 'mdi:washing-machine'),
        'image' => array('kind' => 'path', 'value' => '/var/www/html/a.jpg')));
verifie('Notifier (JSON) : durée bornée, coin inconnu ignoré, id invalide ignoré', jeetvbeOverlay::notifyFromJson(array('message' => 'M', 'duration' => 600,
        'corner' => 'centre', 'id' => str_repeat('x', 80)), array())['order'], array('type' => 'notify', 'title' => '', 'message' => 'M', 'duration' => 120));
verifie('Notifier (JSON) : couleur sans icône ignorée', isset(jeetvbeOverlay::notifyFromJson(array('message' => 'M', 'smallIconColor' => '#fff'), array())['order']['iconColor']), false);
verifie('Notifier (JSON) : vidéo inconnue → erreur', isset(jeetvbeOverlay::notifyFromJson(array('title' => 'T', 'video' => 'jardin'), $sources)['error']), true);
verifie('Notifier (JSON) : vide → erreur', isset(jeetvbeOverlay::notifyFromJson(array('smallIcon' => 'mdi:bell'), array())['error']), true);
verifie('Notifier (JSON) : vidéo seule acceptée', jeetvbeOverlay::notifyFromJson(array('video' => 'rtsp://192.0.2.30/x'), array())['order']['video'], 'rtsp://192.0.2.30/x');
verifie('Notifier (JSON) : pas un objet → erreur', isset(jeetvbeOverlay::notifyFromJson(null, array())['error']), true);

/* --- Réseau local ------------------------------------------------------------------------ */
verifie('adresses locales', array_map(array('jeetvbeOverlay', 'isLocalIp'), array('192.168.1.20', '10.1.2.3', '172.16.0.1', '172.32.0.1', '127.0.0.1', '8.8.8.8', 'fd12::1', '2001:db8::1', 'x')),
        array(true, true, true, false, true, false, true, false, false));

/* --- Les deux pièges du coeur, en lecture du source ------------------------------ */
$source = file_get_contents(__DIR__ . '/../core/class/jeetvbe.class.php');
preg_match_all('/^\s*(?:public|protected|private|var)\s+(?:static\s+)?\$(\w+)/m', $source, $m);
verifie('aucune propriété sans souligné', array_values(array_filter($m[1], function ($_n) { return $_n[0] !== '_'; })), array());
verifie('aucune méthode setCmd / set+clé de formulaire', preg_match('/function\s+set(Id|Name|LogicalId|Generic_type|Object_id|EqType_name|IsVisible|IsEnable|Configuration|Timeout|Category|Display|Order|Comment|Tags|Cmd)\s*\(/i', $source), 0);
verifie('preSave ne lève pas d\'exception', preg_match('/function preSave\(\)\s*\{(?:(?!\n    \}).)*throw/s', $source), 0);
verifie('pas de .htaccess devant l\'API', file_exists(__DIR__ . '/../core/php/.htaccess'), false);
verifie('images : .htaccess « Require all denied »', strpos($source, 'Require all denied') !== false, true);
verifie('changes : seule l\'attente la plus récente prend les ordres', preg_match('/function waitChanges.*takeOrders\(/s', $source), 0);
verifie('clé : doublon contrôlé en preSave', preg_match('/function preSave\(\)\s*\{[^}]*tokenTakenByOther/s', $source), 1);
$api = file_get_contents(__DIR__ . '/../core/php/api.php');
verifie('API : aucune partie de la clé reçue au journal', preg_match('/%\.?\d*s…\'?, \$key/', $api), 0);
$deployignore = file_get_contents(__DIR__ . '/../.deployignore');
verifie('images : data/ exclu du déploiement (rsync --delete)', preg_match('#^/data/$#m', $deployignore), 1);
verifie('images : data/ non versionné', preg_match('#^data/$#m', file_get_contents(__DIR__ . '/../.gitignore')), 1);

echo ($echecs === 0) ? "OK : $total vérifications passent.\n" : "$echecs échec(s) sur $total vérifications.\n";
exit($echecs === 0 ? 0 : 1);
