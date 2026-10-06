<?php
declare(strict_types=1);

namespace Internezzo\HessMaster\Command;

use Internezzo\HessMaster\Service\LocationReferenceSynchronizer;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Service\SiteService;

/**
 * Maintenance commands for the location reference documents of the local sites
 * (see Internezzo\HessMaster\Service\LocationReferenceSynchronizer)
 */
class LocationCommandController extends CommandController
{
    private const LOCATION_NODE_TYPE = 'Internezzo.HessMaster:Document.Location';
    private const MASTER_LOCATIONS_NODE_NAME = 'locations';

    /**
     * @Flow\InjectConfiguration(package="Internezzo.HessMaster", path="locations.masterSiteNodeName")
     * @var string
     */
    protected $masterSiteNodeName;

    /**
     * @Flow\Inject
     * @var LocationReferenceSynchronizer
     */
    protected $synchronizer;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var ContentDimensionPresetSourceInterface
     */
    protected $contentDimensionPresetSource;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * Create/update/remove the location reference documents of all master locations
     *
     * Iterates over all master locations (in all dimensions of the given workspace) and synchronizes their
     * reference documents in all languages of the local sites according to their "sites" property.
     *
     * @param string $workspace Name of the workspace to synchronize
     */
    public function syncReferencesCommand(string $workspace = 'live'): void
    {
        $this->forEachLocation($workspace, function (NodeInterface $locationNode, string $dimensions) {
            $this->outputLine('%s [%s]: %s', [$locationNode->getLabel(), $dimensions, $this->formatResult($this->synchronizer->synchronize($locationNode))]);
        });
        $this->persistenceManager->persistAll();
        $this->outputLine('<success>Done, flush the content cache to see the changes: ./flow cache:flushone --identifier Neos_Fusion_Content</success>');
    }

    /**
     * Remove the location reference documents of all master locations
     *
     * Removes the automatically created reference documents in the local sites (in all dimensions of the given
     * workspace). Manually created reference documents are not affected.
     *
     * @param string $workspace Name of the workspace to clean up
     */
    public function removeReferencesCommand(string $workspace = 'live'): void
    {
        $this->forEachLocation($workspace, function (NodeInterface $locationNode, string $dimensions) {
            $this->outputLine('%s [%s]: %s', [$locationNode->getLabel(), $dimensions, $this->formatResult($this->synchronizer->removeReferences($locationNode))]);
        });
        $this->persistenceManager->persistAll();
        $this->outputLine('<success>Done, flush the content cache to see the changes: ./flow cache:flushone --identifier Neos_Fusion_Content</success>');
    }

    /**
     * @param \Closure(NodeInterface, string): void $callback
     */
    private function forEachLocation(string $workspaceName, \Closure $callback): void
    {
        foreach ($this->allDimensionCombinations() as $dimensions) {
            $context = $this->contextFactory->create([
                'workspaceName' => $workspaceName,
                'dimensions' => $dimensions,
                'invisibleContentShown' => true,
                'inaccessibleContentShown' => true,
            ]);
            $masterLocationsNode = $context->getNode(SiteService::SITES_ROOT_PATH . '/' . $this->masterSiteNodeName . '/' . self::MASTER_LOCATIONS_NODE_NAME);
            if ($masterLocationsNode === null) {
                continue;
            }
            $dimensionsLabel = implode(', ', array_map(static fn(array $values) => implode(',', $values), $dimensions)) ?: 'no dimensions';
            foreach ($masterLocationsNode->getChildNodes(self::LOCATION_NODE_TYPE) as $locationNode) {
                $callback($locationNode, $dimensionsLabel);
            }
        }
    }

    /**
     * @return array<int, array<string, array<int, string>>> e.g. [['language' => ['de']], ['language' => ['fr']]]
     */
    private function allDimensionCombinations(): array
    {
        $combinations = [[]];
        foreach ($this->contentDimensionPresetSource->getAllPresets() as $dimensionName => $dimensionConfiguration) {
            $newCombinations = [];
            foreach ($combinations as $combination) {
                foreach ($dimensionConfiguration['presets'] as $preset) {
                    $newCombinations[] = $combination + [$dimensionName => $preset['values']];
                }
            }
            $combinations = $newCombinations;
        }
        return $combinations;
    }

    /**
     * @param array<string, string> $result
     */
    private function formatResult(array $result): string
    {
        if ($result === []) {
            return 'no local sites found';
        }
        return implode(', ', array_map(static fn(string $site, string $action) => $site . ' ' . $action, array_keys($result), $result));
    }
}
