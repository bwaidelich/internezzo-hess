<?php
declare(strict_types=1);

namespace Internezzo\HessMaster\Eel;

use Internezzo\HessMaster\Domain\Service\SiteAwareContentDimensionPresetSource;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;

/**
 * Eel helper to access the master location of a location reference document, available as
 * "Internezzo.HessMaster.Location" in Fusion
 */
class LocationHelper implements ProtectedContextAwareInterface
{
    /**
     * @Flow\InjectConfiguration(package="Internezzo.HessMaster", path="locations.masterSiteNodeName")
     * @var string
     */
    protected $masterSiteNodeName;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var SiteAwareContentDimensionPresetSource
     */
    protected $contentDimensionPresetSource;

    /**
     * Returns the master location of the given location reference document or NULL if it is not available
     * (e.g. if it was removed or is hidden).
     *
     * The master location only exists in the (default) dimension of the master site, so it is fetched from
     * that dimension in the workspace and with the visibility settings of the reference document.
     *
     * Example: Internezzo.HessMaster.Location.master(node)
     */
    public function master(NodeInterface $referenceNode): ?NodeInterface
    {
        $locationIdentifier = $referenceNode->getProperty('location');
        if (!is_string($locationIdentifier) || $locationIdentifier === '') {
            return null;
        }
        $contextProperties = $referenceNode->getContext()->getProperties();
        $contextProperties['dimensions'] = [];
        $contextProperties['targetDimensions'] = [];
        foreach ($this->contentDimensionPresetSource->getAllPresetsForSite($this->masterSiteNodeName) as $dimensionName => $dimensionConfiguration) {
            $defaultPresetValues = $dimensionConfiguration['presets'][$dimensionConfiguration['defaultPreset']]['values'];
            $contextProperties['dimensions'][$dimensionName] = $defaultPresetValues;
            $contextProperties['targetDimensions'][$dimensionName] = reset($defaultPresetValues);
        }
        return $this->contextFactory->create($contextProperties)->getNodeByIdentifier($locationIdentifier);
    }

    /**
     * @param string $methodName
     */
    public function allowsCallOfMethod($methodName): bool
    {
        return true;
    }
}
