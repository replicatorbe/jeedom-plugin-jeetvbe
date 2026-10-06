<?php
/* This file is part of Jeedom. Licence AGPL — voir LICENSE. */
require_once __DIR__ . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect()) {
    include_file('desktop', '404', 'php');
    die();
}
?>
<form class="form-horizontal">
    <fieldset>
        <div class="form-group">
            <div class="col-sm-12">
                <span class="help-block">{{Le plugin n'a pas de réglage global : chaque TV est un équipement, avec sa propre clé et ses propres pages. L'URL de l'API et la clé sont affichées sur la page de l'équipement.}}</span>
            </div>
        </div>
    </fieldset>
</form>
