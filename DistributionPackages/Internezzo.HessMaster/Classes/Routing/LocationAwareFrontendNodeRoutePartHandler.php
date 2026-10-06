<?php
declare(strict_types=1);

namespace Internezzo\HessMaster\Routing;

use Internezzo\HessMaster\Service\LocationReferenceSynchronizer;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Routing\Dto\MatchResult;
use Neos\Flow\Mvc\Routing\Dto\ResolveResult;
use Neos\Flow\Mvc\Routing\Dto\RouteTags;
use Neos\Flow\Mvc\Routing\Dto\UriConstraints;
use Neos\Neos\Domain\Service\SiteService;
use Neos\Neos\Routing\Exception as RoutingException;
use Neos\Neos\Routing\FrontendNodeRoutePartHandler;

/**
 * Extends the default frontend routing so that the location documents, which only exist
 * underneath the master site, can be rendered as if they were children of a
 * "locations overview" document (see Internezzo.HessMaster:Mixin.LocationsOverview) in
 * every other site. This is the 'virtual' strategy (see Internezzo.HessMaster.locations.strategy),
 * with any other strategy this handler behaves like the default one.
 *
 * Only locations that are assigned to the respective site (via their "sites" property) are
 * treated as virtual sub pages of that site.
 *
 * Matching: "<overview path>/<location uriPathSegment>" on a non-master site is mapped to
 * the master location node with the given uriPathSegment (in the same dimension).
 *
 * Resolving: a master location node requested from a non-master site it is assigned to is
 * rendered as "<overview path>/<location uriPathSegment>" on the current site instead of an
 * absolute link to the master site domain.
 */
class LocationAwareFrontendNodeRoutePartHandler extends FrontendNodeRoutePartHandler
{
    private const LOCATION_NODE_TYPE = 'Internezzo.HessMaster:Document.Location';
    private const OVERVIEW_NODE_TYPE = 'Internezzo.HessMaster:Mixin.LocationsOverview';

    /**
     * Absolute path of the master "Locations" document that holds the actual location nodes
     *
     * @Flow\InjectConfiguration(package="Internezzo.HessMaster", path="routing.masterLocationsNodePath")
     * @var string
     */
    protected $masterLocationsNodePath;

    /**
     * Virtual locations are only enabled if this is set to 'virtual' (see Settings.yaml)
     *
     * @Flow\InjectConfiguration(package="Internezzo.HessMaster", path="locations.strategy")
     * @var string
     */
    protected $locationsStrategy;

    /**
     * @param string $requestPath
     * @return bool|MatchResult
     */
    protected function matchValue($requestPath)
    {
        $matchResult = parent::matchValue($requestPath);
        if ($matchResult !== false) {
            return $matchResult;
        }
        if ($this->onlyMatchSiteNodes() || !$this->virtualLocationsEnabled()) {
            return false;
        }
        return $this->matchVirtualLocation((string)$requestPath);
    }

    /**
     * @param string $requestPath
     * @return bool|MatchResult
     */
    private function matchVirtualLocation(string $requestPath)
    {
        $locationNode = null;
        $overviewNode = null;
        try {
            $this->securityContext->withoutAuthorizationChecks(function () use (&$locationNode, &$overviewNode, $requestPath) {
                [$locationNode, $overviewNode] = $this->convertRequestPathToVirtualLocation($requestPath);
            });
        } catch (RoutingException $exception) {
            $this->systemLogger->debug('LocationAwareFrontendNodeRoutePartHandler matchValue(): ' . $exception->getMessage());
            return false;
        }
        if (!$locationNode instanceof NodeInterface || !$overviewNode instanceof NodeInterface) {
            return false;
        }
        if (!$this->nodeTypeIsAllowed($locationNode)) {
            return false;
        }
        // The route cache is tagged with the location node and its parents automatically (see RouteCacheAspect),
        // the overview node has to be added explicitly so that renaming its uriPathSegment invalidates the entry
        return new MatchResult($locationNode->getContextPath(), RouteTags::createFromArray([$overviewNode->getIdentifier()]));
    }

