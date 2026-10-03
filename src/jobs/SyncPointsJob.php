<?php

namespace justinholtweb\carrier\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\carrier\Plugin;

/**
 * Refresh one connection's pickup-point list.
 */
class SyncPointsJob extends BaseJob
{
    public int $connectionId = 0;

    public function execute($queue): void
    {
        $connection = Plugin::getInstance()->getConnections()->getById($this->connectionId);

        if ($connection && $connection->enabled) {
            Plugin::getInstance()->getPoints()->sync($connection);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('carrier', 'Syncing pickup points');
    }
}
