<?php

namespace justinholtweb\carrier\services;

use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use justinholtweb\carrier\helpers\Units;
use justinholtweb\carrier\models\canonical\Parcel;
use justinholtweb\carrier\models\Method;
use justinholtweb\carrier\Plugin;

/**
 * Turns an order's line items into parcels.
 *
 * Three strategies, and the honest limits of each:
 *
 * - **single** — everything in one parcel; the smallest configured box that holds the lot by
 *   volume gives the dimensions. Split by weight if the carrier has a limit.
 * - **perItem** — every unit ships alone in its own dimensions.
 * - **boxes** — first-fit decreasing: the biggest units first, each into the first open box it
 *   fits (by sorted edges, remaining volume and weight), else into the smallest empty box that
 *   takes it. Volume is a proxy for geometry, not a 3D solver: it will occasionally claim two
 *   long items fit side by side when only one does. A unit no box can take ships on its own.
 *
 * Non-shippable lines and lines with free shipping are left out, as Commerce's own shipping
 * adjuster leaves them out — charging for weight that is not being shipped is the bug otherwise.
 */
class Packer extends Component
{
    /** More units than this are packed by weight alone; nobody is boxing 5,000 stickers by volume. */
    private const MAX_UNITS = 500;

    /**
     * @return Parcel[]
     */
    public function pack(Order $order, ?Method $method = null, int $maxParcelWeightG = 0): array
    {
        $strategy = $method?->packingStrategy() ?? Plugin::getInstance()->getSettings()->packingStrategy;

        return $this->packUnits($this->units($order), $strategy, $maxParcelWeightG);
    }

    /**
     * The packing itself, on plain units, so the test suite and the simulator can exercise it
     * without an order.
     *
     * @param array<int,array{lineItemId:?int,weightG:int,dims:int[],description:string,value:float}> $units
     * @return Parcel[]
     */
    public function packUnits(array $units, string $strategy, int $maxParcelWeightG = 0): array
    {
        if ($units === []) {
            return [];
        }

        $boxes = $this->boxes();

        if (count($units) > self::MAX_UNITS && $strategy === 'boxes') {
            $strategy = 'single';
        }

        $parcels = match ($strategy) {
            'perItem' => $this->perItem($units),
            'boxes' => $boxes !== [] ? $this->intoBoxes($units, $boxes, $maxParcelWeightG) : $this->single($units, [], $maxParcelWeightG),
            default => $this->single($units, $boxes, $maxParcelWeightG),
        };

        foreach ($parcels as $i => $parcel) {
            $parcel->reference = (string)($i + 1);
        }

        return $parcels;
    }

    /**
     * Every shippable unit in an order, in grams and millimetres.
     *
     * @return array<int,array{lineItemId:?int,weightG:int,dims:int[],description:string,value:float}>
     */
    public function units(Order $order): array
    {
        $commerce = \craft\commerce\Plugin::getInstance()->getSettings();
        $weightUnit = $commerce->weightUnits;
        $dimensionUnit = $commerce->dimensionUnits;
        $defaultWeight = Plugin::getInstance()->getSettings()->defaultItemWeight;
        $units = [];

        foreach ($order->getLineItems() as $lineItem) {
            if (!$this->ships($lineItem)) {
                continue;
            }

            $weight = (float)$lineItem->weight > 0 ? (float)$lineItem->weight : $defaultWeight;
            $unit = [
                'lineItemId' => $lineItem->id,
                'weightG' => Units::toGrams($weight, $weightUnit),
                'dims' => [
                    Units::toMm((float)$lineItem->length, $dimensionUnit),
                    Units::toMm((float)$lineItem->width, $dimensionUnit),
                    Units::toMm((float)$lineItem->height, $dimensionUnit),
                ],
                'description' => (string)$lineItem->getDescription(),
                'value' => (float)$lineItem->salePrice,
            ];

            for ($i = 0; $i < (int)$lineItem->qty; $i++) {
                $units[] = $unit;
            }
        }

        return $units;
    }

    private function ships(LineItem $lineItem): bool
    {
        if (method_exists($lineItem, 'getIsShippable') && !$lineItem->getIsShippable()) {
            return false;
        }

        return !(method_exists($lineItem, 'getHasFreeShipping') && $lineItem->getHasFreeShipping());
    }

    private function boxes(): array
    {
        $commerce = \craft\commerce\Plugin::getInstance()?->getSettings();

        return Plugin::getInstance()->getSettings()->boxesInternal(
            $commerce->weightUnits ?? 'kg',
            $commerce->dimensionUnits ?? 'cm',
        );
    }

    private function perItem(array $units): array
    {
        return array_map(function(array $unit) {
            $dims = $this->sortedDims($unit['dims']);

            return $this->parcel([$unit], $unit['weightG'], $dims[0], $dims[1], $dims[2]);
        }, $units);
    }

    private function single(array $units, array $boxes, int $maxWeightG): array
    {
        $groups = $this->splitByWeight($units, $maxWeightG);
        $parcels = [];

        foreach ($groups as $group) {
            $box = $this->smallestBoxFor($group, $boxes);
            $weight = array_sum(array_column($group, 'weightG')) + ($box['tareG'] ?? 0);

            if ($box) {
                $parcels[] = $this->parcel($group, $weight, $box['lengthMm'], $box['widthMm'], $box['heightMm'], $box['name']);
                continue;
            }

            // No box fits, or none configured: report the largest item's edges rather than
            // invent a carton. Carriers that need dimensions will say so; most bill by weight.
            $dims = [0, 0, 0];

            foreach ($group as $unit) {
                $sorted = $this->sortedDims($unit['dims']);
                $dims = [max($dims[0], $sorted[0]), max($dims[1], $sorted[1]), max($dims[2], $sorted[2])];
            }

            $parcels[] = $this->parcel($group, $weight, ...$dims);
        }

        return $parcels;
    }

