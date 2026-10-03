<?php

namespace justinholtweb\carrier;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use justinholtweb\carrier\base\PluginTrait;
use justinholtweb\carrier\models\Settings;
use justinholtweb\carrier\services\ServicesTrait;

/**
 * Carrier — the shipping carrier gateway for Craft Commerce.
 *
 * The gateway owns everything that has to be true of every carrier: parcels on the order,
 * packing, live and table rates at checkout, claim-first label purchase, printing, the pickup
 * point picker, tracking written back to the order, the log and the control panel. Each carrier
 * is a free add-on that translates one API — the same arrangement Erpy uses for ERPs, for the
 * same reason: the hard part is not any one integration but everything that has to hold for all
 * of them.
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    use ServicesTrait;
    use PluginTrait;

    public const HANDLE = 'carrier';

    public string $schemaVersion = '5.0.0';

    public bool $hasCpSettings = true;

    public bool $hasCpSection = true;

    public function init(): void
    {
        parent::init();

        $this->registerTwigVariable();
        $this->registerPermissions();
        $this->registerRoutes();
        $this->registerGarbageCollection();
        $this->registerSweep();

        // Everything below reaches into Commerce. The plugin can be installed while Commerce is
        // disabled or mid-upgrade, and a fatal during a Commerce update is a very bad afternoon.
        if (!self::commerceIsReady()) {
            return;
        }

        $this->registerShippingMethods();
        $this->registerPaymentGuard();
        $this->registerOrderCompletion();
        $this->registerOrderScreen();
        $this->registerOrderIndex();
    }

    public static function commerceIsReady(): bool
    {
        return class_exists(\craft\commerce\Plugin::class)
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('carrier/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }
}
