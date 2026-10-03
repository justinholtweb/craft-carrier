<?php

namespace justinholtweb\carrier\controllers;

use Craft;
use craft\web\Controller;
use craft\web\UploadedFile;
use justinholtweb\carrier\models\canonical\PickupPoint;
use justinholtweb\carrier\models\canonical\PointQuery;
use justinholtweb\carrier\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Pickup points: the storefront's search and select endpoints, and the CP's browse and import.
 */
class PointsController extends Controller
{
    protected array|bool|int $allowAnonymous = ['search', 'select'];

    // =========================================================================
    // Storefront
    // =========================================================================

    /**
     * `GET actions/carrier/points/search?method=boxnow-locker&postcode=10000` (or `lat`/`lng`).
     *
     * Anonymous, read-only, and cached per query. It only ever answers for an enabled method that
     * requires a point, so it cannot be used to enumerate arbitrary connections.
     */
    public function actionSearch(): Response
    {
        $this->requireAcceptsJson();

        $plugin = Plugin::getInstance();
        $method = $plugin->getMethods()->getByHandle((string)$this->request->getRequiredParam('method'));

        if (!$method || !$method->enabled || !$method->requiresPoint || !($connection = $method->getConnection()) || !$connection->enabled) {
            throw new NotFoundHttpException('No such method.');
        }

        $cart = $this->cart();
        $address = $cart?->getShippingAddress();
        $lat = $this->request->getParam('lat');
        $lng = $this->request->getParam('lng');

        $query = new PointQuery([
            'countryCode' => strtoupper((string)$this->request->getParam('country', $address?->countryCode ?? '')),
            'postcode' => mb_substr(trim((string)$this->request->getParam('postcode', $address?->postalCode ?? '')), 0, 16),
            'city' => mb_substr(trim((string)$this->request->getParam('city', $address?->locality ?? '')), 0, 64),
            'latitude' => is_numeric($lat) ? max(-90, min(90, (float)$lat)) : null,
            'longitude' => is_numeric($lng) ? max(-180, min(180, (float)$lng)) : null,
            'radiusKm' => max(1, min(50, (int)$this->request->getParam('radius', 10))),
            'limit' => max(1, min(50, (int)$this->request->getParam('limit', 20))),
        ]);

        $points = $plugin->getPoints()->search($connection, $query);

        // Respect the cart: a 25 kg order cannot go to a 20 kg locker, a COD order to a no-cash one.
        if ($cart) {
            $weightG = array_sum(array_column($plugin->getPacker()->units($cart), 'weightG'));
            $cod = $plugin->getOrders()->isCod($cart);
            $points = array_values(array_filter($points, static fn(PickupPoint $p) =>
                ($p->maxWeightG === 0 || $weightG <= $p->maxWeightG) && (!$cod || $p->codAllowed)));
        }

        $plugin->getSelections()->rememberSearch($connection->id, $points);
        $selection = $cart?->id ? $plugin->getSelections()->getForOrder($cart->id) : null;

        return $this->asJson([
            'points' => array_map(static fn(PickupPoint $p) => [
                'id' => $p->id,
                'type' => $p->type,
                'name' => $p->name,
                'street' => $p->street,
                'city' => $p->city,
                'postcode' => $p->postcode,
                'countryCode' => $p->countryCode,
                'latitude' => $p->latitude,
                'longitude' => $p->longitude,
                'hours' => $p->hours,
                'distanceKm' => $p->distanceKm,
                'label' => $p->label(),
            ], $points),
            'selected' => $selection?->pointId,
        ]);
    }

    /**
     * `POST actions/carrier/points/select` with `method` and `pointId`.
     *
     * Acts on the **current session's cart only** — never an order id from the request, which
     * would let anyone change where somebody else's parcel goes.
     */
    public function actionSelect(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $cart = $this->cart();
        $method = $plugin->getMethods()->getByHandle((string)$this->request->getRequiredBodyParam('method'));
        $pointId = trim((string)$this->request->getRequiredBodyParam('pointId'));

        if (!$cart || !$cart->id) {
            throw new BadRequestHttpException('There is no cart.');
        }

        $selection = $method && $method->enabled ? $plugin->getSelections()->select($cart, $method, $pointId) : null;

        if (!$selection) {
            $error = Craft::t('carrier', 'That pickup point is not available.');

            return $this->request->getAcceptsJson()
                ? $this->asJson(['success' => false, 'error' => $error])->setStatusCode(400)
                : $this->asFailure($error);
        }

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'pointId' => $selection->pointId, 'label' => $selection->getLabel()]);
        }

        return $this->asSuccess(Craft::t('carrier', 'Pickup point saved.'));
    }

    private function cart(): ?\craft\commerce\elements\Order
    {
        try {
            // `getCart()` would create one for a bot; only look at a cart that already exists.
            return \craft\commerce\Plugin::getInstance()->getCarts()->peekCart();
        } catch (\Throwable) {
            return null;
        }
    }

    // =========================================================================
    // Control panel
    // =========================================================================

    public function actionIndex(): Response
    {
        $this->requirePermission('carrier-manageConnections');

        $plugin = Plugin::getInstance();
        $connectionId = (int)$this->request->getQueryParam('connection', 0);
        $search = trim((string)$this->request->getQueryParam('q', ''));
        $page = max(1, (int)$this->request->getQueryParam('page', 1));
        $query = $plugin->getPoints()->query();

        if ($connectionId) {
            $query->andWhere(['connectionId' => $connectionId]);
        }

        if ($search !== '') {
            $like = str_replace(['%', '_'], ['\%', '\_'], $search);
            $query->andWhere(['or', ['like', 'name', $like], ['like', 'city', $like], ['like', 'postcode', $like . '%', false], ['pointId' => $search]]);
        }

        $total = (int)(clone $query)->count();

        return $this->renderTemplate('carrier/points/_index', [
            'points' => $query->offset(($page - 1) * 100)->limit(100)->all(),
            'total' => $total,
            'page' => $page,
            'pages' => (int)ceil($total / 100),
            'connections' => $plugin->getConnections()->withFeature(\justinholtweb\carrier\base\Feature::POINTS),
            'connectionId' => $connectionId,
            'search' => $search,
        ]);
    }

    public function actionImport(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('carrier-manageConnections');

        $plugin = Plugin::getInstance();
        $connection = $plugin->getConnections()->getById((int)$this->request->getRequiredBodyParam('connectionId'));
        $file = UploadedFile::getInstanceByName('csv');

        if (!$connection || !$file) {
            $this->setFailFlash(Craft::t('carrier', 'Choose a connection and a CSV file.'));

            return $this->redirectToPostedUrl();
        }

        $result = $plugin->getPoints()->importCsv($connection, (string)file_get_contents($file->tempName), (bool)$this->request->getBodyParam('replace'));

        if ($result['imported'] > 0) {
            $this->setSuccessFlash(Craft::t('carrier', '{n, plural, =1{One point} other{# points}} imported.', ['n' => $result['imported']]) . ($result['errors'] ? ' ' . implode(' ', array_slice($result['errors'], 0, 3)) : ''));
        } else {
            $this->setFailFlash(implode(' ', array_slice($result['errors'], 0, 5)) ?: Craft::t('carrier', 'Nothing was imported.'));
        }

        return $this->redirectToPostedUrl();
    }

    public function actionToggle(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('carrier-manageConnections');

        Plugin::getInstance()->getPoints()->setEnabled((int)$this->request->getRequiredBodyParam('id'), (bool)$this->request->getBodyParam('enabled'));

        return $this->redirectToPostedUrl();
    }
}
