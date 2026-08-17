<?php

namespace App\Controller\Admin;

use App\Repository\ClientRepository;
use App\Repository\PaymentRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class SearchController extends AbstractController
{
    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    #[Route(path: '/admin/search', name: 'admin_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $query = trim((string) $request->query->get('q', ''));

        $clients = [];
        $payments = [];
        $users = [];

        if ($query !== '') {
            $clients = $this->clientRepository->search($query);
            $payments = $this->paymentRepository->search($query);
            $users = $this->userRepository->search($query);
        }

        return $this->render('admin/search/results.html.twig', [
            'query' => $query,
            'clients' => $clients,
            'payments' => $payments,
            'users' => $users,
        ]);
    }
}
