<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;

/**
 * Durée en secondes réglée au curseur, la valeur affichée à côté du libellé
 * (bloc « seconds_slider » de templates/form/tailwind_layout.html.twig). Les
 * bornes viennent des attributs « min » et « max ».
 *
 * @extends AbstractType<int>
 */
final class SecondsSliderType extends AbstractType
{
    public function getParent(): string
    {
        return IntegerType::class;
    }
}
