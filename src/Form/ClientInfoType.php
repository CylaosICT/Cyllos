<?php

namespace App\Form;

use App\Entity\Client;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Step 1 of the client creation wizard / the "informations" edit page: the
 * client's identity, independent of its HelloAsso and Cyclos configuration.
 */
class ClientInfoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom du client',
                'attr' => ['placeholder' => 'Ex : Le Réelon'],
            ])
            ->add('slug', TextType::class, [
                'label' => 'Slug',
                'attr' => ['placeholder' => 'ex : reelon'],
                'help' => "Utilisé dans l'URL du webhook HelloAsso : /webhook/helloasso/{slug}",
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Client actif',
                'required' => false,
            ]);

        if ($options['with_logo']) {
            $builder->add('logoFile', FileType::class, [
                'label' => 'Logo du client',
                'mapped' => false,
                'required' => false,
                'help' => 'PNG, JPG ou SVG, 2 Mo maximum.',
                'constraints' => [
                    new Assert\File(
                        maxSize: '2M',
                        mimeTypes: ['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp'],
                        mimeTypesMessage: 'Merci de déposer une image (PNG, JPG, WEBP ou SVG).',
                    ),
                ],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Client::class,
            'with_logo' => false,
        ]);
    }
}
