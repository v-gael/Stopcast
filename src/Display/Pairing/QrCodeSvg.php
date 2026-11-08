<?php

declare(strict_types=1);

namespace App\Display\Pairing;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * QR code en SVG à insérer dans la page : modules sombres sur un carré blanc
 * entouré de sa marge (quiet zone, quatre modules environ). Ni dimensions ni
 * style : la feuille de style de la page lui donne sa taille, et la CSP
 * n'autorise pas de style inline.
 */
final readonly class QrCodeSvg
{
    /** Taille de référence du dessin, mise à l'échelle par son viewBox. */
    private const int SIZE = 300;

    /** Marge blanche autour des modules, dans la même unité. */
    private const int MARGIN = 36;

    public function render(string $data): string
    {
        return new SvgWriter()->write(new QrCode($data, size: self::SIZE, margin: self::MARGIN), options: [
            SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true,
            SvgWriter::WRITER_OPTION_EXCLUDE_SVG_WIDTH_AND_HEIGHT => true,
        ])->getString();
    }
}
