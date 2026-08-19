<?php

namespace App\Controller\Admin;

use App\Client\ClientLogoUploader;
use App\Entity\Client;
use App\Entity\EmailAlias;
use App\Entity\User;
use App\Form\ClientInfoType;
use App\Form\ClientSettingType;
use App\Form\ClientUserType;
use App\Form\EmailAliasType;
use App\Form\ResetPasswordType;
use App\Form\ClientWizardState;
use App\Form\CyclosConfigType;
use App\Form\HelloAssoConfigType;
use App\Payment\PaymentProcessor;
use App\Repository\ClientRepository;
use App\Repository\EmailAliasRepository;
use App\Repository\PaymentRepository;
use App\Repository\UserRepository;
use App\Security\SecretEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Route(path: '/admin/clients', name: 'admin_client_')]
#[IsGranted('ROLE_ADMIN')]
class ClientController extends AbstractController
{
    private const USERS_PER_PAGE = 10;
    private const CLIENTS_PER_PAGE = 28;

    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SecretEncryptor $secretEncryptor,
        private readonly PaymentProcessor $paymentProcessor,
        private readonly ClientWizardState $wizardState,
        private readonly ClientLogoUploader $logoUploader,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly PaymentRepository $paymentRepository,
        private readonly EmailAliasRepository $emailAliasRepository,
    ) {
    }

    #[Route(path: '', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $page = $request->query->getInt('page', 1);
        $pagination = $this->clientRepository->paginate($page, self::CLIENTS_PER_PAGE);

        return $this->render('admin/client/list.html.twig', [
            'clients' => $pagination['items'],
            'pagination' => $pagination,
        ]);
    }

    #[Route(path: '/{id}', requirements: ['id' => '\d+'], name: 'show', methods: ['GET'])]
    public function show(Client $client, Request $request): Response
    {
        $page = $request->query->getInt('page', 1);

        return $this->render('admin/client/show.html.twig', [
            'client' => $client,
            'usersPagination' => $this->userRepository->paginateByClient($client, $page, self::USERS_PER_PAGE),
            'emailAliases' => $this->emailAliasRepository->findAllForClient($client),
        ]);
    }

    #[Route(path: '/{id}/alias-email/new', requirements: ['id' => '\d+'], name: 'new_email_alias', methods: ['GET', 'POST'])]
    public function newEmailAlias(Client $client, Request $request): Response
    {
        $alias = new EmailAlias();
        $alias->setClient($client);

        $prefillSourceEmail = $request->query->get('sourceEmail');
        if (\is_string($prefillSourceEmail) && $prefillSourceEmail !== '') {
            $alias->setSourceEmail($prefillSourceEmail);
        }

        $form = $this->createForm(EmailAliasType::class, $alias);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->emailAliasRepository->findOneByClientAndSourceEmail($client, $alias->getSourceEmail()) !== null) {
                $form->get('sourceEmail')->addError(new FormError('Une règle existe déjà pour cet e-mail HelloAsso.'));
            } else {
                $this->entityManager->persist($alias);
                $this->entityManager->flush();

                $this->addFlash('success', sprintf('Les paiements de "%s" seront désormais crédités sur "%s".', $alias->getSourceEmail(), $alias->getTargetEmail()));

                return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
            }
        }

        return $this->render('admin/client/new_email_alias.html.twig', [
            'client' => $client,
            'form' => $form,
        ]);
    }

    #[Route(path: '/{id}/alias-email/{aliasId}/supprimer', requirements: ['id' => '\d+', 'aliasId' => '\d+'], name: 'delete_email_alias', methods: ['POST'])]
    public function deleteEmailAlias(Client $client, int $aliasId, Request $request): Response
    {
        $alias = $this->emailAliasRepository->find($aliasId);
        if ($alias === null || $alias->getClient() !== $client) {
            throw $this->createNotFoundException('Règle introuvable pour ce client.');
        }

        if ($this->isCsrfTokenValid('delete_email_alias_' . $alias->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($alias);
            $this->entityManager->flush();
            $this->addFlash('success', sprintf('La règle pour "%s" a été supprimée.', $alias->getSourceEmail()));
        }

        return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
    }

    #[Route(path: '/{id}/utilisateurs/new', requirements: ['id' => '\d+'], name: 'new_user', methods: ['GET', 'POST'])]
    public function newUser(Client $client, Request $request): Response
    {
        $isFirstAccount = $this->userRepository->findByClient($client) === [];
        $prefill = $isFirstAccount && $client->getContactEmail() !== null
            ? ['email' => $client->getContactEmail()]
            : [];

        $form = $this->createForm(ClientUserType::class, $prefill);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            if ($this->userRepository->findOneBy(['email' => $data['email']]) !== null) {
                $form->get('email')->addError(new FormError('Un utilisateur avec cet e-mail existe déjà.'));
            } else {
                $user = new User();
                $user->setEmail($data['email']);
                $user->setRoles([User::ROLE_CLIENT]);
                $user->setClient($client);
                $user->setPassword($this->passwordHasher->hashPassword($user, $data['plainPassword']));

                $this->entityManager->persist($user);
                $this->entityManager->flush();

                $this->addFlash('success', sprintf('Le compte "%s" a été créé pour %s.', $user->getEmail(), $client->getName()));

                return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
            }
        }

        return $this->render('admin/client/new_user.html.twig', [
            'client' => $client,
            'form' => $form,
        ]);
    }

    #[Route(path: '/{id}/utilisateurs/{userId}/mot-de-passe', requirements: ['id' => '\d+', 'userId' => '\d+'], name: 'reset_user_password', methods: ['GET', 'POST'])]
    public function resetUserPassword(Client $client, int $userId, Request $request): Response
    {
        $user = $this->getClientUserOrNotFound($client, $userId);

        $form = $this->createForm(ResetPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($this->passwordHasher->hashPassword($user, $form->getData()['plainPassword']));
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('Le mot de passe de "%s" a été réinitialisé.', $user->getEmail()));

            return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
        }

        return $this->render('admin/client/reset_user_password.html.twig', [
            'client' => $client,
            'targetUser' => $user,
            'form' => $form,
        ]);
    }

    #[Route(path: '/{id}/utilisateurs/{userId}/statut', requirements: ['id' => '\d+', 'userId' => '\d+'], name: 'toggle_user_active', methods: ['POST'])]
    public function toggleUserActive(Client $client, int $userId, Request $request): Response
    {
        $user = $this->getClientUserOrNotFound($client, $userId);

        if ($this->isCsrfTokenValid('toggle_client_user_' . $user->getId(), $request->request->get('_token'))) {
            $user->setActive(!$user->isActive());
            $this->entityManager->flush();
            $this->addFlash('success', sprintf('Le compte "%s" a été %s.', $user->getEmail(), $user->isActive() ? 'réactivé' : 'désactivé'));
        }

        return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
    }

    #[Route(path: '/{id}/utilisateurs/{userId}/supprimer', requirements: ['id' => '\d+', 'userId' => '\d+'], name: 'delete_user', methods: ['POST'])]
    public function deleteUser(Client $client, int $userId, Request $request): Response
    {
        $user = $this->getClientUserOrNotFound($client, $userId);

        if (!$this->isCsrfTokenValid('delete_user_' . $user->getId(), $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
        }

        if ($user->isActive()) {
            $this->addFlash('error', 'Le compte doit être désactivé avant de pouvoir être supprimé.');

            return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
        }

        $this->entityManager->remove($user);
        $this->entityManager->flush();
        $this->addFlash('success', sprintf('Le compte "%s" a été supprimé.', $user->getEmail()));

        return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
    }

    private function getClientUserOrNotFound(Client $client, int $userId): User
    {
        $user = $this->userRepository->find($userId);
        if ($user === null || $user->getClient() !== $client) {
            throw $this->createNotFoundException('Utilisateur introuvable pour ce client.');
        }

        return $user;
    }

    #[Route(path: '/{id}/supprimer', requirements: ['id' => '\d+'], name: 'delete', methods: ['POST'])]
    public function delete(Client $client, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete_client_' . $client->getId(), $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_client_list');
        }

        if ($client->isActive()) {
            $this->addFlash('error', 'Le client doit être désactivé avant de pouvoir être supprimé.');

            return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
        }

        foreach ($this->paymentRepository->findAllForClient($client) as $payment) {
            $this->entityManager->remove($payment);
        }
        foreach ($this->userRepository->findByClient($client) as $user) {
            $this->entityManager->remove($user);
        }
        foreach ($this->emailAliasRepository->findAllForClient($client) as $alias) {
            $this->entityManager->remove($alias);
        }

        $this->logoUploader->remove($client);
        $clientName = $client->getName();
        $this->entityManager->remove($client);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Le client "%s" a été supprimé.', $clientName));

        return $this->redirectToRoute('admin_client_list');
    }

    // ---------------------------------------------------------------------
    // Creation wizard: informations -> helloasso -> cyclos -> reglages
    // ---------------------------------------------------------------------

    #[Route(path: '/new', name: 'new', methods: ['GET', 'POST'])]
    public function wizardInfo(Request $request): Response
    {
        $this->wizardState->clear();
        $client = $this->wizardState->clientInfo();

        $form = $this->createForm(ClientInfoType::class, $client);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->wizardState->set('info', [
                'name' => $client->getName(),
                'slug' => $client->getSlug(),
                'active' => $client->isActive(),
            ]);

            return $this->redirectToRoute('admin_client_new_helloasso');
        }

        return $this->render('admin/client/wizard/informations.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/new/helloasso', name: 'new_helloasso', methods: ['GET', 'POST'])]
    public function wizardHelloAsso(Request $request): Response
    {
        if (!$this->wizardState->hasStep('info')) {
            return $this->redirectToRoute('admin_client_new');
        }

        $config = $this->wizardState->helloAssoConfig();
        $form = $this->createForm(HelloAssoConfigType::class, $config, ['secret_required' => !$this->wizardState->hasStep('helloAsso')]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $secret = $form->get('clientSecret')->getData() ?: $this->wizardState->helloAssoSecret();
            $this->wizardState->set('helloAsso', [
                'apiUrl' => $config->getApiUrl(),
                'helloAssoClientId' => $config->getHelloAssoClientId(),
                'clientSecret' => $secret,
                'organizationSlug' => $config->getOrganizationSlug(),
                'formType' => $config->getFormType(),
                'formSlug' => $config->getFormSlug(),
                'maxAmount' => $config->getMaxAmount(),
                'extraMailFieldName' => $config->getExtraMailFieldName(),
                'fetchNbDays' => $config->getFetchNbDays(),
            ]);

            return $this->redirectToRoute('admin_client_new_cyclos');
        }

        return $this->render('admin/client/wizard/helloasso.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/new/cyclos', name: 'new_cyclos', methods: ['GET', 'POST'])]
    public function wizardCyclos(Request $request): Response
    {
        if (!$this->wizardState->hasStep('helloAsso')) {
            return $this->redirectToRoute('admin_client_new');
        }

        $config = $this->wizardState->cyclosConfig();
        $form = $this->createForm(CyclosConfigType::class, $config, ['secret_required' => !$this->wizardState->hasStep('cyclos')]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $password = $form->get('password')->getData() ?: $this->wizardState->cyclosPassword();
            $this->wizardState->set('cyclos', [
                'baseUrl' => $config->getBaseUrl(),
                'technicalUserId' => $config->getTechnicalUserId(),
                'password' => $password,
                'groupProInternal' => $config->getGroupProInternal(),
                'groupsPartInternal' => $config->getGroupsPartInternal(),
                'emissionProInternal' => $config->getEmissionProInternal(),
                'emissionPartInternal' => $config->getEmissionPartInternal(),
            ]);

            return $this->redirectToRoute('admin_client_new_settings');
        }

        return $this->render('admin/client/wizard/cyclos.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/new/reglages', name: 'new_settings', methods: ['GET', 'POST'])]
    public function wizardSettings(Request $request): Response
    {
        if (!$this->wizardState->hasStep('cyclos')) {
            return $this->redirectToRoute('admin_client_new');
        }

        $setting = $this->wizardState->clientSetting();
        $form = $this->createForm(ClientSettingType::class, $setting);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $client = $this->wizardState->clientInfo();
            $helloAssoConfig = $this->wizardState->helloAssoConfig();
            $helloAssoConfig->setClientSecretEncrypted($this->secretEncryptor->encrypt($this->wizardState->helloAssoSecret()));
            $cyclosConfig = $this->wizardState->cyclosConfig();
            $cyclosConfig->setPasswordEncrypted($this->secretEncryptor->encrypt($this->wizardState->cyclosPassword()));

            $client->setHelloAssoConfig($helloAssoConfig);
            $client->setCyclosConfig($cyclosConfig);
            $client->setSetting($setting);

            $this->entityManager->persist($client);
            $this->entityManager->flush();
            $this->wizardState->clear();

            $this->addFlash('success', sprintf('Le client "%s" a été créé.', $client->getName()));

            return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
        }

        return $this->render('admin/client/wizard/reglages.html.twig', [
            'form' => $form,
        ]);
    }

    // ---------------------------------------------------------------------
    // Per-section edition, from the client's profile page
    // ---------------------------------------------------------------------

    #[Route(path: '/{id}/informations', requirements: ['id' => '\d+'], name: 'edit_info', methods: ['GET', 'POST'])]
    public function editInfo(Client $client, Request $request): Response
    {
        $form = $this->createForm(ClientInfoType::class, $client, ['with_logo' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile|null $logoFile */
            $logoFile = $form->get('logoFile')->getData();
            if ($logoFile !== null) {
                $this->logoUploader->upload($client, $logoFile);
            }

            $this->entityManager->flush();
            $this->addFlash('success', 'Les informations du client ont été mises à jour.');

            return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
        }

        return $this->render('admin/client/edit_section.html.twig', [
            'client' => $client,
            'form' => $form,
            'section_title' => 'Informations',
        ]);
    }

    #[Route(path: '/{id}/helloasso', requirements: ['id' => '\d+'], name: 'edit_helloasso', methods: ['GET', 'POST'])]
    public function editHelloAsso(Client $client, Request $request): Response
    {
        $config = $client->getHelloAssoConfig();
        $form = $this->createForm(HelloAssoConfigType::class, $config, ['secret_required' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $secret = $form->get('clientSecret')->getData();
            if ($secret !== null && $secret !== '') {
                $config->setClientSecretEncrypted($this->secretEncryptor->encrypt($secret));
            }
            $this->entityManager->flush();
            $this->addFlash('success', 'La configuration HelloAsso a été mise à jour.');

            return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
        }

        return $this->render('admin/client/edit_section.html.twig', [
            'client' => $client,
            'form' => $form,
            'section_title' => 'HelloAsso',
        ]);
    }

    #[Route(path: '/{id}/cyclos', requirements: ['id' => '\d+'], name: 'edit_cyclos', methods: ['GET', 'POST'])]
    public function editCyclos(Client $client, Request $request): Response
    {
        $config = $client->getCyclosConfig();
        $form = $this->createForm(CyclosConfigType::class, $config, ['secret_required' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $password = $form->get('password')->getData();
            if ($password !== null && $password !== '') {
                $config->setPasswordEncrypted($this->secretEncryptor->encrypt($password));
            }
            $this->entityManager->flush();
            $this->addFlash('success', 'La configuration Cyclos a été mise à jour.');

            return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
        }

        return $this->render('admin/client/edit_section.html.twig', [
            'client' => $client,
            'form' => $form,
            'section_title' => 'Cyclos',
        ]);
    }

    #[Route(path: '/{id}/reglages', requirements: ['id' => '\d+'], name: 'edit_settings', methods: ['GET', 'POST'])]
    public function editSettings(Client $client, Request $request): Response
    {
        $setting = $client->getSetting();
        $form = $this->createForm(ClientSettingType::class, $setting);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'Les réglages ont été mis à jour.');

            return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
        }

        return $this->render('admin/client/edit_section.html.twig', [
            'client' => $client,
            'form' => $form,
            'section_title' => 'Réglages',
        ]);
    }

    #[Route(path: '/{id}/fetch', requirements: ['id' => '\d+'], name: 'fetch', methods: ['POST'])]
    public function fetch(Client $client, Request $request): Response
    {
        if ($this->isCsrfTokenValid('client_fetch_' . $client->getId(), $request->request->get('_token'))) {
            $added = $this->paymentProcessor->fetchMissingPayments($client, attemptAutomaticCredit: true);
            $this->addFlash('success', sprintf('%d paiement(s) récupéré(s) depuis HelloAsso.', $added));
        }

        return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
    }
}
