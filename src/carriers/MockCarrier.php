<?php

namespace justinholtweb\carrier\carriers;

use Craft;
use craft\web\Request;
use justinholtweb\carrier\base\Accessorial;
use justinholtweb\carrier\base\ActionResult;
use justinholtweb\carrier\base\AddressResult;
use justinholtweb\carrier\base\Capabilities;
use justinholtweb\carrier\base\Carrier;
use justinholtweb\carrier\base\Feature;
use justinholtweb\carrier\base\Field;
use justinholtweb\carrier\base\HealthResult;
use justinholtweb\carrier\base\LabelResult;
use justinholtweb\carrier\base\PointPage;
use justinholtweb\carrier\base\RateResult;
use justinholtweb\carrier\base\ShipmentResult;
use justinholtweb\carrier\base\TrackingStatus;
use justinholtweb\carrier\helpers\LabelFormat;
use justinholtweb\carrier\helpers\Pdf;
use justinholtweb\carrier\models\canonical\Address;
use justinholtweb\carrier\models\canonical\Label;
use justinholtweb\carrier\models\canonical\PickupPoint;
use justinholtweb\carrier\models\canonical\PickupRequest;
use justinholtweb\carrier\models\canonical\PointQuery;
use justinholtweb\carrier\models\canonical\Rate;
use justinholtweb\carrier\models\canonical\ShipmentRef;
use justinholtweb\carrier\models\canonical\ShipmentRequest;
use justinholtweb\carrier\models\canonical\TrackingEvent;
use justinholtweb\carrier\models\canonical\TrackingInfo;

/**
 * A carrier that never leaves the server.
 *
 * It exists for two audiences. A merchant can wire up methods, checkout, the point picker, bulk
 * labels and tracking end to end before their UPS account is approved. And the test suite drives
 * the whole engine through it, including the failure modes that matter most: a refusal, a
 * throttle and — the expensive one — a timeout after which nobody knows if the label exists.
 *
 * Tracking numbers encode the moment the label was made, so a parcel moves through the statuses
 * on its own as time passes, `stageSeconds` per stage.
 */
class MockCarrier extends Carrier
{
    public const FAIL_NONE = 'none';
    public const FAIL_REFUSE = 'refuse';
    public const FAIL_THROTTLE = 'throttle';
    public const FAIL_TIMEOUT = 'timeout';
    public const FAIL_DOWN = 'down';

    /** A point list per country, centred on its capital, so the picker has something real-ish. */
    private const CENTRES = [
        'HR' => ['Zagreb', 45.8150, 15.9819, '10000'],
        'SI' => ['Ljubljana', 46.0569, 14.5058, '1000'],
        'HU' => ['Budapest', 47.4979, 19.0402, '1051'],
        'GR' => ['Athens', 37.9838, 23.7275, '10552'],
        'BG' => ['Sofia', 42.6977, 23.3219, '1000'],
        'US' => ['Charlotte', 35.2271, -80.8431, '28202'],
        'GB' => ['London', 51.5072, -0.1276, 'EC1A 1BB'],
        'DE' => ['Berlin', 52.5200, 13.4050, '10115'],
    ];

    public static function handle(): string
    {
        return 'mock';
    }

    public static function displayName(): string
    {
        return 'Mock Carrier';
    }

    public static function region(): string
    {
        return Craft::t('carrier', 'Built in');
    }

    public static function description(): string
    {
        return Craft::t('carrier', 'A pretend carrier for setting up and testing checkout, labels and tracking without a real account.');
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            ->supports(
                Feature::RATES, Feature::LABELS, Feature::CANCEL, Feature::REPRINT, Feature::TRACKING,
                Feature::PICKUPS, Feature::CLOSE_DAY, Feature::RETURNS, Feature::COD,
                Feature::ADDRESS_VALIDATION, Feature::WEBHOOKS, Feature::INTERNATIONAL, Feature::MULTI_PIECE,
            )
            ->points(Capabilities::POINTS_LIST, PickupPoint::TYPE_LOCKER, PickupPoint::TYPE_SHOP)
            ->labelFormats(LabelFormat::PDF_A6, LabelFormat::PDF_A4, LabelFormat::PDF_4X6, LabelFormat::ZPL, LabelFormat::PNG)
            ->accessorials(Accessorial::SIGNATURE, Accessorial::SATURDAY_DELIVERY, Accessorial::LIFTGATE_DELIVERY, Accessorial::RESIDENTIAL_DELIVERY)
            ->maxParcelWeight(31500)
            ->trackingBatch(25)
            ->withSandbox();
    }

