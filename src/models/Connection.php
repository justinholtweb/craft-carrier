<?php

namespace justinholtweb\carrier\models;

use Craft;
use craft\base\Model;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;
use DateTime;
use justinholtweb\carrier\base\CarrierInterface;
use justinholtweb\carrier\base\Field;
use justinholtweb\carrier\models\canonical\Address;
use justinholtweb\carrier\Plugin;
use justinholtweb\carrier\records\ConnectionRecord;

/**
 * One carrier account.
 *
 * A store can have several of the same carrier — a live UPS account and a sandbox one, or GLS
 * Croatia and GLS Slovenia — so everything is scoped to a connection, never to a carrier.
 */
class Connection extends Model
{
    public ?int $id = null;

    public string $name = '';

    public string $handle = '';

    /** The carrier handle — `ups`, `gls`, `boxnow`, `freight-odfl`… */
    public string $carrier = '';

    public bool $enabled = false;

    /** `helpers\LabelFormat` value; empty means the carrier's first format. */
    public string $labelFormat = '';

    /** Credentials and options, keyed by the carrier's own field names. */
    public array $settings = [];

    /** Where this account ships from, when it is not the store's location. */
    public array $shipFrom = [];

    /** OAuth refresh tokens and the like. Never rendered. */
    public array $tokens = [];

    public ?DateTime $pointsSyncedAt = null;

    /** An asset shown next to this connection's methods at checkout, unless a method has its own. */
    public ?int $logoId = null;

    public ?int $sortOrder = null;

    public ?DateTime $dateCreated = null;

    public ?DateTime $dateUpdated = null;

    public ?string $uid = null;

    public function __construct($config = [])
    {
        foreach (['settings', 'shipFrom', 'tokens'] as $key) {
            if (array_key_exists($key, $config) && !is_array($config[$key])) {
                $config[$key] = json_decode((string)$config[$key], true) ?: [];
            }
        }

        $config['labelFormat'] = (string)($config['labelFormat'] ?? '');

        if (array_key_exists('logoId', $config)) {
            $config['logoId'] = (int)$config['logoId'] ?: null;
        }

        parent::__construct($config);
    }

    protected function defineRules(): array
    {
        return [
            [['name', 'handle', 'carrier'], 'required'],
            [['name', 'handle', 'carrier'], 'string', 'max' => 255],
            [['handle'], HandleValidator::class, 'reservedWords' => ['id', 'uid', 'new']],
            [['handle'], UniqueValidator::class, 'targetClass' => ConnectionRecord::class],
            [['enabled'], 'boolean'],
            [['logoId'], 'integer'],
            [['carrier'], 'validateCarrier'],
            [['labelFormat'], 'validateLabelFormat'],
            [['settings'], 'validateSettings'],
        ];
    }

    public function validateCarrier(string $attribute): void
    {
        if ($this->carrier !== '' && !Plugin::getInstance()->getCarriers()->has($this->carrier)) {
            $this->addError($attribute, Craft::t('carrier', 'No carrier is installed for “{handle}”. Install its add-on plugin, or pick another.', [
                'handle' => $this->carrier,
            ]));
        }
    }

    public function validateLabelFormat(string $attribute): void
    {
        $class = $this->getCarrierClass();

        if ($this->labelFormat !== '' && $class && !$class::capabilities()->supportsLabelFormat($this->labelFormat)) {
            $this->addError($attribute, Craft::t('carrier', '{carrier} cannot produce that label format.', [
                'carrier' => $class::displayName(),
            ]));
        }
    }

    /**
     * Required credentials are enforced only when the connection is switched on, so a merchant
     * can save half a setup and come back with the missing key.
     *
     * Only the fields every feature needs block enabling. A field marked `requiredFor` (say,
     * label credentials on a carrier whose point feed is public) is checked when that feature is
     * used instead, so a shop can run the locker picker before its contract is signed.
     */
    public function validateSettings(): void
    {
        if (!$this->enabled || !$this->getCarrierClass()) {
            return;
        }

        foreach ($this->missingCredentials() as $label) {
            $this->addError('settings', Craft::t('carrier', '“{field}” is required before this connection can be enabled.', [
                'field' => $label,
            ]));
        }
    }

