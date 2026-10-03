<?php

namespace justinholtweb\carrier\services;

/**
 * Carrier's service map.
 *
 * @property-read Carriers $carriers
 * @property-read Connections $connections
 * @property-read Methods $methods
 * @property-read Packer $packer
 * @property-read Orders $orders
 * @property-read Rates $rates
 * @property-read Labels $labels
 * @property-read Shipments $shipments
 * @property-read Tracking $tracking
 * @property-read Points $points
 * @property-read Selections $selections
 * @property-read Pickups $pickups
 * @property-read Log $log
 * @property-read Sweeper $sweeper
 */
trait ServicesTrait
{
    public static function config(): array
    {
        return [
            'components' => [
                'carriers' => ['class' => Carriers::class],
                'connections' => ['class' => Connections::class],
                'methods' => ['class' => Methods::class],
                'packer' => ['class' => Packer::class],
                'orders' => ['class' => Orders::class],
                'rates' => ['class' => Rates::class],
                'labels' => ['class' => Labels::class],
                'shipments' => ['class' => Shipments::class],
                'tracking' => ['class' => Tracking::class],
                'points' => ['class' => Points::class],
                'selections' => ['class' => Selections::class],
                'pickups' => ['class' => Pickups::class],
                'log' => ['class' => Log::class],
                'sweeper' => ['class' => Sweeper::class],
            ],
        ];
    }

    public function getCarriers(): Carriers
    {
        $component = $this->get('carriers');
        assert($component instanceof Carriers);

        return $component;
    }

    public function getConnections(): Connections
    {
        $component = $this->get('connections');
        assert($component instanceof Connections);

        return $component;
    }

    public function getMethods(): Methods
    {
        $component = $this->get('methods');
        assert($component instanceof Methods);

        return $component;
    }

    public function getPacker(): Packer
    {
        $component = $this->get('packer');
        assert($component instanceof Packer);

        return $component;
    }

    public function getOrders(): Orders
    {
        $component = $this->get('orders');
        assert($component instanceof Orders);

        return $component;
    }

    public function getRates(): Rates
    {
        $component = $this->get('rates');
        assert($component instanceof Rates);

        return $component;
    }

    public function getLabels(): Labels
    {
        $component = $this->get('labels');
        assert($component instanceof Labels);

        return $component;
    }

    public function getShipments(): Shipments
    {
        $component = $this->get('shipments');
        assert($component instanceof Shipments);

        return $component;
    }

    public function getTracking(): Tracking
    {
        $component = $this->get('tracking');
        assert($component instanceof Tracking);

        return $component;
    }

    public function getPoints(): Points
    {
        $component = $this->get('points');
        assert($component instanceof Points);

        return $component;
    }

    public function getSelections(): Selections
    {
        $component = $this->get('selections');
        assert($component instanceof Selections);

        return $component;
    }

    public function getPickups(): Pickups
    {
        $component = $this->get('pickups');
        assert($component instanceof Pickups);

        return $component;
    }

    public function getLog(): Log
    {
        $component = $this->get('log');
        assert($component instanceof Log);

        return $component;
    }

    public function getSweeper(): Sweeper
    {
        $component = $this->get('sweeper');
        assert($component instanceof Sweeper);

        return $component;
    }
}
