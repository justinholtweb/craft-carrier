<?php

namespace justinholtweb\carrier\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\carrier\Plugin;
use yii\console\ExitCode;

/**
 * Carrier connections.
 */
class ConnectionsController extends Controller
{
    /**
     * List connections and the carriers installed.
     */
    public function actionList(): int
    {
        $plugin = Plugin::getInstance();

        foreach ($plugin->getConnections()->all() as $connection) {
            $this->stdout(sprintf("%-24s %-20s %s%s\n",
                $connection->handle,
                $connection->carrier,
                $connection->enabled ? 'enabled' : 'disabled',
                $connection->isSandbox() ? ' (sandbox)' : '',
            ));
        }

        $this->stdout("\nInstalled carriers: " . implode(', ', array_keys($plugin->getCarriers()->all())) . "\n");

        return ExitCode::OK;
    }

    /**
     * Test a connection's credentials.
     */
    public function actionTest(string $handle): int
    {
        $connection = Plugin::getInstance()->getConnections()->getByHandle($handle);
        $carrier = $connection?->getCarrier();

        if (!$carrier) {
            $this->stderr("No such connection, or its add-on is missing.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $result = $carrier->test();
        $this->stdout(($result->ok ? '✓ ' : '✗ ') . $result->message . "\n", $result->ok ? Console::FG_GREEN : Console::FG_RED);

        foreach ($result->details as $key => $value) {
            $this->stdout("  $key: $value\n");
        }

        foreach ($result->hints as $hint) {
            $this->stdout("  → $hint\n");
        }

        return $result->ok ? ExitCode::OK : ExitCode::UNAVAILABLE;
    }
}
