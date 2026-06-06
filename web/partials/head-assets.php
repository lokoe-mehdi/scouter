<?php
/**
 * Assets htmx partagés — à inclure dans le <head> de toutes les pages loguées.
 *
 * htmx est vendorisé en local (aucun CDN au runtime). Voir htmx.md.
 *
 * Variable optionnelle :
 *  - $assetBase : préfixe de chemin vers /web (ex. '../' pour les pages de
 *    sous-dossier comme pages/settings.php). Défaut : déduit de $isInSubfolder,
 *    sinon ''.
 */
$assetBase = $assetBase ?? (($isInSubfolder ?? false) ? '../' : '');
?>
<?php /* htmx-bootstrap AVANT htmx et SANS defer : il définit htmxOnReady/
   htmxPageListener qui sont appelés par les scripts inline (bas de body)
   pendant le parsing, donc avant l'exécution d'un script differé. */ ?>
<script src="<?= $assetBase ?>assets/htmx-bootstrap.js?v=<?= @filemtime(__DIR__ . '/../assets/htmx-bootstrap.js') ?: '1' ?>"></script>
<script src="<?= $assetBase ?>assets/vendor/htmx/htmx.min.js" defer></script>
