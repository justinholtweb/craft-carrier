<?php

namespace justinholtweb\carrier\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\carrier\models\Method;
use justinholtweb\carrier\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Checkout methods: what the customer chooses between, and how each is priced.
 */
class MethodsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('carrier-manageMethods');

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('carrier/methods/_index', [
            'methods' => Plugin::getInstance()->getMethods()->all(),
            'connections' => Plugin::getInstance()->getConnections()->all(),
        ]);
    }

    public function actionEdit(?int $methodId = null, ?Method $method = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($method === null) {
            $method = $methodId ? $plugin->getMethods()->getById($methodId) : new Method([
                'connectionId' => (int)$this->request->getQueryParam('connectionId') ?: null,
                'pricing' => ['mode' => Method::PRICING_FLAT],
            ]);
        }

        if (!$method) {
            throw new NotFoundHttpException('No such method.');
        }

        $carriers = [];

        foreach ($plugin->getConnections()->all() as $connection) {
            $class = $connection->getCarrierClass();

            if ($class) {
                $carriers[$connection->id] = $plugin->getCarriers()->describeClass($class);
            }
        }

        $commerce = \craft\commerce\Plugin::getInstance();
        $stores = [];

        foreach ($commerce->getStores()->getAllStores() as $store) {
            $stores[] = ['value' => $store->id, 'label' => $store->getName()];
        }

        return $this->renderTemplate('carrier/methods/_edit', [
            'method' => $method,
            'connections' => $plugin->getConnections()->all(),
            'carriers' => $carriers,
            'stores' => $stores,
            'weightUnit' => $commerce->getSettings()->weightUnits,
            'accessorialLabels' => \justinholtweb\carrier\base\Accessorial::labels(),
            'currency' => $commerce->getStores()->getPrimaryStore()->getCurrency()->getCode(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $id = $this->request->getBodyParam('methodId');
        $method = $id ? $plugin->getMethods()->getById((int)$id) : new Method();

        if (!$method) {
            throw new NotFoundHttpException('No such method.');
        }

        $method->name = (string)$this->request->getBodyParam('name');
        $method->handle = (string)$this->request->getBodyParam('handle');
        $method->connectionId = (int)$this->request->getBodyParam('connectionId') ?: null;
        $method->storeId = (int)$this->request->getBodyParam('storeId') ?: null;
        $method->serviceCode = (string)$this->request->getBodyParam('serviceCode', '');
        $method->requiresPoint = (bool)$this->request->getBodyParam('requiresPoint');
        $method->enabled = (bool)$this->request->getBodyParam('enabled');

        $pricing = (array)$this->request->getBodyParam('pricing', []);
        $pricing['table'] = array_values(array_filter(
            (array)($pricing['table'] ?? []),
            static fn($row) => is_array($row) && ($row['maxWeight'] ?? '') !== '',
        ));
        $method->pricing = $pricing;

        $conditions = (array)$this->request->getBodyParam('conditions', []);

        foreach (['countries', 'excludeCountries'] as $key) {
            $value = $conditions[$key] ?? [];
            $conditions[$key] = is_array($value)
                ? array_values(array_filter($value))
                : array_values(array_filter(array_map('trim', explode(',', strtoupper((string)$value)))));
        }

        $method->conditions = $conditions;
        $method->packing = (array)$this->request->getBodyParam('packing', []);

        if (!$plugin->getMethods()->save($method)) {
            $this->setFailFlash(Craft::t('carrier', 'Couldn’t save the method.'));
            Craft::$app->getUrlManager()->setRouteParams(['method' => $method]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('carrier', 'Method saved.'));

        return $this->redirectToPostedUrl($method);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $method = Plugin::getInstance()->getMethods()->getById((int)$this->request->getRequiredBodyParam('id'));

        return $this->asJson(['success' => $method && Plugin::getInstance()->getMethods()->delete($method)]);
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        Plugin::getInstance()->getMethods()->reorder(json_decode((string)$this->request->getRequiredBodyParam('ids'), true) ?: []);

        return $this->asJson(['success' => true]);
    }
}
