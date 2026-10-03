<?php

namespace justinholtweb\carrier\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\carrier\models\LogEntry;
use justinholtweb\carrier\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The connection log: every request and response, credentials redacted at the transport.
 */
class LogController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('carrier-viewLog');

        return true;
    }

    public function actionIndex(): Response
    {
        $log = Plugin::getInstance()->getLog();
        $connectionId = (int)$this->request->getQueryParam('connection', 0);
        $errorsOnly = (bool)$this->request->getQueryParam('errors', false);
        $page = max(1, (int)$this->request->getQueryParam('page', 1));
        $query = $log->query();

        if ($connectionId) {
            $query->andWhere(['connectionId' => $connectionId]);
        }

        if ($shipmentId = (int)$this->request->getQueryParam('shipment', 0)) {
            $query->andWhere(['shipmentId' => $shipmentId]);
        }

        if ($errorsOnly) {
            $query->andWhere(['or', ['type' => 'error'], ['>=', 'status', 400], ['status' => 0]]);
        }

        $total = (int)(clone $query)->count();

        return $this->renderTemplate('carrier/log/_index', [
            'entries' => array_map(static fn(array $row) => new LogEntry($row), $query->offset(($page - 1) * 100)->limit(100)->all()),
            'total' => $total,
            'page' => $page,
            'pages' => (int)ceil($total / 100),
            'connections' => Plugin::getInstance()->getConnections()->all(),
            'connectionId' => $connectionId,
            'errorsOnly' => $errorsOnly,
        ]);
    }

    public function actionDetail(int $entryId): Response
    {
        $entry = Plugin::getInstance()->getLog()->getById($entryId);

        if (!$entry) {
            throw new NotFoundHttpException();
        }

        return $this->renderTemplate('carrier/log/_detail', [
            'entry' => $entry,
            'connection' => $entry->connectionId ? Plugin::getInstance()->getConnections()->getById($entry->connectionId) : null,
        ]);
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin(false);

        $count = Plugin::getInstance()->getLog()->clear();
        $this->setSuccessFlash(Craft::t('carrier', '{n} log entries cleared.', ['n' => $count]));

        return $this->redirect('carrier/log');
    }
}
