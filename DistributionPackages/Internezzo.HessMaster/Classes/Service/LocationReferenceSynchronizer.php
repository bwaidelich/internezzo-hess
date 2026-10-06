<?php
declare(strict_types=1);

namespace Internezzo\HessMaster\Service;

use Internezzo\HessMaster\Domain\Service\SiteAwareContentDimensionPresetSource;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\SiteService;

/**
 * Creates, updates and removes "Internezzo.HessMaster:Document.LocationReference" documents in the local sites
 * according to the "sites" property of the master location documents.
 *
 * The master locations only exist in the language independent dimension of the master site. For every assigned
 * site, a reference document is created underneath the locations overview document (see
 * Internezzo.HessMaster:Mixin.LocationsOverview) in every language of that site (see
 * Internezzo.HessMaster.dimensionPresetsBySite), in the same workspace as the master location.
 * The translatable properties (e.g. "title_fr") are copied to the corresponding reference document variant.
 *
 * The reference documents get a deterministic node name and identifier, so that the variants of a reference
 * document in the different languages belong together.
 *
 * @Flow\Scope("singleton")
 */
class LocationReferenceSynchronizer
{
    private const LOCATION_NODE_TYPE = 'Internezzo.HessMaster:Document.Location';
    private const REFERENCE_NODE_TYPE = 'Internezzo.HessMaster:Document.LocationReference';
    private const OVERVIEW_NODE_TYPE = 'Internezzo.HessMaster:Mixin.LocationsOverview';
    private const LANGUAGE_DIMENSION = 'language';

    /**
     * Translatable properties of the master location (without language suffix) that are copied to its reference documents
     */
    private const SYNCHRONIZED_PROPERTIES = ['title', 'uriPathSegment'];

    /**
     * @Flow\InjectConfiguration(package="Internezzo.HessMaster", path="locations.masterSiteNodeName")
     * @var string
     */
    protected $masterSiteNodeName;

    /**
     * @Flow\InjectConfiguration(package="Internezzo.HessMaster", path="locations.languages")
     * @var array<int, string>
     */
    protected $languages;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var SiteAwareContentDimensionPresetSource
     */
    protected $contentDimensionPresetSource;

    /**
     * Slot for the Node::nodeAdded signal
     */
    public function onNodeAdded(NodeInterface $node): void
    {
        if ($this->isLocation($node)) {
            $this->synchronize($node);
        }
    }

    /**
     * Slot for the Node::nodePropertyChanged signal
     */
    public function onNodePropertyChanged(NodeInterface $node, string $propertyName): void
    {
        if (!$this->isLocation($node)) {
            return;
        }
        if ($propertyName === 'sites' || $propertyName === '_hidden' || $this->isSynchronizedProperty($propertyName)) {
            $this->synchronize($node);
        }
    }

    /**
     * Slot for the Node::nodeRemoved signal
     */
    public function onNodeRemoved(NodeInterface $node): void
    {
        if ($this->isLocation($node)) {
            $this->removeReferences($node);
        }
    }

    /**
     * Creates missing, updates existing and removes obsolete reference documents of the given master location
     * in all languages of the local sites, in the workspace of the location node.
     *
     * @return array<string, string> "<site node name> (<language>)" => action ('created', 'updated', 'removed', 'skipped')
     */
    public function synchronize(NodeInterface $locationNode): array
    {
        $result = [];
        if (!$this->isLocation($locationNode)) {
            return $result;
        }
        $assignedSites = $locationNode->getProperty('sites');
        $assignedSites = is_array($assignedSites) ? $assignedSites : [];

        foreach ($this->findLocalOverviewNodes($locationNode) as $siteNodeName => [$language, $overviewNode]) {
            $resultKey = sprintf('%s (%s)', $siteNodeName, $language);
            $referenceNode = $overviewNode->getNode($this->referenceNodeName($locationNode));
            if (!in_array($siteNodeName, $assignedSites, true)) {
                if ($referenceNode !== null && !$referenceNode->isRemoved()) {
                    $referenceNode->remove();
                    $result[$resultKey] = 'removed';
                } else {
                    $result[$resultKey] = 'skipped';
                }
                continue;
            }
            if ($referenceNode === null) {
                $referenceNode = $overviewNode->createNode(
                    $this->referenceNodeName($locationNode),
                    $this->nodeTypeManager->getNodeType(self::REFERENCE_NODE_TYPE),
                    $this->referenceNodeIdentifier($siteNodeName, $locationNode)
                );
                $result[$resultKey] = 'created';
            } elseif ($referenceNode->isRemoved()) {
                // The reference was removed in this workspace before (e.g. by unassigning the site), restore it
                $referenceNode->setRemoved(false);
                $result[$resultKey] = 'created';
            } else {
                $result[$resultKey] = 'updated';
            }
            $referenceNode->setProperty('location', $locationNode->getIdentifier());
            foreach (self::SYNCHRONIZED_PROPERTIES as $propertyName) {
                $referenceNode->setProperty($propertyName, $locationNode->getProperty($propertyName . '_' . $language));
            }
            if ($referenceNode->isHidden() !== $locationNode->isHidden()) {
                $referenceNode->setHidden($locationNode->isHidden());
            }
        }
        return $result;
    }

