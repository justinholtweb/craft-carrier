<?php

namespace justinholtweb\carrier\db;

/**
 * Carrier's table names, in one place.
 */
abstract class Table
{
    public const CONNECTIONS = '{{%carrier_connections}}';
    public const METHODS = '{{%carrier_methods}}';
    public const SHIPMENTS = '{{%carrier_shipments}}';
    public const PARCELS = '{{%carrier_parcels}}';
    public const LABELS = '{{%carrier_labels}}';
    public const EVENTS = '{{%carrier_events}}';
    public const POINTS = '{{%carrier_points}}';
    public const SELECTIONS = '{{%carrier_selections}}';
    public const PICKUPS = '{{%carrier_pickups}}';
    public const LOG = '{{%carrier_log}}';
}
