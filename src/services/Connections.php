<?php

namespace justinholtweb\carrier\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\carrier\base\Field;
use justinholtweb\carrier\db\Table;
use justinholtweb\carrier\models\Connection;
use justinholtweb\carrier\records\ConnectionRecord;
use Throwable;

/**
 * Carrier accounts. They live in the database rather than in project config.
 *
 * That is a considered break with the family habit, and the same one Erpy makes: project config
 * would push a staging site's sandbox UPS credentials into production on the next deploy, and a
 * test account quietly printing labels that no driver will ever scan is the worst thing this
 * plugin could do.
 */
class Connections extends Component
{
    /** @var array<int,Connection>|null */
    private ?array $_connections = null;

    /**
     * @return Connection[]
     */
    public function all(): array
    {
        if ($this->_connections !== null) {
            return $this->_connections;
        }

        $connections = [];

        foreach ((new Query())->from(Table::CONNECTIONS)->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])->all() as $row) {
            $connection = new Connection($row);
            $connections[$connection->id] = $connection;
        }

        return $this->_connections = $connections;
    }

    /**
     * @return Connection[]
     */
    public function enabled(): array
    {
        return array_filter($this->all(), static fn(Connection $c) => $c->enabled && $c->getCarrierClass() !== null);
    }

    public function getById(int $id): ?Connection
    {
        return $this->all()[$id] ?? null;
    }

    public function getByHandle(string $handle): ?Connection
    {
        foreach ($this->all() as $connection) {
            if ($connection->handle === $handle) {
                return $connection;
            }
        }

        return null;
    }

    /**
     * Enabled connections whose carrier has a feature.
     *
     * @return Connection[]
     */
    public function withFeature(string $feature): array
    {
        return array_values(array_filter(
            $this->enabled(),
            static fn(Connection $c) => $c->getCarrierClass()::capabilities()->has($feature),
        ));
    }

    public function save(Connection $connection, bool $runValidation = true): bool
    {
        if ($runValidation && !$connection->validate()) {
            return false;
        }

        $isNew = !$connection->id;
        $record = $isNew ? new ConnectionRecord() : ConnectionRecord::findOne($connection->id);

        if (!$record) {
            throw new \InvalidArgumentException("No connection with the id “{$connection->id}”.");
        }

        $record->setAttribute('name', $connection->name);
        $record->setAttribute('handle', $connection->handle);
        $record->setAttribute('carrier', $connection->carrier);
        $record->setAttribute('enabled', $connection->enabled);
        $record->setAttribute('labelFormat', $connection->labelFormat ?: null);
        $record->setAttribute('shipFrom', json_encode($connection->shipFrom));

        // A blank secret posted from the form means "leave it alone". The CP never sends a saved
        // credential back to the browser, so it cannot come back here either.
        $record->setAttribute('settings', json_encode($this->_mergeSettings($connection, $isNew)));

        // Tokens belong to saveTokens() once a connection exists: the model being saved was loaded
        // with the edit screen, and an OAuth refresh may have rotated a token since.
        if ($isNew) {
            $record->setAttribute('tokens', json_encode($connection->tokens));
            $record->setAttribute('sortOrder', (int)(new Query())->from(Table::CONNECTIONS)->max('[[sortOrder]]') + 1);
        }

        $record->save(false);

        $connection->id = $record->getAttribute('id');
        $connection->uid = $record->getAttribute('uid');
        $connection->settings = json_decode((string)$record->getAttribute('settings'), true) ?: [];
        $this->_connections = null;

        return true;
    }

    /**
     * Persist just the token bag. Token refreshes happen mid-request; saving the whole model
     * there would write back whatever else happened to be in memory.
     */
    public function saveTokens(Connection $connection): void
    {
        if (!$connection->id) {
            return;
        }

        Db::update(Table::CONNECTIONS, ['tokens' => json_encode($connection->tokens)], ['id' => $connection->id]);
        $this->_connections = null;
    }

    public function markPointsSynced(Connection $connection, DateTime $at): void
    {
        Db::update(Table::CONNECTIONS, ['pointsSyncedAt' => Db::prepareDateForDb($at)], ['id' => $connection->id]);
        $connection->pointsSyncedAt = $at;
        $this->_connections = null;
    }

    public function delete(Connection $connection): bool
    {
        if (!$connection->id) {
            return false;
        }

        try {
            Db::delete(Table::LOG, ['connectionId' => $connection->id]);
            Db::delete(Table::CONNECTIONS, ['id' => $connection->id]);
        } catch (Throwable $e) {
            Craft::error('Carrier could not delete a connection: ' . $e->getMessage(), 'carrier');

            return false;
        }

        $this->_connections = null;

        return true;
    }

    /**
     * A copy for a second account or a sandbox, credentials deliberately left blank.
     */
    public function duplicate(Connection $connection): Connection
    {
        $class = $connection->getCarrierClass();
        $secrets = $class ? Field::secretNames($class::settingsFields()) : [];

        return new Connection([
            'name' => Craft::t('carrier', '{name} (copy)', ['name' => $connection->name]),
            'handle' => $connection->handle . StringHelper::randomString(4),
            'carrier' => $connection->carrier,
            'enabled' => false,
            'labelFormat' => $connection->labelFormat,
            'shipFrom' => $connection->shipFrom,
            'settings' => array_diff_key($connection->settings, array_flip($secrets)),
        ]);
    }

    public function clearCache(): void
    {
        $this->_connections = null;
    }

    private function _mergeSettings(Connection $connection, bool $isNew): array
    {
        if ($isNew) {
            return $connection->settings;
        }

        $existing = json_decode((string)(new Query())
            ->select(['settings'])
            ->from(Table::CONNECTIONS)
            ->where(['id' => $connection->id])
            ->scalar(), true) ?: [];

        $merged = $connection->settings;
        $class = $connection->getCarrierClass();

        foreach ($class ? Field::secretNames($class::settingsFields()) : [] as $name) {
            if (($merged[$name] ?? '') === '' && ($existing[$name] ?? '') !== '') {
                $merged[$name] = $existing[$name];
            }
        }

        return $merged;
    }
}
