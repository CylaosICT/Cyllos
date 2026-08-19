<?php

namespace App\Controller\Admin;

use App\Entity\Payment;
use App\Entity\PaymentStatus;
use App\Payment\PaymentProcessor;
use App\Repository\ClientRepository;
use App\Repository\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/admin/payments', name: 'admin_payment_')]
#[IsGranted('ROLE_ADMIN')]
class PaymentController extends AbstractController
{
    private const PER_PAGE = 12;

    public function __construct(
        private readonly PaymentRepository $paymentRepository,
        private readonly ClientRepository $clientRepository,
        private readonly PaymentProcessor $paymentProcessor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    private const STATUS_FILTERS = [
        'todo' => [PaymentStatus::Todo],
        'success' => [PaymentStatus::Success, PaymentStatus::SuccessAuto],
        'fail' => [PaymentStatus::Fail],
    ];

    #[Route(path: '', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $clientId = $request->query->get('client');
        $client = $clientId !== null ? $this->clientRepository->find($clientId) : null;
        $page = $request->query->getInt('page', 1);
        $statusFilter = $request->query->get('status');
        $statuses = self::STATUS_FILTERS[$statusFilter] ?? [];

        $pagination = $this->paymentRepository->paginate($client, $page, self::PER_PAGE, $statuses);

        return $this->render('admin/payment/list.html.twig', [
            'payments' => $pagination['items'],
            'pagination' => $pagination,
            'clients' => $this->clientRepository->findBy([], ['name' => 'ASC']),
            'selectedClient' => $client,
            'statusFilter' => \array_key_exists($statusFilter, self::STATUS_FILTERS) ? $statusFilter : null,
        ]);
    }

    #[Route(path: '/{id}/credit', name: 'credit', methods: ['POST'])]
    public function credit(Payment $payment, Request $request): Response
    {
        if ($this->isCsrfTokenValid('payment_action_' . $payment->getId(), $request->request->get('_token'))) {
            $result = $this->paymentProcessor->creditManually($payment);
            $this->addFlash($result->isSuccessful() ? 'success' : 'error', $result->isSuccessful()
                ? 'Le paiement a été crédité avec succès.'
                : 'Échec du crédit : ' . implode(', ', $result->errors));
        }

        return $this->redirectToRoute('admin_payment_list');
    }

    #[Route(path: '/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Payment $payment, Request $request): Response
    {
        if ($this->isCsrfTokenValid('payment_action_' . $payment->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($payment);
            $this->entityManager->flush();
            $this->addFlash('success', 'Le paiement a été supprimé.');
        }

        return $this->redirectToRoute('admin_payment_list');
    }
}
