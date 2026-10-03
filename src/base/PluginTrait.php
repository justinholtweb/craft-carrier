<?php

namespace justinholtweb\carrier\base;

use Craft;
use craft\base\Element;
use craft\commerce\elements\Order;
use craft\commerce\events\ProcessPaymentEvent;
use craft\commerce\events\RegisterAvailableShippingMethodsEvent;
use craft\commerce\errors\PaymentException;
use craft\commerce\services\Payments;
use craft\commerce\services\ShippingMethods;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\carrier\elements\actions\CreateLabels;
use justinholtweb\carrier\elements\actions\PrintLabels;
use justinholtweb\carrier\jobs\CreateLabelJob;
use justinholtweb\carrier\shipping\CarrierShippingMethod;
use justinholtweb\carrier\twig\CarrierVariable;
use Throwable;
use yii\base\Event;

/**
 * Event listeners and URL rules. The plugin class orchestrates; this does the wiring.
 */
trait PluginTrait
{
    // =========================================================================
    // Control panel
    // =========================================================================

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('carrier', 'Carrier');
        $user = Craft::$app->getUser();
        $subNav = [];

        if ($user->checkPermission('carrier-viewShipments')) {
            $subNav['shipments'] = ['label' => Craft::t('carrier', 'Shipments'), 'url' => 'carrier/shipments'];
            $subNav['problems'] = ['label' => Craft::t('carrier', 'Problems'), 'url' => 'carrier/problems'];
        }

        if ($user->checkPermission('carrier-requestPickups')) {
            $subNav['pickups'] = ['label' => Craft::t('carrier', 'Collections'), 'url' => 'carrier/pickups'];
        }

        if ($user->checkPermission('carrier-manageMethods')) {
            $subNav['methods'] = ['label' => Craft::t('carrier', 'Checkout methods'), 'url' => 'carrier/methods'];
        }

        if ($user->checkPermission('carrier-manageConnections')) {
            $subNav['connections'] = ['label' => Craft::t('carrier', 'Connections'), 'url' => 'carrier/connections'];
            $subNav['points'] = ['label' => Craft::t('carrier', 'Pickup points'), 'url' => 'carrier/points'];
        }

        if ($user->checkPermission('carrier-viewLog')) {
            $subNav['log'] = ['label' => Craft::t('carrier', 'Log'), 'url' => 'carrier/log'];
        }

        if ($user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $subNav['settings'] = ['label' => Craft::t('carrier', 'Settings'), 'url' => 'settings/plugins/carrier'];
        }

        if ($subNav === []) {
            return null;
        }

        $item['subnav'] = $subNav;

