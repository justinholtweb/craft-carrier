<?php

namespace justinholtweb\carrier\controllers;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\web\Controller;
use justinholtweb\carrier\base\Feature;
use justinholtweb\carrier\db\Table;
use justinholtweb\carrier\helpers\LabelFormat;
use justinholtweb\carrier\models\Shipment;
use justinholtweb\carrier\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Collections and end-of-day closes.
 */
class PickupsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('carrier-requestPickups');

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $today = [];

        foreach ($plugin->getConnections()->enabled() as $connection) {
            $count = (int)(new Query())->from(Table::SHIPMENTS)
                ->where(['connectionId' => $connection->id, 'status' => Shipment::STATUS_LABELLED, 'trackingStatus' => 'preTransit'])
                ->count();

            $today[$connection->id] = $count;
        }

        return $this->renderTemplate('carrier/pickups/_index', [
            'pickupConnections' => $plugin->getConnections()->withFeature(Feature::PICKUPS),
            'closeConnections' => $plugin->getConnections()->withFeature(Feature::CLOSE_DAY),
            'waiting' => $today,
            'recent' => $plugin->getPickups()->recent(),
            'connectionNames' => array_map(static fn($c) => $c->name, $plugin->getConnections()->all()),
        ]);
    }

    public function actionRequest(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $connection = $plugin->getConnections()->getById((int)$this->request->getRequiredBodyParam('connectionId'));

        if (!$connection) {
            throw new NotFoundHttpException();
        }

        $date = DateTimeHelper::toDateTime($this->request->getBodyParam('date')) ?: new \DateTime('tomorrow');
        $shipments = $plugin->getShipments()->hydrate((new Query())->from(Table::SHIPMENTS)
            ->where(['connectionId' => $connection->id, 'status' => Shipment::STATUS_LABELLED, 'trackingStatus' => 'preTransit'])
            ->andWhere(['>=', 'labelledAt', Db::prepareDateForDb((new \DateTime())->modify('-7 days'))])
            ->all());

        $result = $plugin->getPickups()->request(
            $connection,
            $date,
            $shipments,
            (string)$this->request->getBodyParam('readyTime', '09:00'),
            (string)$this->request->getBodyParam('closeTime', '17:00'),
            (string)$this->request->getBodyParam('instructions', ''),
        );

        $result->success
            ? $this->setSuccessFlash(Craft::t('carrier', 'Collection booked: {ref}', ['ref' => $result->reference]))
            : $this->setFailFlash($result->message ?? Craft::t('carrier', 'The carrier refused the collection.'));

        return $this->redirectToPostedUrl();
    }

    public function actionClose(): Response
    {
        $this->requirePostRequest();

        $connection = Plugin::getInstance()->getConnections()->getById((int)$this->request->getRequiredBodyParam('connectionId'));

        if (!$connection) {
            throw new NotFoundHttpException();
        }

        $result = Plugin::getInstance()->getPickups()->closeDay($connection);

        if (!$result->success) {
            $this->setFailFlash($result->message ?? Craft::t('carrier', 'The carrier refused to close the day.'));

            return $this->redirectToPostedUrl();
        }

        $documents = $result->data['documents'] ?? [];

        // A manifest the driver has to sign is the thing to hand back, not a flash message.
        if ($documents !== []) {
            $document = $documents[0];

            return $this->response->sendContentAsFile($document->data, 'manifest-' . date('Y-m-d') . '.' . LabelFormat::extension($document->format), [
                'mimeType' => LabelFormat::mimeType($document->format),
            ]);
        }

        $this->setSuccessFlash(Craft::t('carrier', 'Day closed.'));

        return $this->redirectToPostedUrl();
    }
}
