<?php
/* Fabrique l'icône du plugin : une TV dont l'écran porte une grille de tuiles.
 *
 *   php tools/make-icon.php
 *
 * Dessin en 1024 puis réduction en 256, pour des bords lissés.
 */
const TAILLE = 256;
const ECHELLE = 4;
$s = ECHELLE;
$img = imagecreatetruecolor(TAILLE * $s, TAILLE * $s);
imagesavealpha($img, true);
imagealphablending($img, false);
imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
imagealphablending($img, true);
$cadre  = imagecolorallocate($img, 0x26, 0x32, 0x38);
$ecran  = imagecolorallocate($img, 0x10, 0x14, 0x16);
$allume = imagecolorallocate($img, 0x2D, 0x7F, 0xF9);
$eteint = imagecolorallocate($img, 0x1E, 0x3A, 0x5F);
imagefilledrectangle($img, 16 * $s, 36 * $s, 240 * $s, 190 * $s, $cadre);
imagefilledrectangle($img, 28 * $s, 48 * $s, 228 * $s, 178 * $s, $ecran);
imagefilledrectangle($img, 112 * $s, 190 * $s, 144 * $s, 214 * $s, $cadre);
imagefilledrectangle($img, 72 * $s, 214 * $s, 184 * $s, 226 * $s, $cadre);
$x0 = 40; $y0 = 60; $w = 56; $h = 50; $gap = 8;
for ($r = 0; $r < 2; $r++) {
    for ($c = 0; $c < 3; $c++) {
        $x = $x0 + $c * ($w + $gap);
        $y = $y0 + $r * ($h + $gap);
        imagefilledrectangle($img, $x * $s, $y * $s, ($x + $w) * $s, ($y + $h) * $s, ($r === 0 || $c === 1) ? $allume : $eteint);
    }
}
$out = imagecreatetruecolor(TAILLE, TAILLE);
imagesavealpha($out, true);
imagealphablending($out, false);
imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
imagecopyresampled($out, $img, 0, 0, 0, 0, TAILLE, TAILLE, TAILLE * $s, TAILLE * $s);
imagepng($out, __DIR__ . '/../plugin_info/jeetvbe_icon.png');
echo "plugin_info/jeetvbe_icon.png écrit\n";
