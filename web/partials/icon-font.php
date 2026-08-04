<?php
/**
 * Icon font (Material Symbols) — à inclure dans le <head> de TOUTE page qui
 * affiche des `.material-symbols-outlined`.
 *
 * Pourquoi un partial plutôt qu'un simple <link> recopié : la police doit être
 * demandée le plus tôt possible ET avant tout rendu d'icône. Sans ça, chaque
 * icône est écrite en clair ("rocket_launch", "tune", "expand_more") tant que la
 * police n'est pas arrivée — le fichier fait ~3,7 Mo, donc sur une connexion
 * lente la période de blocage du navigateur expire et c'est la ligature qui
 * s'affiche, en cassant la mise en page au passage.
 *
 * Trois leviers, dans cet ordre :
 *  1. `rel=preload` : le téléchargement démarre au parsing du <head>, en
 *     parallèle du CSS, au lieu d'attendre qu'une icône soit rencontrée.
 *  2. `font-display: block` (dans material-symbols.css) : pas de rendu en police
 *     de repli pendant la période de blocage.
 *  3. le garde-fou ci-dessous : les icônes restent invisibles (mais occupent
 *     leur place — `visibility`, pas `display`) jusqu'à ce que la police soit
 *     prête, avec révélation forcée au bout de quelques secondes pour ne JAMAIS
 *     laisser une interface sans icônes si le chargement échoue.
 *
 * Variable optionnelle :
 *  - $assetBase : préfixe de chemin vers /web (ex. '../' depuis pages/).
 *    Défaut : déduit de $isInSubfolder, sinon ''.
 */
$iconFontBase = $assetBase ?? (($isInSubfolder ?? false) ? '../' : '');
$iconFontDir = $iconFontBase . 'assets/vendor/material-symbols/';
?>
<link rel="preload" href="<?= $iconFontDir ?>material-symbols-outlined.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= $iconFontDir ?>material-symbols.css">
<script>
/* Doit rester inline et synchrone : il s'exécute après la feuille de style
   ci-dessus (donc la @font-face est enregistrée) et avant le rendu du body. */
(function () {
    var el = document.documentElement;
    var reveal = function () { el.classList.add('icon-font-ready'); };
    // Pas d'API Font Loading (vieux navigateur) : on n'a aucun moyen de savoir,
    // on affiche tout de suite plutôt que de risquer des icônes fantômes.
    if (!document.fonts || typeof document.fonts.load !== 'function') { reveal(); return; }
    var done = false;
    var once = function () { if (!done) { done = true; reveal(); } };
    try {
        document.fonts.load('24px "Material Symbols Outlined"').then(once, once);
    } catch (e) { once(); return; }
    // Filet de sécurité : police injoignable/lente → on repasse au comportement
    // d'avant (ligature visible) plutôt que de garder une UI sans icônes.
    setTimeout(once, 5000);
})();
</script>
