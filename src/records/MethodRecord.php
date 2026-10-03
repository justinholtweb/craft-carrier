<?php

namespace justinholtweb\carrier\records;

use craft\db\ActiveRecord;
use justinholtweb\carrier\db\Table;

/**
 * Exists for Craft's `UniqueValidator`, which wants a record class. Reads and writes go through
 * the service with plain queries.
 */
class MethodRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::METHODS;
    }
}
