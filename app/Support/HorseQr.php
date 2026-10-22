<?php

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Horsefax QR codes with the HF mark in the middle (Richard, Oct 18 2026: for sale catalogs using Horsefax, and claim
 * certificates). Forest green on white at the highest error correction, so the mark can cover the centre and the code
 * still scans. SVG for print, PNG for everything else.
 */
class HorseQr
{
    private const INK = [19, 32, 26]; // #13201A, the mark's own colour
    private const LOGO_SHARE = 0.24;  // of the code's width; H correction tolerates ~30% damage

    public static function svg(string $url, int $size = 600): string
    {
        $fill = Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(...self::INK));
        $svg = (new Writer(new ImageRenderer(new RendererStyle($size, 2, null, null, $fill), new SvgImageBackEnd())))
            ->writeString($url, 'UTF-8', ErrorCorrectionLevel::H());
        $mark = file_get_contents(public_path('img/hf-mark.svg'));
        preg_match('/viewBox="([^"]+)"/', $mark, $vb);
        $inner = preg_replace(['/^.*?<svg[^>]*>/s', '/<\/svg>\s*$/s'], '', $mark);
        [$vx, $vy, $vw, $vh] = array_map('floatval', explode(' ', $vb[1] ?? '0 0 377 249'));
        $box = $size * self::LOGO_SHARE;
        $x = ($size - $box) / 2;
        $pad = $box * 0.14;
        $w = $box - 2 * $pad;
        $h = $w * $vh / $vw;
        // A transformed group rather than a nested <svg>: print and design tools don't all honour nested x/y.
        $logo = sprintf(
            '<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="%.2f" fill="#ffffff"/><g transform="translate(%.3f %.3f) scale(%.5f) translate(%.3f %.3f)">%s</g>',
            $x, $x, $box, $box, $box * 0.12, $x + $pad, ($size - $h) / 2, $w / $vw, -$vx, -$vy, $inner
        );

        return preg_replace('/<\/svg>\s*$/', $logo.'</svg>', $svg);
    }

    public static function png(string $url, int $size = 1200): string
    {
        $fill = Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(...self::INK));
        $img = imagecreatefromstring((new Writer(new GDLibRenderer($size, 2, 'png', 9, $fill)))->writeString($url, 'UTF-8', ErrorCorrectionLevel::H()));
        $w = imagesx($img);
        $box = (int) round($w * self::LOGO_SHARE);
        $x = intdiv($w - $box, 2);
        imagefilledrectangle($img, $x, $x, $x + $box, $x + $box, imagecolorallocate($img, 255, 255, 255));
        $logo = imagecreatefrompng(public_path('img/hf-mark-qr.png'));
        $pad = (int) round($box * 0.14);
        $lw = $box - 2 * $pad;
        $lh = (int) round($lw * imagesy($logo) / imagesx($logo));
        imagealphablending($img, true);
        imagecopyresampled($img, $logo, $x + $pad, intdiv($w - $lh, 2), 0, 0, $lw, $lh, imagesx($logo), imagesy($logo));
        ob_start();
        imagepng($img, null, 9);
        $out = ob_get_clean();
        imagedestroy($img);
        imagedestroy($logo);

        return $out;
    }
}
