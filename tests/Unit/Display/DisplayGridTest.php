<?php

declare(strict_types=1);

namespace App\Tests\Unit\Display;

use App\Display\DisplayPage;
use PHPUnit\Framework\TestCase;

/**
 * La grille des passages d'un écran (display.css) a une ligne par passage
 * d'un écran : la CSP refuse les attributs style, le gabarit ne peut donc pas
 * la régler lui-même.
 */
final class DisplayGridTest extends TestCase
{
    private const string STYLESHEET = __DIR__.'/../../../assets/styles/display.css';

    public function testPassageGridHasOneRowPerPassageOfAScreen(): void
    {
        $css = file_get_contents(self::STYLESHEET);
        self::assertIsString($css);

        self::assertSame(1, preg_match('/\.screen__passages\s*\{[^}]*grid-template-rows:\s*repeat\((\d+),/', $css, $matches));

        self::assertSame((string) DisplayPage::PASSAGES_PER_SCREEN, $matches[1] ?? null);
    }
}
