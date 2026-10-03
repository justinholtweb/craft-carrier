<?php

namespace justinholtweb\carrier\models\canonical;

/**
 * A shipment as the carrier knows it — enough to void it, reprint it or track it.
 *
 * `data` is whatever the carrier returned at creation that it will need again later: GLS's
 * internal parcel ids, a FedEx shipment's `shipDatestamp`, a freight carrier's quote number. It is
 * the carrier's to fill and the carrier's to read; the engine only stores it.
 */
class ShipmentRef extends Document
{
    public string $shipmentId = '';

    /** @var string[] */
    public array $trackingNumbers = [];

    public string $serviceCode = '';

    public array $data = [];
}