    /**
     * The labels of required settings that are still blank.
     *
     * With no feature, only the fields every feature needs. With one, also the fields marked as
     * needed for it.
     *
     * @return string[]
     */
    public function missingCredentials(?string $feature = null): array
    {
        $class = $this->getCarrierClass();
        $missing = [];

        foreach ($class ? $class::settingsFields() : [] as $field) {
            $name = $field['name'] ?? '';

            if ($name === '' || !Field::isRequiredFor($field, $feature)) {
                continue;
            }

            if (trim((string)($this->settings[$name] ?? '')) === '') {
                $missing[] = (string)($field['label'] ?? $name);
            }
        }

        return $missing;
    }

    /**
     * Whether this connection can do something right now: its carrier declares the feature and
     * every credential the feature needs is filled in.
     */
    public function can(string $feature): bool
    {
        $class = $this->getCarrierClass();

        return $class !== null
            && $class::capabilities()->has($feature)
            && $this->missingCredentials($feature) === [];
    }

    /**
     * The sentence a merchant sees when a feature is unavailable for want of credentials, or null.
     */
    public function credentialsError(string $feature): ?string
    {
        $missing = $this->missingCredentials($feature);

        if ($missing === []) {
            return null;
        }

        return Craft::t('carrier', '{connection} needs {fields} before it can be used for {feature}.', [
            'connection' => $this->name ?: $this->handle,
            'fields' => implode(', ', array_map(static fn($l) => '“' . $l . '”', $missing)),
            'feature' => mb_strtolower(\justinholtweb\carrier\base\Feature::label($feature)),
        ]);
    }

    public function getLogo(): ?\craft\elements\Asset
    {
        return $this->logoId ? \craft\elements\Asset::find()->id($this->logoId)->status(null)->one() : null;
    }

    /**
     * @return class-string<CarrierInterface>|null
     */
    public function getCarrierClass(): ?string
    {
        return Plugin::getInstance()->getCarriers()->classFor($this->carrier);
    }

    /**
     * The carrier bound to this connection, or null if its add-on is not installed. A fresh
     * instance each call, so a test double on one cannot leak into another.
     */
    public function getCarrier(): ?CarrierInterface
    {
        return Plugin::getInstance()->getCarriers()->create($this);
    }

    public function getCarrierName(): string
    {
        $class = $this->getCarrierClass();

        return $class ? $class::displayName() : $this->carrier;
    }

    /**
     * A setting, with `$ENV_VAR` resolved. Every read goes through here so an environment
     * variable works everywhere a literal does.
     */
    public function getSetting(string $name, mixed $default = null): mixed
    {
        $value = $this->settings[$name] ?? $default;

        if (is_string($value) && $value !== '') {
            return \craft\helpers\App::parseEnv($value);
        }

        return $value;
    }

    public function setSetting(string $name, mixed $value): void
    {
        $this->settings[$name] = $value;
    }

    public function isSandbox(): bool
    {
        return $this->getSetting('environment', 'production') === 'sandbox';
    }

    /**
     * The ship-from override as a canonical address, or null to use the store's location.
     */
    public function getShipFromAddress(): ?Address
    {
        $values = array_filter($this->shipFrom, static fn($v) => $v !== '' && $v !== null);

        if (empty($values['street1']) || empty($values['countryCode'])) {
            return null;
        }

        return new Address(array_map(
            static fn($v) => is_string($v) ? \craft\helpers\App::parseEnv($v) : $v,
            $values,
        ));
    }

    /**
     * Safe to log, export or paste into a support ticket.
     */
    public function toSafeArray(): array
    {
        $class = $this->getCarrierClass();
        $settings = $this->settings;

        foreach ($class ? Field::secretNames($class::settingsFields()) : [] as $name) {
            if (($settings[$name] ?? '') !== '' && !str_starts_with((string)$settings[$name], '$')) {
                $settings[$name] = '••••••••';
            }
        }

        return [
            'name' => $this->name,
            'handle' => $this->handle,
            'carrier' => $this->carrier,
            'enabled' => $this->enabled,
            'labelFormat' => $this->labelFormat,
            'settings' => $settings,
            'shipFrom' => $this->shipFrom,
        ];
    }

    public function getCpEditUrl(): string
    {
        return \craft\helpers\UrlHelper::cpUrl('carrier/connections/' . $this->id);
    }

    public function __toString(): string
    {
        return $this->name ?: $this->handle;
    }
}
