<?php

namespace justinholtweb\carrier\carriers;

use Craft;
use craft\commerce\Plugin as Commerce;
use justinholtweb\carrier\base\Capabilities;
use justinholtweb\carrier\base\Carrier;
use justinholtweb\carrier\base\Feature;
use justinholtweb\carrier\base\Field;
use justinholtweb\carrier\base\HealthResult;
use justinholtweb\carrier\base\PointPage;
use justinholtweb\carrier\base\ShipmentResult;
use justinholtweb\carrier\helpers\LabelFormat;
use justinholtweb\carrier\helpers\Pdf;
use justinholtweb\carrier\models\canonical\Label;
use justinholtweb\carrier\models\canonical\PickupPoint;
use justinholtweb\carrier\models\canonical\PointQuery;
use justinholtweb\carrier\models\canonical\ShipmentRequest;
use Throwable;

/**
 * Pick up in store.
 *
 * The points are Commerce's own inventory locations, so a merchant who already keeps stock per
 * shop has nothing new to set up: each location with an address becomes a point the customer can
 * choose at checkout, the same picker as a GLS locker. The "label" is a collection slip to stick
 * on the bag on the shelf.
 */
class StorePickupCarrier extends Carrier
{
    public static function handle(): string
    {
        return 'store-pickup';
    }

    public static function displayName(): string
    {
        return Craft::t('carrier', 'Pick up in store');
    }

    public static function region(): string
    {
        return Craft::t('carrier', 'Built in');
    }

    public static function description(): string
    {
        return Craft::t('carrier', 'Customers collect from one of your Commerce inventory locations. Prints a collection slip instead of a label.');
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            ->supports(Feature::LABELS, Feature::COD)
            ->points(Capabilities::POINTS_LIST, PickupPoint::TYPE_STORE)
            ->labelFormats(LabelFormat::PDF_A6, LabelFormat::PDF_A4);
    }

    public static function settingsFields(): array
    {
        return [
            Field::text('locationHandles', 'Locations', [
                'instructions' => 'Comma-separated inventory location handles customers may collect from. Leave blank for every location with an address.',
                'suggestEnvVars' => false,
            ]),
            Field::text('hours', 'Opening hours', [
                'instructions' => 'Shown in the picker, e.g. “Mon–Sat 9:00–18:00”.',
                'suggestEnvVars' => false,
            ]),
        ];
    }

    public static function services(): array
    {
        return [
            'collect' => ['name' => Craft::t('carrier', 'Collect in store'), 'international' => false, 'points' => true],
        ];
    }

    protected function probe(): HealthResult
    {
        $count = count($this->locations());

        return $count > 0
            ? HealthResult::pass(Craft::t('carrier', '{n, plural, =1{One location} other{# locations}} available for collection.', ['n' => $count]))
            : HealthResult::fail(Craft::t('carrier', 'No inventory location has an address.'), [Craft::t('carrier', 'Give a location an address in Commerce → Inventory → Locations.')]);
    }

    public function pickupPoints(PointQuery $query): PointPage
    {
        $points = array_values(array_filter(
            $this->locations(),
            static fn(PickupPoint $p) => $query->countryCode === '' || strcasecmp($p->countryCode, $query->countryCode) === 0,
        ));

        return PointPage::of($points);
    }

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        if ($request->pointId === '') {
            return ShipmentResult::rejected(Craft::t('carrier', 'Choose the store the customer collects from.'));
        }

        $point = null;

        foreach ($this->locations() as $location) {
            if ($location->id === $request->pointId) {
                $point = $location;
            }
        }

        $number = 'PICKUP-' . strtoupper($request->reference ?: (string)$request->orderId);
        $format = $this->labelFormatFor($request);
        $lines = [
            'COLLECTION SLIP',
            '',
            $request->shipTo?->contactName() ?? '',
            $request->shipTo?->phone ?? '',
            '',
            'Order ' . $request->reference,
            sprintf('%d parcel(s), %.2f kg', count($request->parcels), $request->totalWeightG() / 1000),
            $request->hasCod() ? sprintf('TO PAY AT COUNTER: %.2f %s', $request->codAmount, $request->codCurrency) : 'Paid',
            '',
            'Collect at: ' . ($point?->name ?? $request->pointId),
            $point?->street ?? '',
        ];

        return ShipmentResult::ok($number, [$number], [new Label([
            'trackingNumber' => $number,
            'format' => $format,
            'data' => $format === LabelFormat::PDF_A4 ? Pdf::text($lines, 595.3, 841.9) : Pdf::text($lines, 297.6, 419.5),
        ])]);
    }

    /**
     * @return PickupPoint[]
     */
    private function locations(): array
    {
        if (!class_exists(Commerce::class) || !Commerce::getInstance()) {
            return [];
        }

        $only = array_filter(array_map('trim', explode(',', (string)$this->setting('locationHandles', ''))));
        $points = [];

        try {
            $locations = Commerce::getInstance()->getInventoryLocations()->getAllInventoryLocations();
        } catch (Throwable) {
            return [];
        }

        foreach ($locations as $location) {
            if ($only !== [] && !in_array($location->handle, $only, true)) {
                continue;
            }

            $address = $location->getAddress();

            if (!$address || !$address->countryCode) {
                continue;
            }

            $points[] = new PickupPoint([
                'id' => (string)$location->handle,
                'type' => PickupPoint::TYPE_STORE,
                'name' => (string)$location->name,
                'street' => trim($address->addressLine1 . ' ' . $address->addressLine2),
                'city' => (string)$address->locality,
                'postcode' => (string)$address->postalCode,
                'countryCode' => strtoupper((string)$address->countryCode),
                'latitude' => is_numeric($address->latitude) ? (float)$address->latitude : null,
                'longitude' => is_numeric($address->longitude) ? (float)$address->longitude : null,
                'hours' => (string)$this->setting('hours', ''),
            ]);
        }

        return $points;
    }
}
