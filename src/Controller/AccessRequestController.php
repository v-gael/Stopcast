<?php

declare(strict_types=1);

namespace App\Controller;

use App\Account\AccessRequester;
use App\Form\AccessRequestType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Demande d'accès d'un visiteur, depuis la vitrine. Page publique.
 */
#[Route('/demande-acces')]
final class AccessRequestController extends AbstractController
{
    #[Route('', name: 'app_access_request', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        AccessRequester $accessRequester,
        #[Target('access_request')]
        RateLimiterFactoryInterface $accessRequestLimiter,
    ): Response {
        $form = $this->createForm(AccessRequestType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Chaque demande valide compte, que l'adresse ait un compte ou non.
            if ($accessRequestLimiter->create($request->getClientIp() ?? '')->consume()->isAccepted()) {
                /** @var array{email: string, message: ?string} $data */
                $data = $form->getData();
                $accessRequester->request($data['email'], $data['message']);

                return $this->redirectToRoute('app_access_request_sent', status: Response::HTTP_SEE_OTHER);
            }

            $form->addError(new FormError('Trop de demandes. Réessayez plus tard.'));
        }

        return $this->render('access_request/request.html.twig', [
            'form' => $form,
        ]);
    }

    /**
     * Même page que l'adresse ait un compte ou non.
     */
    #[Route('/envoyee', name: 'app_access_request_sent', methods: ['GET'])]
    public function sent(): Response
    {
        return $this->render('access_request/sent.html.twig');
    }
}
