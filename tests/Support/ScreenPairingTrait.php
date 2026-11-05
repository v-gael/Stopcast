<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Device;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * Gestes communs aux tests de l'appairage d'un écran, joués avec le dernier
 * client créé par la classe de test : l'écran et l'espace web partagent ce
 * client, chacun sous son firewall.
 */
trait ScreenPairingTrait
{
    use WebTestTrait;

    /**
     * L'écran ouvre /display sans cookie de dispositif : le code qu'il affiche.
     */
    private function displayedCode(): string
    {
        $crawler = $this->browser()->request('GET', '/display');

        self::assertResponseIsSuccessful();

        return $crawler->filter('.pairing__code')->text();
    }

    /**
     * Le compte connecté saisit un code sur la fiche de ce dispositif.
     */
    private function enterCode(string $code, Device $device): void
    {
        $crawler = $this->browser()->request('GET', \sprintf('/devices/%d/pair', $device->getId()));
        self::assertResponseIsSuccessful();

        $this->browser()->submit($crawler->selectButton('Appairer')->form(['pair_screen[code]' => $code]));
    }

    /**
     * Le compte connecté a scanné le QR code de l'écran : il choisit ce
     * dispositif sur la page ouverte et appaire.
     */
    private function pairScannedCode(string $code, Device $device): void
    {
        $crawler = $this->browser()->request('GET', $this->scannedPairingPath($code));
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Appairer')->form();
        $choice = $form['scanned_pairing[device]'];
        self::assertInstanceOf(ChoiceFormField::class, $choice);
        $choice->select((string) $device->getId());

        $this->browser()->submit($form);
    }

    /**
     * Adresse encodée dans le QR code d'un écran qui affiche ce code.
     */
    private function scannedPairingPath(string $code): string
    {
        return '/devices/pair?code='.str_replace('-', '', $code);
    }

    /**
     * L'écran interroge le serveur après la saisie de son code : il reçoit son
     * cookie de dispositif (205).
     */
    private function receiveScreenCookie(): void
    {
        $this->browser()->request('GET', '/display/pairing');

        self::assertResponseStatusCodeSame(205);
    }

    /**
     * Le dispositif relu en base, après les écritures des requêtes.
     */
    private function storedDevice(Device $device): Device
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();

        $stored = $entityManager->find(Device::class, $device->getId());
        self::assertInstanceOf(Device::class, $stored);

        return $stored;
    }
}
