<?php

namespace justinholtweb\carrier\models\canonical;

/**
 * One printable document a carrier returned: a parcel's label, a whole consignment's labels on
 * one page, a bill of lading, a commercial invoice.
 */
class Label extends Document
{
    /** Which parcel this belongs to, by index into the request's parcels. Null for all of them. */
    public ?int $parcelIndex = null;

    public string $trackingNumber = '';

    /** `helpers\LabelFormat` value. */
    public string $format = '';

    /** `label`, `bol`, `invoice`, `return`. */
    public string $kind = 'label';

    /** The raw bytes — never base64. Carriers decode what they receive. */
    public string $data = '';

    public function mimeType(): string
    {
        return \justinholtweb\carrier\helpers\LabelFormat::mimeType($this->format);
    }
}
