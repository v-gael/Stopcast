<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    /**
     * Le framework donne au validateur le client HTTP par défaut, sans délai
     * maximal. Seul son client change : le reste vient de framework.validation.
     */
    public function process(ContainerBuilder $container): void
    {
        $container->getDefinition('validator.not_compromised_password')
            ->setArgument(0, new Reference('hibp.client'))
        ;
    }
}
