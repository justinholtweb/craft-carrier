<?php

namespace justinholtweb\carrier\models;

use Craft;
use craft\base\Model;
use craft\helpers\UrlHelper;
use DateTime;
use justinholtweb\carrier\base\TrackingStatus;
use justinholtweb\carrier\models\canonical\ShipmentRef;
use justinholtweb\carrier\models\canonical\ShipmentRequest;
use justinholtweb\carrier\Plugin;

/**
 * One label purchase: a consignment of one or more parcels sent with one carrier.
 */
class Shipment extends Model
{
    /** Claimed; the carrier is being asked. A row stuck here was interrupted mid-purchase. */
    public const STATUS_PURCHASING = 'purchasing';
    public const STATUS_LABELLED = 'labelled';
    /** The carrier refused it. No label exists; fix and try again. */
    public const STATUS_FAILED = 'failed';
    /** No usable answer came back. A label may exist; a human has to look. */
    public const STATUS_UNCERTAIN = 'uncertain';
    public const STATUS_VOIDED = 'voided';

    public ?int $id = null;

    public ?int $orderId = null;

    public ?int $connectionId = null;

    public ?int $methodId = null;

    public string $carrier = '';

    public string $serviceCode = '';

    public ?string $serviceName = null;

    public string $status = self::STATUS_PURCHASING;

    public ?string $claimKey = null;

    public ?string $carrierShipmentId = null;

    public ?string $trackingNumber = null;

    public string $trackingStatus = TrackingStatus::UNKNOWN;

    public ?string $trackingStatusText = null;

    public ?string $labelFormat = null;

    public ?float $charge = null;

    public ?string $currency = null;

    public ?float $codAmount = null;

    public ?string $codCurrency = null;

    public ?string $pointId = null;

    public array $point = [];

    public bool $isReturn = false;

    /** The request snapshot — what was actually sent. */
    public array $request = [];

    /** Whatever the carrier needs to void or reprint. */
    public array $data = [];

    public ?string $error = null;

    public ?DateTime $estimatedDelivery = null;

    public ?DateTime $labelledAt = null;

    public ?DateTime $shippedAt = null;

    public ?DateTime $deliveredAt = null;

    public ?DateTime $voidedAt = null;

    public ?DateTime $lastTrackedAt = null;

    public ?DateTime $nextTrackAt = null;

    public ?int $createdById = null;

    public ?DateTime $dateCreated = null;

    public ?DateTime $dateUpdated = null;

    public ?string $uid = null;

    /** @var ShipmentParcel[]|null */
    private ?array $_parcels = null;

    public function __construct($config = [])
    {
        foreach (['point', 'request', 'data'] as $key) {
            if (array_key_exists($key, $config) && !is_array($config[$key])) {
                $config[$key] = json_decode((string)$config[$key], true) ?: [];
            }
        }

        foreach (['charge', 'codAmount'] as $key) {
            if (isset($config[$key])) {
                $config[$key] = (float)$config[$key];
            }
        }

        // Craft stores bare UTC strings; a DateTime built without a zone reads them as site-local.
        foreach (['estimatedDelivery', 'labelledAt', 'shippedAt', 'deliveredAt', 'voidedAt', 'lastTrackedAt', 'nextTrackAt', 'dateCreated', 'dateUpdated'] as $key) {
            if (isset($config[$key]) && is_string($config[$key])) {
                $config[$key] = new DateTime($config[$key], new \DateTimeZone('UTC'));
            }
        }

        parent::__construct($config);
    }

    public function getConnection(): ?Connection
    {
        return $this->connectionId ? Plugin::getInstance()->getConnections()->getById($this->connectionId) : null;
    }

    public function getMethod(): ?Method
    {
        return $this->methodId ? Plugin::getInstance()->getMethods()->getById($this->methodId) : null;
    }

    public function getOrder(): ?\craft\commerce\elements\Order
    {
        return $this->orderId
            ? \craft\commerce\elements\Order::find()->id($this->orderId)->status(null)->one()
            : null;
    }

    /**
     * @return ShipmentParcel[]
     */
    public function getParcels(): array
    {
        if ($this->_parcels === null) {
            $this->_parcels = $this->id ? Plugin::getInstance()->getShipments()->parcelsFor($this->id) : [];
        }

        return $this->_parcels;
    }

    public function setParcels(array $parcels): void
    {
        $this->_parcels = $parcels;
    }

    public function getRequest(): ShipmentRequest
    {
        return new ShipmentRequest($this->request);
    }

    public function getRef(): ShipmentRef
    {
        $numbers = array_values(array_filter(array_map(
            static fn(ShipmentParcel $p) => $p->trackingNumber,
            $this->getParcels(),
        )));

        if ($numbers === [] && $this->trackingNumber) {
            $numbers = [$this->trackingNumber];
        }

        return new ShipmentRef([
            'shipmentId' => (string)$this->carrierShipmentId,
            'trackingNumbers' => $numbers,
            'serviceCode' => $this->serviceCode,
            'data' => $this->data,
        ]);
    }

    /** @return string[] every tracking number this shipment carries */
    public function trackingNumbers(): array
    {
        return $this->getRef()->trackingNumbers;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_LABELLED;
    }

    public function needsAttention(): bool
    {
        return in_array($this->status, [self::STATUS_UNCERTAIN, self::STATUS_FAILED], true)
            || ($this->status === self::STATUS_PURCHASING && $this->dateCreated
                && $this->dateCreated->getTimestamp() < time() - 600);
    }

    public function getTrackingUrl(): ?string
    {
        $class = Plugin::getInstance()->getCarriers()->classFor($this->carrier);

        return $class && $this->trackingNumber ? $class::trackingUrl($this->trackingNumber) : null;
    }

    public function getCarrierName(): string
    {
        $class = Plugin::getInstance()->getCarriers()->classFor($this->carrier);

        return $class ? $class::displayName() : $this->carrier;
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PURCHASING => Craft::t('carrier', 'Buying label'),
            self::STATUS_LABELLED => Craft::t('carrier', 'Labelled'),
            self::STATUS_FAILED => Craft::t('carrier', 'Refused'),
            self::STATUS_UNCERTAIN => Craft::t('carrier', 'Check with carrier'),
            self::STATUS_VOIDED => Craft::t('carrier', 'Voided'),
            default => $this->status,
        };
    }

    public function getTrackingStatusLabel(): string
    {
        return TrackingStatus::label($this->trackingStatus);
    }

    public function getCpUrl(): string
    {
        return UrlHelper::cpUrl('carrier/shipments/' . $this->id);
    }
}
