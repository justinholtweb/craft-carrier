<?php

namespace justinholtweb\carrier\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use justinholtweb\carrier\base\Capabilities;
use justinholtweb\carrier\base\Feature;
use justinholtweb\carrier\db\Table;
use justinholtweb\carrier\models\canonical\PickupPoint;
use justinholtweb\carrier\models\canonical\PointQuery;
use justinholtweb\carrier\models\Connection;
use justinholtweb\carrier\Plugin;
use Throwable;

/**
 * Parcel shops, lockers and post offices.
 *
 * Two kinds of carrier, two strategies. A **list** carrier (GLS, BOX NOW, the posts) publishes
 * every point in a country; Carrier syncs that daily into `carrier_points` and searches it locally
 * with a bounding box and a haversine sort, so checkout never waits on a carrier and still works
 * when one is down. A **search** carrier (UPS, FedEx, USPS) only answers "what is near here",
 * live; those answers are cached briefly.
 *
 * A third source covers carriers with no point API at all — DPD EasyShip tells merchants to ask
 * DPD for the ids — which is a merchant-imported CSV, stored as `manual` rows a sync never touches.
 */
class Points extends Component
{
    /** Never ask a list carrier for more pages than this in one sync — a guard against a cursor that loops. */
    private const MAX_PAGES = 500;

    /**
     * Pull every point for a list-mode connection, replacing the previous sync's rows.
     *
     * @return array{count:int,error:?string}
     */
    public function sync(Connection $connection, array $countries = []): array
    {
        $carrier = $connection->getCarrier();

        if (!$carrier || !$carrier::capabilities()->has(Feature::POINTS) || $carrier::capabilities()->getPointsMode() !== Capabilities::POINTS_LIST) {
            return ['count' => 0, 'error' => Craft::t('carrier', 'This carrier has no point list to sync.')];
        }

        $countries = $countries ?: $this->countriesFor($connection);
        $startedAt = new DateTime();
        $count = 0;

        foreach ($countries as $country) {
            $cursor = null;
            $pages = 0;

            do {
                $page = $carrier->pickupPoints(new PointQuery(['countryCode' => $country, 'cursor' => $cursor, 'limit' => 1000]));

                if ($page->error !== null) {
                    Plugin::getInstance()->getLog()->error($connection, "Point sync ($country): {$page->error}");

                    // Keep yesterday's points rather than wiping a country because today's sync
                    // failed halfway — a stale locker list beats an empty picker.
                    return ['count' => $count, 'error' => $page->error];
                }

                foreach ($page->points as $point) {
                    $this->store($connection, $point, 'sync', $startedAt);
                    $count++;
                }

                $cursor = $page->nextCursor;
            } while ($cursor !== null && ++$pages < self::MAX_PAGES);
        }

        // Points the carrier no longer lists are gone; manual rows are the merchant's.
        Db::delete(Table::POINTS, [
            'and',
            ['connectionId' => $connection->id, 'source' => 'sync'],
            ['<', 'syncedAt', Db::prepareDateForDb($startedAt)],
        ]);

        Plugin::getInstance()->getConnections()->markPointsSynced($connection, $startedAt);

        return ['count' => $count, 'error' => null];
    }

    /**
     * The countries a list sync covers: the connection's `pointCountries` setting, else the
     * carrier's origin countries, else the ship-from country.
     */
    public function countriesFor(Connection $connection): array
    {
        $configured = array_filter(array_map('trim', explode(',', strtoupper((string)$connection->getSetting('pointCountries', '')))));

        if ($configured !== []) {
            return array_values($configured);
        }

        $class = $connection->getCarrierClass();
        $origins = $class ? $class::capabilities()->getOriginCountries() : [];

        if ($origins !== []) {
            return $origins;
        }

        $from = $connection->getShipFromAddress();

        return $from ? [$from->countryCode] : [];
    }

    /**
     * Find points near a place for a connection — local table for list carriers and manual
     * imports, live (cached) for search carriers.
     *
     * @return PickupPoint[]
     */
    public function search(Connection $connection, PointQuery $query): array
    {
        $class = $connection->getCarrierClass();

        if (!$class || !$class::capabilities()->has(Feature::POINTS)) {
            return [];
        }

        $query->countryCode = strtoupper($query->countryCode);
        $local = $this->hasLocal($connection);

        if ($class::capabilities()->getPointsMode() !== Capabilities::POINTS_SEARCH || $local) {
            return $this->searchLocal($connection, $query);
        }

        $key = 'carrier:points:' . $connection->id . ':' . md5(json_encode($query->toArray()));
        $cached = Craft::$app->getCache()->get($key);

        if (is_array($cached)) {
            return array_map(static fn(array $p) => new PickupPoint($p), $cached);
        }

        try {
            $page = $connection->getCarrier()->pickupPoints($query);
        } catch (Throwable $e) {
            Craft::warning('Carrier point search failed: ' . $e->getMessage(), 'carrier');

            return [];
        }

        if ($page->error !== null) {
            return [];
        }

        $points = $this->withDistance($page->points, $query);
        Craft::$app->getCache()->set($key, array_map(static fn(PickupPoint $p) => $p->toArray(), $points), 3600);

        return $points;
    }

