<?php

namespace justinholtweb\carrier\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\carrier\Plugin;
use Throwable;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Tracking pushes from carriers. Verification is the carrier's job (signatures differ for every
 * one of them); this controller only routes to the right connection and never says why a push was
 * refused beyond a status code.
 */
class WebhookController extends Controller
{
    protected array|bool|int $allowAnonymous = ['receive'];

    public $enableCsrfValidation = false;

    public function actionReceive(string $connectionHandle): Response
    {
        $this->requirePostRequest();

        $connection = Plugin::getInstance()->getConnections()->getByHandle($connectionHandle);

        if (!$connection || !$connection->enabled) {
            throw new NotFoundHttpException();
        }

        try {
            $touched = Plugin::getInstance()->getTracking()->webhook($connection, $this->request);
        } catch (ForbiddenHttpException|NotFoundHttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            Plugin::getInstance()->getLog()->error($connection, 'Webhook failed: ' . $e->getMessage());
            Craft::$app->getResponse()->setStatusCode(500);

            return $this->asJson(['ok' => false]);
        }

        return $this->asJson(['ok' => true, 'updated' => $touched]);
    }
}
