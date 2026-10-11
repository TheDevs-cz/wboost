<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * A switchable project module. A project lists the modules it uses
 * ({@see \WBoost\Web\Entity\Project::$modules}); the left navigation and the
 * dashboard show only those, and the rest are offered on the "Objevte další
 * moduly" page, where a project editor can switch one on (the admins get an
 * e-mail). Switching a module off only hides it — no data is deleted.
 *
 * Galerie and Fonty are NOT modules: other modules draw on them, so they are
 * always available. Kalendáře isn't built yet, so it isn't one either.
 */
enum ProjectModule: string
{
    case Manuals = 'manuals';
    case Templates = 'templates';
    case EmailSignatures = 'email_signatures';
    case WeeklyMenus = 'weekly_menus';

    /**
     * What a new project starts with — the core product.
     *
     * @return list<self>
     */
    public static function defaults(): array
    {
        return [self::Manuals, self::Templates];
    }

    public function label(): string
    {
        return match ($this) {
            self::Manuals => 'Manuály',
            self::Templates => 'Šablony',
            self::EmailSignatures => 'E-maily',
            self::WeeklyMenus => 'Jídelníčky',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Manuals => 'Brand a logo manuály projektu.',
            self::Templates => 'Šablony příspěvků, tiskovin a grafik.',
            self::EmailSignatures => 'Šablony e-mailových podpisů ve firemních barvách a písmech, aby celý tým podepisoval e-maily jednotně.',
            self::WeeklyMenus => 'Týdenní jídelníčky se seznamem jídel, dietami a druhy jídel — pro kantýny, restaurace a stravovací provozy.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Manuals => 'uil-copy-alt',
            self::Templates => 'uil-postcard',
            self::EmailSignatures => 'ri-mail-line',
            self::WeeklyMenus => 'uil-restaurant',
        };
    }

    /**
     * The module's landing route and its project parameter name (the routes
     * predate a shared convention: some take `id`, some `projectId`).
     *
     * @return array{string, string}
     */
    public function landingRoute(): array
    {
        return match ($this) {
            self::Manuals => ['manuals_list', 'id'],
            self::Templates => ['templates', 'projectId'],
            self::EmailSignatures => ['email_signature_templates', 'id'],
            self::WeeklyMenus => ['weekly_menus', 'projectId'],
        };
    }
}
