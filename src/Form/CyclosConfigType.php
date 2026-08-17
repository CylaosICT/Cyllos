<?php

namespace App\Form;

use App\Entity\CyclosConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class CyclosConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('baseUrl', TextType::class, [
                'label' => 'URL de base Cyclos (API)',
                'row_attr' => ['class' => 'form-row--span2'],
            ])
            ->add('technicalUserId', TextType::class, [
                'label' => "Identifiant de l'utilisateur technique",
                'help' => "Identifiant interne (numérique) de l'utilisateur technique dans Cyclos, pas son login.",
            ])
            ->add('password', PasswordType::class, [
                'label' => 'Mot de passe',
                'mapped' => false,
                'required' => $options['secret_required'],
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => $options['secret_required'] ? [new Assert\NotBlank()] : [],
                'help' => $options['secret_required'] ? null : 'Laisser vide pour conserver le mot de passe actuel.',
            ])
            ->add('groupProInternal', TextType::class, [
                'label' => 'Nom interne du groupe "professionnels"',
            ])
            ->add('groupsPartInternal', TextType::class, [
                'label' => 'Nom(s) interne(s) du/des groupe(s) "particuliers"',
                'help' => 'Séparer plusieurs groupes par une virgule.',
            ])
            ->add('emissionProInternal', TextType::class, [
                'label' => "Nom interne de l'émission pour les professionnels",
            ])
            ->add('emissionPartInternal', TextType::class, [
                'label' => "Nom interne de l'émission pour les particuliers",
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CyclosConfig::class,
            'secret_required' => true,
        ]);
    }
}
