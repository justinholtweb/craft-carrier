<?php

namespace justinholtweb\carrier\base;

use justinholtweb\carrier\helpers\LabelFormat;

/**
 * What a carrier can actually do.
 *
 * Carrier builds its whole UI from this — which buttons the shipment screen shows, which label
 * formats a connection may pick, whether a method can price live or must use a table — so a
 * carrier never ships a template and can never advertise something it has not implemented.
 */
class Capabilities
{
    public const POINTS_LIST = 'list';
    public const POINTS_SEARCH = 'search';

    /**
     * The carrier has points but no API to list or search them (DPD EasyShip): the merchant
     * imports them as CSV, and they are searched locally. Nothing is ever synced.
     */
    public const POINTS_MANUAL = 'manual';

    /** @var array<string,bool> */
    private array $features = [];

    /** @var string[] */
    private array $labelFormats = [];

    private string $pointsMode = self::POINTS_SEARCH;

    /** @var string[] point types the carrier has */
    private array $pointTypes = [];

    /** @var string[] ISO alpha-2 countries the carrier ships *from*. Empty means anywhere. */
    private array $originCountries = [];

    /** @var string[] accessorials the carrier maps */
    private array $accessorials = [];

    /** Heaviest single parcel, grams. 0 means unlimited as far as Carrier knows. */
    private int $maxParcelWeightG = 0;

    /** How many tracking numbers one request may carry. */
    private int $trackingBatchSize = 1;

    private bool $sandbox = false;

    public static function make(): self
    {
        return new self();
    }

    public function supports(string ...$features): self
    {
        foreach ($features as $feature) {
            $this->features[$feature] = true;
        }

        return $this;
    }

    public function labelFormats(string ...$formats): self
    {
        $this->labelFormats = array_values(array_intersect($formats, LabelFormat::all()));

        return $this;
    }

    public function points(string $mode, string ...$types): self
    {
        $this->features[Feature::POINTS] = true;
        $this->pointsMode = $mode;
        $this->pointTypes = $types;

        return $this;
    }

    public function originCountries(string ...$countries): self
    {
        $this->originCountries = array_map('strtoupper', $countries);

        return $this;
    }

    public function accessorials(string ...$accessorials): self
    {
        $this->accessorials = $accessorials;

        return $this;
    }

    public function maxParcelWeight(int $grams): self
    {
        $this->maxParcelWeightG = max(0, $grams);

        return $this;
    }

    public function trackingBatch(int $size): self
    {
        $this->trackingBatchSize = max(1, $size);

        return $this;
    }

    public function withSandbox(bool $value = true): self
    {
        $this->sandbox = $value;

        return $this;
    }

    public function has(string $feature): bool
    {
        return $this->features[$feature] ?? false;
    }

    /** @return string[] */
    public function features(): array
    {
        return array_values(array_filter(Feature::all(), fn(string $f) => $this->has($f)));
    }

    /** @return string[] */
    public function getLabelFormats(): array
    {
        return $this->labelFormats;
    }

    public function supportsLabelFormat(string $format): bool
    {
        return in_array($format, $this->labelFormats, true);
    }

    public function getPointsMode(): string
    {
        return $this->pointsMode;
    }

    public function getPointTypes(): array
    {
        return $this->pointTypes;
    }

    public function getOriginCountries(): array
    {
        return $this->originCountries;
    }

    public function shipsFrom(string $countryCode): bool
    {
        return $this->originCountries === [] || in_array(strtoupper($countryCode), $this->originCountries, true);
    }

    public function getAccessorials(): array
    {
        return $this->accessorials;
    }

    public function getMaxParcelWeightG(): int
    {
        return $this->maxParcelWeightG;
    }

    public function getTrackingBatchSize(): int
    {
        return $this->trackingBatchSize;
    }

    public function hasSandbox(): bool
    {
        return $this->sandbox;
    }

    public function toArray(): array
    {
        return [
            'features' => $this->features(),
            'labelFormats' => $this->labelFormats,
            'pointsMode' => $this->has(Feature::POINTS) ? $this->pointsMode : null,
            'pointTypes' => $this->pointTypes,
            'originCountries' => $this->originCountries,
            'accessorials' => $this->accessorials,
            'maxParcelWeightG' => $this->maxParcelWeightG,
            'trackingBatchSize' => $this->trackingBatchSize,
            'sandbox' => $this->sandbox,
        ];
    }
}
