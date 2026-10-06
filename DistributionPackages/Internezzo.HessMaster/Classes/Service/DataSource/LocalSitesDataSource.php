<?php
declare(strict_types=1);

namespace Internezzo\HessMaster\Service\DataSource;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
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
     * @Flow\InjectConfiguration(package="Internezzo.HessMaster", path="locations.masterSiteNodeName")
     * @var string
     */
    protected $masterSiteNodeName;

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
            if ($site->getNodeName() === $this->masterSiteNodeName) {
                continue;
            }
            $options[] = [
                'value' => $site->getNodeName(),
                'label' => $site->getName(),
            ];
        }
        return $options;
    }
}
