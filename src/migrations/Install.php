<?php

namespace justinholtweb\carrier\migrations;

use craft\db\Migration;
use justinholtweb\carrier\db\Table;

/**
 * Carrier's schema.
 *
 * Two decisions carry it. A shipment row is written *before* a label is bought (see
 * `services\Labels::purchase()`), so its status column is the claim that stops a retried job from
 * buying the same label twice. And nothing here has a foreign key to a Commerce element: an order
 * that gets deleted must not take the record of a label the merchant paid for with it.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        foreach ([
            Table::LOG, Table::PICKUPS, Table::SELECTIONS, Table::POINTS, Table::EVENTS,
            Table::LABELS, Table::PARCELS, Table::SHIPMENTS, Table::METHODS, Table::CONNECTIONS,
        ] as $table) {
            $this->dropTableIfExists($table);
        }

        return true;
    }

    private function createTables(): void
    {
        if (!$this->db->tableExists(Table::CONNECTIONS)) {
            $this->createTable(Table::CONNECTIONS, [
                'id' => $this->primaryKey(),
                'name' => $this->string()->notNull(),
                'handle' => $this->string()->notNull(),
                'carrier' => $this->string(64)->notNull(),
                'enabled' => $this->boolean()->notNull()->defaultValue(false),
                'labelFormat' => $this->string(16)->null(),
                'settings' => $this->text(),
                'shipFrom' => $this->text(),
                'tokens' => $this->text(),
                'pointsSyncedAt' => $this->dateTime()->null(),
                'sortOrder' => $this->smallInteger()->unsigned()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        if (!$this->db->tableExists(Table::METHODS)) {
            $this->createTable(Table::METHODS, [
                'id' => $this->primaryKey(),
                'storeId' => $this->integer()->null(),
                'connectionId' => $this->integer()->notNull(),
                'name' => $this->string()->notNull(),
                'handle' => $this->string()->notNull(),
                'serviceCode' => $this->string(64)->notNull()->defaultValue(''),
                'requiresPoint' => $this->boolean()->notNull()->defaultValue(false),
                'pricing' => $this->text(),
                'conditions' => $this->text(),
                'packing' => $this->text(),
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                'sortOrder' => $this->smallInteger()->unsigned()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        if (!$this->db->tableExists(Table::SHIPMENTS)) {
            $this->createTable(Table::SHIPMENTS, [
                'id' => $this->primaryKey(),
                'orderId' => $this->integer()->null(),
                'connectionId' => $this->integer()->null(),
                'methodId' => $this->integer()->null(),
                // The carrier handle survives the connection being deleted, so history can still
                // say who carried the parcel.
                'carrier' => $this->string(64)->notNull(),
                'serviceCode' => $this->string(64)->notNull()->defaultValue(''),
                'serviceName' => $this->string()->null(),
                'status' => $this->string(16)->notNull(),
                // What a retried job claims. Unique, so two workers cannot both buy for one key.
                'claimKey' => $this->string(191)->null(),
                'carrierShipmentId' => $this->string()->null(),
                'trackingNumber' => $this->string(64)->null(),
                'trackingStatus' => $this->string(24)->notNull()->defaultValue('unknown'),
                'trackingStatusText' => $this->string()->null(),
                'labelFormat' => $this->string(16)->null(),
                'charge' => $this->decimal(14, 4)->null(),
                'currency' => $this->string(3)->null(),
                'codAmount' => $this->decimal(14, 4)->null(),
                'codCurrency' => $this->string(3)->null(),
                'pointId' => $this->string(64)->null(),
                'point' => $this->text(),
                'isReturn' => $this->boolean()->notNull()->defaultValue(false),
                'request' => $this->mediumText(),
                'data' => $this->text(),
                'error' => $this->text(),
                'estimatedDelivery' => $this->dateTime()->null(),
                'labelledAt' => $this->dateTime()->null(),
                'shippedAt' => $this->dateTime()->null(),
                'deliveredAt' => $this->dateTime()->null(),
                'voidedAt' => $this->dateTime()->null(),
                'lastTrackedAt' => $this->dateTime()->null(),
                'nextTrackAt' => $this->dateTime()->null(),
                'createdById' => $this->integer()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        if (!$this->db->tableExists(Table::PARCELS)) {
            $this->createTable(Table::PARCELS, [
                'id' => $this->primaryKey(),
                'shipmentId' => $this->integer()->notNull(),
                'sequence' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
                'trackingNumber' => $this->string(64)->null(),
                'weightG' => $this->integer()->unsigned()->notNull()->defaultValue(0),
                'lengthMm' => $this->integer()->unsigned()->notNull()->defaultValue(0),
                'widthMm' => $this->integer()->unsigned()->notNull()->defaultValue(0),
                'heightMm' => $this->integer()->unsigned()->notNull()->defaultValue(0),
                'box' => $this->string()->null(),
                'items' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        if (!$this->db->tableExists(Table::LABELS)) {
            // Base64 in the database rather than files on disk: Craft Cloud and load-balanced
            // hosts have no shared filesystem, and a label carries a customer's name and address,
            // which must never sit in a public asset volume.
            $this->createTable(Table::LABELS, [
                'id' => $this->primaryKey(),
                'shipmentId' => $this->integer()->notNull(),
                'parcelId' => $this->integer()->null(),
                'kind' => $this->string(16)->notNull()->defaultValue('label'),
                'format' => $this->string(16)->notNull(),
                'trackingNumber' => $this->string(64)->null(),
                'size' => $this->integer()->unsigned()->notNull()->defaultValue(0),
                'data' => $this->longText(),
                'printedAt' => $this->dateTime()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        if (!$this->db->tableExists(Table::EVENTS)) {
            $this->createTable(Table::EVENTS, [
                'id' => $this->primaryKey(),
                'shipmentId' => $this->integer()->notNull(),
                'trackingNumber' => $this->string(64)->null(),
                'hash' => $this->char(32)->notNull(),
                'status' => $this->string(24)->notNull(),
                'code' => $this->string(64)->null(),
                'description' => $this->string(1000)->null(),
                'location' => $this->string()->null(),
                'occurredAt' => $this->dateTime()->null(),
                'source' => $this->string(16)->notNull()->defaultValue('poll'),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        if (!$this->db->tableExists(Table::POINTS)) {
            $this->createTable(Table::POINTS, [
                'id' => $this->primaryKey(),
                'connectionId' => $this->integer()->notNull(),
                'pointId' => $this->string(64)->notNull(),
                'type' => $this->string(16)->notNull(),
                'name' => $this->string()->notNull(),
                'street' => $this->string()->null(),
                'city' => $this->string()->null(),
                'postcode' => $this->string(32)->null(),
                'countryCode' => $this->char(2)->notNull(),
                'latitude' => $this->decimal(10, 7)->null(),
                'longitude' => $this->decimal(10, 7)->null(),
                'hours' => $this->text(),
                'codAllowed' => $this->boolean()->notNull()->defaultValue(true),
                'maxWeightG' => $this->integer()->unsigned()->notNull()->defaultValue(0),
                'extra' => $this->text(),
                // `sync` rows are replaced by the next sync; `manual` rows (an imported list, for a
                // carrier with no point API such as DPD EasyShip) are only touched by the merchant.
                'source' => $this->string(8)->notNull()->defaultValue('sync'),
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                'syncedAt' => $this->dateTime()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        if (!$this->db->tableExists(Table::SELECTIONS)) {
            $this->createTable(Table::SELECTIONS, [
                'id' => $this->primaryKey(),
                'orderId' => $this->integer()->notNull(),
                'methodId' => $this->integer()->null(),
                'connectionId' => $this->integer()->notNull(),
                'pointId' => $this->string(64)->notNull(),
                'point' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        if (!$this->db->tableExists(Table::PICKUPS)) {
            $this->createTable(Table::PICKUPS, [
                'id' => $this->primaryKey(),
                'connectionId' => $this->integer()->null(),
                'carrier' => $this->string(64)->notNull(),
                'pickupDate' => $this->date()->notNull(),
                'readyTime' => $this->string(5)->null(),
                'closeTime' => $this->string(5)->null(),
                'parcelCount' => $this->integer()->unsigned()->notNull()->defaultValue(0),
                'reference' => $this->string()->null(),
                'status' => $this->string(16)->notNull(),
                'shipmentIds' => $this->text(),
                'message' => $this->text(),
                'createdById' => $this->integer()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        if (!$this->db->tableExists(Table::LOG)) {
            $this->createTable(Table::LOG, [
                'id' => $this->primaryKey(),
                'connectionId' => $this->integer()->null(),
                'shipmentId' => $this->integer()->null(),
                'type' => $this->string(16)->notNull(),
                'method' => $this->string(8)->null(),
                'url' => $this->string(2000)->null(),
                'requestHeaders' => $this->text(),
                'requestBody' => $this->mediumText(),
                'status' => $this->smallInteger()->null(),
                'responseBody' => $this->mediumText(),
                'durationMs' => $this->integer()->null(),
                'attempt' => $this->smallInteger()->null(),
                'message' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }
    }

    private function createIndexes(): void
    {
        $this->createIndex(null, Table::CONNECTIONS, ['handle'], true);
        $this->createIndex(null, Table::METHODS, ['handle'], true);
        $this->createIndex(null, Table::METHODS, ['connectionId']);
        $this->createIndex(null, Table::SHIPMENTS, ['orderId']);
        $this->createIndex(null, Table::SHIPMENTS, ['claimKey'], true);
        $this->createIndex(null, Table::SHIPMENTS, ['status']);
        $this->createIndex(null, Table::SHIPMENTS, ['trackingNumber']);
        $this->createIndex(null, Table::SHIPMENTS, ['nextTrackAt']);
        $this->createIndex(null, Table::PARCELS, ['shipmentId']);
        $this->createIndex(null, Table::PARCELS, ['trackingNumber']);
        $this->createIndex(null, Table::LABELS, ['shipmentId']);
        $this->createIndex(null, Table::LABELS, ['dateCreated']);
        // The tracking dedup guarantee: the same scan fetched twice is one row.
        $this->createIndex(null, Table::EVENTS, ['shipmentId', 'hash'], true);
        $this->createIndex(null, Table::POINTS, ['connectionId', 'pointId'], true);
        $this->createIndex(null, Table::POINTS, ['connectionId', 'countryCode', 'postcode']);
        $this->createIndex(null, Table::POINTS, ['latitude', 'longitude']);
        $this->createIndex(null, Table::SELECTIONS, ['orderId'], true);
        $this->createIndex(null, Table::LOG, ['connectionId', 'dateCreated']);
        $this->createIndex(null, Table::LOG, ['shipmentId']);
    }

    private function addForeignKeys(): void
    {
        // Owned data cascades. References to connections from history do not — see above.
        $this->addForeignKey(null, Table::METHODS, ['connectionId'], Table::CONNECTIONS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::PARCELS, ['shipmentId'], Table::SHIPMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::LABELS, ['shipmentId'], Table::SHIPMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::EVENTS, ['shipmentId'], Table::SHIPMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::POINTS, ['connectionId'], Table::CONNECTIONS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::SHIPMENTS, ['connectionId'], Table::CONNECTIONS, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::SHIPMENTS, ['methodId'], Table::METHODS, ['id'], 'SET NULL');
    }
}
