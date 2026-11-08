<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Šířka loga v manuálu" switches from "% of the frame" to "% of the logo's
 * DEFAULT size" (LogoScale, 2026-11-09).
 *
 * The default size is a CSS layout result (card width × height cap × SVG
 * aspect ratio), so it cannot be computed in SQL. Every prod card with a
 * width was MEASURED in Chromium on the public preview (wboost.cz, 1440 and
 * 1920 px — identical, the manual is fixed-width): new = legacy rendered
 * width / default width × 100 (LogoScale::fromLegacyFramePercent). Cards
 * whose ratio came out equal to the stored value (the default already fills
 * the frame — most of them) need no row change and are not listed.
 *
 * Every UPDATE is guarded by the OLD value, so a width edited after the
 * measurement — or a second run — is left alone.
 *
 * logo-spolecne: its verticalWithClaim variant-level 85 renders 85 % on the
 * own-page cards (unchanged) but 111 % of the default on "Minimální
 * rozměry" — that card gets its own override. logos_with_claim.
 * horizontalWithClaim.base is currently hidden (own-page variant): converted
 * from the SVG aspect ratio (300×55) and the page geometry (808 px frame,
 * 120 px cap) of the visible sibling card, 58 → 72.
 */
final class Version20261109010000 extends AbstractMigration
{
    private const array SLOT_CONVERSIONS = [
        // celadna-logotyp-obce
        ['01945eab-5329-72c0-b5e7-d225c63fae8e', 'basic_logos.symbol.base', 75, 83],
        ['01945eab-5329-72c0-b5e7-d225c63fae8e', 'vertical_backgrounds.vertical.darkBackground', 75, 79],
        ['01945eab-5329-72c0-b5e7-d225c63fae8e', 'vertical_backgrounds.vertical.lightBackground', 75, 79],
        ['01945eab-5329-72c0-b5e7-d225c63fae8e', 'vertical_backgrounds.vertical.oneColor', 75, 79],
        ['01945eab-5329-72c0-b5e7-d225c63fae8e', 'vertical_monochrome.vertical.whiteBackground', 75, 79],
        ['01945eab-5329-72c0-b5e7-d225c63fae8e', 'vertical_monochrome.vertical.blackBackground', 75, 79],
        // fotbal-celadna
        ['01944f92-05c1-72bd-a7e6-6d5b67726443', 'basic_logos.symbol.base', 55, 84],
        // sagena
        ['01a1ad3e-7f0b-7354-81cd-a8d1ffb256bb', 'basic_logos.symbol.base', 55, 64],
        ['01a1ad3e-7f0b-7354-81cd-a8d1ffb256bb', 'vertical_backgrounds.vertical.darkBackground', 55, 69],
        ['01a1ad3e-7f0b-7354-81cd-a8d1ffb256bb', 'vertical_backgrounds.vertical.lightBackground', 65, 81],
        ['01a1ad3e-7f0b-7354-81cd-a8d1ffb256bb', 'vertical_backgrounds.vertical.oneColor', 65, 81],
        ['01a1ad3e-7f0b-7354-81cd-a8d1ffb256bb', 'vertical_monochrome.vertical.whiteBackground', 65, 81],
        ['01a1ad3e-7f0b-7354-81cd-a8d1ffb256bb', 'vertical_monochrome.vertical.blackBackground', 65, 81],
        // logo-spolecne (hidden card, see above)
        ['019d9b49-992c-73d7-8dc1-bfab2039aac7', 'logos_with_claim.horizontalWithClaim.base', 58, 72],
    ];

    public function getDescription(): string
    {
        return 'Manual logo widths: % of the frame → % of the default size (measured per card).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::SLOT_CONVERSIONS as [$manualId, $slot, $old, $new]) {
            $this->addSql(
                "UPDATE manual SET logo_slot_widths = jsonb_set(logo_slot_widths::jsonb, ARRAY[:slot], to_jsonb(CAST(:new AS int)))::json
                 WHERE id = :id AND logo_slot_widths::jsonb ->> :slot = :old",
                ['id' => $manualId, 'slot' => $slot, 'new' => $new, 'old' => (string) $old],
            );
        }

        // logo-spolecne "Minimální rozměry" inherits the variant's 85 — that
        // is 111 % of this card's default; pin it so the card stays as it is.
        $this->addSql(
            "UPDATE manual SET logo_slot_widths = jsonb_set(logo_slot_widths::jsonb, '{minimum_dimensions.verticalWithClaim.base}', '111')::json
             WHERE id = '019d9b49-992c-73d7-8dc1-bfab2039aac7'
               AND NOT jsonb_exists(logo_slot_widths::jsonb, 'minimum_dimensions.verticalWithClaim.base')
               AND logo -> 'verticalWithClaim' ->> 'displayWidth' = '85'",
        );
    }

    public function down(Schema $schema): void
    {
        foreach (self::SLOT_CONVERSIONS as [$manualId, $slot, $old, $new]) {
            $this->addSql(
                "UPDATE manual SET logo_slot_widths = jsonb_set(logo_slot_widths::jsonb, ARRAY[:slot], to_jsonb(CAST(:old AS int)))::json
                 WHERE id = :id AND logo_slot_widths::jsonb ->> :slot = :new",
                ['id' => $manualId, 'slot' => $slot, 'old' => $old, 'new' => (string) $new],
            );
        }

        $this->addSql(
            "UPDATE manual SET logo_slot_widths = (logo_slot_widths::jsonb - 'minimum_dimensions.verticalWithClaim.base')::json
             WHERE id = '019d9b49-992c-73d7-8dc1-bfab2039aac7'
               AND logo_slot_widths::jsonb ->> 'minimum_dimensions.verticalWithClaim.base' = '111'",
        );
    }
}
