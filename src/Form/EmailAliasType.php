<?php

namespace App\Form;

use App\Entity\EmailAlias;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Creates a persistent email correction rule for a client, from its admin
 * profile page — see EmailAlias for what it does.
 */
class EmailAliasType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('sourceEmail', EmailType::class, [
                'label' => 'E-mail utilisé sur HelloAsso',
                'help' => "L'email exact que le payeur a utilisé sur HelloAsso, et qui ne correspond à aucun compte Cyclos.",
                'attr' => ['placeholder' => 'payeur@exemple.com'],
            ])
            ->add('targetEmail', EmailType::class, [
                'label' => 'E-mail réel du compte Cyclos',
                'help' => 'Utilisé à la place, pour chaque futur paiement reçu avec cet email HelloAsso.',
                'attr' => ['placeholder' => 'compte-cyclos@exemple.com'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EmailAlias::class,
        ]);
    }
}
