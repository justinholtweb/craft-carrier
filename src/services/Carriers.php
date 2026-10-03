<?php

namespace justinholtweb\carrier\services;

use Craft;
use craft\base\Component;
use craft\events\RegisterComponentTypesEvent;
use justinholtweb\carrier\base\CarrierInterface;
use justinholtweb\carrier\base\Feature;
use justinholtweb\carrier\carriers\MockCarrier;
use justinholtweb\carrier\carriers\StorePickupCarrier;
use justinholtweb\carrier\models\Connection;
use Throwable;

/**
 * The registry every add-on plugs into.
 *
 * This is the whole extension model, and it is deliberately the same shape as every other
 * "register your types" event in Craft, so an add-on author already knows how to write it:
 *
 *     Event::on(
 *         Carriers::class,
 *         Carriers::EVENT_REGISTER_CARRIERS,
 *         static function(RegisterComponentTypesEvent $event) {
 *             $event->types[] = GlsCarrier::class;
 *         }
 *     );
 *
 * One add-on may register several carriers — the freight add-on registers six and the posts add-on
 * four — so a merchant installs one thing per family rather than guessing which package they need.
 */
class Carriers extends Component
{
    /**
     * @event RegisterComponentTypesEvent fired when carrier classes are being collected
     */
    public const EVENT_REGISTER_CARRIERS = 'registerCarriers';

    /** @var array<string,class-string<CarrierInterface>>|null */
    private ?array $carriers = null;

    /**
     * @return array<string,class-string<CarrierInterface>> handle => class
     */
    public function all(): array
    {
        if ($this->carriers !== null) {
            return $this->carriers;
        }

        $event = new RegisterComponentTypesEvent([
            'types' => [
                MockCarrier::class,
                StorePickupCarrier::class,
            ],
        ]);

        $this->trigger(self::EVENT_REGISTER_CARRIERS, $event);

        $carriers = [];

        foreach ($event->types as $class) {
            if (!is_string($class) || !class_exists($class) || !is_subclass_of($class, CarrierInterface::class)) {
                Craft::warning("Carrier ignored a registered carrier that is not a " . CarrierInterface::class . ': ' . (is_string($class) ? $class : gettype($class)), 'carrier');
                continue;
            }

            $handle = $class::handle();

            // Two add-ons claiming one handle would silently swap which carrier a live
            // connection buys labels from, so the first registration wins and the second is reported loudly.
            if (isset($carriers[$handle])) {
                Craft::warning("Carrier has two carriers claiming the handle “{$handle}”: {$carriers[$handle]} and $class. The second was ignored.", 'carrier');
                continue;
            }

            $carriers[$handle] = $class;
        }

        return $this->carriers = $carriers;
    }

    public function has(string $handle): bool
    {
        return isset($this->all()[$handle]);
    }

    /**
     * @return class-string<CarrierInterface>|null
     */
    public function classFor(string $handle): ?string
    {
        return $this->all()[$handle] ?? null;
    }

    /**
     * A carrier bound to a connection, or null if its add-on is not installed.
     *
     * Returning null rather than throwing is deliberate: a merchant who uninstalls an add-on
     * should find a connection that says "install the GLS add-on to use this", not a control
     * panel that 500s.
     */
    public function create(Connection $connection): ?CarrierInterface
    {
        $class = $this->classFor($connection->carrier);

        if ($class === null) {
            return null;
        }

        try {
            /** @var CarrierInterface $carrier */
            $carrier = new $class();
            $carrier->setConnection($connection);

            return $carrier;
        } catch (Throwable $e) {
            Craft::error("Carrier could not instantiate $class: " . $e->getMessage(), 'carrier');

            return null;
        }
    }

    /**
     * Carriers grouped by region and sorted for the picker.
     *
     * @return array<string,array<string,class-string<CarrierInterface>>>
     */
    public function byRegion(): array
    {
        $grouped = [];

        foreach ($this->all() as $handle => $class) {
            $grouped[$class::region() ?: Craft::t('carrier', 'Other')][$handle] = $class;
        }

        uksort($grouped, static fn(string $a, string $b) => strcasecmp($a, $b));

        foreach ($grouped as &$carriers) {
            uasort($carriers, static fn(string $a, string $b) => strcasecmp($a::displayName(), $b::displayName()));
        }

        return $grouped;
    }

    /**
     * Everything the control panel needs to describe a carrier, already resolved.
     *
     * Twig cannot call a static method on a class name — it sees a string and refuses — so
     * templates are handed plain arrays rather than class strings.
     */
    public function describeClass(string $class): array
    {
        $capabilities = $class::capabilities();

        return [
            'handle' => $class::handle(),
            'name' => $class::displayName(),
            'region' => $class::region(),
            'description' => $class::description(),
            'setupUrl' => $class::setupUrl(),
            'features' => $capabilities->features(),
            'featureLabels' => array_map(Feature::label(...), $capabilities->features()),
            'capabilities' => $capabilities->toArray(),
            'services' => $class::services(),
            'settingsFields' => $class::settingsFields(),
        ];
    }

    /**
     * @return array<string,array<int,array<string,mixed>>> region => descriptors
     */
    public function describe(): array
    {
        $grouped = [];

        foreach ($this->byRegion() as $region => $carriers) {
            foreach ($carriers as $class) {
                $grouped[$region][] = $this->describeClass($class);
            }
        }

        return $grouped;
    }

    public function describeOne(string $handle): ?array
    {
        $class = $this->classFor($handle);

        return $class ? $this->describeClass($class) : null;
    }

    /**
     * Forget what is registered. Only the test suite needs this — installing an add-on restarts
     * PHP anyway.
     */
    public function reset(): void
    {
        $this->carriers = null;
    }
}
