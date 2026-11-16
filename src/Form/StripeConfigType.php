<?php

namespace App\Form;

use App\Entity\StripeConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A client's Stripe credentials and amount limits. The two secrets are not
 * mapped: the controller encrypts them (SecretEncryptor) and sets the
 * *Encrypted fields. On edit, leaving a secret blank keeps the current one.
 *
 * @extends AbstractType<StripeConfig>
 */
class StripeConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $required = $options['secret_required'];

        $builder
            ->add('secretKey', PasswordType::class, [
                'label' => 'Clé API Stripe (restreinte ou secrète)',
                'mapped' => false,
                'required' => $required,
                'always_empty' => true,
                'attr' => ['autocomplete' => 'new-password', 'placeholder' => 'rk_live_… ou sk_live_…'],
                'constraints' => array_filter([
                    $required ? new Assert\NotBlank() : null,
                    new Assert\Regex(
                        pattern: StripeConfig::SECRET_KEY_PATTERN,
                        message: 'Clé invalide : attendu un format sk_test_…, sk_live_…, rk_test_… ou rk_live_… (jamais une clé publiable pk_…).',
                    ),
                ]),
                'help' => $required
                    ? 'Idéalement une clé restreinte (rk_…) limitée à « Checkout Sessions » (écriture) et « PaymentIntents / Charges » (lecture).'
                    : 'Laisser vide pour conserver la clé actuelle.',
                'row_attr' => ['class' => 'form-row--span2'],
            ])
            ->add('webhookSecret', PasswordType::class, [
                'label' => 'Secret de signature du webhook',
                'mapped' => false,
                'required' => $required,
                'always_empty' => true,
                'attr' => ['autocomplete' => 'new-password', 'placeholder' => 'whsec_…'],
                'constraints' => array_filter([
                    $required ? new Assert\NotBlank() : null,
                    new Assert\Regex(
                        pattern: StripeConfig::WEBHOOK_SECRET_PATTERN,
                        message: 'Secret invalide : attendu un format whsec_….',
                    ),
                ]),
                'help' => $required
                    ? 'Affiché par Stripe à la création du endpoint webhook (Développeurs → Webhooks).'
                    : 'Laisser vide pour conserver le secret actuel.',
                'row_attr' => ['class' => 'form-row--span2'],
            ])
            ->add('minAmount', IntegerType::class, [
                'label' => 'Montant minimum par paiement (€)',
            ])
            ->add('maxAmount', IntegerType::class, [
                'label' => 'Montant maximum par paiement (€)',
            ]);

        if (!$options['branding']) {
            return;
        }

        $builder
            ->add('brandPrimaryColor', TextType::class, [
                'label' => 'Couleur principale (boutons, accents)',
                'required' => false,
                'help' => 'Format #RRGGBB. Laisser vide pour le thème par défaut.',
                'attr' => ['placeholder' => '#2b6cb0', 'maxlength' => 7, 'autocomplete' => 'off', 'spellcheck' => 'false'],
            ])
            ->add('brandBackgroundColor', TextType::class, [
                'label' => 'Couleur du fond de page',
                'required' => false,
                'help' => 'Format #RRGGBB. Laisser vide pour le thème par défaut.',
                'attr' => ['placeholder' => '#0f172a', 'maxlength' => 7, 'autocomplete' => 'off', 'spellcheck' => 'false'],
            ])
            ->add('brandSurfaceColor', TextType::class, [
                'label' => 'Couleur de la carte',
                'required' => false,
                'help' => 'Format #RRGGBB. Laisser vide pour le thème par défaut.',
                'attr' => ['placeholder' => '#1e293b', 'maxlength' => 7, 'autocomplete' => 'off', 'spellcheck' => 'false'],
            ])
            ->add('brandTextColor', TextType::class, [
                'label' => 'Couleur du texte',
                'required' => false,
                'help' => 'Format #RRGGBB. Laisser vide pour le thème par défaut.',
                'attr' => ['placeholder' => '#f1f5f9', 'maxlength' => 7, 'autocomplete' => 'off', 'spellcheck' => 'false'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => StripeConfig::class,
            'secret_required' => true,
            // The wizard also asks for the page colours; the edit page leaves them to the dedicated "payment page" form.
            'branding' => true,
        ]);
        $resolver->setAllowedTypes('secret_required', 'bool');
        $resolver->setAllowedTypes('branding', 'bool');
    }
}
