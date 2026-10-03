<?php

namespace justinholtweb\carrier\base;

use justinholtweb\carrier\models\canonical\Label;
use justinholtweb\carrier\models\canonical\ShipmentRef;

/**
 * What came back when Carrier bought labels.
 *
 * The distinction that matters is `retryable`. A carrier that *refused* the shipment (a bad
 * postcode, an overweight parcel) will refuse it identically next time; a carrier that timed out
 * may or may not have created it, and the engine treats that as "ask a human", never "buy again".
 */
class ShipmentResult
{
    /**
     * @param string[] $trackingNumbers one per parcel, in parcel order
     * @param Label[] $labels
     */
    public function __construct(
        public bool $success = false,
        public string $shipmentId = '',
        public array $trackingNumbers = [],
        public array $labels = [],
        public ?float $charge = null,
        public string $currency = '',
        /** Carrier data needed later to void or reprint — see `ShipmentRef::$data`. */
        public array $data = [],
        public ?string $message = null,
        public bool $retryable = false,
        /**
         * The carrier may have created the shipment even though no answer came back — a timeout,
         * a 502. The engine parks it for a human rather than buying it again.
         */
        public bool $uncertain = false,
        public array $warnings = [],
    ) {
    }

    public static function ok(string $shipmentId, array $trackingNumbers, array $labels, array $data = []): self
    {
        return new self(
            success: true,
            shipmentId: $shipmentId,
            trackingNumbers: array_values(array_map('strval', $trackingNumbers)),
            labels: array_values($labels),
            data: $data,
        );
    }

    /** The carrier refused the shipment. Fix the data; do not retry as-is. */
    public static function rejected(string $message): self
    {
        return new self(message: $message, retryable: false);
    }

    /** Throttled before anything was created — safe to try again. */
    public static function failed(string $message): self
    {
        return new self(message: $message, retryable: true);
    }

    /** No usable answer after the request left — the label may exist. */
    public static function uncertain(string $message): self
    {
        return new self(message: $message, retryable: false, uncertain: true);
    }

    /**
     * Classify a failed HTTP answer the way every carrier should.
     *
     * A 4xx other than 408/429 is the carrier refusing the shipment; a 429 is throttling before
     * anything happened; anything else — a 5xx, a 408, no answer at all — leaves it unknown
     * whether the label was created.
     */
    public static function fromResponse(Response $response, ?string $message = null): self
    {
        $message ??= $response->errorMessage();

        if ($response->status === 429) {
            return self::failed($message);
        }

        if ($response->status >= 400 && $response->status < 500 && $response->status !== 408) {
            return self::rejected($message);
        }

        return self::uncertain($message);
    }

    public function withCharge(?float $amount, string $currency): self
    {
        $this->charge = $amount;
        $this->currency = $currency;

        return $this;
    }

    public function ref(string $serviceCode = ''): ShipmentRef
    {
        return new ShipmentRef([
            'shipmentId' => $this->shipmentId,
            'trackingNumbers' => $this->trackingNumbers,
            'serviceCode' => $serviceCode,
            'data' => $this->data,
        ]);
    }
}
