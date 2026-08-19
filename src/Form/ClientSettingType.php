<?php

namespace App\Form;

use App\Entity\ClientSetting;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ClientSettingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('paymentCyclosEnabled', CheckboxType::class, [
                'label' => 'Activer les paiements vers Cyclos',
                'required' => false,
                'help' => "Si désactivé, les paiements sont exécutés en mode aperçu ('preview') sans créditer réellement le compte.",
                'row_attr' => ['class' => 'form-row--span2'],
            ])
            ->add('paymentAutomaticEnabled', CheckboxType::class, [
                'label' => 'Activer les paiements automatiques',
                'required' => false,
                'help' => 'Si désactivé, les paiements reçus doivent être crédités manuellement.',
                'row_attr' => ['class' => 'form-row--span2'],
            ])
            ->add('mailRecipient', EmailType::class, [
                'label' => 'Mail principal',
                'help' => "Adresse recevant les alertes techniques (et les notifications de paiement si activées ci-dessous).",
                'row_attr' => ['class' => 'form-row--span2'],
            ])
            ->add('notifySuccessOnPayment', CheckboxType::class, [
                'label' => 'Notification de paiement réussi',
                'required' => false,
                'help' => "Envoie un e-mail à l'adresse de contact du client (voir Informations) à chaque paiement automatique réussi.",
                'row_attr' => ['class' => 'form-row--span2'],
            ])
            ->add('notifyFailureOnPayment', CheckboxType::class, [
                'label' => 'Notification de paiement en échec',
                'required' => false,
                'help' => "Envoie un e-mail à l'adresse de contact du client (voir Informations) à chaque paiement automatique en échec.",
                'row_attr' => ['class' => 'form-row--span2'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ClientSetting::class,
        ]);
    }
}