    public static function settingsFields(): array
    {
        return [
            Field::environment(),
            Field::text('accountNumber', 'Account number', [
                'instructions' => 'Anything. Printed on the label so you can tell sandboxes apart.',
                'default' => 'MOCK-001',
            ]),
            Field::secret('apiKey', 'API key', [
                'instructions' => 'Anything at least six characters long. Never printed, never logged.',
            ]),
            Field::select('failMode', 'Simulate a failure', [
                self::FAIL_NONE => 'Never fail',
                self::FAIL_REFUSE => 'Refuse every label (a validation error)',
                self::FAIL_THROTTLE => 'Throttle every label (HTTP 429)',
                self::FAIL_TIMEOUT => 'Time out after sending (the label may exist)',
                self::FAIL_DOWN => 'Be down for rates, tracking and points',
            ]),
            Field::number('stageSeconds', 'Seconds per tracking stage', [
                'default' => 3600,
                'min' => 1,
                'instructions' => 'How quickly a mock parcel moves from label to delivered.',
            ]),
            Field::secret('webhookSecret', 'Webhook signing secret', [
                'instructions' => 'Pushes to the webhook URL must carry an X-Mock-Signature HMAC-SHA256 of the body.',
            ]),
        ];
    }

    public static function services(): array
    {
        return [
            'standard' => ['name' => 'Mock Standard', 'international' => true, 'points' => false],
            'express' => ['name' => 'Mock Express', 'international' => true, 'points' => false],
            'locker' => ['name' => 'Mock Locker', 'international' => false, 'points' => true],
        ];
    }

    public static function trackingUrl(string $trackingNumber): ?string
    {
        return 'https://example.com/track/' . rawurlencode($trackingNumber);
    }

    protected function probe(): HealthResult
    {
        if ($this->failMode() === self::FAIL_DOWN) {
            return HealthResult::fail('The mock carrier is pretending to be down.');
        }

        return HealthResult::pass('Connected to the mock carrier.', ['Account' => (string)$this->setting('accountNumber', 'MOCK-001')]);
    }

    public function rate(ShipmentRequest $request): RateResult
    {
        if ($this->failMode() === self::FAIL_DOWN) {
            return RateResult::failed('Mock carrier is down.');
        }

        $kg = max(0.1, $request->totalWeightG() / 1000);
        $base = 4.0 + 1.25 * $kg + 0.5 * max(0, count($request->parcels) - 1);
        $international = $request->isInternational();
        $rates = [];

        foreach (self::services() as $code => $service) {
            if ($international && !$service['international']) {
                continue;
            }

            if ($request->serviceCode !== '' && $request->serviceCode !== $code) {
                continue;
            }

            $amount = match ($code) {
                'express' => $base * 1.8,
                'locker' => $base * 0.7,
                default => $base,
            };

            $rates[] = new Rate([
                'serviceCode' => $code,
                'serviceName' => $service['name'],
                'amount' => round($amount * ($international ? 2.5 : 1), 2),
                'currency' => $request->currency ?: 'EUR',
                'transitDays' => $code === 'express' ? 1 : ($international ? 5 : 2),
            ]);
        }

        return RateResult::ok($rates);
    }

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        switch ($this->failMode()) {
            case self::FAIL_REFUSE:
                return ShipmentResult::rejected('Mock carrier refused the shipment: postcode not recognised.');
            case self::FAIL_THROTTLE:
                return ShipmentResult::failed('Mock carrier is throttling; try again shortly.');
            case self::FAIL_TIMEOUT:
                return ShipmentResult::uncertain('Mock carrier timed out after the request was sent.');
        }

        if ((self::services()[$request->serviceCode]['points'] ?? false) && $request->pointId === '') {
            return ShipmentResult::rejected('A locker shipment needs a pickup point.');
        }

        $format = $this->labelFormatFor($request);
        $stamp = time();
        $numbers = [];
        $labels = [];

        foreach ($request->parcels as $i => $parcel) {
            $number = sprintf('MK%d%02d%04d', $stamp, $i, random_int(0, 9999));
            $numbers[] = $number;
            $labels[] = new Label([
                'parcelIndex' => $i,
                'trackingNumber' => $number,
                'format' => $format,
                'kind' => $request->isReturn ? 'return' : 'label',
                'data' => $this->render($format, $request, $number, $i),
            ]);
        }

        $result = ShipmentResult::ok('MOCK-' . $numbers[0], $numbers, $labels, ['format' => $format, 'createdAt' => $stamp]);

        if (count(array_unique(array_map(static fn($l) => $l->format, $labels))) === 1 && $request->hasCod()) {
            $result->warnings[] = sprintf('COD %.2f %s collected by the mock courier.', $request->codAmount, $request->codCurrency);
        }

        $rate = $this->rate(new ShipmentRequest(array_merge($request->toArray(), ['serviceCode' => $request->serviceCode])));

