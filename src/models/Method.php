<?php

namespace justinholtweb\carrier\models;

use Craft;
use craft\base\Model;
use craft\helpers\UrlHelper;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;
use DateTime;
use justinholtweb\carrier\base\Feature;
use justinholtweb\carrier\Plugin;
use justinholtweb\carrier\records\MethodRecord;

/**
 * A shipping method as the customer sees it at checkout: "BOX NOW locker", "UPS Ground",
 * "GLS to your door". One connection, one service, one way of arriving at a price.
 */
class Method extends Model
{
    public const PRICING_LIVE = 'live';
    public const PRICING_FLAT = 'flat';
    public const PRICING_TABLE = 'table';

    public const PACKING_DEFAULT = '';
    public const PACKING_SINGLE = 'single';
    public const PACKING_PER_ITEM = 'perItem';
    public const PACKING_BOXES = 'boxes';

    public ?int $id = null;

    /** Null means every store. */
    public ?int $storeId = null;

    public ?int $connectionId = null;

    public string $name = '';

    public string $handle = '';

    public string $serviceCode = '';

    public bool $requiresPoint = false;

    /**
     * `mode` (live|flat|table), `flatRate`, `markupPercent`, `markupFixed`, `table`
     * (`[['maxWeight' => kg-or-lb, 'price' => …]]`, store weight units), `freeOver`,
     * `fallbackRate` (null hides the method when a live rate fails), `roundUpTo`.
     */
    public array $pricing = [];

    /**
     * `countries`, `excludeCountries`, `minWeight`, `maxWeight` (store weight units),
     * `minSubtotal`, `maxSubtotal`.
     */
    public array $conditions = [];

    /**
     * `strategy`: '' (use the plugin setting) | single | perItem | boxes. For freight methods also
     * `freightClass` (default NMFC class for every handling unit), `packagingType` (`pallet`…)
     * and `accessorials` (`base\Accessorial` values added to every shipment on this method).
     */
    public array $packing = [];

    public bool $enabled = true;

    public ?int $sortOrder = null;

    public ?DateTime $dateCreated = null;

    public ?DateTime $dateUpdated = null;

    public ?string $uid = null;

    public function __construct($config = [])
    {
        foreach (['pricing', 'conditions', 'packing'] as $key) {
            if (array_key_exists($key, $config) && !is_array($config[$key])) {
                $config[$key] = json_decode((string)$config[$key], true) ?: [];
            }
        }

        $config['serviceCode'] = (string)($config['serviceCode'] ?? '');

        parent::__construct($config);
    }

    protected function defineRules(): array
    {
        return [
            [['name', 'handle', 'connectionId'], 'required'],
            [['name', 'handle', 'serviceCode'], 'string', 'max' => 255],
            [['handle'], HandleValidator::class, 'reservedWords' => ['id', 'uid', 'new']],
            [['handle'], UniqueValidator::class, 'targetClass' => MethodRecord::class],
            [['storeId', 'connectionId'], 'integer'],
            [['enabled', 'requiresPoint'], 'boolean'],
            [['pricing'], 'validatePricing'],
        ];
    }

    public function validatePricing(): void
    {
        $connection = $this->getConnection();
        $class = $connection?->getCarrierClass();

        if ($this->pricingMode() === self::PRICING_LIVE && $class && !$class::capabilities()->has(Feature::RATES)) {
            $this->addError('pricing', Craft::t('carrier', '{carrier} has no live rates. Use a flat price or a weight table.', [
                'carrier' => $class::displayName(),
            ]));
        }

        if ($this->pricingMode() === self::PRICING_TABLE && $this->table() === []) {
            $this->addError('pricing', Craft::t('carrier', 'Add at least one row to the weight table.'));
        }

        if ($this->requiresPoint && $class && !$class::capabilities()->has(Feature::POINTS)) {
            $this->addError('requiresPoint', Craft::t('carrier', '{carrier} has no pickup points.', [
                'carrier' => $class::displayName(),
            ]));
        }
    }

    public function getConnection(): ?Connection
    {
        return $this->connectionId ? Plugin::getInstance()->getConnections()->getById($this->connectionId) : null;
    }

    public function pricingMode(): string
    {
        return (string)($this->pricing['mode'] ?? self::PRICING_FLAT);
    }

    public function pricingValue(string $key): ?float
    {
        $value = $this->pricing[$key] ?? null;

        return $value === null || $value === '' ? null : (float)$value;
    }

    /**
     * The weight table, sorted lightest first, with blank rows dropped.
     *
     * @return array<int,array{maxWeight:float,price:float}>
     */
    public function table(): array
    {
        $rows = [];

        foreach ((array)($this->pricing['table'] ?? []) as $row) {
            if (!is_array($row) || ($row['maxWeight'] ?? '') === '' || ($row['price'] ?? '') === '') {
                continue;
            }

            $rows[] = ['maxWeight' => (float)$row['maxWeight'], 'price' => (float)$row['price']];
        }

        usort($rows, static fn($a, $b) => $a['maxWeight'] <=> $b['maxWeight']);

        return $rows;
    }

    public function condition(string $key): mixed
    {
        $value = $this->conditions[$key] ?? null;

        return $value === '' ? null : $value;
    }

    /**
     * @return string[] accessorials every shipment on this method carries
     */
    public function accessorials(): array
    {
        return array_values(array_filter(
            (array)($this->packing['accessorials'] ?? []),
            static fn($a) => in_array($a, \justinholtweb\carrier\base\Accessorial::all(), true),
        ));
    }

    public function packingStrategy(): string
    {
        $strategy = (string)($this->packing['strategy'] ?? '');

        return $strategy !== '' ? $strategy : Plugin::getInstance()->getSettings()->packingStrategy;
    }

    /**
     * The handle Commerce stores on an order. Prefixed so a Carrier method can never collide with
     * a Commerce one, and so an order can be traced back to its method by handle alone.
     */
    public function getShippingMethodHandle(): string
    {
        return 'carrier-' . $this->handle;
    }

    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('carrier/methods/' . $this->id);
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
