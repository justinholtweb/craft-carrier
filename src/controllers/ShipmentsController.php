<?php

namespace justinholtweb\carrier\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\helpers\StringHelper;
use craft\web\Controller;
use justinholtweb\carrier\base\Feature;
use justinholtweb\carrier\base\TrackingStatus;
use justinholtweb\carrier\helpers\LabelFormat;
use justinholtweb\carrier\helpers\Units;
use justinholtweb\carrier\models\canonical\Parcel;
use justinholtweb\carrier\models\Shipment;
use justinholtweb\carrier\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Shipments: the list, the problems queue, the per-order shipment screen and every action on it.
 */
class ShipmentsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('carrier-viewShipments');

        return true;
    }

    // =========================================================================
    // Screens
    // =========================================================================

    public function actionIndex(): Response
    {
        $status = (string)$this->request->getQueryParam('status', '');
        $tracking = (string)$this->request->getQueryParam('tracking', '');
        $connectionId = (int)$this->request->getQueryParam('connection', 0);
        $search = trim((string)$this->request->getQueryParam('q', ''));
        $page = max(1, (int)$this->request->getQueryParam('page', 1));
        $perPage = 50;

        $query = Plugin::getInstance()->getShipments()->query();

        if ($status !== '') {
            $query->andWhere(['status' => $status]);
        }

        if ($tracking !== '' && TrackingStatus::isValid($tracking)) {
            $query->andWhere(['trackingStatus' => $tracking]);
        }

        if ($connectionId) {
            $query->andWhere(['connectionId' => $connectionId]);
        }

        if ($search !== '') {
            $orderIds = Order::find()->reference($search . '*')->status(null)->limit(50)->ids();
            $query->andWhere(['or', ['trackingNumber' => $search], ['orderId' => $orderIds ?: [0]]]);
        }

        $total = (int)(clone $query)->count();
        $shipments = Plugin::getInstance()->getShipments()->hydrate(
            $query->offset(($page - 1) * $perPage)->limit($perPage)->all(),
        );

        return $this->renderTemplate('carrier/shipments/_index', [
            'shipments' => $shipments,
            'orders' => $this->ordersFor($shipments),
            'total' => $total,
            'page' => $page,
            'pages' => (int)ceil($total / $perPage),
            'filters' => compact('status', 'tracking', 'connectionId', 'search'),
            'connections' => Plugin::getInstance()->getConnections()->all(),
            'trackingStatuses' => array_combine(TrackingStatus::all(), array_map(TrackingStatus::label(...), TrackingStatus::all())),
        ]);
    }

    public function actionProblems(): Response
    {
        $shipments = Plugin::getInstance()->getShipments()->problems();

        return $this->renderTemplate('carrier/shipments/_problems', [
            'shipments' => $shipments,
            'orders' => $this->ordersFor($shipments),
            'canResolve' => Craft::$app->getUser()->checkPermission('carrier-voidLabels'),
            'canCreate' => Craft::$app->getUser()->checkPermission('carrier-createLabels'),
        ]);
    }

    public function actionDetail(int $shipmentId): Response
    {
        $shipment = $this->shipment($shipmentId);

        if ($shipment->orderId) {
            return $this->redirect('carrier/orders/' . $shipment->orderId . '#shipment-' . $shipment->id);
        }

        throw new NotFoundHttpException('This shipment has no order.');
    }

    /**
     * The shipment screen for one order: what has shipped, and the form to ship more.
     */
    public function actionOrder(int $orderId): Response
    {
        $order = $this->order($orderId);
        $plugin = Plugin::getInstance();
        $method = $plugin->getMethods()->getByShippingMethodHandle($order->shippingMethodHandle);
        $connections = array_values(array_filter(
            $plugin->getConnections()->enabled(),
            static fn($c) => $c->getCarrierClass()::capabilities()->has(Feature::LABELS),
        ));
        $requested = (int)$this->request->getQueryParam('connection', 0);
        $connection = null;

        foreach ($connections as $candidate) {
            if ($candidate->id === $requested) {
                $connection = $candidate;
            }
        }

        $connection ??= ($method?->getConnection()?->enabled ? $method->getConnection() : null) ?? ($connections[0] ?? null);

        if ($method && $connection && $method->connectionId !== $connection->id) {
            $method = null;
        }
        $parcels = $connection ? $plugin->getPacker()->pack($order, $method, $connection->getCarrierClass()::capabilities()->getMaxParcelWeightG()) : [];
        $shipments = $plugin->getShipments()->forOrder($order->id);
        $details = [];

        foreach ($shipments as $shipment) {
            $details[$shipment->id] = [
                'labels' => $plugin->getShipments()->labelsFor($shipment->id),
                'events' => $plugin->getShipments()->eventsFor($shipment->id),
                'capabilities' => $shipment->getConnection()?->getCarrierClass()::capabilities()->toArray(),
            ];
        }

        $commerce = \craft\commerce\Plugin::getInstance()->getSettings();

        return $this->renderTemplate('carrier/shipments/_order', [
            'order' => $order,
            'method' => $method,
            'shipments' => $shipments,
            'shipmentDetails' => $details,
            'connections' => $connections,
            'trackingConnections' => $plugin->getConnections()->withFeature(Feature::TRACKING),
            'connection' => $connection,
            'carriers' => array_combine(
                array_map(static fn($c) => $c->id, $connections),
                array_map(static fn($c) => $plugin->getCarriers()->describeClass($c->getCarrierClass()), $connections),
            ) ?: [],
            'parcels' => array_map(static fn(Parcel $p) => [
                'weight' => round(Units::gramsTo($p->weightG, $commerce->weightUnits, 3), 3),
                'length' => Units::mmTo($p->lengthMm, $commerce->dimensionUnits, 1),
                'width' => Units::mmTo($p->widthMm, $commerce->dimensionUnits, 1),
                'height' => Units::mmTo($p->heightMm, $commerce->dimensionUnits, 1),
                'box' => $p->box,
                'items' => $p->items,
            ], $parcels),
            'weightUnit' => $commerce->weightUnits,
            'dimensionUnit' => $commerce->dimensionUnits,
            'selection' => $plugin->getSelections()->getForOrder($order->id),
            'isCod' => $plugin->getOrders()->isCod($order),
            'nonce' => StringHelper::UUID(),
            'canCreate' => Craft::$app->getUser()->checkPermission('carrier-createLabels'),
            'canVoid' => Craft::$app->getUser()->checkPermission('carrier-voidLabels'),
            'accessorialLabels' => \justinholtweb\carrier\base\Accessorial::labels(),
            'labelFormats' => array_combine(LabelFormat::all(), array_map(LabelFormat::label(...), LabelFormat::all())),
        ]);
    }

    // =========================================================================
    // Actions
    // =========================================================================

    /**
     * Buy labels from the shipment screen. The form carries a nonce, which becomes the claim key:
     * a double-click or a resubmitted form finds its own claim and cannot buy twice, while a
     * merchant who deliberately ships a second consignment gets a fresh form and a fresh nonce.
     */
    public function actionPurchase(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('carrier-createLabels');

        $plugin = Plugin::getInstance();
        $order = $this->order((int)$this->request->getRequiredBodyParam('orderId'));
        $connection = $plugin->getConnections()->getById((int)$this->request->getRequiredBodyParam('connectionId'));

        if (!$connection || !$connection->enabled) {
            throw new BadRequestHttpException('Unknown connection.');
        }

        $nonce = (string)$this->request->getRequiredBodyParam('nonce');

        if (!StringHelper::isUUID($nonce)) {
            throw new BadRequestHttpException('Bad form.');
        }

        $method = $plugin->getMethods()->getByShippingMethodHandle($order->shippingMethodHandle);

        if ($method && $method->connectionId !== $connection->id) {
            $method = null;
        }

        $shipment = $plugin->getLabels()->purchase($order, $connection, $method, array_filter([
            'claimKey' => 'manual:' . $order->id . ':' . $nonce,
            'serviceCode' => (string)$this->request->getBodyParam('serviceCode', ''),
            'labelFormat' => (string)$this->request->getBodyParam('labelFormat', ''),
            'isReturn' => (bool)$this->request->getBodyParam('isReturn'),
            'pointId' => $this->request->getBodyParam('pointId'),
            'accessorials' => array_values(array_filter((array)$this->request->getBodyParam('accessorials', []))),
            'parcels' => $this->postedParcels(),
        ], static fn($v) => $v !== null && $v !== ''));

        if ($shipment->status === Shipment::STATUS_LABELLED) {
            $this->setSuccessFlash(Craft::t('carrier', 'Label created: {number}', ['number' => $shipment->trackingNumber]));
        } else {
            $this->setFailFlash($shipment->getStatusLabel() . ': ' . ($shipment->error ?? ''));
        }

        return $this->redirect('carrier/orders/' . $order->id);
    }

    public function actionVoid(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('carrier-voidLabels');

        $shipment = $this->shipment((int)$this->request->getRequiredBodyParam('shipmentId'));

        if (Plugin::getInstance()->getLabels()->void($shipment)) {
            $this->setSuccessFlash(Craft::t('carrier', 'Label voided.'));
        } else {
            $this->setFailFlash(implode(' ', $shipment->getFirstErrors()) ?: Craft::t('carrier', 'Couldn’t void the label.'));
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Record a void done in the carrier's portal, or settle an uncertain purchase.
     */
    public function actionResolve(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('carrier-voidLabels');

        $shipment = $this->shipment((int)$this->request->getRequiredBodyParam('shipmentId'));
        $outcome = (string)$this->request->getRequiredBodyParam('outcome');
        $labels = Plugin::getInstance()->getLabels();

        $ok = match ($outcome) {
            'voided' => (function() use ($shipment, $labels) {
                $labels->markVoided($shipment);

                return true;
            })(),
            default => $labels->resolve($shipment, $outcome, (string)$this->request->getBodyParam('trackingNumber', '')),
        };

        $ok
            ? $this->setSuccessFlash(Craft::t('carrier', 'Shipment updated.'))
            : $this->setFailFlash(Craft::t('carrier', 'That shipment can’t be resolved that way.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Retry a refused purchase — the only state that may be retried — through the same claim.
     */
    public function actionRetry(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('carrier-createLabels');

        $shipment = $this->shipment((int)$this->request->getRequiredBodyParam('shipmentId'));
        $order = $shipment->orderId ? $this->order($shipment->orderId) : null;
        $connection = $shipment->getConnection();

        if (!$order || !$connection || $shipment->status !== Shipment::STATUS_FAILED) {
            $this->setFailFlash(Craft::t('carrier', 'Only a refused purchase can be retried.'));

            return $this->redirectToPostedUrl();
        }

        $claimKey = $shipment->claimKey;

        // An unclaimed refusal (a preflight problem) gets a claim now, so a retry cannot run twice.
        if ($claimKey === null) {
            $claimKey = 'retry:' . $shipment->id;
            $shipment->claimKey = $claimKey;
            Plugin::getInstance()->getShipments()->update($shipment);
        }

        $result = Plugin::getInstance()->getLabels()->purchase($order, $connection, $shipment->getMethod(), array_filter([
            'claimKey' => $claimKey,
            'serviceCode' => $shipment->serviceCode,
            'isReturn' => $shipment->isReturn,
        ]));

        $result->status === Shipment::STATUS_LABELLED
            ? $this->setSuccessFlash(Craft::t('carrier', 'Label created: {number}', ['number' => $result->trackingNumber]))
            : $this->setFailFlash($result->getStatusLabel() . ': ' . ($result->error ?? ''));

        return $this->redirectToPostedUrl();
    }

    /**
     * Attach a tracking number from a label made outside Carrier.
     */
    public function actionRecord(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('carrier-createLabels');

        $plugin = Plugin::getInstance();
        $order = $this->order((int)$this->request->getRequiredBodyParam('orderId'));
        $connection = $plugin->getConnections()->getById((int)$this->request->getRequiredBodyParam('connectionId'));
        $number = trim((string)$this->request->getRequiredBodyParam('trackingNumber'));

        if (!$connection || $number === '' || !preg_match('/^[A-Za-z0-9\-]{4,64}$/', $number)) {
            $this->setFailFlash(Craft::t('carrier', 'Enter the tracking number exactly as the carrier printed it.'));

            return $this->redirectToPostedUrl();
        }

        $shipment = $plugin->getLabels()->recordExternal($order, $connection, $number, (string)$this->request->getBodyParam('serviceCode', ''));
        $plugin->getTracking()->refresh([$shipment]);
        $this->setSuccessFlash(Craft::t('carrier', 'Shipment recorded. Tracking will be followed from here.'));

        return $this->redirectToPostedUrl();
    }

    public function actionRefreshTracking(): Response
    {
        $this->requirePostRequest();

        $shipment = $this->shipment((int)$this->request->getRequiredBodyParam('shipmentId'));
        $updated = Plugin::getInstance()->getTracking()->refresh([$shipment]);

        $this->setSuccessFlash($updated
            ? Craft::t('carrier', 'Tracking updated.')
            : Craft::t('carrier', 'No new tracking information.'));

        return $this->redirectToPostedUrl();
    }

    public function actionReprint(): Response
    {
        $this->requirePostRequest();

        $shipment = $this->shipment((int)$this->request->getRequiredBodyParam('shipmentId'));
        $format = (string)$this->request->getBodyParam('labelFormat', '');

        Plugin::getInstance()->getLabels()->reprint($shipment, $format ?: null)
            ? $this->setSuccessFlash(Craft::t('carrier', 'Labels fetched again.'))
            : $this->setFailFlash(Craft::t('carrier', 'The carrier could not reprint this label.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Download one stored document.
     */
    public function actionLabel(int $labelId): Response
    {
        $row = Plugin::getInstance()->getShipments()->getLabelRow($labelId);

        if (!$row) {
            throw new NotFoundHttpException();
        }

        $data = (string)base64_decode((string)$row['data']);
        $name = ($row['trackingNumber'] ?: 'label-' . $row['id']) . '.' . LabelFormat::extension($row['format']);

        return $this->response->sendContentAsFile($data, $name, [
            'mimeType' => LabelFormat::mimeType($row['format']),
            'inline' => LabelFormat::isPdf($row['format']),
        ]);
    }

    /**
     * One print job for many shipments — the bulk "Print labels" action lands here.
     */
    public function actionPrint(): Response
    {
        $shipmentIds = array_filter(array_map('intval', explode(',', (string)$this->request->getParam('shipmentIds', ''))));
        $orderIds = array_filter(array_map('intval', explode(',', (string)$this->request->getParam('orderIds', ''))));

        foreach (Plugin::getInstance()->getShipments()->forOrders($orderIds) as $shipments) {
            foreach ($shipments as $shipment) {
                if (!$shipment->isReturn) {
                    $shipmentIds[] = $shipment->id;
                }
            }
        }

        $bundle = Plugin::getInstance()->getLabels()->bundle($shipmentIds);

        if ($bundle === null) {
            return $this->renderTemplate('carrier/shipments/_print-empty');
        }

        if ($bundle['type'] === 'sheet') {
            $this->getView()->setTemplateMode(\craft\web\View::TEMPLATE_MODE_CP);

            return $this->renderTemplate('carrier/shipments/_print-sheet', [
                'images' => array_map(static fn($l) => 'data:image/png;base64,' . base64_encode($l->data), $bundle['labels']),
                'layout' => (string)$this->request->getParam('layout', 'a4'),
            ]);
        }

        return $this->response->sendContentAsFile($bundle['data'], $bundle['filename'], [
            'mimeType' => $bundle['mime'],
            'inline' => $bundle['mime'] === 'application/pdf',
        ]);
    }

    /**
     * Compare every service a connection offers for this order. AJAX.
     */
    public function actionShop(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $plugin = Plugin::getInstance();
        $order = $this->order((int)$this->request->getRequiredBodyParam('orderId'));
        $connection = $plugin->getConnections()->getById((int)$this->request->getRequiredBodyParam('connectionId'));

        if (!$connection) {
            throw new BadRequestHttpException('Unknown connection.');
        }

        $result = $plugin->getRates()->shop($order, $connection, $this->postedParcels());

        return $this->asJson([
            'error' => $result->error,
            'rates' => array_map(static fn($r) => $r->toArray(), $result->rates),
        ]);
    }

    /**
     * Exactly what would be sent to the carrier, built by the same code that sends it. AJAX.
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $plugin = Plugin::getInstance();
        $order = $this->order((int)$this->request->getRequiredBodyParam('orderId'));
        $connection = $plugin->getConnections()->getById((int)$this->request->getRequiredBodyParam('connectionId'));
        $method = $plugin->getMethods()->getByShippingMethodHandle($order->shippingMethodHandle);
        $request = $plugin->getOrders()->buildRequest($order, $method?->connectionId === $connection?->id ? $method : null, $connection, $this->postedParcels(), 'preview');

        if ($service = $this->request->getBodyParam('serviceCode')) {
            $request->serviceCode = (string)$service;
        }

        return $this->asJson(['request' => $request->toArray()]);
    }

    public function actionValidateAddress(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $plugin = Plugin::getInstance();
        $order = $this->order((int)$this->request->getRequiredBodyParam('orderId'));
        $connection = $plugin->getConnections()->getById((int)$this->request->getRequiredBodyParam('connectionId'));
        $carrier = $connection?->getCarrier();

        if (!$carrier || !$carrier::capabilities()->has(Feature::ADDRESS_VALIDATION)) {
            return $this->asJson(['error' => Craft::t('carrier', 'This carrier does not validate addresses.')]);
        }

        $request = $plugin->getOrders()->buildRequest($order, null, $connection, [], 'preview');
        $result = $carrier->validateAddress($request->shipTo);

        return $this->asJson([
            'valid' => $result->valid,
            'residential' => $result->residential,
            'messages' => $result->messages,
            'error' => $result->error,
            'suggestions' => array_map(static fn($a) => $a->toArray(), $result->suggestions),
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Parcels as edited on the shipment screen, in store units, converted once to grams and mm.
     *
     * @return Parcel[]|null null when the form did not post any, so the packer decides
     */
    private function postedParcels(): ?array
    {
        $rows = $this->request->getBodyParam('parcels');

        if (!is_array($rows) || $rows === []) {
            return null;
        }

        $commerce = \craft\commerce\Plugin::getInstance()->getSettings();
        $parcels = [];

        foreach ($rows as $row) {
            if (!is_array($row) || (float)($row['weight'] ?? 0) <= 0) {
                continue;
            }

            $parcels[] = new Parcel([
                'weightG' => Units::toGrams((float)$row['weight'], $commerce->weightUnits),
                'lengthMm' => Units::toMm((float)($row['length'] ?? 0), $commerce->dimensionUnits),
                'widthMm' => Units::toMm((float)($row['width'] ?? 0), $commerce->dimensionUnits),
                'heightMm' => Units::toMm((float)($row['height'] ?? 0), $commerce->dimensionUnits),
                'box' => (string)($row['box'] ?? ''),
                'value' => (float)($row['value'] ?? 0),
                'freightClass' => (string)($row['freightClass'] ?? ''),
                'packagingType' => (string)($row['packagingType'] ?? ''),
                'items' => json_decode((string)($row['items'] ?? '[]'), true) ?: [],
                'reference' => (string)(count($parcels) + 1),
            ]);
        }

        return $parcels ?: null;
    }

    private function order(int $id): Order
    {
        $order = Order::find()->id($id)->status(null)->one();

        if (!$order) {
            throw new NotFoundHttpException('No such order.');
        }

        return $order;
    }

    private function shipment(int $id): Shipment
    {
        $shipment = Plugin::getInstance()->getShipments()->getById($id);

        if (!$shipment) {
            throw new NotFoundHttpException('No such shipment.');
        }

        return $shipment;
    }

    /**
     * @param Shipment[] $shipments
     * @return array<int,Order>
     */
    private function ordersFor(array $shipments): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn(Shipment $s) => $s->orderId, $shipments))));

        if ($ids === []) {
            return [];
        }

        $orders = [];

        foreach (Order::find()->id($ids)->status(null)->all() as $order) {
            $orders[$order->id] = $order;
        }

        return $orders;
    }
}
