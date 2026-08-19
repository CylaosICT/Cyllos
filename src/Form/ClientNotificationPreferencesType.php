<?php

namespace App\Form;

use App\Entity\ClientSetting;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ClientNotificationPreferencesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('notifySuccessOnPayment', CheckboxType::class, [
                'label' => 'Recevoir un e-mail à chaque paiement réussi',
                'required' => false,
            ])
            ->add('notifyFailureOnPayment', CheckboxType::class, [
                'label' => 'Recevoir un e-mail en cas d\'échec de paiement',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ClientSetting::class,
        ]);
    }
}
