<?php

namespace App\Form;

use App\Entity\ClientCustomization;
use App\Integration\Cyclos\CyclosClient;
use App\Notification\EmailComposer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin-only editor for a client's ClientCustomization.
 *
 * The scalar overrides (subject prefix, footer, Cyclos description prefix,
 * preview label) map straight onto the entity. The per-type e-mail templates
 * live in a single JSON column, so they're handled as unmapped
 * subject/body fields that a POST_SET_DATA listener fills from the entity and
 * a SUBMIT listener folds back into ClientCustomization::setEmailTemplates()
 * (dropping blank entries, so "cleared" means "back to the default text").
 *
 * Every field shows its effective default as an HTML placeholder, so an admin
 * always sees the text that applies when the field is left empty.
 */
class ClientCustomizationType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('emailSubjectPrefix', TextType::class, [
                'label' => 'Préfixe des sujets d’e-mail',
                'required' => false,
                'attr' => ['placeholder' => EmailComposer::DEFAULT_SUBJECT_PREFIX],
                'help' => 'Placé devant chaque sujet. Vide = "[Cyllos]" par défaut ; un espace seul = aucun préfixe.',
            ])
            ->add('emailFooter', TextareaType::class, [
                'label' => 'Pied de page des e-mails',
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => 'Aucun pied de page par défaut'],
                'help' => 'Ajouté après une ligne vide au bas de chaque e-mail (signature, mentions, contact…).',
                'row_attr' => ['class' => 'form-row--span2'],
            ])
            ->add('cyclosDescriptionPrefix', TextType::class, [
                'label' => 'Préfixe de description des transactions Cyclos',
                'required' => false,
                // Keep the trailing space the admin types: the HelloAsso id is
                // concatenated right after, so "Recharge instantanée " must not
                // be trimmed to "Recharge instantanée".
                'trim' => false,
                'attr' => ['placeholder' => CyclosClient::PAYMENT_DESCRIPTION_PREFIX],
                'help' => 'L’identifiant HelloAsso est ajouté immédiatement à la suite — pensez à l’espace final. '
                    . 'Vide = « Recharge instantanée N°  ». '
                    . 'Le contrôle anti-doublon reconnaît aussi l’ancien libellé après un changement.',
                'row_attr' => ['class' => 'form-row--span2'],
            ])
            ->add('previewModeLabel', TextType::class, [
                'label' => 'Libellé du mode aperçu',
                'required' => false,
                'attr' => ['placeholder' => EmailComposer::DEFAULT_PREVIEW_MODE_LABEL],
                'help' => 'Valeur du champ %mode% quand les paiements Cyclos sont désactivés. Vide = "aperçu (non crédité)".',
            ]);

        $placeholderConstraint = new Assert\Callback([self::class, 'validatePlaceholders']);

        foreach (array_keys(ClientCustomization::EMAIL_TYPE_LABELS) as $type) {
            $builder
                ->add($type . '__subject', TextType::class, [
                    'label' => 'Sujet',
                    'required' => false,
                    'mapped' => false,
                    'attr' => ['placeholder' => $this->translator->trans($type . '.subject', [], 'emails')],
                    'constraints' => [new Assert\Length(max: 200), $placeholderConstraint],
                ])
                ->add($type . '__body', TextareaType::class, [
                    'label' => 'Corps',
                    'required' => false,
                    'mapped' => false,
                    'attr' => ['rows' => 4, 'placeholder' => $this->translator->trans($type . '.body', [], 'emails')],
                    'constraints' => [new Assert\Length(max: 2000), $placeholderConstraint],
                    'row_attr' => ['class' => 'form-row--span2'],
                ]);
        }

        $builder->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event): void {
            $customization = $event->getData();
            $templates = $customization instanceof ClientCustomization ? $customization->getEmailTemplates() : [];
            $form = $event->getForm();

            foreach (array_keys(ClientCustomization::EMAIL_TYPE_LABELS) as $type) {
                $form->get($type . '__subject')->setData($templates[$type]['subject'] ?? '');
                $form->get($type . '__body')->setData($templates[$type]['body'] ?? '');
            }
        });

        $builder->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event): void {
            $customization = $event->getData();
            if (!$customization instanceof ClientCustomization) {
                return;
            }

            $form = $event->getForm();
            $templates = [];

            foreach (array_keys(ClientCustomization::EMAIL_TYPE_LABELS) as $type) {
                $subject = trim((string) $form->get($type . '__subject')->getData());
                $body = trim((string) $form->get($type . '__body')->getData());

                $entry = [];
                if ($subject !== '') {
                    $entry['subject'] = $subject;
                }
                if ($body !== '') {
                    $entry['body'] = $body;
                }
                if ($entry !== []) {
                    $templates[$type] = $entry;
                }
            }

            $customization->setEmailTemplates($templates);
        });
    }

    public static function validatePlaceholders(?string $value, ExecutionContextInterface $context): void
    {
        if ($value === null || $value === '') {
            return;
        }

        preg_match_all('/%[a-zA-Z_]+%/', $value, $matches);
        $unknown = array_values(array_diff(array_unique($matches[0]), array_keys(ClientCustomization::PLACEHOLDERS)));

        if ($unknown !== []) {
            $allowed = implode(' ', array_keys(ClientCustomization::PLACEHOLDERS));
            $context->buildViolation('Variable(s) non reconnue(s) : {{ tokens }}. Variables disponibles : {{ allowed }}.')
                ->setParameter('{{ tokens }}', implode(', ', $unknown))
                ->setParameter('{{ allowed }}', $allowed)
                ->addViolation();
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ClientCustomization::class,
        ]);
    }
}
