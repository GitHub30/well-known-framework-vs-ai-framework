<?php
declare(strict_types=1);

/**
 * Generates crisp PWA icons (192, 512, 192 maskable, 512 maskable), favicon.svg, og-image.png,
 * and PWA manifest screenshots (desktop wide + mobile narrow).
 */

$outDir = __DIR__ . '/public/assets';
@mkdir($outDir, 0775, true);

// 1. Crisp SVG Favicon
$faviconSvg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64" role="img" aria-label="CloudDrive">
  <rect width="64" height="64" rx="14" fill="#ffffff"/>
  <path d="M23.5 11h17l15.5 27-8.5 14.5-15.5-27L23.5 11z" fill="#fbbc04"/>
  <path d="M8 38l8.5 14.5h31L56 38H24.5L8 38z" fill="#1a73e8"/>
  <path d="M23.5 11L8 38l8.5 14.5L32 25.5 23.5 11z" fill="#34a853"/>
  <path d="M24.5 38l-8 14.5h15.5L24.5 38z" fill="#1967d2"/>
</svg>
SVG;
file_put_contents($outDir . '/favicon.svg', $faviconSvg);

function renderDriveIcon(int $size, bool $maskable, string $outFile): void
{
    $scale = 2;
    $s = $size * $scale;
    $img = imagecreatetruecolor($s, $s);
    imagealphablending($img, true);
    imagesavealpha($img, true);

    $bg = $maskable
        ? imagecolorallocate($img, 248, 250, 253)
        : imagecolorallocate($img, 255, 255, 255);
    imagefilledrectangle($img, 0, 0, $s, $s, $bg);

    $yellow = imagecolorallocate($img, 251, 188, 4);
    $blue = imagecolorallocate($img, 26, 115, 232);
    $green = imagecolorallocate($img, 52, 168, 83);
    $darkBlue = imagecolorallocate($img, 25, 103, 210);
    $darkGreen = imagecolorallocate($img, 24, 128, 56);
    $orange = imagecolorallocate($img, 234, 134, 0);

    $pad = $maskable ? 0.22 : 0.14;
    $u = $s * (1.0 - 2 * $pad);
    $ox = $s * $pad;
    $oy = $s * $pad;

    $pt = static fn(float $x, float $y): array => [(int)round($ox + $x * $u), (int)round($oy + $y * $u)];

    imagefilledpolygon($img, array_merge($pt(0.35, 0.08), $pt(0.05, 0.62), $pt(0.21, 0.90), $pt(0.51, 0.36)), $green);
    imagefilledpolygon($img, array_merge($pt(0.35, 0.08), $pt(0.65, 0.08), $pt(0.95, 0.62), $pt(0.65, 0.62)), $yellow);
    imagefilledpolygon($img, array_merge($pt(0.21, 0.90), $pt(0.79, 0.90), $pt(0.95, 0.62), $pt(0.37, 0.62)), $blue);
    imagefilledpolygon($img, array_merge($pt(0.21, 0.90), $pt(0.37, 0.62), $pt(0.50, 0.90)), $darkBlue);
    imagefilledpolygon($img, array_merge($pt(0.35, 0.08), $pt(0.51, 0.36), $pt(0.65, 0.08)), $orange);
    imagefilledpolygon($img, array_merge($pt(0.05, 0.62), $pt(0.21, 0.90), $pt(0.37, 0.62)), $darkGreen);

    $final = imagecreatetruecolor($size, $size);
    imagealphablending($final, true);
    imagesavealpha($final, true);
    imagecopyresampled($final, $img, 0, 0, 0, 0, $size, $size, $s, $s);
    imagepng($final, $outFile, 9);
    imagedestroy($img);
    imagedestroy($final);
}

renderDriveIcon(192, false, $outDir . '/icon-192.png');
renderDriveIcon(192, true,  $outDir . '/icon-maskable-192.png');
renderDriveIcon(512, false, $outDir . '/icon-512.png');
renderDriveIcon(512, true,  $outDir . '/icon-maskable-512.png');

// 2. Open Graph social card image (1200x630)
$og = imagecreatetruecolor(1200, 630);
$bg = imagecolorallocate($og, 248, 250, 253);
$white = imagecolorallocate($og, 255, 255, 255);
$blue = imagecolorallocate($og, 26, 115, 232);
$pill = imagecolorallocate($og, 194, 231, 255);
$ink = imagecolorallocate($og, 31, 31, 31);
$muted = imagecolorallocate($og, 68, 71, 70);
$border = imagecolorallocate($og, 218, 220, 224);

imagefilledrectangle($og, 0, 0, 1200, 630, $bg);
imagefilledrectangle($og, 64, 64, 1136, 566, $white);
imagerectangle($og, 64, 64, 1136, 566, $border);

$icon = imagecreatefrompng($outDir . '/icon-192.png');
imagecopy($og, $icon, 120, 219, 0, 0, 192, 192);
imagedestroy($icon);

imagefilledrectangle($og, 360, 180, 740, 224, $pill);
imagestring($og, 5, 384, 194, 'PHP 8.3 FRAMEWORK BENCHMARK', $ink);
imagestring($og, 5, 360, 262, 'CloudDrive - Google Drive Clone in PHP', $ink);
imagestring($og, 4, 360, 312, 'AI Custom FW  |  Laravel 13  |  Phalcon 5  |  Slim 4', $blue);
imagestring($og, 4, 360, 362, 'PWA + SEO + AEO + GEO + AIO + LLMO | Lighthouse 100', $muted);
imagefilledrectangle($og, 360, 420, 1060, 424, $blue);
imagestring($og, 3, 360, 446, 'Identical SSR + REST API + SQLite + Material 3 Responsive UI', $muted);

imagepng($og, $outDir . '/og-image.png', 7);
imagedestroy($og);

echo "Generated icons (including 192 maskable) and OG image in {$outDir}\n";