    /**
     * One point, by its carrier id. Local first; a search-mode carrier's point is only known if
     * it was snapshotted on a selection.
     */
    public function find(Connection $connection, string $pointId): ?PickupPoint
    {
        $row = (new Query())->from(Table::POINTS)->where(['connectionId' => $connection->id, 'pointId' => $pointId])->one();

        return $row ? $this->fromRow($row) : null;
    }

    private function hasLocal(Connection $connection): bool
    {
        return (new Query())->from(Table::POINTS)->where(['connectionId' => $connection->id, 'enabled' => true])->exists();
    }

    /**
     * @return PickupPoint[]
     */
    private function searchLocal(Connection $connection, PointQuery $query): array
    {
        $base = (new Query())->from(Table::POINTS)->where(['connectionId' => $connection->id, 'enabled' => true]);

        if ($query->countryCode !== '') {
            $base->andWhere(['countryCode' => $query->countryCode]);
        }

        if ($query->types !== []) {
            $base->andWhere(['type' => $query->types]);
        }

        if ($query->latitude !== null && $query->longitude !== null) {
            // A bounding box the index can use, then an exact distance sort in PHP. One degree of
            // latitude is ~111 km everywhere; a degree of longitude shrinks with the cosine.
            $lat = $query->latitude;
            $lng = $query->longitude;
            $dLat = $query->radiusKm / 111.0;
            $dLng = $query->radiusKm / max(1.0, 111.0 * cos(deg2rad($lat)));

            $rows = (clone $base)
                ->andWhere(['between', 'latitude', $lat - $dLat, $lat + $dLat])
                ->andWhere(['between', 'longitude', $lng - $dLng, $lng + $dLng])
                ->limit(2000)
                ->all();

            $points = array_filter(
                $this->withDistance(array_map($this->fromRow(...), $rows), $query),
                static fn(PickupPoint $p) => $p->distanceKm === null || $p->distanceKm <= $query->radiusKm,
            );

            return array_slice(array_values($points), 0, $query->limit);
        }

        $term = trim($query->postcode !== '' ? $query->postcode : $query->city);

        if ($term !== '') {
            $like = str_replace(['%', '_'], ['\%', '\_'], $term);
            $base->andWhere(['or',
                ['like', 'postcode', $like . '%', false],
                ['like', 'city', '%' . $like . '%', false],
                ['like', 'name', '%' . $like . '%', false],
                ['like', 'street', '%' . $like . '%', false],
            ]);
        }

        // A postcode search sorts by how close the postcode is numerically, which is crude but
        // right far more often than alphabetical order is.
        $rows = $base->limit(500)->all();
        $points = array_map($this->fromRow(...), $rows);

        if (ctype_digit($query->postcode)) {
            $target = (int)$query->postcode;
            usort($points, static fn($a, $b) => abs((int)preg_replace('/\D/', '', $a->postcode) - $target) <=> abs((int)preg_replace('/\D/', '', $b->postcode) - $target));
        }

        return array_slice($points, 0, $query->limit);
    }

    /**
     * @param PickupPoint[] $points
     * @return PickupPoint[]
     */
    private function withDistance(array $points, PointQuery $query): array
    {
        if ($query->latitude === null || $query->longitude === null) {
            return array_values($points);
        }

        foreach ($points as $point) {
            if ($point->latitude !== null && $point->longitude !== null) {
                $point->distanceKm = round(self::haversine($query->latitude, $query->longitude, $point->latitude, $point->longitude), 2);
            }
        }

        usort($points, static fn($a, $b) => ($a->distanceKm ?? PHP_FLOAT_MAX) <=> ($b->distanceKm ?? PHP_FLOAT_MAX));

        return array_values($points);
    }

    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function store(Connection $connection, PickupPoint $point, string $source, ?DateTime $syncedAt = null): void
    {
        if ($point->id === '' || $point->countryCode === '') {
            return;
        }

        $values = [
            'type' => $point->type ?: PickupPoint::TYPE_SHOP,
            'name' => mb_substr($point->name ?: $point->id, 0, 255),
            'street' => mb_substr($point->street, 0, 255),
            'city' => mb_substr($point->city, 0, 255),
            'postcode' => mb_substr($point->postcode, 0, 32),
            'countryCode' => strtoupper($point->countryCode),
            'latitude' => $point->latitude,
            'longitude' => $point->longitude,
            'hours' => $point->hours,
            'codAllowed' => $point->codAllowed,
            'maxWeightG' => max(0, $point->maxWeightG),
            'extra' => $point->extra ? json_encode($point->extra) : null,
            'source' => $source,
            'syncedAt' => Db::prepareDateForDb($syncedAt ?? new DateTime()),
        ];

        $existing = (new Query())->select(['id', 'source'])->from(Table::POINTS)
            ->where(['connectionId' => $connection->id, 'pointId' => $point->id])->one();

        if ($existing) {
            // A sync never overwrites a point the merchant imported or edited.
            if ($existing['source'] === 'manual' && $source === 'sync') {
                return;
            }

            Db::update(Table::POINTS, $values, ['id' => $existing['id']]);

            return;
        }

        Db::insert(Table::POINTS, array_merge($values, [
            'connectionId' => $connection->id,
            'pointId' => mb_substr($point->id, 0, 64),
        ]));
    }

