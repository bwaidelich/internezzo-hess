<?php
declare(strict_types=1);

namespace Internezzo\HessMaster\Service;

use Behat\Transliterator\Transliterator;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Service\TransliterationService;

/**
 * Keeps the derived properties of the master locations up to date:
 *
 * - empty "uriPathSegment_<language>" properties are generated from the corresponding "title_<language>"
 * - the inherited "title" and "uriPathSegment" properties (required by Neos, e.g. for routing and labels) are
 *   copied from the primary language
 *
 * The languages are configured in the setting Internezzo.HessMaster.locations.languages
 *
 * @Flow\Scope("singleton")
 */
class LocationPropertyNormalizer
{
    private const LOCATION_NODE_TYPE = 'Internezzo.HessMaster:Document.Location';

    /**
     * @Flow\InjectConfiguration(package="Internezzo.HessMaster", path="locations.languages")
     * @var array<int, string>
     */
    protected $languages;

    /**
     * @Flow\Inject
     * @var TransliterationService
     */
    protected $transliterationService;

    /**
     * Slot for the Node::nodePropertyChanged signal
     */
    public function onNodePropertyChanged(NodeInterface $node, string $propertyName): void
    {
        if (!$node->getNodeType()->isOfType(self::LOCATION_NODE_TYPE)) {
            return;
        }
        if (str_starts_with($propertyName, 'title_') || str_starts_with($propertyName, 'uriPathSegment_')) {
            $this->normalize($node);
        }
    }

    public function normalize(NodeInterface $locationNode): void
    {
        foreach ($this->languages as $language) {
            $title = (string)$locationNode->getProperty('title_' . $language);
            if ($title !== '' && (string)$locationNode->getProperty('uriPathSegment_' . $language) === '') {
                $uriPathSegment = Transliterator::urlize($this->transliterationService->transliterate($title, $language));
                $this->setPropertyIfChanged($locationNode, 'uriPathSegment_' . $language, $uriPathSegment);
            }
        }
        $primaryLanguage = reset($this->languages);
        foreach (['title', 'uriPathSegment'] as $propertyName) {
            $primaryValue = (string)$locationNode->getProperty($propertyName . '_' . $primaryLanguage);
            if ($primaryValue !== '') {
                $this->setPropertyIfChanged($locationNode, $propertyName, $primaryValue);
            }
        }
    }

    /**
     * Only sets changed values to prevent unnecessary Node::nodePropertyChanged signals
     *
     * @param mixed $value
     */
    private function setPropertyIfChanged(NodeInterface $node, string $propertyName, $value): void
    {
        if ($node->getProperty($propertyName) !== $value) {
            $node->setProperty($propertyName, $value);
        }
    }
}
