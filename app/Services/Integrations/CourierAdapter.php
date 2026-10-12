<?php

namespace App\Services\Integrations;

/**
 * Courier adapters: book a parcel and track it. Two Pakistani couriers (PostEx and Leopards), written from the providers' public
 * API documentation as it stood when this was built. No live merchant account was available, so the request shapes are proven
 * only against recorded fixtures: confirm each field against the courier's current documentation before the first live booking.
 *
 * A shipment is: consignee_name, consignee_phone, consignee_address, city, cod_amount, pieces, weight_grams, reference, description.
 * Every method answers `['ok' => bool, 'tracking_no' => ?string, 'status' => ?string, 'error' => ?string]`, never throws.
 */
interface CourierAdapter
{
    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $shipment
     * @return array{ok: bool, tracking_no: ?string, status: ?string, error: ?string}
     */
    public function book(array $credentials, array $shipment): array;

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, tracking_no: ?string, status: ?string, error: ?string}
     */
    public function track(array $credentials, string $trackingNo): array;
}
