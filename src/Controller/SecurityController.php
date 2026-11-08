<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Routes interceptées par le firewall main (form_login et logout) : leurs
 * actions ne sont jamais exécutées. Le formulaire est affiché par la vitrine.
 */
final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['POST'])]
    public function login(): never
    {
        throw new \LogicException('Intercepté par form_login du firewall main.');
    }

    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepté par logout du firewall main.');
    }
}