    /**
     * @param string $requestPath
     * @return array{0: NodeInterface|null, 1: NodeInterface|null} location node and overview node, or nulls if the path is no virtual location path
     * @throws RoutingException
     */
    private function convertRequestPathToVirtualLocation(string $requestPath): array
    {
        $contentContext = $this->buildContextFromRequestPath($requestPath);
        $requestPathWithoutContext = $this->removeContextFromPath($requestPath);
        if ($contentContext->getWorkspace() === null || $requestPathWithoutContext === null || $requestPathWithoutContext === '') {
            return [null, null];
        }
        $siteNode = $contentContext->getCurrentSiteNode();
        if ($siteNode === null || $this->isMasterSiteNode($siteNode)) {
            return [null, null];
        }

        $segments = explode('/', (string)$this->truncateUriPathSuffix($requestPathWithoutContext));
        if (count($segments) < 2) {
            return [null, null];
        }
        $locationSegment = array_pop($segments);
        $overviewNodePath = $this->getRelativeNodePathByUriPathSegmentProperties($siteNode, implode('/', $segments));
        $overviewNode = $overviewNodePath !== false ? $siteNode->getNode($overviewNodePath) : null;
        if (!$overviewNode instanceof NodeInterface || !$overviewNode->getNodeType()->isOfType(self::OVERVIEW_NODE_TYPE)) {
            return [null, null];
        }

        $masterLocationsNode = $contentContext->getNode($this->masterLocationsNodePath);
        if (!$masterLocationsNode instanceof NodeInterface) {
            return [null, null];
        }
        foreach ($masterLocationsNode->getChildNodes(self::LOCATION_NODE_TYPE) as $locationNode) {
            if ($locationNode->getProperty('uriPathSegment') === $locationSegment && $this->isLocationAssignedToSite($locationNode, $siteNode->getName())) {
                return [$locationNode, $overviewNode];
            }
        }
        return [null, null];
    }

    /**
     * @param NodeInterface|string|string[] $node
     * @return bool|ResolveResult
     */
    protected function resolveValue($node)
    {
        $resolveResult = parent::resolveValue($node);
        if (!$resolveResult instanceof ResolveResult) {
            return $resolveResult;
        }
        $resolvedNode = $this->convertRouteValueToNode($node);
        $overviewNode = $resolvedNode !== null ? $this->findOverviewNodeForVirtualLocation($resolvedNode) : null;
        if ($overviewNode === null) {
            return $resolveResult;
        }
        // Make sure that renaming the overview node's uriPathSegment invalidates cached URIs of virtual locations
        $tags = ($resolveResult->getTags() ?? RouteTags::createEmpty())->withTag($overviewNode->getIdentifier());
        return new ResolveResult($resolveResult->getResolvedValue(), $resolveResult->getUriConstraints(), $tags, $resolveResult->getLifetime());
    }

    /**
     * Skips the cross-site domain constraints for virtual locations, they are served from the current site
     *
     * @param NodeInterface $node
     * @return UriConstraints
     */
    protected function buildUriConstraintsForResolvedNode(NodeInterface $node): UriConstraints
    {
        if ($this->findOverviewNodeForVirtualLocation($node) === null) {
            return parent::buildUriConstraintsForResolvedNode($node);
        }
        $uriConstraints = UriConstraints::create();
        if (!empty($this->options['uriPathSuffix'])) {
            $uriConstraints = $uriConstraints->withPathSuffix($this->options['uriPathSuffix']);
        }
        return $uriConstraints;
    }