        return $item;
    }

    private function registerTwigVariable(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, static function(Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('carrier', CarrierVariable::class);
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, static function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('carrier', 'Carrier'),
                'permissions' => [
                    'carrier-viewShipments' => [
                        'label' => Craft::t('carrier', 'View shipments and labels'),
                        'nested' => [
                            'carrier-createLabels' => ['label' => Craft::t('carrier', 'Buy labels')],
                            'carrier-voidLabels' => ['label' => Craft::t('carrier', 'Void labels and resolve problems')],
                            'carrier-requestPickups' => ['label' => Craft::t('carrier', 'Request collections and close the day')],
                        ],
                    ],
                    'carrier-manageMethods' => ['label' => Craft::t('carrier', 'Manage checkout methods')],
                    'carrier-manageConnections' => ['label' => Craft::t('carrier', 'Manage carrier connections and pickup points')],
                    'carrier-viewLog' => ['label' => Craft::t('carrier', 'View the carrier log')],
                ],
            ];
        });
    }

    private function registerRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, static function(RegisterUrlRulesEvent $event) {
            $event->rules['carrier'] = 'carrier/shipments/index';
            $event->rules['carrier/shipments'] = 'carrier/shipments/index';
            $event->rules['carrier/shipments/<shipmentId:\d+>'] = 'carrier/shipments/detail';
            $event->rules['carrier/orders/<orderId:\d+>'] = 'carrier/shipments/order';
            $event->rules['carrier/problems'] = 'carrier/shipments/problems';
            $event->rules['carrier/pickups'] = 'carrier/pickups/index';
            $event->rules['carrier/methods'] = 'carrier/methods/index';
            $event->rules['carrier/methods/new'] = 'carrier/methods/edit';
            $event->rules['carrier/methods/<methodId:\d+>'] = 'carrier/methods/edit';
            $event->rules['carrier/connections'] = 'carrier/connections/index';
            $event->rules['carrier/connections/new'] = 'carrier/connections/edit';
            $event->rules['carrier/connections/<connectionId:\d+>'] = 'carrier/connections/edit';
            $event->rules['carrier/points'] = 'carrier/points/index';
            $event->rules['carrier/log'] = 'carrier/log/index';
            $event->rules['carrier/log/<entryId:\d+>'] = 'carrier/log/detail';
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, static function(RegisterUrlRulesEvent $event) {
            $event->rules['carrier/webhook/<connectionHandle:[\w\-]+>'] = 'carrier/webhook/receive';
        });
    }

    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, [$this, 'collectGarbage']);
    }

    /**
     * Named so the test suite can call it without triggering every other plugin's GC.
     */
    public function collectGarbage(): void
    {
        $settings = $this->getSettings();
        $this->getLog()->prune($settings->logRetentionDays);
        $this->getShipments()->pruneLabels($settings->labelRetentionDays);
    }

    private function registerSweep(): void
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }

        Craft::$app->on(\craft\web\Application::EVENT_AFTER_REQUEST, function() {
            // Page views only; an AJAX poll or a form post has better things to do.
            $request = Craft::$app->getRequest();

            if ($request->getIsAjax() || $request->getIsPost() || !Craft::$app->getIsInstalled()) {
                return;
            }

            $this->getSweeper()->maybeRun();
        });
    }

    // =========================================================================
    // Commerce
    // =========================================================================

    private function registerShippingMethods(): void
    {
        Event::on(ShippingMethods::class, ShippingMethods::EVENT_REGISTER_AVAILABLE_SHIPPING_METHODS, function(RegisterAvailableShippingMethodsEvent $event) {
            try {
                $methods = $event->getShippingMethods();

                foreach ($this->getMethods()->enabledForStore($event->order->storeId) as $method) {
                    $methods->push(new CarrierShippingMethod([
                        'method' => $method,
                        'storeId' => $method->storeId ?? $event->order->storeId,
                    ]));
                }

                $event->setShippingMethods($methods);
            } catch (Throwable $e) {
                // A broken method must never take the shipping step down with it.
                Craft::error('Carrier could not register its shipping methods: ' . $e->getMessage(), 'carrier');
            }
        });
    }

    /**
     * Refuse payment for a pickup-point method with no point chosen. This is the one place a
     * missing locker can stop a checkout before money moves, and the message is the customer's,
     * not Commerce's generic "unable to make payment".
     */
    private function registerPaymentGuard(): void
    {
        Event::on(Payments::class, Payments::EVENT_BEFORE_PROCESS_PAYMENT, function(ProcessPaymentEvent $event) {
            if (!$this->getSettings()->requirePointOnPayment || !Craft::$app->getRequest()->getIsSiteRequest()) {
                return;
            }

            $order = $event->order;

            if ($order && $this->getSelections()->isMissing($order)) {
                $message = Craft::t('carrier', 'Choose a pickup point for your delivery before paying.');
                $order->addError('shippingMethodHandle', $message);

                throw new PaymentException($message);
            }
        });
    }

    private function registerOrderCompletion(): void
    {
        Event::on(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER, function(Event $event) {
            /** @var Order $order */
            $order = $event->sender;

            try {
                if ($this->getSettings()->autoLabelOnComplete && $this->getMethods()->getByShippingMethodHandle($order->shippingMethodHandle)) {
                    // Queued, never inline: a carrier having a slow afternoon must not delay a
                    // customer's confirmation, and one being down must not fail a sale.
                    Craft::$app->getQueue()->push(new CreateLabelJob(['orderId' => $order->id]));
                }
            } catch (Throwable $e) {
                Craft::error('Carrier could not queue a label: ' . $e->getMessage(), 'carrier');
            }
        });
    }

    private function registerOrderScreen(): void
    {
        Craft::$app->getView()->hook('cp.commerce.order.edit.details', function(array &$context) {
            /** @var Order|null $order */
            $order = $context['order'] ?? null;

            if (!$order || !$order->id || !Craft::$app->getUser()->checkPermission('carrier-viewShipments')) {
                return '';
            }

            try {
                return Craft::$app->getView()->renderTemplate('carrier/_order-panel', [
                    'order' => $order,
                    'shipments' => $this->getShipments()->forOrder($order->id),
                    'selection' => $this->getSelections()->getForOrder($order->id),
                    'method' => $this->getMethods()->getByShippingMethodHandle($order->shippingMethodHandle),
                ]);
            } catch (Throwable $e) {
                Craft::error('Carrier could not render its order panel: ' . $e->getMessage(), 'carrier');

                return '';
            }
        });
    }

    private function registerOrderIndex(): void
    {
        Event::on(Order::class, Element::EVENT_REGISTER_ACTIONS, static function(RegisterElementActionsEvent $event) {
            $user = Craft::$app->getUser();

            if ($user->checkPermission('carrier-createLabels')) {
                $event->actions[] = CreateLabels::class;
            }

            if ($user->checkPermission('carrier-viewShipments')) {
                $event->actions[] = PrintLabels::class;
            }
        });

        Event::on(Order::class, Element::EVENT_REGISTER_TABLE_ATTRIBUTES, static function(RegisterElementTableAttributesEvent $event) {
            $event->tableAttributes['carrierTracking'] = ['label' => Craft::t('carrier', 'Tracking')];
        });

        Event::on(Order::class, Element::EVENT_DEFINE_ATTRIBUTE_HTML, function(DefineAttributeHtmlEvent $event) {
            if ($event->attribute !== 'carrierTracking') {
                return;
            }

            /** @var Order $order */
            $order = $event->sender;
            $event->handled = true;
            $event->html = '';

            try {
                $event->html = Craft::$app->getView()->renderTemplate('carrier/_tracking-cell', [
                    'shipments' => $order->id ? $this->getShipments()->forOrder($order->id, false) : [],
                ]);
            } catch (Throwable $e) {
                Craft::warning('Carrier could not render a tracking cell: ' . $e->getMessage(), 'carrier');
            }
        });
    }
}
