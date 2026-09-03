<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use WBoost\Web\FormData\UploadFontFormData;

/**
 * ONE font file per request — the dropzone posts a batch as sequential
 * requests (the gallery uploader pattern), so a refused file never blocks the
 * rest. Field prefix `upload_font_form[file]`, CSRF token id `upload_font`.
 *
 * @extends AbstractType<UploadFontFormData>
 */
final class UploadFontFormType extends AbstractType
{
    public const string CSRF_TOKEN_ID = 'upload_font';

    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('file', FileType::class, [
            'required' => true,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => UploadFontFormData::class,
            'csrf_token_id' => self::CSRF_TOKEN_ID,
        ]);
    }
}
