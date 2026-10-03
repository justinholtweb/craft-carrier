<?php

namespace justinholtweb\carrier\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\carrier\db\Table;
use justinholtweb\carrier\models\Connection;
use justinholtweb\carrier\models\LogEntry;
use justinholtweb\carrier\Plugin;
use Throwable;

/**
 * The connection log.
 *
 * Merchants do not buy a gateway, they buy the ability to answer "why wasn't that label created?"
 * at four in the afternoon with the courier waiting. So the log records the actual request and the actual response, with
 * credentials redacted at the transport rather than here, and keeps them long enough to be useful
 * without becoming the largest table in the database.
 */
class Log extends Component
{
    /** Anything longer than this is stored truncated, with a marker. */
    private const MAX_BODY = 65000;


    public function request(
        Connection $connection,
        ?int $shipmentId,
        string $method,
        string $url,
        array $requestHeaders,
        string $requestBody,
        int $status,
        string $responseBody,
        int $durationMs,
        ?string $error = null,
        int $attempt = 1,
    ): void {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->logRequests) {
            return;
        }

        // "Errors only" is the setting a busy store lands on: tracking polls are thousands of
        // requests a day and nobody reads the successful ones.
        if ($settings->logErrorsOnly && $status >= 200 && $status < 300) {
            return;
        }

        $this->insert([
            'connectionId' => $connection->id,
            'shipmentId' => $shipmentId,
            'type' => 'request',
            'method' => $method,
            'url' => $url,
            'requestHeaders' => json_encode($requestHeaders),
            'requestBody' => $settings->logBodies ? $this->truncate($requestBody) : null,
            'status' => $status,
            'responseBody' => $settings->logBodies ? $this->truncate($responseBody) : null,
            'durationMs' => $durationMs,
            'attempt' => $attempt,
            'message' => $error,
        ]);
    }

    public function note(Connection $connection, string $message, ?int $shipmentId = null): void
    {
        $this->insert([
            'connectionId' => $connection->id,
            'shipmentId' => $shipmentId,
            'type' => 'note',
            'message' => $message,
        ]);
    }

    public function error(?Connection $connection, string $message, ?int $shipmentId = null): void
    {
        $this->insert([
            'connectionId' => $connection?->id,
            'shipmentId' => $shipmentId,
            'type' => 'error',
            'message' => $message,
        ]);

        Craft::error('Carrier: ' . $message, 'carrier');
    }

    /**
     * Every write path — request, note, error — ends here, so this is where the rule lives: a
     * log write must never be the reason a label or a checkout fails. A full disk, or a column
     * too narrow for some carrier's error message, costs one log row and a warning, never the
     * request that was being logged.
     */
    private function insert(array $values): void
    {
        try {
            $now = Db::prepareDateForDb(new DateTime());

            Craft::$app->getDb()->createCommand()
                ->insert(Table::LOG, array_merge([
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => StringHelper::UUID(),
                ], $values))
                ->execute();
        } catch (Throwable $e) {
            Craft::warning('Carrier could not write a ' . ($values['type'] ?? 'log') . ' entry: ' . $e->getMessage(), 'carrier');
        }
    }

    private function truncate(string $body): string
    {
        if (strlen($body) <= self::MAX_BODY) {
            return $body;
        }

        return substr($body, 0, self::MAX_BODY) . "\n… truncated, " . strlen($body) . ' bytes total';
    }

    public function query(): Query
    {
        return (new Query())->from(Table::LOG)->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC]);
    }

    public function getById(int $id): ?LogEntry
    {
        $row = (new Query())->from(Table::LOG)->where(['id' => $id])->one();

        return $row ? new LogEntry($row) : null;
    }

    /**
     * @return LogEntry[]
     */
    public function forShipment(int $shipmentId, int $limit = 100): array
    {
        return array_map(
            static fn(array $row) => new LogEntry($row),
            $this->query()->where(['shipmentId' => $shipmentId])->limit($limit)->all(),
        );
    }

    /**
     * Drop log rows older than the configured retention.
     *
     * Run from the garbage-collection hook rather than on write, so a busy tracking sweep is not
     * also a busy delete.
     */
    public function prune(?int $days = null): int
    {
        $days ??= Plugin::getInstance()->getSettings()->logRetentionDays;

        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTime())->modify("-$days days");

        return (int)Craft::$app->getDb()->createCommand()
            ->delete(Table::LOG, ['<', 'dateCreated', Db::prepareDateForDb($cutoff)])
            ->execute();
    }

    public function clear(?Connection $connection = null): int
    {
        $condition = $connection ? ['connectionId' => $connection->id] : [];

        return (int)Craft::$app->getDb()->createCommand()->delete(Table::LOG, $condition)->execute();
    }
}
