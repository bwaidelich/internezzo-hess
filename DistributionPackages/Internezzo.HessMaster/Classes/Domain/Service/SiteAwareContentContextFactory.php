<?php
declare(strict_types=1);

namespace Internezzo\HessMaster\Domain\Service;

use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Service\ContentContextFactory;

/**
 * Content context factory that uses the default dimension values of the context's site
 * (see Internezzo\HessMaster\Domain\Service\SiteAwareContentDimensionPresetSource).
 *
 * The default values are used for contexts that are created without explicit dimensions (e.g. the initial
 * context of the Neos backend or the site export), so they have to match the dimensions the site exists in.
 *
 * @Flow\Scope("singleton")
 */
class SiteAwareContentContextFactory extends ContentContextFactory
{
    /**
     * @Flow\Inject
     * @var SiteAwareContentDimensionPresetSource
     */
    protected $contentDimensionPresetSource;

    /**
     * @param array<string, mixed> $contextProperties
     * @return array<string, mixed>
     */
    protected function mergeContextPropertiesWithDefaults(array $contextProperties)
    {
        if (!isset($contextProperties['dimensions'])) {
            $site = $contextProperties['currentSite'] ?? $this->setDefaultSiteAndDomainFromCurrentRequest([])['currentSite'] ?? null;
            if ($site instanceof Site) {
                foreach ($this->contentDimensionPresetSource->getAllPresetsForSite($site->getNodeName()) as $dimensionName => $dimensionConfiguration) {
                    $contextProperties['dimensions'][$dimensionName] = [$dimensionConfiguration['default']];
                }
            }
        }
        return parent::mergeContextPropertiesWithDefaults($contextProperties);
    }
}