        return $result->withCharge($rate->rates[0]->amount ?? null, $request->currency ?: 'EUR');
    }

    public function cancelShipment(ShipmentRef $shipment): ActionResult
    {
        if ($shipment->shipmentId === '') {
            return ActionResult::failed('Unknown shipment.');
        }

        return ActionResult::ok('VOID-' . $shipment->shipmentId);
    }

    public function printLabels(array $shipments, string $format): LabelResult
    {
        $format = static::capabilities()->supportsLabelFormat($format) ? $format : LabelFormat::PDF_A6;
        $lines = [];

        foreach ($shipments as $shipment) {
            foreach ($shipment->trackingNumbers as $number) {
                $lines[] = 'MOCK  ' . $number;
            }
        }

        if ($lines === []) {
            return LabelResult::failed('Nothing to print.');
        }

        // One merged document, the way GLS and DPD return a batch.
        $data = match ($format) {
            LabelFormat::ZPL => implode("\n", array_map(static fn($l) => "^XA^FO40,40^A0N,40,40^FD$l^FS^XZ", $lines)),
            LabelFormat::PNG => self::png(),
            default => Pdf::text(array_merge(['MOCK CARRIER - BATCH'], $lines), 595.3, 841.9),
        };

        return LabelResult::ok([new Label(['format' => $format, 'data' => $data])]);
    }

    public function track(array $trackingNumbers): array
    {
        if ($this->failMode() === self::FAIL_DOWN) {
            return [];
        }

        $stage = max(1, (int)$this->setting('stageSeconds', 3600));
        $out = [];

        foreach ($trackingNumbers as $number) {
            if (!preg_match('/^MK(\d{10})/', $number, $m)) {
                $out[] = new TrackingInfo(['trackingNumber' => $number, 'notFound' => true]);
                continue;
            }

            $created = (int)$m[1];
            $steps = [
                [TrackingStatus::PRE_TRANSIT, 'LBL', 'Label created'],
                [TrackingStatus::IN_TRANSIT, 'DEP', 'Departed depot'],
                [TrackingStatus::OUT_FOR_DELIVERY, 'OFD', 'Out for delivery'],
                [TrackingStatus::DELIVERED, 'DLV', 'Delivered'],
            ];
            $reached = min(count($steps) - 1, intdiv(max(0, time() - $created), $stage));
            $events = [];

            for ($i = 0; $i <= $reached; $i++) {
                $events[] = new TrackingEvent([
                    'occurredAt' => (new \DateTime('@' . ($created + $i * $stage))),
                    'status' => $steps[$i][0],
                    'code' => $steps[$i][1],
                    'description' => $steps[$i][2],
                    'location' => 'Mock Depot',
                ]);
            }

            $out[] = new TrackingInfo([
                'trackingNumber' => $number,
                'status' => $steps[$reached][0],
                'statusText' => $steps[$reached][2],
                'deliveredAt' => $steps[$reached][0] === TrackingStatus::DELIVERED ? end($events)->occurredAt : null,
                'events' => array_reverse($events),
            ]);
        }

        return $out;
    }

    public function pickupPoints(PointQuery $query): PointPage
    {
        if ($this->failMode() === self::FAIL_DOWN) {
            return PointPage::failed('Mock carrier is down.');
        }

        $country = strtoupper($query->countryCode);
        $centre = self::CENTRES[$country] ?? null;

        if ($centre === null) {
            return PointPage::of([]);
        }

        // Two pages of 15, to exercise paging.
        $page = (int)($query->cursor ?? 0);
        $points = [];

        for ($i = $page * 15; $i < ($page + 1) * 15; $i++) {
            $angle = $i * 0.7;
            $distance = 0.004 * ($i + 1);
            $isLocker = $i % 3 !== 0;

            $points[] = new PickupPoint([
                'id' => sprintf('%s-%s%03d', $country, $isLocker ? 'L' : 'S', $i + 1),
                'type' => $isLocker ? PickupPoint::TYPE_LOCKER : PickupPoint::TYPE_SHOP,
                'name' => sprintf('Mock %s %d', $isLocker ? 'Locker' : 'Shop', $i + 1),
                'street' => sprintf('Mock Street %d', $i + 1),
                'city' => $centre[0],
                'postcode' => $centre[3],
                'countryCode' => $country,
                'latitude' => round($centre[1] + $distance * sin($angle), 6),
                'longitude' => round($centre[2] + $distance * cos($angle), 6),
                'hours' => $isLocker ? '24/7' : 'Mon–Fri 08:00–20:00',
                'codAllowed' => !$isLocker || $i % 2 === 0,
                'maxWeightG' => $isLocker ? 20000 : 31500,
            ]);
        }

        return PointPage::of($points, $page === 0 ? '1' : null);
    }

    public function requestPickup(PickupRequest $request): ActionResult
    {
        return ActionResult::ok('PU-' . ($request->date?->format('Ymd') ?? date('Ymd')) . '-' . $request->parcelCount);
    }

    public function closeDay(array $shipments): ActionResult
    {
        $lines = array_merge(['MOCK MANIFEST ' . date('Y-m-d')], array_map(
            static fn(ShipmentRef $s) => $s->shipmentId . '  ' . implode(', ', $s->trackingNumbers),
            $shipments,
        ));

        return ActionResult::ok('MAN-' . date('Ymd'), count($shipments) . ' shipments closed.', [
            'documents' => [new Label(['kind' => 'manifest', 'format' => LabelFormat::PDF_A4, 'data' => Pdf::text($lines, 595.3, 841.9)])],
        ]);
    }

    public function validateAddress(Address $address): AddressResult
    {
        $valid = $address->postcode !== '' && $address->city !== '' && $address->street1 !== '';
        $suggestion = clone $address;
        $suggestion->city = mb_convert_case($address->city, MB_CASE_TITLE);

        return new AddressResult(
            valid: $valid,
            suggestions: $valid ? [$suggestion] : [],
            residential: $address->company === '',
            messages: $valid ? [] : ['Street, city and postcode are all required.'],
        );
    }

    /**
     * Expects `{"trackingNumber": "...", "status": "delivered", "description": "..."}`, signed.
     */
    public function handleWebhook(Request $request): array
    {
        $secret = (string)$this->setting('webhookSecret', '');
        $body = $request->getRawBody();
        $signature = (string)$request->getHeaders()->get('X-Mock-Signature', '');

        if ($secret === '' || !hash_equals(hash_hmac('sha256', $body, $secret), $signature)) {
            throw new \yii\web\ForbiddenHttpException('Bad signature.');
        }

        $data = json_decode($body, true);

        if (!is_array($data) || empty($data['trackingNumber'])) {
            return [];
        }

        $status = TrackingStatus::isValid((string)($data['status'] ?? '')) ? $data['status'] : TrackingStatus::IN_TRANSIT;

        return [new TrackingInfo([
            'trackingNumber' => (string)$data['trackingNumber'],
            'status' => $status,
            'statusText' => (string)($data['description'] ?? TrackingStatus::label($status)),
            'events' => [new TrackingEvent([
                'occurredAt' => new \DateTime(),
                'status' => $status,
                'code' => 'WH',
                'description' => (string)($data['description'] ?? TrackingStatus::label($status)),
            ])],
        ])];
    }

    private function failMode(): string
    {
        return (string)$this->setting('failMode', self::FAIL_NONE) ?: self::FAIL_NONE;
    }

    private function render(string $format, ShipmentRequest $request, string $number, int $index): string
    {
        $to = $request->shipTo;
        $lines = [
            'MOCK CARRIER' . ($request->isReturn ? ' - RETURN' : ''),
            'Account ' . $this->setting('accountNumber', 'MOCK-001'),
            '',
            'TO: ' . ($to?->contactName() ?? ''),
            $to?->streetLine() ?? '',
            trim(($to?->postcode ?? '') . ' ' . ($to?->city ?? '')) . ' ' . ($to?->countryCode ?? ''),
            '',
            'SERVICE ' . strtoupper($request->serviceCode),
            sprintf('PARCEL %d/%d  %.2f kg', $index + 1, count($request->parcels), ($request->parcels[$index]->weightG ?? 0) / 1000),
            $request->pointId !== '' ? 'POINT ' . $request->pointId : '',
            $request->hasCod() ? sprintf('COD %.2f %s', $request->codAmount, $request->codCurrency) : '',
            'REF ' . $request->reference,
            '',
            $number,
        ];

        return match ($format) {
            LabelFormat::ZPL => "^XA\n" . implode("\n", array_map(
                static fn($line, $i) => sprintf('^FO40,%d^A0N,30,30^FD%s^FS', 40 + $i * 40, str_replace(['^', '~'], '', $line)),
                $lines,
                array_keys($lines),
            )) . "\n^BY3^FO40,640^BCN,120,Y,N,N^FD$number^FS\n^XZ",
            LabelFormat::PNG => self::png(),
            LabelFormat::PDF_A4 => Pdf::text($lines, 595.3, 841.9),
            LabelFormat::PDF_A6 => Pdf::text($lines, 297.6, 419.5),
            default => Pdf::text($lines),
        };
    }

    /** A valid 4×6-proportioned blank PNG, built without GD. */
    private static function png(): string
    {
        $width = 4;
        $height = 6;
        $raw = str_repeat("\x00" . str_repeat("\xff\xff\xff", $width), $height);
        $chunk = static fn(string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            . $chunk('IDAT', (string)gzcompress($raw))
            . $chunk('IEND', '');
    }
}
