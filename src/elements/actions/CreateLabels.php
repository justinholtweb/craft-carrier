<?php

namespace justinholtweb\carrier\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\commerce\elements\Order;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\carrier\jobs\CreateLabelJob;
use justinholtweb\carrier\Plugin;

/**
 * "Create labels" on the order index. Queues one job per order; each job claims its order, so
 * running the action twice on the same selection buys nothing twice.
 */
class CreateLabels extends ElementAction
{
    public static function displayName(): string
    {
        return Craft::t('carrier', 'Create shipping labels');
    }

    public function getTriggerLabel(): string
    {
        return self::displayName();
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        if (!Craft::$app->getUser()->checkPermission('carrier-createLabels')) {
            $this->setMessage(Craft::t('carrier', 'You may not buy labels.'));

            return false;
        }

        $methods = Plugin::getInstance()->getMethods();
        $queued = 0;
        $skipped = 0;

        /** @var Order $order */
        foreach ($query->all() as $order) {
            if (!$order->isCompleted || !$methods->getByShippingMethodHandle($order->shippingMethodHandle)) {
                $skipped++;
                continue;
            }

            Craft::$app->getQueue()->push(new CreateLabelJob(['orderId' => $order->id]));
            $queued++;
        }

        $message = Craft::t('carrier', '{n, plural, =1{One label} other{# labels}} queued.', ['n' => $queued]);

        if ($skipped) {
            $message .= ' ' . Craft::t('carrier', '{n, plural, =1{One order was} other{# orders were}} skipped: not completed, or not on a Carrier method.', ['n' => $skipped]);
        }

        $this->setMessage($message);

        return $queued > 0;
    }
}
