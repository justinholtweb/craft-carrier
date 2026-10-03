<?php

namespace justinholtweb\carrier\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\carrier\base\Capabilities;
use justinholtweb\carrier\base\Feature;
use justinholtweb\carrier\helpers\LabelFormat;
use justinholtweb\carrier\models\Connection;
use justinholtweb\carrier\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Carrier accounts: the list, the editor, the connection test and the point sync button.
 */
class ConnectionsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('carrier-manageConnections');

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $summary = [];

        foreach ($plugin->getConnections()->all() as $connection) {
            $summary[$connection->id] = [
                'carrier' => $plugin->getCarriers()->describeOne($connection->carrier),
                'methods' => count($plugin->getMethods()->forConnection($connection->id)),
                'points' => $plugin->getPoints()->count($connection),
            ];
        }

        return $this->renderTemplate('carrier/connections/_index', [
            'connections' => $plugin->getConnections()->all(),
            'summary' => $summary,
            'carriers' => $plugin->getCarriers()->describe(),
        ]);
    }

    public function actionEdit(?int $connectionId = null, ?Connection $connection = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($connection === null) {
            $connection = $connectionId
                ? $plugin->getConnections()->getById($connectionId)
                : new Connection(['carrier' => (string)$this->request->getQueryParam('carrier')]);
        }

        if (!$connection) {
            throw new NotFoundHttpException('No such connection.');
        }

        $descriptor = $connection->carrier !== '' ? $plugin->getCarriers()->describeOne($connection->carrier) : null;
        $formats = $descriptor['capabilities']['labelFormats'] ?? [];

        return $this->renderTemplate('carrier/connections/_edit', [
            'connection' => $connection,
            'carrier' => $descriptor,
            'carriers' => $plugin->getCarriers()->describe(),
            'labelFormatOptions' => LabelFormat::options($formats),
            'isListPoints' => ($descriptor['capabilities']['pointsMode'] ?? null) === Capabilities::POINTS_LIST,
            'hasPoints' => in_array(Feature::POINTS, $descriptor['features'] ?? [], true),
            'pointCount' => $connection->id ? $plugin->getPoints()->count($connection) : 0,
            'webhookUrl' => $connection->handle && in_array(Feature::WEBHOOKS, $descriptor['features'] ?? [], true)
                ? UrlHelper::siteUrl('carrier/webhook/' . $connection->handle)
                : null,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $id = $this->request->getBodyParam('connectionId');
        $connection = $id ? $plugin->getConnections()->getById((int)$id) : new Connection();

        if (!$connection) {
            throw new NotFoundHttpException('No such connection.');
        }

        $connection->name = (string)$this->request->getBodyParam('name', $connection->name);
        $connection->handle = (string)$this->request->getBodyParam('handle', $connection->handle);

        // The carrier cannot change once saved: methods and shipments already speak its services.
        if (!$connection->id) {
            $connection->carrier = (string)$this->request->getBodyParam('carrier', $connection->carrier);
        }

        $connection->enabled = (bool)$this->request->getBodyParam('enabled', false);
        $connection->labelFormat = (string)$this->request->getBodyParam('labelFormat', '');
        $connection->settings = array_merge($connection->settings, (array)$this->request->getBodyParam('settings', []));
        $connection->shipFrom = array_map('strval', (array)$this->request->getBodyParam('shipFrom', []));

        if (!$plugin->getConnections()->save($connection)) {
            $this->setFailFlash(Craft::t('carrier', 'Couldn’t save the connection.'));
            Craft::$app->getUrlManager()->setRouteParams(['connection' => $connection]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('carrier', 'Connection saved.'));

        return $this->redirectToPostedUrl($connection);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $connection = Plugin::getInstance()->getConnections()->getById((int)$this->request->getRequiredBodyParam('id'));

        return $this->asJson(['success' => $connection && Plugin::getInstance()->getConnections()->delete($connection)]);
    }

    public function actionDuplicate(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $source = $plugin->getConnections()->getById((int)$this->request->getRequiredBodyParam('connectionId'));

        if (!$source) {
            throw new NotFoundHttpException();
        }

        $copy = $plugin->getConnections()->duplicate($source);
        $plugin->getConnections()->save($copy, false);
        $this->setSuccessFlash(Craft::t('carrier', 'Copied. Fill in the credentials for the new account.'));

        return $this->redirect('carrier/connections/' . $copy->id);
    }

    /**
     * The test button. Built from saved settings only — testing what is on screen but unsaved
     * would pass a test the live connection then fails.
     */
    public function actionTest(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $connection = Plugin::getInstance()->getConnections()->getById((int)$this->request->getRequiredBodyParam('connectionId'));
        $carrier = $connection?->getCarrier();

        if (!$carrier) {
            return $this->asJson(['ok' => false, 'message' => Craft::t('carrier', 'The add-on for this carrier is not installed.')]);
        }

        $result = $carrier->test();

        return $this->asJson([
            'ok' => $result->ok,
            'message' => $result->message,
            'details' => $result->details,
            'hints' => $result->hints,
            'durationMs' => $result->durationMs,
        ]);
    }

    public function actionSyncPoints(): Response
    {
        $this->requirePostRequest();

        $connection = Plugin::getInstance()->getConnections()->getById((int)$this->request->getRequiredBodyParam('connectionId'));

        if (!$connection) {
            throw new NotFoundHttpException();
        }

        $result = Plugin::getInstance()->getPoints()->sync($connection);

        $result['error'] === null
            ? $this->setSuccessFlash(Craft::t('carrier', '{n, plural, =1{One point} other{# points}} synced.', ['n' => $result['count']]))
            : $this->setFailFlash($result['error']);

        return $this->redirectToPostedUrl();
    }
}
