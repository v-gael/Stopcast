<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Gestes communs aux tests fonctionnels de l'espace web, joués avec le
 * dernier client créé par la classe de test.
 */
trait WebTestTrait
{
    /**
     * Connexion depuis le formulaire de la vitrine.
     */
    private function logIn(string $email, string $password, bool $rememberMe = false): void
    {
        $crawler = $this->browser()->request('GET', '/');
        $form = $crawler->selectButton('Se connecter')->form([
            '_username' => $email,
            '_password' => $password,
        ]);

        if ($rememberMe) {
            $checkbox = $form['_remember_me'];
            self::assertInstanceOf(ChoiceFormField::class, $checkbox);
            $checkbox->tick();
        }

        $this->browser()->submit($form);
    }

    /**
     * Déconnexion depuis le bouton de la navigation de l'espace.
     */
    private function logOut(): void
    {
        $crawler = $this->browser()->request('GET', '/account');
        $this->browser()->submit($crawler->filter('header nav')->selectButton('Se déconnecter')->form());
    }

    /**
     * Ligne d'un compte dans la liste des comptes.
     */
    private function accountRow(Crawler $crawler, string $email): Crawler
    {
        $row = $crawler->filter('main tbody tr')->reduce(static fn (Crawler $row): bool => str_starts_with(trim($row->filter('td')->text()), $email));
        self::assertCount(1, $row);

        return $row;
    }

    /**
     * Jeton du lien de l'email envoyé par la dernière requête : le premier
     * groupe capturé par le motif dans la version texte.
     */
    private function tokenFromEmail(string $pattern): string
    {
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        if (1 !== preg_match($pattern, (string) $email->getTextBody(), $matches)) {
            self::fail("Aucun lien dans l'email.");
        }

        return $matches[1];
    }

    /**
     * Jeton CSRF valide dans la session du client, comme le porterait un
     * formulaire forgé : utile quand aucune page ne l'affiche.
     */
    private function csrfToken(string $tokenId): string
    {
        $session = $this->browser()->getRequest()->getSession();
        $request = new Request();
        $request->setSession($session);

        $requestStack = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requestStack);
        $requestStack->push($request);

        $tokenManager = self::getContainer()->get(CsrfTokenManagerInterface::class);
        self::assertInstanceOf(CsrfTokenManagerInterface::class, $tokenManager);
        $token = $tokenManager->getToken($tokenId)->getValue();

        $session->save();
        $requestStack->pop();

        return $token;
    }

    private function browser(): KernelBrowser
    {
        $client = self::getClient();
        self::assertInstanceOf(KernelBrowser::class, $client);

        return $client;
    }
}
