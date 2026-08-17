<?php

namespace App\Controller\App;

use App\Entity\Payment;
use App\Payment\PaymentProcessor;
use App\Repository\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/app/payments', name: 'app_payment_')]
#[IsGranted('ROLE_CLIENT')]
class PaymentController extends AbstractController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly PaymentRepository $paymentRepository,
        private readonly PaymentProcessor $paymentProcessor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $client = $this->getUser()->getClient();
        $page = $request->query->getInt('page', 1);

        $pagination = $this->paymentRepository->paginate($client, $page, self::PER_PAGE);

        return $this->render('app/payment/list.html.twig', [
            'client' => $client,
            'payments' => $pagination['items'],
            'pagination' => $pagination,
        ]);
    }

    #[Route(path: '/{id}/credit', name: 'credit', methods: ['POST'])]
    #[IsGranted('CLIENT_OWNS_PAYMENT', subject: 'payment')]
    public function credit(Payment $payment, Request $request): Response
    {
        if ($this->isCsrfTokenValid('payment_action_' . $payment->getId(), $request->request->get('_token'))) {
            $result = $this->paymentProcessor->creditManually($payment);
            $this->addFlash($result->isSuccessful() ? 'success' : 'error', $result->isSuccessful()
                ? 'Le paiement a été crédité avec succès.'
                : 'Échec du crédit : ' . implode(', ', $result->errors));
        }

        return $this->redirectToRoute('app_payment_list');
    }

    #[Route(path: '/{id}/delete', name: 'delete', methods: ['POST'])]
    #[IsGranted('CLIENT_OWNS_PAYMENT', subject: 'payment')]
    public function delete(Payment $payment, Request $request): Response
    {
        if ($this->isCsrfTokenValid('payment_action_' . $payment->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($payment);
            $this->entityManager->flush();
            $this->addFlash('success', 'Le paiement a été supprimé.');
        }

        return $this->redirectToRoute('app_payment_list');
    }
}