    /**
     * Import points from CSV for a carrier without a point API. Columns, by header name:
     * `id, name, type, street, city, postcode, country, latitude, longitude, hours, cod`.
     *
     * @return array{imported:int,skipped:int,errors:string[]}
     */
    public function importCsv(Connection $connection, string $csv, bool $replace = false): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        $delimiter = str_contains($lines[0] ?? '', ';') && !str_contains($lines[0] ?? '', ',') ? ';' : ',';
        $header = array_map(static fn($h) => strtolower(trim((string)$h, " \t\"\xEF\xBB\xBF")), str_getcsv((string)array_shift($lines), $delimiter));
        $result = ['imported' => 0, 'skipped' => 0, 'errors' => []];

        foreach (['id', 'name', 'country'] as $required) {
            if (!in_array($required, $header, true)) {
                $result['errors'][] = Craft::t('carrier', 'The CSV needs a “{column}” column.', ['column' => $required]);
            }
        }

        if ($result['errors'] !== []) {
            return $result;
        }

        if ($replace) {
            Db::delete(Table::POINTS, ['connectionId' => $connection->id, 'source' => 'manual']);
        }

        foreach ($lines as $n => $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line, $delimiter);
            $row = array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), ''));
            $type = strtolower(trim((string)($row['type'] ?? '')));

            $point = new PickupPoint([
                'id' => trim((string)$row['id']),
                'name' => trim((string)$row['name']),
                'type' => in_array($type, [PickupPoint::TYPE_LOCKER, PickupPoint::TYPE_SHOP, PickupPoint::TYPE_POST_OFFICE, PickupPoint::TYPE_STORE], true) ? $type : PickupPoint::TYPE_SHOP,
                'street' => trim((string)($row['street'] ?? '')),
                'city' => trim((string)($row['city'] ?? '')),
                'postcode' => trim((string)($row['postcode'] ?? '')),
                'countryCode' => strtoupper(trim((string)$row['country'])),
                'latitude' => is_numeric($row['latitude'] ?? null) ? (float)$row['latitude'] : null,
                'longitude' => is_numeric($row['longitude'] ?? null) ? (float)$row['longitude'] : null,
                'hours' => trim((string)($row['hours'] ?? '')),
                'codAllowed' => !in_array(strtolower(trim((string)($row['cod'] ?? '1'))), ['0', 'no', 'false', 'n'], true),
            ]);

            if ($point->id === '' || strlen($point->countryCode) !== 2) {
                $result['skipped']++;
                $result['errors'][] = Craft::t('carrier', 'Line {n}: an id and a two-letter country are required.', ['n' => $n + 2]);
                continue;
            }

            $this->store($connection, $point, 'manual');
            $result['imported']++;
        }

        return $result;
    }

    public function count(Connection $connection): int
    {
        return (int)(new Query())->from(Table::POINTS)->where(['connectionId' => $connection->id])->count();
    }

    public function query(): Query
    {
        return (new Query())->from(Table::POINTS)->orderBy(['countryCode' => SORT_ASC, 'city' => SORT_ASC, 'name' => SORT_ASC]);
    }

    public function setEnabled(int $id, bool $enabled): void
    {
        Db::update(Table::POINTS, ['enabled' => $enabled], ['id' => $id]);
    }

    /**
     * Connections whose list is older than the sync interval.
     *
     * @return Connection[]
     */
    public function due(): array
    {
        $hours = Plugin::getInstance()->getSettings()->pointsSyncHours;

        if ($hours <= 0) {
            return [];
        }

        $cutoff = time() - $hours * 3600;

        return array_values(array_filter(
            Plugin::getInstance()->getConnections()->withFeature(Feature::POINTS),
            static fn(Connection $c) => $c->getCarrierClass()::capabilities()->getPointsMode() === Capabilities::POINTS_LIST
                && ($c->pointsSyncedAt === null || $c->pointsSyncedAt->getTimestamp() < $cutoff),
        ));
    }

    public function fromRow(array $row): PickupPoint
    {
        return new PickupPoint([
            'id' => (string)$row['pointId'],
            'type' => (string)$row['type'],
            'name' => (string)$row['name'],
            'street' => (string)$row['street'],
            'city' => (string)$row['city'],
            'postcode' => (string)$row['postcode'],
            'countryCode' => (string)$row['countryCode'],
            'latitude' => $row['latitude'] !== null ? (float)$row['latitude'] : null,
            'longitude' => $row['longitude'] !== null ? (float)$row['longitude'] : null,
            'hours' => (string)$row['hours'],
            'codAllowed' => (bool)$row['codAllowed'],
            'maxWeightG' => (int)$row['maxWeightG'],
            'extra' => json_decode((string)$row['extra'], true) ?: [],
        ]);
    }
}