    /**
     * Builds "<overview path>/<location uriPathSegment>" for virtual locations
     *
     * @param NodeInterface $node
     * @return string
     */
    protected function resolveRoutePathForNode(NodeInterface $node)
    {
        $overviewNode = $this->findOverviewNodeForVirtualLocation($node);
        if ($overviewNode === null) {
            return parent::resolveRoutePathForNode($node);
        }
        if (!$node->hasProperty('uriPathSegment')) {
            throw new RoutingException\MissingNodePropertyException(sprintf('Missing "uriPathSegment" property for node "%s". Nodes can be migrated with the "flow node:repair" command.', $node->getPath()), 1758037200);
        }
        $overviewPath = parent::resolveRoutePathForNode($overviewNode);
        $contextSuffixPosition = strpos($overviewPath, '@');
        $contextSuffix = $contextSuffixPosition !== false ? substr($overviewPath, $contextSuffixPosition) : '';
        $overviewPath = $contextSuffixPosition !== false ? substr($overviewPath, 0, $contextSuffixPosition) : $overviewPath;

        return $overviewPath . '/' . $node->getProperty('uriPathSegment') . $contextSuffix;
    }

    /**
     * Returns the locations overview node of the currently requested site if the given node is a location that
     * has to be rendered as a virtual child of it. NULL if the node should be resolved the regular way.
     *
     * @param NodeInterface $node
     * @return NodeInterface|null
     */
    private function findOverviewNodeForVirtualLocation(NodeInterface $node): ?NodeInterface
    {
        if (!$this->virtualLocationsEnabled() || !$node->getNodeType()->isOfType(self::LOCATION_NODE_TYPE)) {
            return null;
        }
        try {
            $requestSite = $this->getCurrentSite();
        } catch (RoutingException\NoSiteException $exception) {
            return null;
        }
        $requestSiteNodePath = NodePaths::addNodePathSegment(SiteService::SITES_ROOT_PATH, $requestSite->getNodeName());
        if (NodePaths::isSubPathOf($requestSiteNodePath, $node->getPath())) {
            return null;
        }
        if (!$this->isLocationAssignedToSite($node, $requestSite->getNodeName())) {
            return null;
        }
        $requestSiteNode = $node->getContext()->getNode($requestSiteNodePath);
        if (!$requestSiteNode instanceof NodeInterface) {
            return null;
        }
        foreach ($requestSiteNode->getChildNodes(self::OVERVIEW_NODE_TYPE) as $overviewNode) {
            return $overviewNode;
        }
        return null;
    }

    /**
     * @param NodeInterface|string|string[] $node
     * @return NodeInterface|null
     */
    private function convertRouteValueToNode($node): ?NodeInterface
    {
        if (is_array($node) && isset($node['__contextNodePath'])) {
            $node = $node['__contextNodePath'];
        }
        if ($node instanceof NodeInterface) {
            return $node;
        }
        if (!is_string($node)) {
            return null;
        }
        try {
            $contentContext = $this->buildContextFromPath($node, true);
        } catch (RoutingException $exception) {
            return null;
        }
        if ($contentContext->getWorkspace() === null) {
            return null;
        }
        $nodePath = $this->removeContextFromPath($node);
        return $nodePath !== null ? $contentContext->getNode($nodePath) : null;
    }

    /**
     * Whether the given location has been assigned to the site with the given node name (see "sites" property)
     *
     * @param NodeInterface $locationNode
     * @param string $siteNodeName
     * @return bool
     */
    private function isLocationAssignedToSite(NodeInterface $locationNode, string $siteNodeName): bool
    {
        $assignedSites = $locationNode->getProperty('sites');
        return is_array($assignedSites) && in_array($siteNodeName, $assignedSites, true);
    }

    private function virtualLocationsEnabled(): bool
    {
        return $this->locationsStrategy === LocationReferenceSynchronizer::STRATEGY_VIRTUAL;
    }

    private function isMasterSiteNode(NodeInterface $siteNode): bool
    {
        return NodePaths::isSubPathOf($siteNode->getPath(), $this->masterLocationsNodePath);
    }
}
