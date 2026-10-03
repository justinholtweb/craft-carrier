<?php

namespace justinholtweb\carrier\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\helpers\Json;
use craft\helpers\UrlHelper;

/**
 * "Print labels" on the order index: opens one print job for every selected order's labels.
 *
 * Handled in the browser rather than by `performAction()`, because the answer is a document to
 * open, not a message to flash.
 */
class PrintLabels extends ElementAction
{
    public static function displayName(): string
    {
        return Craft::t('carrier', 'Print shipping labels');
    }

    public function getTriggerLabel(): string
    {
        return self::displayName();
    }

    public function getTriggerHtml(): ?string
    {
        $url = Json::encode(UrlHelper::actionUrl('carrier/shipments/print'));
        $type = Json::encode(static::class);

        Craft::$app->getView()->registerJs(<<<JS
new Craft.ElementActionTrigger({
    type: $type,
    bulk: true,
    activate: (selectedItems) => {
        const ids = selectedItems.toArray().map((el) => el.dataset.id).join(',');
        window.open($url + (($url).includes('?') ? '&' : '?') + 'orderIds=' + encodeURIComponent(ids), '_blank');
    },
});
JS);

        return null;
    }
}
