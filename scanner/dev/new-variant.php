<?php

/**
 * Development helper of the scanner's file-job driver: an EMPTY template + variant of a pixel size
 * in a project, because no MCP tool creates one. Runs INSIDE the app's dev container:
 *
 *   php new-variant.php <projectId> "<name>" <widthPx> <heightPx>
 *
 * Prints {"templateId", "variantId", "width", "height"}. Refuses outside APP_ENV=dev.
 */

declare(strict_types=1);

use Ramsey\Uuid\Uuid;
use Symfony\Component\Dotenv\Dotenv;
use WBoost\Web\Message\Template\AddTemplate;
use WBoost\Web\Message\Template\AddTemplateVariant;
use WBoost\Web\MessageHandler\Template\AddTemplateHandler;
use WBoost\Web\MessageHandler\Template\AddTemplateVariantHandler;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\SymfonyApplicationKernel;
use WBoost\Web\Value\DimensionPreset;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\TemplateDimension;

$root = getenv('APP_ROOT') ?: '/app';
require $root . '/vendor/autoload.php';
(new Dotenv())->bootEnv($root . '/.env');
if (($_SERVER['APP_ENV'] ?? 'dev') !== 'dev') {
    fwrite(STDERR, "refusing: APP_ENV is not dev\n");
    exit(2);
}
[$_, $projectId, $name, $w, $h] = $argv + [null, null, null, null, null];
if ($projectId === null || $name === null || $w === null || $h === null) {
    fwrite(STDERR, "usage: new-variant.php <projectId> <name> <widthPx> <heightPx>\n");
    exit(2);
}
$kernel = new SymfonyApplicationKernel('dev', true);
$kernel->boot();
$c = $kernel->getContainer();
$em = $c->get('doctrine')->getManager();
$ids = $c->get(ProvideIdentity::class);

$dimension = ((int) $w === 1080 && (int) $h === 1350)
    ? TemplateDimension::fromPreset(DimensionPreset::InstagramPortrait)
    : new TemplateDimension(DimensionUnit::Px, (float) $w, (float) $h, null, 0.0, false);
$templateId = $ids->next();
$variantId = $ids->next();
$c->get(AddTemplateHandler::class)(new AddTemplate(Uuid::fromString($projectId), $templateId, null, $name, null));
$em->flush();
$c->get(AddTemplateVariantHandler::class)(new AddTemplateVariant($templateId, $variantId, $dimension, null));
$em->flush();
echo json_encode(['templateId' => $templateId->toString(), 'variantId' => $variantId->toString(), 'width' => (int) $w, 'height' => (int) $h]) . "\n";
