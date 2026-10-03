<?php

namespace justinholtweb\carrier\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\carrier\Plugin;

/**
 * Poll the carriers for shipments whose next check is due.
 */
class TrackJob extends BaseJob
{
    public int $limit = 200;

    public function execute($queue): void
    {
        $tracking = Plugin::getInstance()->getTracking();
        $tracking->refresh($tracking->due($this->limit));
        $this->setProgress($queue, 1);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('carrier', 'Updating shipment tracking');
    }
}
