<?php
declare(strict_types=1);

namespace Internezzo\HessMaster\Domain\Service;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Http\HttpRequestHandlerInterface;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ConfigurationContentDimensionPresetSource;
use Neos\Utility\PositionalArraySorter;

/**
 * Content dimension preset source that only provides the presets that are allowed for the current site
 * (see setting Internezzo.HessMaster.dimensionPresetsBySite).
 *
 * The current site is determined like Neos does it: by the domain of the active HTTP request with a fallback
 * to the default site. Without an HTTP request (e.g. in CLI commands) all presets are available.
 *
 * This allows the master site to only contain language independent content ("mul" preset) while the local
 * sites only provide the actual languages.
 *
 * @Flow\Scope("singleton")
 */
class SiteAwareContentDimensionPresetSource extends ConfigurationContentDimensionPresetSource
{
    /**
     * @Flow\InjectConfiguration(package="Internezzo.HessMaster", path="dimensionPresetsBySite")
     * @var array<string, array<string, array<int, string>>>
     */
    protected $presetIdentifiersBySite = [];

    /**
     * @Flow\Inject
     * @var Bootstrap
     */
    protected $bootstrap;

    /**
     * @Flow\Inject
     * @var DomainRepository
     */
    protected $domainRepository;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * The configuration of all presets, set once the configuration has been restricted to the current site
     *
     * @var array<string, mixed>|null
     */
    private $unrestrictedConfiguration;

    /**
     * Returns the presets of all dimensions that are allowed in the given site, regardless of the current site
     *
     * @return array<string, mixed> in the format of getAllPresets()
     */
    public function getAllPresetsForSite(string $siteNodeName): array
    {
        $this->restrictToCurrentSite();
        $configuration = $this->restrictToSite($this->unrestrictedConfiguration, $siteNodeName);
        foreach ($configuration as $dimensionName => $dimensionConfiguration) {
            $configuration[$dimensionName]['presets'] = (new PositionalArraySorter($dimensionConfiguration['presets']))->toArray();
        }
        return $configuration;
    }

    public function getAllPresets()
    {
        $this->restrictToCurrentSite();
        return parent::getAllPresets();
    }

    public function getDefaultPreset($dimensionName)
    {
        $this->restrictToCurrentSite();
        return parent::getDefaultPreset($dimensionName);
    }

    public function findPresetByDimensionValues($dimensionName, array $dimensionValues)
    {
        $this->restrictToCurrentSite();
        return parent::findPresetByDimensionValues($dimensionName, $dimensionValues);
    }

    public function findPresetByUriSegment($dimensionName, $uriSegment)
    {
        $this->restrictToCurrentSite();
        return parent::findPresetByUriSegment($dimensionName, $uriSegment);
    }

    public function getAllowedDimensionPresetsAccordingToPreselection($dimensionName, array $preselectedDimensionPresets)
    {
        $this->restrictToCurrentSite();
        return parent::getAllowedDimensionPresetsAccordingToPreselection($dimensionName, $preselectedDimensionPresets);
    }

    public function isPresetCombinationAllowedByConstraints(array $dimensionsNamesAndPresetIdentifiers)
    {
        $this->restrictToCurrentSite();
        return parent::isPresetCombinationAllowedByConstraints($dimensionsNamesAndPresetIdentifiers);
    }

    public function findPresetsByTargetValues(array $targetValues)
    {
        $this->restrictToCurrentSite();
        return parent::findPresetsByTargetValues($targetValues);
    }

    /**
     * Restricts the configuration to the presets of the current site, once per request
     */
    private function restrictToCurrentSite(): void
    {
        if ($this->unrestrictedConfiguration !== null) {
            return;
        }
        $this->unrestrictedConfiguration = $this->configuration;
        $currentSite = $this->findCurrentSite();
        if ($currentSite !== null) {
            $this->configuration = $this->restrictToSite($this->configuration, $currentSite->getNodeName());
        }
    }

    /**
     * @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    private function restrictToSite(array $configuration, string $siteNodeName): array
    {
        foreach ($this->presetIdentifiersBySite[$siteNodeName] ?? [] as $dimensionName => $presetIdentifiers) {
            if (!isset($configuration[$dimensionName])) {
                continue;
            }
            $configuration[$dimensionName]['presets'] = array_intersect_key($configuration[$dimensionName]['presets'], array_flip($presetIdentifiers));
            if (!isset($configuration[$dimensionName]['presets'][$configuration[$dimensionName]['defaultPreset']])) {
                $defaultPresetIdentifier = array_key_first(array_intersect_key(array_flip($presetIdentifiers), $configuration[$dimensionName]['presets']));
                $configuration[$dimensionName]['defaultPreset'] = $defaultPresetIdentifier;
                $configuration[$dimensionName]['default'] = $configuration[$dimensionName]['presets'][$defaultPresetIdentifier]['values'][0] ?? null;
            }
        }
        return $configuration;
    }

    private function findCurrentSite(): ?Site
    {
        if (!$this->bootstrap->getActiveRequestHandler() instanceof HttpRequestHandlerInterface) {
            return null;
        }
        $domain = $this->domainRepository->findOneByActiveRequest();
        if ($domain instanceof Domain) {
            return $domain->getSite();
        }
        $defaultSite = $this->siteRepository->findDefault();
        return $defaultSite instanceof Site ? $defaultSite : null;
    }
}
