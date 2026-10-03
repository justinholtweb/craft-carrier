<?php

namespace justinholtweb\carrier\services;

use Craft;
use craft\base\Component;
use justinholtweb\carrier\jobs\SyncPointsJob;
use justinholtweb\carrier\jobs\TrackJob;
use justinholtweb\carrier\Plugin;
use Throwable;

/**
 * Craft has no scheduler, so the sweep rides on ordinary requests: at most once every few
 * minutes, guarded by the cache, it queues a tracking poll and any point syncs that are due. It
 * queues rather than works, so the request that triggered it pays for one cache read.
 *
 * Sites with a real cron should run `carrier/tracking/refresh` and `carrier/points/sync`, which
 * do the same work.
 */
class Sweeper extends Component
{
    private const INTERVAL = 600;

    public function maybeRun(): void
    {
        $cache = Craft::$app->getCache();

        if ($cache->get('carrier:sweep') !== false) {
            return;
        }

        $cache->set('carrier:sweep', time(), self::INTERVAL);

        try {
            $this->run();
        } catch (Throwable $e) {
            Craft::warning('Carrier sweep failed: ' . $e->getMessage(), 'carrier');
        }
    }

    public function run(): void
    {
        $plugin = Plugin::getInstance();
        $queue = Craft::$app->getQueue();

        if ($plugin->getSettings()->trackingEnabled && $plugin->getTracking()->due(1) !== []) {
            $queue->push(new TrackJob());
        }

        foreach ($plugin->getPoints()->due() as $connection) {
            $queue->push(new SyncPointsJob(['connectionId' => $connection->id]));
        }
    }
}
