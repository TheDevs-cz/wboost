<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * What KIND of object a stored file is, derived purely from its storage key
 * prefix — the namespace each writer picked. Deliberately independent of what
 * (if anything) references the object in the database: the category describes
 * where the bytes live, the reference describes whether they are still in use.
 *
 * Keeping the two separate is what makes a mismatch visible instead of silent
 * — e.g. email-signature backgrounds written by the EDIT handler land under
 * `manuals/` while the ADD handler writes them under `emails/`, so they show up
 * as {@see self::Manual} objects referenced by `email_signature_template`.
 */
enum StorageCategory: string
{
    case GalleryImage = 'gallery_image';
    case Manual = 'manual';
    case SocialNetwork = 'social_network';
    case Template = 'template';
    case Preview = 'preview';
    case Font = 'font';
    case EmailSignature = 'email_signature';
    case SocialPublish = 'social_publish';
    case Thumbnail = 'thumbnail';
    case Other = 'other';

    public static function fromPath(string $path): self
    {
        // Previews are checked first — they are nested under the two template
        // namespaces but are regenerated output, not designer-uploaded input.
        return match (true) {
            str_starts_with($path, 'social-networks/preview/'),
            str_starts_with($path, 'custom-templates/preview/') => self::Preview,
            str_starts_with($path, 'file-upload/') => self::GalleryImage,
            str_starts_with($path, 'manuals/') => self::Manual,
            str_starts_with($path, 'social-networks/') => self::SocialNetwork,
            str_starts_with($path, 'custom-templates/') => self::Template,
            str_starts_with($path, 'fonts/') => self::Font,
            str_starts_with($path, 'emails/') => self::EmailSignature,
            str_starts_with($path, 'social-publish/') => self::SocialPublish,
            str_starts_with($path, 'thumbnails/') => self::Thumbnail,
            default => self::Other,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::GalleryImage => 'Galerie',
            self::Manual => 'Manuál',
            self::SocialNetwork => 'Sociální sítě',
            self::Template => 'Šablony',
            self::Preview => 'Náhled',
            self::Font => 'Font',
            self::EmailSignature => 'E-mailový podpis',
            self::SocialPublish => 'Publikace na sítě',
            self::Thumbnail => 'Miniatura',
            self::Other => 'Ostatní',
        };
    }

    /**
     * Objects the app writes as disposable by-products rather than as user
     * content. They have no DB reference by design, so counting them as
     * "orphans needing cleanup" would be noise.
     */
    public function isTransient(): bool
    {
        return $this === self::SocialPublish || $this === self::Thumbnail;
    }
}
