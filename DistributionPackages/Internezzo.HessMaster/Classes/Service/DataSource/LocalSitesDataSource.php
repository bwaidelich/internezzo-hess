<?php
declare(strict_types=1);

namespace Internezzo\HessMaster\Service\DataSource;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\SiteService;
use Neos\Neos\Service\DataSource\AbstractDataSource;

/**
 * Provides all online sites except the master site as select box options.
 * Used to assign master locations to the local sites they should appear in.
 */
class LocalSitesDataSource extends AbstractDataSource
{
    /**
     * @var string
     */
    protected static $identifier = 'internezzo-hessmaster-local-sites';

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * Absolute path of the master "Locations" document that holds the actual location nodes
     *
     * @Flow\InjectConfiguration(package="Internezzo.HessMaster", path="routing.masterLocationsNodePath")
     * @var string
     */
    protected $masterLocationsNodePath;

    /**
     * @param NodeInterface|null $node
     * @param array<string, mixed> $arguments
     * @return array<int, array{value: string, label: string}>
     */
    public function getData(?NodeInterface $node = null, array $arguments = []): array
    {
        $options = [];
        /** @var Site $site */
        foreach ($this->siteRepository->findOnline() as $site) {
            if ($this->isMasterSite($site)) {
                continue;
            }
            $options[] = [
                'value' => $site->getNodeName(),
                'label' => $site->getName(),
            ];
        }
        return $options;
    }

    private function isMasterSite(Site $site): bool
    {
        $siteNodePath = NodePaths::addNodePathSegment(SiteService::SITES_ROOT_PATH, $site->getNodeName());
        return NodePaths::isSubPathOf($siteNodePath, $this->masterLocationsNodePath);
    }
}
