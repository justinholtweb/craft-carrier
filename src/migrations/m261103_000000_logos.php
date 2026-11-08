<?php

namespace justinholtweb\carrier\migrations;

use craft\db\Migration;
use justinholtweb\carrier\db\Table;

/**
 * An optional checkout logo on connections and methods (#5).
 */
class m261103_000000_logos extends Migration
{
    public function safeUp(): bool
    {
        foreach ([Table::CONNECTIONS, Table::METHODS] as $table) {
            if (!$this->db->columnExists($table, 'logoId')) {
                $this->addColumn($table, 'logoId', $this->integer()->null()->after('sortOrder'));
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        foreach ([Table::CONNECTIONS, Table::METHODS] as $table) {
            if ($this->db->columnExists($table, 'logoId')) {
                $this->dropColumn($table, 'logoId');
            }
        }

        return true;
    }
}
