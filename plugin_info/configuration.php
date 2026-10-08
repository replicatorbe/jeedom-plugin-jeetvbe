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
                <span class="help-block">{{Chaque TV est un équipement, avec sa propre clé et ses propres pages. L'URL de l'API et la clé sont affichées sur la page de l'équipement.}}</span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Dossiers d'images autorisés en plus}}</label>
            <div class="col-sm-7">
                <textarea class="configKey form-control" data-l1key="imageRoots" rows="3" placeholder="/chemin/absolu/vers/un/dossier"></textarea>
                <span class="help-block">{{Une image jointe ([image=…], files, Notifier (JSON)) n'est acceptée que depuis le dossier data/ d'un plugin, le dossier data/ de Jeedom ou son dossier temporaire. Ajoutez ici d'autres dossiers, un chemin absolu par ligne.}}</span>
            </div>
        </div>
    </fieldset>
</form>
