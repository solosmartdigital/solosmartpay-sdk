<?php

namespace Solosmartpay\Models;

/** A QRIS invoice. `qrisImage` / `qrisString` stay null until provisioning is ready and while the invoice is pending. */
final class Qris extends Invoice
{
    public ?string $qrisString = null;

    /** PNG data URI, ready for `<img src>`. */
    public ?string $qrisImage = null;

    public static function fromArray(array $data, ?string $statusUrl = null): static
    {
        $qris = parent::fromArray($data, $statusUrl);
        $qris->qrisString = isset($data['qris_string']) ? (string) $data['qris_string'] : null;
        $qris->qrisImage = isset($data['qris_image']) ? (string) $data['qris_image'] : null;

        return $qris;
    }
}