    private function intoBoxes(array $units, array $boxes, int $maxWeightG): array
    {
        usort($units, fn($a, $b) => array_product($b['dims']) <=> array_product($a['dims']) ?: $b['weightG'] <=> $a['weightG']);

        /** @var array<int,array{box:array,units:array,volume:int,weight:int}> $open */
        $open = [];
        $loose = [];

        foreach ($units as $unit) {
            $placed = false;

            foreach ($open as &$candidate) {
                if ($this->fits($unit, $candidate['box'], $candidate['volume'], $candidate['weight'], $maxWeightG)) {
                    $candidate['units'][] = $unit;
                    $candidate['volume'] += array_product($unit['dims']);
                    $candidate['weight'] += $unit['weightG'];
                    $placed = true;
                    break;
                }
            }
            unset($candidate);

            if ($placed) {
                continue;
            }

            foreach ($boxes as $box) {
                if ($this->fits($unit, $box, 0, $box['tareG'], $maxWeightG)) {
                    $open[] = [
                        'box' => $box,
                        'units' => [$unit],
                        'volume' => array_product($unit['dims']),
                        'weight' => $box['tareG'] + $unit['weightG'],
                    ];
                    $placed = true;
                    break;
                }
            }

            if (!$placed) {
                $loose[] = $unit;
            }
        }

        $parcels = [];

        foreach ($open as $candidate) {
            $box = $candidate['box'];
            $parcels[] = $this->parcel($candidate['units'], $candidate['weight'], $box['lengthMm'], $box['widthMm'], $box['heightMm'], $box['name']);
        }

        foreach ($loose as $unit) {
            $dims = $this->sortedDims($unit['dims']);
            $parcels[] = $this->parcel([$unit], $unit['weightG'], $dims[0], $dims[1], $dims[2]);
        }

        return $parcels;
    }

    private function fits(array $unit, array $box, int $usedVolume, int $currentWeight, int $maxWeightG): bool
    {
        $limit = $box['maxWeightG'] > 0 ? $box['maxWeightG'] : PHP_INT_MAX;

        if ($maxWeightG > 0) {
            $limit = min($limit, $maxWeightG);
        }

        if ($currentWeight + $unit['weightG'] > $limit) {
            return false;
        }

        // An item with no dimensions recorded fits anywhere its weight allows.
        if (array_product($unit['dims']) === 0) {
            return true;
        }

        $item = $this->sortedDims($unit['dims']);
        $edges = $this->sortedDims([$box['lengthMm'], $box['widthMm'], $box['heightMm']]);

        if ($item[0] > $edges[0] || $item[1] > $edges[1] || $item[2] > $edges[2]) {
            return false;
        }

        return $usedVolume + array_product($unit['dims']) <= array_product($edges);
    }

    private function smallestBoxFor(array $units, array $boxes): ?array
    {
        $volume = array_sum(array_map(static fn($u) => array_product($u['dims']), $units));
        $weight = array_sum(array_column($units, 'weightG'));

        foreach ($boxes as $box) {
            $edges = $this->sortedDims([$box['lengthMm'], $box['widthMm'], $box['heightMm']]);

            if ($volume > array_product($edges) || ($box['maxWeightG'] > 0 && $weight + $box['tareG'] > $box['maxWeightG'])) {
                continue;
            }

            foreach ($units as $unit) {
                $item = $this->sortedDims($unit['dims']);

                if ($item[0] > $edges[0] || $item[1] > $edges[1] || $item[2] > $edges[2]) {
                    continue 2;
                }
            }

            return $box;
        }

        return null;
    }

    /**
     * Greedy split into groups no heavier than the limit. A single unit over the limit is its own
     * group — the carrier refuses it with its own message, which beats Carrier refusing it silently.
     */
    private function splitByWeight(array $units, int $maxWeightG): array
    {
        if ($maxWeightG <= 0 || array_sum(array_column($units, 'weightG')) <= $maxWeightG) {
            return [$units];
        }

        usort($units, static fn($a, $b) => $b['weightG'] <=> $a['weightG']);
        $groups = [];

        foreach ($units as $unit) {
            foreach ($groups as &$group) {
                if ($group['weight'] + $unit['weightG'] <= $maxWeightG) {
                    $group['units'][] = $unit;
                    $group['weight'] += $unit['weightG'];
                    continue 2;
                }
            }
            unset($group);

            $groups[] = ['units' => [$unit], 'weight' => $unit['weightG']];
        }

        return array_column($groups, 'units');
    }

    private function sortedDims(array $dims): array
    {
        $dims = array_map('intval', array_pad($dims, 3, 0));
        rsort($dims);

        return $dims;
    }

    private function parcel(array $units, int $weightG, int $l, int $w, int $h, string $box = ''): Parcel
    {
        $items = [];
        $value = 0.0;

        foreach ($units as $unit) {
            $key = (string)($unit['lineItemId'] ?? 'x');
            $items[$key] ??= ['lineItemId' => $unit['lineItemId'], 'qty' => 0];
            $items[$key]['qty']++;
            $value += $unit['value'];
        }

        return new Parcel([
            'weightG' => max(1, $weightG),
            'lengthMm' => $l,
            'widthMm' => $w,
            'heightMm' => $h,
            'box' => $box,
            'value' => round($value, 2),
            'items' => array_values($items),
        ]);
    }
}
