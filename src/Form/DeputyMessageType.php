<?php

namespace AppBundle\Form;

use AppBundle\Deputy\DeputyMessage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DeputyMessageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('subject', TextType::class, [
                'label' => false,
                'attr' => ['placeholder' => 'Entrez l\'objet de votre message'],
                'filter_emojis' => true,
            ])
            ->add('content', PurifiedTextareaType::class, [
                'label' => false,
                'attr' => [
                    'maxlength' => 5000,
                    'placeholder' => 'Écrivez votre message',
                ],
                'filter_emojis' => true,
                'purifier_type' => 'enrich_content',
                'with_character_count' => true,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefault('data_class', DeputyMessage::class);
    }
}