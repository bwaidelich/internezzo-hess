<?php
declare(strict_types=1);

namespace Internezzo\HessMaster\Fusion;

use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Neos\Fusion\DimensionsMenuItemsImplementation as NeosDimensionsMenuItemsImplementation;

/**
 * Variant of the default dimensions menu that also works if the current node is not part of the
 * current site. That is the case for master locations that are rendered as virtual sub pages of
 * other sites (see \Internezzo\HessMaster\Routing\LocationAwareFrontendNodeRoutePartHandler).
 */
class DimensionsMenuItemsImplementation extends NeosDimensionsMenuItemsImplementation
{
    /**
     * @return array<int, \Neos\ContentRepository\Domain\Model\NodeInterface>
     */
    protected function getCurrentNodeRootline()
    {
        $siteNode = $this->currentNode->getContext()->getCurrentSiteNode();
        if ($siteNode === null || !NodePaths::isSubPathOf($siteNode->getPath(), $this->currentNode->getPath())) {
            return [];
        }
        return parent::getCurrentNodeRootline();
    }
}
