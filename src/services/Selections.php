<?php

namespace justinholtweb\carrier\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\Db;
use justinholtweb\carrier\db\Table;
use justinholtweb\carrier\models\canonical\PickupPoint;
use justinholtweb\carrier\models\Method;
use justinholtweb\carrier\models\Selection;
use justinholtweb\carrier\Plugin;

/**
 * The pickup point chosen on a cart.
 *
 * Kept in its own table keyed by order id rather than in a custom field, so it works on every
 * Commerce install without the merchant adding a field to the order layout, and so a theme's
 * checkout cannot accidentally post over it.
 */
class Selections extends Component
{
    /** @var array<int,Selection|false> */
    private array $_cache = [];

    public function getForOrder(int $orderId): ?Selection
    {
        if (!array_key_exists($orderId, $this->_cache)) {
            $row = (new Query())->from(Table::SELECTIONS)->where(['orderId' => $orderId])->one();
            $this->_cache[$orderId] = $row ? new Selection($row) : false;
        }

        return $this->_cache[$orderId] ?: null;
    }

    /**
     * Choose a point for a cart. The point must exist for the method's connection — a customer
     * cannot post an arbitrary id and have it printed on a label.
     */
    public function select(Order $order, Method $method, string $pointId): ?Selection
    {
        $connection = $method->getConnection();

        if (!$order->id || !$connection || !$method->requiresPoint) {
            return null;
        }

        $point = Plugin::getInstance()->getPoints()->find($connection, $pointId)
            ?? $this->fromRecentSearch($connection->id, $pointId);

        if (!$point) {
            return null;
        }

        $values = [
            'methodId' => $method->id,
            'connectionId' => $connection->id,
            'pointId' => $point->id,
            'point' => json_encode($point->toArray()),
        ];

        if ((new Query())->from(Table::SELECTIONS)->where(['orderId' => $order->id])->exists()) {
            Db::update(Table::SELECTIONS, $values, ['orderId' => $order->id]);
        } else {
            Db::insert(Table::SELECTIONS, array_merge($values, ['orderId' => $order->id]));
        }

        unset($this->_cache[$order->id]);

        return $this->getForOrder($order->id);
    }

    public function clear(Order $order): void
    {
        if ($order->id) {
            Db::delete(Table::SELECTIONS, ['orderId' => $order->id]);
            unset($this->_cache[$order->id]);
        }
    }

    /**
     * Search-mode carriers' points are not stored, so the search endpoint remembers what it showed
     * this session; only those ids can be chosen. That is what stops a forged id for them.
     */
    public function rememberSearch(int $connectionId, array $points): void
    {
        $session = Craft::$app->has('session', true) && !Craft::$app->getRequest()->getIsConsoleRequest() ? Craft::$app->getSession() : null;

        if (!$session) {
            return;
        }

        $key = 'carrier.points.' . $connectionId;
        $known = (array)$session->get($key, []);

        foreach ($points as $point) {
            $known[$point->id] = $point->toArray();
        }

        // Bounded so a scripted client cannot grow a session without limit.
        $session->set($key, array_slice($known, -200, null, true));
    }

    private function fromRecentSearch(int $connectionId, string $pointId): ?PickupPoint
    {
        if (!Craft::$app->has('session', true) || Craft::$app->getRequest()->getIsConsoleRequest()) {
            return null;
        }

        $known = (array)Craft::$app->getSession()->get('carrier.points.' . $connectionId, []);

        return isset($known[$pointId]) ? new PickupPoint($known[$pointId]) : null;
    }

    /**
     * Whether an order is on a method that needs a point and has none — the check the payment
     * veto and the shipment screen share.
     */
    public function isMissing(Order $order): bool
    {
        $method = Plugin::getInstance()->getMethods()->getByShippingMethodHandle($order->shippingMethodHandle);

        if (!$method || !$method->requiresPoint) {
            return false;
        }

        $selection = $order->id ? $this->getForOrder($order->id) : null;

        return !$selection || $selection->connectionId !== $method->connectionId;
    }

    public function forgetCache(): void
    {
        $this->_cache = [];
    }
}
