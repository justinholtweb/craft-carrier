<?php

namespace justinholtweb\carrier\models\canonical;

use DateTime;

/**
 * One scan.
 */
class TrackingEvent extends Document
{
    public ?DateTime $occurredAt = null;

    /** `base\TrackingStatus` value this scan maps to. */
    public string $status = '';

    /** The carrier's own code, kept so a mapping can be corrected later. */
    public string $code = '';

    public string $description = '';

    public string $location = '';

    /**
     * What makes a scan the same scan the next time it is fetched. A webhook and a poll report the
     * same event with different whitespace, so this is built from normalised fields only.
     */
    public function hash(string $trackingNumber = ''): string
    {
        return md5(implode('|', [
            $trackingNumber,
            $this->occurredAt?->getTimestamp() ?? '',
            strtolower(trim($this->code)),
            strtolower(preg_replace('/\s+/', ' ', trim($this->description))),
        ]));
    }
}
