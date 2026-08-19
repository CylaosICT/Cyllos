<?php

namespace App\Controller\Dev;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Internal engineering documentation, written directly as a Twig template
 * rather than pulled from a database or external wiki. Reserved for
 * developers and the CEO (ROLE_CEO inherits ROLE_DEVELOPER, see
 * config/packages/security.yaml).
 */
#[Route(path: '/dev/documentation', name: 'dev_documentation_')]
#[IsGranted('ROLE_DEVELOPER')]
class DocumentationController extends AbstractController
{
    #[Route(path: '', name: 'show', methods: ['GET'])]
    public function show(): Response
    {
        return $this->render('dev/documentation/show.html.twig');
    }
}
