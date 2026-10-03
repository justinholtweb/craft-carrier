<?php

namespace justinholtweb\carrier\twig;

use Craft;
use craft\commerce\elements\Order;
use craft\web\View;
use justinholtweb\carrier\base\TrackingStatus;
use justinholtweb\carrier\models\Method;
use justinholtweb\carrier\models\Selection;
use justinholtweb\carrier\models\Shipment;
use justinholtweb\carrier\Plugin;
use Twig\Markup;

/**
 * `craft.carrier` — what a storefront template needs.
 *
 *     {{ craft.carrier.pointPicker(cart) }}
 *     {% for shipment in craft.carrier.shipments(order) %}
 *         <a href="{{ shipment.trackingUrl }}">{{ shipment.trackingNumber }}</a>
 *         {{ shipment.trackingStatusLabel }}
 *     {% endfor %}
 */
class CarrierVariable
{
    /**
     * An order's live shipments, oldest first, returns excluded.
     *
     * @return Shipment[]
     */
    public function shipments(Order $order, bool $includeReturns = false): array
    {
        if (!$order->id) {
            return [];
        }

        $shipments = array_filter(
            Plugin::getInstance()->getShipments()->forOrder($order->id, false),
            static fn(Shipment $s) => $s->status === Shipment::STATUS_LABELLED && ($includeReturns || !$s->isReturn),
        );

        return array_reverse(array_values($shipments));
    }

    /**
     * Tracking events for a shipment, newest first, as plain arrays.
     */
    public function events(Shipment $shipment): array
    {
        return array_map(static fn(array $row) => [
            'occurredAt' => $row['occurredAt'] ? new \DateTime($row['occurredAt'], new \DateTimeZone('UTC')) : null,
            'status' => $row['status'],
            'statusLabel' => TrackingStatus::label($row['status']),
            'description' => $row['description'],
            'location' => $row['location'],
        ], Plugin::getInstance()->getShipments()->eventsFor($shipment->id));
    }

    public function selection(Order $order): ?Selection
    {
        return $order->id ? Plugin::getInstance()->getSelections()->getForOrder($order->id) : null;
    }

    public function method(Order $order): ?Method
    {
        return Plugin::getInstance()->getMethods()->getByShippingMethodHandle($order->shippingMethodHandle);
    }

    /**
     * Methods on this store that deliver to a pickup point. Handles are what the picker posts.
     *
     * @return Method[]
     */
    public function pointMethods(?Order $order = null): array
    {
        return array_values(array_filter(
            Plugin::getInstance()->getMethods()->enabledForStore($order?->storeId),
            static fn(Method $m) => $m->requiresPoint,
        ));
    }

    /**
     * Whether the cart is on a point method with no point chosen — for disabling a Pay button.
     */
    public function needsPoint(Order $order): bool
    {
        return Plugin::getInstance()->getSelections()->isMissing($order);
    }

    /**
     * The drop-in picker. It renders nothing unless the cart is on a pickup-point method, so it is
     * safe to include unconditionally on the shipping step. Override the markup by copying
     * `carrier/_frontend/point-picker.twig` into the site's `templates/_carrier/point-picker.twig`.
     */
    public function pointPicker(Order $order, array $options = []): Markup
    {
        $method = $this->method($order);

        if (!$method || !$method->requiresPoint) {
            return new Markup('', 'UTF-8');
        }

        $view = Craft::$app->getView();
        $variables = [
            'cart' => $order,
            'method' => $method,
            'selection' => $this->selection($order),
            'options' => array_merge(['map' => false, 'limit' => 20], $options),
        ];

        $override = '_carrier/point-picker';

        if ($view->doesTemplateExist($override, View::TEMPLATE_MODE_SITE)) {
            return new Markup($view->renderTemplate($override, $variables, View::TEMPLATE_MODE_SITE), 'UTF-8');
        }

        return new Markup($view->renderTemplate('carrier/_frontend/point-picker', $variables, View::TEMPLATE_MODE_CP), 'UTF-8');
    }

    public function statusLabel(string $status): string
    {
        return TrackingStatus::label($status);
    }

    public function statusColor(string $status): string
    {
        return TrackingStatus::color($status);
    }
}
