<?php

namespace App\Controller\Admin;

use App\Client\ClientLogoUploader;
use App\Entity\Client;
use App\Entity\ClientCustomization;
use App\Entity\EmailAlias;
use App\Entity\HelloAssoConfig;
use App\Entity\User;
use App\Form\ClientCustomizationType;
use App\Form\ClientInfoType;
use App\Form\ClientSettingType;
use App\Form\ClientUserType;
use App\Form\ClientWizardState;
use App\Form\CyclosConfigType;
use App\Form\EmailAliasType;
use App\Form\HelloAssoConfigType;
use App\Form\ResetPasswordType;
use App\Message\FetchClientPaymentsMessage;
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
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
        private readonly ClientWizardState $wizardState,
        private readonly ClientLogoUploader $logoUploader,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly PaymentRepository $paymentRepository,
        private readonly EmailAliasRepository $emailAliasRepository,
        private readonly MessageBusInterface $messageBus,
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

        // "Deletable" requires the form to already be inactive (a safety net against
        // deleting a form that's still live), to have no payment history (which is
        // permanent and makes deletion pointless to even offer), and not to be the
        // client's primary form (never deletable, regardless of history — see
        // Client::getPrimaryHelloAssoConfig()). The "not the last form" rule is
        // situational (add another first) so, like the deactivate guard above it,
        // it's enforced server-side with a flash error rather than hidden.
        $primaryHelloAssoConfig = $client->getPrimaryHelloAssoConfig();
        $deletableHelloAssoConfigIds = [];
        foreach ($client->getHelloAssoConfigs() as $haConfig) {
            if ($haConfig !== $primaryHelloAssoConfig
                && !$haConfig->isActive()
                && !$this->paymentRepository->hasAnyForHelloAssoConfig($haConfig)
            ) {
                $deletableHelloAssoConfigIds[] = $haConfig->getId();
            }
        }

        return $this->render('admin/client/show.html.twig', [
            'client' => $client,
            'usersPagination' => $this->userRepository->paginateByClient($client, $page, self::USERS_PER_PAGE),
            'emailAliases' => $this->emailAliasRepository->findAllForClient($client),
            'deletableHelloAssoConfigIds' => $deletableHelloAssoConfigIds,
            'primaryHelloAssoConfigId' => $primaryHelloAssoConfig?->getId(),
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

                $this->addFlash('success', \sprintf('Les paiements de "%s" seront désormais crédités sur "%s".', $alias->getSourceEmail(), $alias->getTargetEmail()));

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
            $this->addFlash('success', \sprintf('La règle pour "%s" a été supprimée.', $alias->getSourceEmail()));
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
                $user->setManualPaymentCreditPermission((bool) $data['manualPaymentCredit']);
                $user->setClient($client);
                $user->setPassword($this->passwordHasher->hashPassword($user, $data['plainPassword']));

                $this->entityManager->persist($user);
                $this->entityManager->flush();

                $this->addFlash('success', \sprintf('Le compte "%s" a été créé pour %s.', $user->getEmail(), $client->getName()));

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

            $this->addFlash('success', \sprintf('Le mot de passe de "%s" a été réinitialisé.', $user->getEmail()));

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
            $this->addFlash('success', \sprintf('Le compte "%s" a été %s.', $user->getEmail(), $user->isActive() ? 'réactivé' : 'désactivé'));
        }

        return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
    }

    #[Route(path: '/{id}/utilisateurs/{userId}/alimentation-manuelle', requirements: ['id' => '\d+', 'userId' => '\d+'], name: 'toggle_user_manual_payment_credit', methods: ['POST'])]
    public function toggleUserManualPaymentCredit(Client $client, int $userId, Request $request): Response
    {
        $user = $this->getClientUserOrNotFound($client, $userId);

        if ($this->isCsrfTokenValid('toggle_client_user_manual_credit_' . $user->getId(), $request->request->get('_token'))) {
            $hasPermission = $user->canMarkPaymentManualCredit();
            $user->setManualPaymentCreditPermission(!$hasPermission);
            $this->entityManager->flush();
            $this->addFlash('success', \sprintf(
                'La permission d\'alimentation manuelle a été %s pour "%s".',
                $hasPermission ? 'retirée' : 'accordée',
                $user->getEmail(),
            ));
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
        $this->addFlash('success', \sprintf('Le compte "%s" a été supprimé.', $user->getEmail()));

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

        $this->addFlash('success', \sprintf('Le client "%s" a été supprimé.', $clientName));

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
                'label' => $config->getLabel(),
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

            $client->addHelloAssoConfig($helloAssoConfig);
            $client->setCyclosConfig($cyclosConfig);
            $client->setSetting($setting);

            $this->entityManager->persist($client);
            $this->entityManager->flush();
            $this->wizardState->clear();

            $this->addFlash('success', \sprintf('Le client "%s" a été créé.', $client->getName()));

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

    #[Route(path: '/{id}/helloasso/new', requirements: ['id' => '\d+'], name: 'new_helloasso_config', methods: ['GET', 'POST'])]
    public function newHelloAssoConfig(Client $client, Request $request): Response
    {
        $sourceConfig = $client->getPrimaryHelloAssoConfig();

        $config = new HelloAssoConfig();
        if ($sourceConfig !== null) {
            // Same HelloAsso organization, most likely the same OAuth2 app too — prefill
            // from the client's existing config so adding a 2nd/3rd form doesn't force
            // re-typing shared credentials (and risking a typo that silently breaks one
            // of the two forms). Every field stays editable for the rare client that
            // genuinely needs a different account.
            $config->setApiUrl($sourceConfig->getApiUrl());
            $config->setHelloAssoClientId($sourceConfig->getHelloAssoClientId());
            $config->setOrganizationSlug($sourceConfig->getOrganizationSlug());
        }

        $form = $this->createForm(HelloAssoConfigType::class, $config, [
            'secret_required' => $sourceConfig === null,
            'secret_help' => $sourceConfig !== null ? 'Laisser vide pour réutiliser le secret du premier formulaire HelloAsso de ce client.' : null,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $secret = $form->get('clientSecret')->getData();
            if ($secret !== null && $secret !== '') {
                $config->setClientSecretEncrypted($this->secretEncryptor->encrypt($secret));
            } elseif ($sourceConfig !== null) {
                $config->setClientSecretEncrypted($sourceConfig->getClientSecretEncrypted());
            }

            $client->addHelloAssoConfig($config);

            $this->entityManager->persist($config);
            $this->entityManager->flush();
            $this->addFlash('success', \sprintf('Le formulaire HelloAsso "%s" a été ajouté.', $config->getLabel()));

            return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
        }

        return $this->render('admin/client/new_helloasso.html.twig', [
            'client' => $client,
            'form' => $form,
            'prefilled' => $sourceConfig !== null,
        ]);
    }

    #[Route(path: '/{id}/helloasso/{configId}', requirements: ['id' => '\d+', 'configId' => '\d+'], name: 'edit_helloasso_config', methods: ['GET', 'POST'])]
    public function editHelloAssoConfig(Client $client, int $configId, Request $request): Response
    {
        $config = $this->getClientHelloAssoConfigOrNotFound($client, $configId);
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

        return $this->render('admin/client/edit_helloasso.html.twig', [
            'client' => $client,
            'config' => $config,
            'form' => $form,
        ]);
    }

    #[Route(path: '/{id}/helloasso/{configId}/statut', requirements: ['id' => '\d+', 'configId' => '\d+'], name: 'toggle_helloasso_config', methods: ['POST'])]
    public function toggleHelloAssoConfig(Client $client, int $configId, Request $request): Response
    {
        $config = $this->getClientHelloAssoConfigOrNotFound($client, $configId);

        if (!$this->isCsrfTokenValid('toggle_helloasso_config_' . $config->getId(), $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
        }

        if ($config->isActive() && \count($client->getActiveHelloAssoConfigs()) <= 1) {
            $this->addFlash('error', 'Impossible de désactiver le dernier formulaire HelloAsso actif du client.');

            return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
        }

        $config->setActive(!$config->isActive());
        $this->entityManager->flush();
        $this->addFlash('success', \sprintf('Le formulaire "%s" a été %s.', $config->getLabel(), $config->isActive() ? 'réactivé' : 'désactivé'));

        return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
    }

    #[Route(path: '/{id}/helloasso/{configId}/supprimer', requirements: ['id' => '\d+', 'configId' => '\d+'], name: 'delete_helloasso_config', methods: ['POST'])]
    public function deleteHelloAssoConfig(Client $client, int $configId, Request $request): Response
    {
        $config = $this->getClientHelloAssoConfigOrNotFound($client, $configId);

        if (!$this->isCsrfTokenValid('delete_helloasso_config_' . $config->getId(), $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
        }

        if ($config === $client->getPrimaryHelloAssoConfig()) {
            $this->addFlash('error', 'Le formulaire principal d\'un client ne peut pas être supprimé, seulement désactivé.');

            return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
        }

        if ($config->isActive()) {
            $this->addFlash('error', 'Désactivez ce formulaire avant de pouvoir le supprimer.');

            return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
        }

        if ($this->paymentRepository->hasAnyForHelloAssoConfig($config)) {
            $this->addFlash('error', 'Ce formulaire a un historique de paiements : désactivez-le au lieu de le supprimer.');

            return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
        }

        // No separate "not the last form" check: the toggle guard above already
        // refuses to deactivate a client's last active form, so the one form that
        // would ever need this check can never reach the isActive() check above.
        $label = $config->getLabel();
        $client->removeHelloAssoConfig($config);
        $this->entityManager->remove($config);
        $this->entityManager->flush();
        $this->addFlash('success', \sprintf('Le formulaire "%s" a été supprimé.', $label));

        return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
    }

    private function getClientHelloAssoConfigOrNotFound(Client $client, int $configId): HelloAssoConfig
    {
        foreach ($client->getHelloAssoConfigs() as $config) {
            if ($config->getId() === $configId) {
                return $config;
            }
        }

        throw $this->createNotFoundException('Formulaire HelloAsso introuvable pour ce client.');
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

    #[Route(path: '/{id}/personnalisation', requirements: ['id' => '\d+'], name: 'edit_customization', methods: ['GET', 'POST'])]
    public function editCustomization(Client $client, Request $request): Response
    {
        $customization = $client->getCustomization();
        $isNew = $customization === null;
        if ($customization === null) {
            $customization = new ClientCustomization();
            $customization->setClient($client);
        }

        $form = $this->createForm(ClientCustomizationType::class, $customization);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isNew) {
                $client->setCustomization($customization);
                $this->entityManager->persist($customization);
            }
            $this->entityManager->flush();
            $this->addFlash('success', 'La personnalisation du client a été mise à jour.');

            return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
        }

        return $this->render('admin/client/edit_customization.html.twig', [
            'client' => $client,
            'form' => $form,
            'placeholders' => ClientCustomization::PLACEHOLDERS,
            'emailTypeLabels' => ClientCustomization::EMAIL_TYPE_LABELS,
        ]);
    }

    #[Route(path: '/{id}/fetch', requirements: ['id' => '\d+'], name: 'fetch', methods: ['POST'])]
    public function fetch(Client $client, Request $request): Response
    {
        if ($this->isCsrfTokenValid('client_fetch_' . $client->getId(), $request->request->get('_token'))) {
            // Off the request thread: pulling the history and crediting each
            // discovered payment can be several HelloAsso/Cyclos round-trips.
            $this->messageBus->dispatch(new FetchClientPaymentsMessage($client->getId(), attemptAutomaticCredit: true));
            $this->addFlash('success', 'Synchronisation HelloAsso lancée. Les paiements récupérés apparaîtront dans la liste d\'ici quelques instants.');
        }

        return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
    }

    #[Route(path: '/{id}/webhook/regenerer-jeton', requirements: ['id' => '\d+'], name: 'regenerate_webhook_token', methods: ['POST'])]
    public function regenerateWebhookToken(Client $client, Request $request): Response
    {
        if ($this->isCsrfTokenValid('regenerate_webhook_token_' . $client->getId(), $request->request->get('_token'))) {
            $client->regenerateWebhookToken();
            $this->entityManager->flush();
            $this->addFlash('success', 'Un nouveau jeton de webhook a été généré. Mettez à jour l\'URL de notification dans HelloAsso : les anciennes notifications ne seront plus acceptées.');
        }

        return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()]);
    }
}
