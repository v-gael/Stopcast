<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Quay;
use App\Enum\Role;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

/**
 * Choix d'un quai actif parmi tout le référentiel, reconnu par ses destinations
 * desservies. L'option « extra_options.kept_quay » (id) garde en plus un quai
 * déprécié déjà choisi, pour qu'une configuration qui le vise reste modifiable.
 *
 * @extends AbstractType<Quay>
 */
#[AsEntityAutocompleteField(alias: 'quay')]
final class QuayAutocompleteField extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Quay::class,
            'label' => 'Quai',
            'placeholder' => 'Rechercher un quai par son nom',
            'searchable_fields' => ['name'],
            'choice_label' => static function (Quay $quay): string {
                $destinations = $quay->getDestinationsServed() ?? [];

                return [] === $destinations ? $quay->getName() : \sprintf('%s → %s', $quay->getName(), implode(', ', $destinations));
            },
            'query_builder' => static fn (Options $options): \Closure => static function (EntityRepository $repository) use ($options): QueryBuilder {
                $queryBuilder = $repository->createQueryBuilder('quay')->orderBy('quay.name', 'ASC');
                $extraOptions = $options['extra_options'];
                $keptQuay = \is_array($extraOptions) ? ($extraOptions['kept_quay'] ?? null) : null;

                if (null === $keptQuay) {
                    return $queryBuilder->andWhere('quay.deprecatedAt IS NULL');
                }

                return $queryBuilder
                    ->andWhere('quay.deprecatedAt IS NULL OR quay.id = :keptQuay')
                    ->setParameter('keptQuay', $keptQuay);
            },
            'security' => Role::User->value,
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
