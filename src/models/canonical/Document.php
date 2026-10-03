<?php

namespace justinholtweb\carrier\models\canonical;

/**
 * The base of every canonical document.
 *
 * Deliberately not a Craft `Model`: these are passed to sixteen carriers' translators thousands of
 * times a day, they never validate themselves, and a carrier author should be able to build one
 * with a plain array in a test without bootstrapping Yii's behaviours.
 */
abstract class Document
{
    /**
     * Properties holding a list of documents, so a stored snapshot can be turned back into one:
     * `['parcels' => Parcel::class]`.
     */
    protected const LISTS = [];

    public function __construct(array $values = [])
    {
        foreach ($values as $key => $value) {
            if (!property_exists($this, $key)) {
                continue;
            }

            $this->$key = $this->hydrate($key, $value);
        }
    }

    /**
     * Turn a stored array back into the type the property declares — a nested document, a list
     * of them, or a date. Anything else is assigned as given.
     */
    private function hydrate(string $key, mixed $value): mixed
    {
        if (isset(static::LISTS[$key]) && is_array($value)) {
            $class = static::LISTS[$key];

            return array_values(array_map(
                static fn($item) => is_array($item) ? new $class($item) : $item,
                $value,
            ));
        }

        $type = (new \ReflectionProperty($this, $key))->getType();

        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return $value;
        }

        $class = $type->getName();

        if (is_array($value) && is_subclass_of($class, self::class)) {
            return new $class($value);
        }

        if (is_string($value) && $value !== '' && is_a($class, \DateTimeInterface::class, true)) {
            return new \DateTime($value);
        }

        if ($value === '' && $type->allowsNull()) {
            return null;
        }

        return $value;
    }

    /**
     * A plain array of the public properties, nested documents included. What gets stored as a
     * shipment's request snapshot and shown in the CP's "what was sent" panel.
     */
    public function toArray(): array
    {
        $out = [];

        foreach (get_object_vars($this) as $key => $value) {
            $out[$key] = self::export($value);
        }

        return $out;
    }

    private static function export(mixed $value): mixed
    {
        if ($value instanceof Document) {
            return $value->toArray();
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_array($value)) {
            return array_map(self::export(...), $value);
        }

        return $value;
    }
}
