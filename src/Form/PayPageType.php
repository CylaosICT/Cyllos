<?php

namespace App\Form;

use App\Entity\StripeConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Everything a client can change about the page its payers see (cyllos-pay):
 * colours, type, corners, background, wording and the flow itself. All of it is
 * optional — an empty field means "use the default". The logo is the client's own.
 *
 * @extends AbstractType<StripeConfig>
 */
class PayPageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $color = static fn (string $label, string $placeholder): array => [
            'label' => $label,
            'required' => false,
            'help' => 'Format #RRGGBB. Vide = couleur par défaut.',
            'attr' => ['placeholder' => $placeholder, 'maxlength' => 7, 'autocomplete' => 'off', 'spellcheck' => 'false', 'data-color-field' => ''],
        ];

        $builder
            // --- Colours
            ->add('brandPrimaryColor', TextType::class, $color('Couleur principale (boutons, accents)', '#2b6cb0'))
            ->add('brandBackgroundColor', TextType::class, $color('Couleur du fond de page', '#0f172a'))
            ->add('brandSurfaceColor', TextType::class, $color('Couleur de la carte', '#1e293b'))
            ->add('brandTextColor', TextType::class, $color('Couleur du texte', '#f1f5f9'))
            // --- Look
            ->add('payFont', ChoiceType::class, [
                'label' => 'Style d\'écriture',
                'required' => false,
                'placeholder' => 'Par défaut (moderne)',
                'choices' => array_flip(StripeConfig::PAY_FONTS),
            ])
            ->add('payRadius', ChoiceType::class, [
                'label' => 'Forme des coins',
                'required' => false,
                'placeholder' => 'Par défaut (légèrement arrondis)',
                'choices' => array_flip(StripeConfig::PAY_RADII),
            ])
            ->add('payBackground', ChoiceType::class, [
                'label' => 'Fond de page',
                'required' => false,
                'placeholder' => 'Par défaut (dégradé animé)',
                'choices' => array_flip(StripeConfig::PAY_BACKGROUNDS),
            ])
            // --- Wording
            ->add('payTitle', TextType::class, [
                'label' => 'Titre de la page',
                'required' => false,
                'help' => 'Par défaut : le nom du client. 80 caractères maximum.',
                'attr' => ['maxlength' => 80],
            ])
            ->add('payIntro', TextareaType::class, [
                'label' => 'Texte d\'introduction',
                'required' => false,
                'help' => 'Affiché sous le titre, à la première étape. 300 caractères maximum. Texte simple (pas de mise en forme).',
                'attr' => ['maxlength' => 300, 'rows' => 3],
                'row_attr' => ['class' => 'form-row--span2'],
            ])
            ->add('payButtonLabel', TextType::class, [
                'label' => 'Texte du bouton de paiement',
                'required' => false,
                'help' => 'Par défaut : « Payer 20,00 € ». Utilisez {montant} pour insérer le montant. 40 caractères maximum.',
                'attr' => ['maxlength' => 40, 'placeholder' => 'Régler {montant}'],
            ])
            ->add('paySuccessMessage', TextareaType::class, [
                'label' => 'Message de succès',
                'required' => false,
                'help' => 'Affiché quand le compte a été crédité. 300 caractères maximum.',
                'attr' => ['maxlength' => 300, 'rows' => 3],
                'row_attr' => ['class' => 'form-row--span2'],
            ])
            ->add('paySupportText', TextType::class, [
                'label' => 'Ligne d\'aide en bas de page',
                'required' => false,
                'help' => 'Ex. « Une question ? contact@asso.fr ». 200 caractères maximum.',
                'attr' => ['maxlength' => 200],
                'row_attr' => ['class' => 'form-row--span2'],
            ])
            // --- Flow
            ->add('payShowConfirmStep', CheckboxType::class, [
                'label' => 'Demander de confirmer le compte avant de choisir le montant',
                'required' => false,
                'help' => 'Décoché : la personne passe directement au montant dès que son compte est trouvé.',
                'row_attr' => ['class' => 'form-row--span2'],
            ])
            // --- Amounts
            ->add('payAmountMode', ChoiceType::class, [
                'label' => 'Comment la personne choisit-elle le montant ?',
                'expanded' => true,
                'required' => true,
                'choices' => array_flip(StripeConfig::AMOUNT_MODES),
                'row_attr' => ['class' => 'form-row--span2'],
            ])
            ->add('minAmount', IntegerType::class, [
                'label' => 'Montant minimum (€)',
                'help' => 'Fourchette : plus petit montant acceptable.',
            ])
            ->add('maxAmount', IntegerType::class, [
                'label' => 'Montant maximum (€)',
                'help' => 'Fourchette : plus grand montant acceptable.',
            ])
            ->add('payQuickAmounts', TextType::class, [
                'label' => 'Montants rapides (€)',
                'required' => false,
                'help' => 'Fourchette : jusqu\'à 6 boutons de raccourci, entiers séparés par des virgules, dans les bornes. Ex. 10, 20, 50, 100.',
                'attr' => ['placeholder' => '10, 20, 50, 100', 'autocomplete' => 'off'],
            ])
            ->add('payCreditRate', NumberType::class, [
                'label' => 'Crédit par euro payé',
                'required' => false,
                'scale' => 4,
                'html5' => true,
                'help' => 'Fourchette : unités créditées dans Cyclos pour 1 € payé. Vide = 1 pour 1 (ex. 1,5 : 100 € payés → 150 unités).',
                'attr' => ['min' => 0, 'step' => '0.0001', 'inputmode' => 'decimal', 'placeholder' => '1'],
            ])
            ->add('payAmountChoices', TextType::class, [
                'label' => 'Montants proposés (€)',
                'required' => false,
                'help' => 'Séparés par des virgules, 12 au plus. Pour créditer un autre montant que celui payé : « payé:crédité ». Ex. 50, 100:150, 200:320.',
                'attr' => ['placeholder' => '50, 100:150, 200', 'autocomplete' => 'off'],
                'row_attr' => ['class' => 'form-row--span2'],
            ])
            ->add('payFixedAmount', IntegerType::class, [
                'label' => 'Montant payé (€)',
                'required' => false,
                'help' => 'Montant unique que la personne règle sur Stripe.',
                'attr' => ['min' => 1],
            ])
            ->add('payFixedCredit', IntegerType::class, [
                'label' => 'Montant crédité dans Cyclos',
                'required' => false,
                'help' => 'Facultatif. Vide = identique au montant payé (ex. 100 € payés, 150 unités créditées).',
                'attr' => ['min' => 1],
            ])
            ->add('payCreditUnit', TextType::class, [
                'label' => 'Nom de l\'unité créditée',
                'required' => false,
                'help' => 'Affiché à côté du montant crédité (ex. « unités », « points »). Vide = « € », ou « unités » quand le crédit diffère du paiement.',
                'attr' => ['maxlength' => 20, 'placeholder' => 'unités'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => StripeConfig::class]);
    }
}
