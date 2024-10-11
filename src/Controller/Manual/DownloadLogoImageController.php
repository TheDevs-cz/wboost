<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Services\SvgColorsMapper;
use WBoost\Web\Value\ImageFormat;

final class DownloadLogoImageController extends AbstractController
{
    public function __construct(
        readonly private SvgColorsMapper $svgColorsMapper,
    ) {
    }

    #[Route(path: '/stahnout-logo/{manualId}/{logo}.{format}', name: 'download_logo_image')]
    public function __invoke(
        #[MapEntity(id: 'manualId')]
        Manual $manual,
        string $logo,
        ImageFormat $format,
        Request $request,
    ): Response {
        /** @var null|array<string, string> $colorsMapping */
        $colorsMapping = $request->get('colorsMapping');

        if (is_array($colorsMapping) === false) {
            $colorsMapping = [];
        }

        /** @var null|string $backgroundQuery */
        $backgroundQuery = $request->get('background');
        $backgroundColor = '#' . ($backgroundQuery ?? 'ffffff');

        $image = match ($logo) {
            'horizontal' => $manual->logo->horizontal,
            'horizontalWithClaim' => $manual->logo->horizontalWithClaim,
            'vertical' => $manual->logo->vertical,
            'verticalWithClaim' => $manual->logo->verticalWithClaim,
            'symbol' => $manual->logo->symbol,
            default => throw $this->createNotFoundException('Unknown logo type'),
        };

        if ($image === null) {
            throw $this->createNotFoundException('Logo type not uploaded');
        }

        $imageContent = $this->svgColorsMapper->map($image->filePath, $colorsMapping);

        if ($format !== ImageFormat::SVG) {
            $imageContent = $this->inlineSvg($imageContent);
        }

        if ($format === ImageFormat::PNG) {
            $imagick = new \Imagick();
            $imagick->setResolution(300, 300);
            $imagick->setBackgroundColor(new \ImagickPixel('transparent'));
            $imagick->readImageBlob($imageContent);
            $imagick->setImageFormat('png32');
            $imageContent = $imagick->getImageBlob();
        }

        if ($format === ImageFormat::JPG) {
            $imagick = new \Imagick();
            $imagick->setResolution(300, 300);
            $imagick->setBackgroundColor(new \ImagickPixel($backgroundColor));
            $imagick->readImageBlob($imageContent);
            $imagick = $imagick->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality(100);
            $imageContent = $imagick->getImageBlob();
        }

        $downloadedFileName = $manual->project->slug . "-logo-" . $logo . '.' . $format->value;

        return new Response($imageContent, headers: [
            'Content-Type' => $format->contentType(),
            'Content-Disposition' => 'attachment; filename="' . $downloadedFileName . '"',
        ]);
    }

    function inlineSvg(string $svgContent): string
    {
        // Suppress libxml errors and allow user to handle them
        libxml_use_internal_errors(true);

        $doc = new \DOMDocument();

        // Load the SVG content into the DOMDocument
        if (!$doc->loadXML($svgContent)) {
            throw new \Exception('Failed to load SVG content into DOMDocument.');
        }

        // Extract and remove <style> tags
        $styleTags = $doc->getElementsByTagName('style');
        $cssText = '';
        $styleNodesToRemove = [];

        foreach ($styleTags as $styleTag) {
            $cssText .= $styleTag->nodeValue ?? '';
            $styleNodesToRemove[] = $styleTag;
        }

        // Remove the <style> tags from the document
        foreach ($styleNodesToRemove as $styleTag) {
            if ($styleTag->parentNode !== null) {
                $styleTag->parentNode->removeChild($styleTag);
            }
        }

        // If there's no CSS to process, return the original SVG content
        if (trim($cssText) === '') {
            return $svgContent;
        }

        // Parse CSS styles
        preg_match_all('/([^{]+)\{([^}]+)\}/', $cssText, $matches, PREG_SET_ORDER);

        $classStyles = [];

        foreach ($matches as $match) {
            $selectors = $match[1];
            $styleRules = trim($match[2]);

            // Split selectors by commas to handle multiple selectors
            $selectorArray = explode(',', $selectors);
            foreach ($selectorArray as $selector) {
                $selector = trim($selector);

                // Only process class selectors (starting with '.')
                if (!str_contains($selector, '.')) {
                    continue; // Skip non-class selectors
                }

                // Remove any pseudo-classes or combinators
                $selector = preg_replace('/(:[\w-]+)|(\s+[>+~]?\s+)/', '', $selector);

                // Extract class names
                preg_match_all('/\.([a-zA-Z0-9_-]+)/', $selector ?? '', $classMatches);
                if (!empty($classMatches[1])) {
                    foreach ($classMatches[1] as $className) {
                        // Initialize styles array for the class if not already
                        if (!isset($classStyles[$className])) {
                            $classStyles[$className] = [];
                        }

                        // Split style declarations
                        $styleDeclarations = explode(';', $styleRules);
                        foreach ($styleDeclarations as $declaration) {
                            if (str_contains($declaration, ':')) {
                                list($property, $value) = explode(':', $declaration, 2);
                                $property = trim($property);
                                $value = trim($value);
                                if ($property !== '' && $value !== '') {
                                    // Overwrite property if it already exists (later rules take precedence)
                                    $classStyles[$className][$property] = $value;
                                }
                            }
                        }
                    }
                }
            }
        }

        // Apply styles to elements with matching classes
        $xpath = new \DOMXPath($doc);
        $elements = $xpath->query('//*[@class]');

        if ($elements !== false) {
            foreach ($elements as $element) {
                /** @var \DOMElement $element */
                $classAttr = $element->getAttribute('class');
                $classNames = preg_split('/[\s,]+/', $classAttr) ?: [];

                $styles = [];
                foreach ($classNames as $className) {
                    if (isset($classStyles[$className])) {
                        // Merge styles, later classes overwrite earlier ones
                        $styles = array_merge($styles, $classStyles[$className]);
                    }
                }

                // Set the styles as attributes on the element
                foreach ($styles as $property => $value) {
                    $element->setAttribute($property, $value);
                }

                // Remove the 'class' attribute
                $element->removeAttribute('class');
            }
        }

        // Return the modified SVG content
        return $doc->saveXML($doc->documentElement) ?: '';
    }
}
