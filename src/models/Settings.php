<?php

namespace justinholtweb\carrier\models;

use craft\base\Model;
use justinholtweb\carrier\helpers\Units;

/**
 * Carrier's plugin settings. Everything here is safe to deploy through project config — the
 * credentials live on connections, in the database.
 */
class Settings extends Model
{
    // Packing ---------------------------------------------------------------------------------

    /** `single`, `perItem` or `boxes`. A method can override it. */
    public string $packingStrategy = 'boxes';

    /**
     * Cartons, in the store's own units as the merchant typed them:
     * `[['name' => 'Small', 'length' => 30, 'width' => 20, 'height' => 10, 'tare' => 0.1, 'maxWeight' => 5]]`.
     */
    public array $boxes = [];

    /** Weight assumed for a purchasable with none set, in store weight units. 0 = none. */
    public float $defaultItemWeight = 0.0;

    // Addresses -------------------------------------------------------------------------------

    /** Custom-field handle on addresses holding a phone number. Commerce 5 addresses have none. */
    public string $phoneField = '';

    /** Custom-field handle for a separate house number, where the address form has one. */
    public string $houseNumberField = '';

    /** Calling code used to complete local phone numbers ("385" for Croatia). */
    public string $defaultCallingCode = '';

    // Customs ---------------------------------------------------------------------------------

    /** Field handle (variant or product) holding an HS tariff code. */
    public string $hsCodeField = '';

    /** Field handle holding the country of origin. */
    public string $originCountryField = '';

    /** Fallback country of origin, ISO alpha-2. */
    public string $defaultOriginCountry = '';

    // Checkout --------------------------------------------------------------------------------

    /** How long a live rate is reused for an unchanged cart. */
    public int $rateCacheMinutes = 15;

    /** Refuse payment for a pickup-point method until a point is chosen. */
    public bool $requirePointOnPayment = true;

    /** Gateway handles that mean "the customer pays the courier". */
    public array $codGateways = [];

    // After the order -------------------------------------------------------------------------

    /** Buy the label as soon as an order is completed, for orders on a Carrier method. */
    public bool $autoLabelOnComplete = false;

    /** Order status handle to move an order to when its label is bought. Empty = leave alone. */
    public string $orderStatusOnLabel = '';

    /** Order status handle to move an order to when everything in it is delivered. */
    public string $orderStatusOnDelivered = '';

    // Tracking --------------------------------------------------------------------------------

    public bool $trackingEnabled = true;

    /** Hours between polls for a shipment in motion. Slows down as a shipment ages. */
    public int $trackingIntervalHours = 4;

    /** Stop polling a shipment this many days after its label, delivered or not. */
    public int $trackingMaxDays = 45;

    /** Re-sync list-mode pickup points after this many hours. */
    public int $pointsSyncHours = 24;

    // Housekeeping ----------------------------------------------------------------------------

    public int $labelRetentionDays = 120;

    public bool $logRequests = true;

    public bool $logErrorsOnly = false;

    public bool $logBodies = true;

    public int $logRetentionDays = 30;

    protected function defineRules(): array
    {
        return [
            [['packingStrategy'], 'in', 'range' => ['single', 'perItem', 'boxes']],
            [['rateCacheMinutes', 'trackingIntervalHours', 'trackingMaxDays', 'pointsSyncHours', 'labelRetentionDays', 'logRetentionDays'], 'integer', 'min' => 0],
            [['defaultItemWeight'], 'number', 'min' => 0],
            [['defaultOriginCountry'], 'string', 'max' => 2],
        ];
    }

    /**
     * The boxes converted to grams and millimetres, dropping incomplete rows and sorted smallest
     * first, which is the order the packer tries them in.
     *
     * @return array<int,array{name:string,lengthMm:int,widthMm:int,heightMm:int,tareG:int,maxWeightG:int}>
     */
    public function boxesInternal(string $weightUnit, string $dimensionUnit): array
    {
        $boxes = [];

        foreach ($this->boxes as $row) {
            if (!is_array($row) || empty($row['length']) || empty($row['width']) || empty($row['height'])) {
                continue;
            }

            $boxes[] = [
                'name' => trim((string)($row['name'] ?? '')) ?: 'Box',
                'lengthMm' => Units::toMm((float)$row['length'], $dimensionUnit),
                'widthMm' => Units::toMm((float)$row['width'], $dimensionUnit),
                'heightMm' => Units::toMm((float)$row['height'], $dimensionUnit),
                'tareG' => Units::toGrams((float)($row['tare'] ?? 0), $weightUnit),
                'maxWeightG' => Units::toGrams((float)($row['maxWeight'] ?? 0), $weightUnit),
            ];
        }

        usort($boxes, static fn($a, $b) => ($a['lengthMm'] * $a['widthMm'] * $a['heightMm']) <=> ($b['lengthMm'] * $b['widthMm'] * $b['heightMm']));

        return $boxes;
    }
}
