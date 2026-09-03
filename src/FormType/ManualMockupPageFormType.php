<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use WBoost\Web\FormData\ManualMockupPageFormData;
use WBoost\Web\Value\MockupPageLayout;

/**
 * @extends AbstractType<ManualMockupPageFormData>
 */
final class ManualMockupPageFormType extends AbstractType
{
    /**
     * Symfony reads the `m` suffix as DECIMAL megabytes, so this is
     * 20 000 000 bytes. Mirrored client-side by the mockup page editor.
     */
    public const string DOWNLOAD_MAX_SIZE = '20m';

    public const int DOWNLOAD_MAX_SIZE_BYTES = 20000000;

    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Název',
            'required' => true,
            'constraints' => [
                new NotBlank(message: 'Zadejte název stránky.'),
            ],
        ]);

        if ($options['allow_layout_choice'] === true) {
            $builder->add('layout', EnumType::class, [
                'class' => MockupPageLayout::class,
                'expanded' => true,
                'label' => false,
                'constraints' => [
                    new NotNull(message: 'Vyberte rozložení stránky.'),
                ],
            ]);
        }

        $builder->add('removeImages', CollectionType::class, [
            'entry_type' => HiddenType::class,
            'entry_options' => [
                'label' => false,
            ],
            'allow_add' => false,
            'allow_delete' => false,
        ]);

        $builder->add('images', CollectionType::class, [
            'entry_type' => FileType::class,
            'entry_options' => [
                'label' => false,
                'required' => false,
                'constraints' => [
                    new Image(
                        maxSize: '10m',
                    ),
                ],
            ],
            'allow_add' => false,
            'allow_delete' => false,
            'by_reference' => false,
        ]);

        // Downloadable attachments are any file type on purpose — the point is
        // handing the reader the source behind a mockup (print PDF, packaged
        // ZIP, vector original). The cap is bigger than the 10 MB images take
        // because those are what such files weigh, and it is still far inside
        // the 50 MB `post_max_size` the whole form has to fit in.
        $builder->add('downloadFile', FileType::class, [
            'label' => false,
            'required' => false,
            'constraints' => [
                new File(
                    maxSize: self::DOWNLOAD_MAX_SIZE,
                ),
            ],
        ]);

        $builder->add('removeDownloadFile', HiddenType::class, [
            'label' => false,
        ]);

        $builder->add('imageDownloads', CollectionType::class, [
            'entry_type' => FileType::class,
            'entry_options' => [
                'label' => false,
                'required' => false,
                'constraints' => [
                    new File(
                        maxSize: self::DOWNLOAD_MAX_SIZE,
                    ),
                ],
            ],
            'allow_add' => false,
            'allow_delete' => false,
            'by_reference' => false,
        ]);

        $builder->add('removeImageDownloads', CollectionType::class, [
            'entry_type' => HiddenType::class,
            'entry_options' => [
                'label' => false,
            ],
            'allow_add' => false,
            'allow_delete' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ManualMockupPageFormData::class,
            'allow_layout_choice' => false,
        ]);

        $resolver->setAllowedTypes('allow_layout_choice', 'bool');
    }
}
