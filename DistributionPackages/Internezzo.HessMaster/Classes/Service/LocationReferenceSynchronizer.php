<?php
declare(strict_types=1);

namespace Internezzo\HessMaster\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\SiteService;

/**
 * Creates, updates and removes "Internezzo.HessMaster:Document.LocationReference" documents in the local sites
 * according to the "sites" property of the master location documents.
 *
 * Only active if the setting Internezzo.HessMaster.locations.strategy is set to "reference".
 *
 * The reference documents are created underneath the locations overview document (see
 * Internezzo.HessMaster:Mixin.LocationsOverview) of each assigned site in the same workspace and dimension
 * as the master location. They get a deterministic node name and identifier, so that variants of a location
 * (e.g. other languages) end up as variants of the same reference document.
 *
 * @Flow\Scope("singleton")
 */
class LocationReferenceSynchronizer
{
    public const STRATEGY_VIRTUAL = 'virtual';
    public const STRATEGY_REFERENCE = 'reference';

    private const LOCATION_NODE_TYPE = 'Internezzo.HessMaster:Document.Location';
    private const REFERENCE_NODE_TYPE = 'Internezzo.HessMaster:Document.LocationReference';
    private const OVERVIEW_NODE_TYPE = 'Internezzo.HessMaster:Mixin.LocationsOverview';

    /**
     * Properties of the master location that are copied to its reference documents
     */
    private const SYNCHRONIZED_PROPERTIES = ['title', 'uriPathSegment'];

    /**
     * @Flow\InjectConfiguration(package="Internezzo.HessMaster", path="locations.strategy")
     * @var string
     */
    protected $strategy;

    /**
     * @Flow\InjectConfiguration(package="Internezzo.HessMaster", path="routing.masterLocationsNodePath")
     * @var string
     */
    protected $masterLocationsNodePath;

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

    public function isActive(): bool
    {
        return $this->strategy === self::STRATEGY_REFERENCE;
    }

    /**
     * Slot for the Node::nodeAdded signal (also emitted for new dimension variants)
     */
    public function onNodeAdded(NodeInterface $node): void
    {
        if ($this->isActive() && $this->isLocation($node)) {
            $this->synchronize($node);
        }
    }

    /**
     * Slot for the Node::nodePropertyChanged signal
     *
     * @param mixed $oldValue
     * @param mixed $newValue
     */
    public function onNodePropertyChanged(NodeInterface $node, string $propertyName, $oldValue, $newValue): void
    {
        if (!$this->isActive() || !$this->isLocation($node)) {
            return;
        }
        if ($propertyName === 'sites' || in_array($propertyName, self::SYNCHRONIZED_PROPERTIES, true)) {
            $this->synchronize($node);
        }
    }

    /**
     * Slot for the Node::nodeRemoved signal
     */
    public function onNodeRemoved(NodeInterface $node): void
    {
        if ($this->isActive() && $this->isLocation($node)) {
            $this->removeReferences($node);
        }
    }

    /**
     * Creates missing, updates existing and removes obsolete reference documents of the given master location
     * in the workspace and dimension of the location node.
     *
     * @return array<string, string> site node name => action ('created', 'updated', 'removed', 'skipped')
     */
    public function synchronize(NodeInterface $locationNode): array
    {
        $result = [];
        if (!$this->isLocation($locationNode)) {
            return $result;
        }
        $assignedSites = $locationNode->getProperty('sites');
        $assignedSites = is_array($assignedSites) ? $assignedSites : [];

        foreach ($this->findLocalOverviewNodes($locationNode) as $siteNodeName => $overviewNode) {
            $referenceNode = $overviewNode->getNode($this->referenceNodeName($locationNode));
            if (!in_array($siteNodeName, $assignedSites, true)) {
                if ($referenceNode !== null && !$referenceNode->isRemoved()) {
                    $referenceNode->remove();
                    $result[$siteNodeName] = 'removed';
                } else {
                    $result[$siteNodeName] = 'skipped';
                }
                continue;
            }
            if ($referenceNode === null) {
                $referenceNode = $overviewNode->createNode(
                    $this->referenceNodeName($locationNode),
                    $this->nodeTypeManager->getNodeType(self::REFERENCE_NODE_TYPE),
                    $this->referenceNodeIdentifier($siteNodeName, $locationNode)
                );
                $result[$siteNodeName] = 'created';
            } elseif ($referenceNode->isRemoved()) {
                // The reference was removed in this workspace before (e.g. by unassigning the site), restore it
                $referenceNode->setRemoved(false);
                $result[$siteNodeName] = 'created';
            } else {
                $result[$siteNodeName] = 'updated';
            }
            $referenceNode->setProperty('location', $locationNode->getIdentifier());
            foreach (self::SYNCHRONIZED_PROPERTIES as $propertyName) {
                $referenceNode->setProperty($propertyName, $locationNode->getProperty($propertyName));
            }
        }
        return $result;
    }

    /**
     * Removes all reference documents of the given master location in its workspace and dimension
     *
     * @return array<string, string> site node name => action ('removed', 'skipped')
     */
    public function removeReferences(NodeInterface $locationNode): array
    {
        $result = [];
        foreach ($this->findLocalOverviewNodes($locationNode) as $siteNodeName => $overviewNode) {
            $referenceNode = $overviewNode->getNode($this->referenceNodeName($locationNode));
            if ($referenceNode !== null && !$referenceNode->isRemoved()) {
                $referenceNode->remove();
                $result[$siteNodeName] = 'removed';
            } else {
                $result[$siteNodeName] = 'skipped';
            }
        }
        return $result;
    }

    private function isLocation(NodeInterface $node): bool
    {
        return $node->getNodeType()->isOfType(self::LOCATION_NODE_TYPE);
    }

    /**
     * Returns the locations overview node of every local (= non-master) site that exists in the workspace and
     * dimension of the given node, indexed by the site node name.
     *
     * The nodes are fetched from a context that also contains hidden and removed nodes, so that references can
     * be removed/restored regardless of their state.
     *
     * @return array<string, NodeInterface>
     */
    private function findLocalOverviewNodes(NodeInterface $locationNode): array
    {
        $contextProperties = $locationNode->getContext()->getProperties();
        $contextProperties['invisibleContentShown'] = true;
        $contextProperties['removedContentShown'] = true;
        $contextProperties['inaccessibleContentShown'] = true;
        $context = $this->contextFactory->create($contextProperties);

        $sitesNode = $context->getNode(SiteService::SITES_ROOT_PATH);
        if ($sitesNode === null) {
            return [];
        }
        $overviewNodes = [];
        foreach ($sitesNode->getChildNodes('Neos.Neos:Site') as $siteNode) {
            if (NodePaths::isSubPathOf($siteNode->getPath(), $this->masterLocationsNodePath)) {
                continue;
            }
            foreach ($siteNode->getChildNodes(self::OVERVIEW_NODE_TYPE) as $overviewNode) {
                $overviewNodes[$siteNode->getName()] = $overviewNode;
                break;
            }
        }
        return $overviewNodes;
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
