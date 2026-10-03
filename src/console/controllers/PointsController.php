<?php

namespace justinholtweb\carrier\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\carrier\Plugin;
use yii\console\ExitCode;

/**
 * Pickup-point lists.
 */
class PointsController extends Controller
{
    /** @var string|null A connection handle; every list-mode connection when omitted. */
    public ?string $connection = null;

    /** @var bool Sync even if the list is fresh. */
    public bool $force = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['connection', 'force']);
    }

    /**
     * Sync pickup points from carriers that publish a list.
     */
    public function actionSync(): int
    {
        $plugin = Plugin::getInstance();
        $connections = $this->connection !== null
            ? array_filter([$plugin->getConnections()->getByHandle($this->connection)])
            : ($this->force ? $plugin->getConnections()->withFeature('points') : $plugin->getPoints()->due());

        if ($connections === []) {
            $this->stdout("Nothing to sync.\n");

            return ExitCode::OK;
        }

        $status = ExitCode::OK;

        foreach ($connections as $connection) {
            $result = $plugin->getPoints()->sync($connection);

            if ($result['error'] !== null) {
                $this->stderr("{$connection->handle}: {$result['error']}\n", Console::FG_RED);
                $status = ExitCode::UNAVAILABLE;
            } else {
                $this->stdout("{$connection->handle}: {$result['count']} points\n", Console::FG_GREEN);
            }
        }

        return $status;
    }

    /**
     * Import pickup points from a CSV file (id,name,type,street,city,postcode,country,latitude,longitude,hours,cod).
     */
    public function actionImport(string $connectionHandle, string $file): int
    {
        $connection = Plugin::getInstance()->getConnections()->getByHandle($connectionHandle);

        if (!$connection || !is_readable($file)) {
            $this->stderr("Unknown connection or unreadable file.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $result = Plugin::getInstance()->getPoints()->importCsv($connection, (string)file_get_contents($file));
        $this->stdout("Imported {$result['imported']}, skipped {$result['skipped']}.\n");

        foreach ($result['errors'] as $error) {
            $this->stderr("  $error\n", Console::FG_YELLOW);
        }

        return $result['imported'] > 0 ? ExitCode::OK : ExitCode::DATAERR;
    }
}
