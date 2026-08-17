<?php

namespace App\Form;

use App\Entity\HelloAssoConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class HelloAssoConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('apiUrl', TextType::class, [
                'label' => "URL de l'API HelloAsso",
                'row_attr' => ['class' => 'form-row--span2'],
            ])
            ->add('helloAssoClientId', TextType::class, [
                'label' => 'Client ID',
            ])
            ->add('clientSecret', PasswordType::class, [
                'label' => 'Client secret',
                'mapped' => false,
                'required' => $options['secret_required'],
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => $options['secret_required'] ? [new Assert\NotBlank()] : [],
                'help' => $options['secret_required'] ? null : 'Laisser vide pour conserver le secret actuel.',
            ])
            ->add('organizationSlug', TextType::class, [
                'label' => "Slug de l'organisation",
            ])
            ->add('formType', ChoiceType::class, [
                'label' => 'Type de formulaire',
                'choices' => [
                    'Cagnotte / Financement participatif (CrowdFunding)' => 'CrowdFunding',
                    'Paiement (PaymentForm)' => 'PaymentForm',
                    'Adhésion (Membership)' => 'Membership',
                    'Événement (Event)' => 'Event',
                    'Don (Donation)' => 'Donation',
                    'Boutique (Shop)' => 'Shop',
                ],
                'help' => "Doit correspondre exactement au type de la campagne dans HelloAsso, sinon la synchro ne trouvera aucun paiement.",
            ])
            ->add('formSlug', TextType::class, [
                'label' => 'Slug du formulaire',
            ])
            ->add('maxAmount', IntegerType::class, [
                'label' => 'Montant maximum autorisé (€)',
            ])
            ->add('extraMailFieldName', TextType::class, [
                'label' => "Nom du champ personnalisé pour l'email alternatif",
                'required' => false,
                'row_attr' => ['class' => 'form-row--span2'],
            ])
            ->add('fetchNbDays', IntegerType::class, [
                'label' => 'Nombre de jours à récupérer lors de la synchro',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => HelloAssoConfig::class,
            'secret_required' => true,
        ]);
    }
}