    /**
     * Removes all reference documents of the given master location in all languages of its workspace
     *
     * @return array<string, string> "<site node name> (<language>)" => action ('removed', 'skipped')
     */
    public function removeReferences(NodeInterface $locationNode): array
    {
        $result = [];
        foreach ($this->findLocalOverviewNodes($locationNode) as $siteNodeName => [$language, $overviewNode]) {
            $resultKey = sprintf('%s (%s)', $siteNodeName, $language);
            $referenceNode = $overviewNode->getNode($this->referenceNodeName($locationNode));
            if ($referenceNode !== null && !$referenceNode->isRemoved()) {
                $referenceNode->remove();
                $result[$resultKey] = 'removed';
            } else {
                $result[$resultKey] = 'skipped';
            }
        }
        return $result;
    }

    private function isLocation(NodeInterface $node): bool
    {
        return $node->getNodeType()->isOfType(self::LOCATION_NODE_TYPE);
    }

    private function isSynchronizedProperty(string $propertyName): bool
    {
        foreach (self::SYNCHRONIZED_PROPERTIES as $synchronizedPropertyName) {
            if (str_starts_with($propertyName, $synchronizedPropertyName . '_')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Yields the locations overview node of every local (= non-master) site in every language of that site
     * that exists in the workspace of the given location node (sites are taken from the site repository because
     * they don't exist in the dimension of the master location), keyed by the site node name.
     *
     * Only languages that are configured as location languages (see Internezzo.HessMaster.locations.languages) are
     * considered. The nodes are fetched from a context that also contains hidden and removed nodes, so that
     * references can be removed/restored regardless of their state.
     *
     * @return \Generator<string, array{0: string, 1: NodeInterface}> site node name => [language, overview node]
     */
    private function findLocalOverviewNodes(NodeInterface $locationNode): \Generator
    {
        $contextProperties = $locationNode->getContext()->getProperties();
        $contextProperties['invisibleContentShown'] = true;
        $contextProperties['removedContentShown'] = true;
        $contextProperties['inaccessibleContentShown'] = true;

        /** @var Site $site */
        foreach ($this->siteRepository->findAll() as $site) {
            $siteNodeName = $site->getNodeName();
            if ($siteNodeName === $this->masterSiteNodeName) {
                continue;
            }
            $languagePresets = $this->contentDimensionPresetSource->getAllPresetsForSite($siteNodeName)[self::LANGUAGE_DIMENSION]['presets'] ?? [];
            foreach ($languagePresets as $language => $languagePreset) {
                if (!in_array($language, $this->languages, true)) {
                    continue;
                }
                $contextProperties['dimensions'] = [self::LANGUAGE_DIMENSION => $languagePreset['values']];
                $contextProperties['targetDimensions'] = [self::LANGUAGE_DIMENSION => reset($languagePreset['values'])];
                $siteNode = $this->contextFactory->create($contextProperties)->getNode(NodePaths::addNodePathSegment(SiteService::SITES_ROOT_PATH, $siteNodeName));
                if ($siteNode === null) {
                    continue;
                }
                foreach ($siteNode->getChildNodes(self::OVERVIEW_NODE_TYPE) as $overviewNode) {
                    yield $siteNodeName => [$language, $overviewNode];
                    break;
                }
            }
        }
    }

    private function referenceNodeName(NodeInterface $locationNode): string
    {
        return 'location-' . preg_replace('/[^a-z0-9\-]/', '-', strtolower($locationNode->getIdentifier()));
    }

    /**
     * Deterministic (UUID formatted) identifier of the reference document of the given location in the given site
     */
    private function referenceNodeIdentifier(string $siteNodeName, NodeInterface $locationNode): string
    {
        $hash = md5('Internezzo.HessMaster:Document.LocationReference|' . $siteNodeName . '|' . $locationNode->getIdentifier());
        return sprintf('%s-%s-%s-%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 12, 4), substr($hash, 16, 4), substr($hash, 20, 12));
    }
}
