<?php

namespace justinholtweb\carrier\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use justinholtweb\carrier\db\Table;
use justinholtweb\carrier\events\MethodEvent;
use justinholtweb\carrier\models\Method;
use justinholtweb\carrier\records\MethodRecord;

/**
 * The checkout shipping methods Carrier contributes to Commerce.
 */
class Methods extends Component
{
    /** @event MethodEvent before a method is saved; set `isValid` false to stop it */
    public const EVENT_BEFORE_SAVE_METHOD = 'beforeSaveMethod';

    /** @event MethodEvent after a method is saved */
    public const EVENT_AFTER_SAVE_METHOD = 'afterSaveMethod';

    /** @var array<int,Method>|null */
    private ?array $_methods = null;

    /**
     * @return Method[]
     */
    public function all(): array
    {
        if ($this->_methods !== null) {
            return $this->_methods;
        }

        $methods = [];

        foreach ((new Query())->from(Table::METHODS)->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])->all() as $row) {
            $method = new Method($row);
            $methods[$method->id] = $method;
        }

        return $this->_methods = $methods;
    }

    public function getById(int $id): ?Method
    {
        return $this->all()[$id] ?? null;
    }

    public function getByHandle(string $handle): ?Method
    {
        foreach ($this->all() as $method) {
            if ($method->handle === $handle) {
                return $method;
            }
        }

        return null;
    }

    /**
     * Find the method behind a Commerce `shippingMethodHandle`, which Carrier prefixes.
     */
    public function getByShippingMethodHandle(?string $handle): ?Method
    {
        if ($handle === null || !str_starts_with($handle, 'carrier-')) {
            return null;
        }

        return $this->getByHandle(substr($handle, 8));
    }

    /**
     * Methods that can appear at checkout for a store: enabled, on an enabled connection whose
     * add-on is installed.
     *
     * @return Method[]
     */
    public function enabledForStore(?int $storeId): array
    {
        return array_values(array_filter($this->all(), static function(Method $method) use ($storeId) {
            if (!$method->enabled || ($method->storeId !== null && $storeId !== null && $method->storeId !== $storeId)) {
                return false;
            }

            $connection = $method->getConnection();

            return $connection && $connection->enabled && $connection->getCarrierClass() !== null;
        }));
    }

    /**
     * @return Method[]
     */
    public function forConnection(int $connectionId): array
    {
        return array_values(array_filter($this->all(), static fn(Method $m) => $m->connectionId === $connectionId));
    }

    public function save(Method $method, bool $runValidation = true): bool
    {
        $event = new MethodEvent(['method' => $method, 'isNew' => !$method->id]);
        $this->trigger(self::EVENT_BEFORE_SAVE_METHOD, $event);

        if (!$event->isValid || ($runValidation && !$method->validate())) {
            return false;
        }

        $record = $method->id ? MethodRecord::findOne($method->id) : new MethodRecord();

        if (!$record) {
            throw new \InvalidArgumentException("No method with the id “{$method->id}”.");
        }

        $record->setAttributes([
            'storeId' => $method->storeId,
            'connectionId' => $method->connectionId,
            'name' => $method->name,
            'handle' => $method->handle,
            'serviceCode' => $method->serviceCode,
            'requiresPoint' => $method->requiresPoint,
            'pricing' => json_encode($method->pricing),
            'conditions' => json_encode($method->conditions),
            'packing' => json_encode($method->packing),
            'enabled' => $method->enabled,
        ], false);

        if (!$method->id) {
            $record->setAttribute('sortOrder', (int)(new Query())->from(Table::METHODS)->max('[[sortOrder]]') + 1);
        }

        $record->save(false);

        $method->id = $record->getAttribute('id');
        $method->uid = $record->getAttribute('uid');
        $this->_methods = null;

        $this->trigger(self::EVENT_AFTER_SAVE_METHOD, $event);

        return true;
    }

    public function delete(Method $method): bool
    {
        if (!$method->id) {
            return false;
        }

        Db::delete(Table::METHODS, ['id' => $method->id]);
        $this->_methods = null;

        return true;
    }

    public function reorder(array $ids): void
    {
        foreach (array_values($ids) as $order => $id) {
            Db::update(Table::METHODS, ['sortOrder' => $order + 1], ['id' => (int)$id]);
        }

        $this->_methods = null;
    }

    public function clearCache(): void
    {
        $this->_methods = null;
    }
}
