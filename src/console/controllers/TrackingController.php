<?php

namespace justinholtweb\carrier\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\carrier\Plugin;
use yii\console\ExitCode;

/**
 * Tracking from the command line — run `carrier/tracking/refresh` from cron for sites that would
 * rather not rely on page views to drive polling.
 */
class TrackingController extends Controller
{
    /** @var int How many due shipments to poll in one run. */
    public int $limit = 500;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['limit']);
    }

    /**
     * Poll every shipment whose next tracking check is due.
     */
    public function actionRefresh(): int
    {
        $tracking = Plugin::getInstance()->getTracking();
        $due = $tracking->due($this->limit);
        $updated = $tracking->refresh($due);

        $this->stdout(sprintf("%d shipments due, %d updated.\n", count($due), $updated), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Poll one shipment now, by tracking number.
     */
    public function actionShow(string $trackingNumber): int
    {
        $shipment = Plugin::getInstance()->getShipments()->getByTrackingNumber($trackingNumber);

        if (!$shipment) {
            $this->stderr("No shipment has that tracking number.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        Plugin::getInstance()->getTracking()->refresh([$shipment]);
        $shipment = Plugin::getInstance()->getShipments()->getById($shipment->id);
        $this->stdout("{$shipment->trackingNumber}: {$shipment->getTrackingStatusLabel()} — {$shipment->trackingStatusText}\n");

        foreach (Plugin::getInstance()->getShipments()->eventsFor($shipment->id) as $event) {
            $this->stdout(sprintf("  %s  %-16s %s %s\n", $event['occurredAt'], $event['status'], $event['description'], $event['location']));
        }

        return ExitCode::OK;
    }
}
