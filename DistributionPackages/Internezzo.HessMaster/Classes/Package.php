<?php
declare(strict_types=1);

namespace Internezzo\HessMaster;

use Internezzo\HessMaster\Service\LocationPropertyNormalizer;
use Internezzo\HessMaster\Service\LocationReferenceSynchronizer;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Package\Package as BasePackage;

class Package extends BasePackage
{
    public function boot(Bootstrap $bootstrap): void
    {
        $dispatcher = $bootstrap->getSignalSlotDispatcher();
        // Derive the properties of the master locations from their translatable properties (before synchronizing them)
        $dispatcher->connect(Node::class, 'nodePropertyChanged', LocationPropertyNormalizer::class, 'onNodePropertyChanged');
        // Keep location reference documents in sync with the master locations
        $dispatcher->connect(Node::class, 'nodeAdded', LocationReferenceSynchronizer::class, 'onNodeAdded');
        $dispatcher->connect(Node::class, 'nodePropertyChanged', LocationReferenceSynchronizer::class, 'onNodePropertyChanged');
        $dispatcher->connect(Node::class, 'nodeRemoved', LocationReferenceSynchronizer::class, 'onNodeRemoved');
    }
}
