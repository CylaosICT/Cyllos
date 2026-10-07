<?php

namespace App\Controller\Admin;

use App\Repository\ClientRepository;
use App\Repository\EmailAliasRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Read-only cross-client overview of every EmailAlias rule ("redirection de
 * mail"), filterable by client. Creating/deleting a rule stays on the client's
 * own page (see ClientController).
 */
#[Route(path: '/admin/redirections-email', name: 'admin_email_alias_')]
#[IsGranted('ROLE_ADMIN')]
class EmailAliasController extends AbstractController
{
    public function __construct(
        private readonly EmailAliasRepository $emailAliasRepository,
        private readonly ClientRepository $clientRepository,
    ) {
    }

    #[Route(path: '', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $clientId = $request->query->get('client');
        $client = $clientId !== null && ctype_digit((string) $clientId) ? $this->clientRepository->find((int) $clientId) : null;

        return $this->render('admin/email_alias/list.html.twig', [
            'emailAliases' => $this->emailAliasRepository->findAllForAdmin($client),
            'clients' => $this->clientRepository->findBy([], ['name' => 'ASC']),
            'selectedClient' => $client,
        ]);
    }
}
