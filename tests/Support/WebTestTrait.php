<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Factory\UserFactory;
use App\Security\TurnstileVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
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
     * Connexion depuis le formulaire de la vitrine, avec le jeton Turnstile
     * que le widget y aurait ajouté.
     */
    private function logIn(string $email, string $password, bool $rememberMe = false, string $turnstileToken = TurnstileSiteverifyMock::VALID_TOKEN): void
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

        $this->submitWithTurnstile($form, $turnstileToken);
    }

    /**
     * Demande d'un lien de réinitialisation depuis « mot de passe oublié ».
     */
    private function requestPasswordReset(string $email, string $turnstileToken = TurnstileSiteverifyMock::VALID_TOKEN): void
    {
        $crawler = $this->browser()->request('GET', '/reset-password');
        self::assertResponseIsSuccessful();

        $this->submitWithTurnstile($crawler->selectButton('Envoyer le lien')->form([
            'reset_password_request[email]' => $email,
        ]), $turnstileToken);
    }

    /**
     * Envoi d'un formulaire protégé par Turnstile. Le champ
     * cf-turnstile-response est ajouté par le JavaScript du widget : absent
     * du HTML, il est ajouté ici aux valeurs du formulaire.
     */
    private function submitWithTurnstile(Form $form, string $turnstileToken = TurnstileSiteverifyMock::VALID_TOKEN): void
    {
        $this->browser()->request($form->getMethod(), $form->getUri(), [...$form->getPhpValues(), TurnstileVerifier::RESPONSE_FIELD => $turnstileToken]);
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
     * Liens du pied de page commun (pages légales et sources des données).
     */
    private function footerNav(Crawler $crawler): Crawler
    {
        $nav = $crawler->filter('footer nav[aria-label="Informations légales"]');
        self::assertCount(1, $nav);

        return $nav;
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
     * Demande de changement d'email depuis « Mon compte », connecté.
     */
    private function requestEmailChange(string $email, string $password = UserFactory::DEFAULT_PASSWORD): void
    {
        $this->browser()->request('GET', '/account/email');
        $this->browser()->submitForm('Envoyer le lien', [
            'change_email[newEmail]' => $email,
            'change_email[currentPassword]' => $password,
        ]);
    }

    /**
     * Dernier email envoyé par la dernière requête.
     */
    private function lastEmail(): Email
    {
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    /**
     * Jeton du lien de l'email envoyé par la dernière requête : le premier
     * groupe capturé par le motif dans la version texte.
     */
    private function tokenFromEmail(string $pattern): string
    {
        if (1 !== preg_match($pattern, (string) $this->lastEmail()->getTextBody(), $matches)) {
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

    /**
     * Le compte relu en base, après les écritures des requêtes.
     */
    private function storedUser(string $email): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();

        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function browser(): KernelBrowser
    {
        $client = self::getClient();
        self::assertInstanceOf(KernelBrowser::class, $client);

        return $client;
    }
}
