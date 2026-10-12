<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use Throwable;

class LeopardsAdapter implements CourierAdapter
{
    private const BASE = 'https://merchantapi.leopardscourier.com/api';

    public function book(array $credentials, array $shipment): array
    {
        if (blank($credentials['api_key'] ?? null) || blank($credentials['api_password'] ?? null)) {
            return ['ok' => false, 'tracking_no' => null, 'status' => null, 'error' => 'Enter the Leopards API key and password.'];
        }

        try {
            $response = Http::acceptJson()->asForm()->timeout(25)->post(self::BASE.'/bookPacket/format/json/', [
                'api_key' => $credentials['api_key'], 'api_password' => $credentials['api_password'],
                'booked_packet_weight' => (int) ($shipment['weight_grams'] ?? 500), 'booked_packet_no_piece' => (int) ($shipment['pieces'] ?? 1),
                'booked_packet_collect_amount' => (float) ($shipment['cod_amount'] ?? 0), 'booked_packet_order_id' => (string) ($shipment['reference'] ?? ''),
                'origin_city' => 'self', 'destination_city' => (string) $shipment['city'],
                'shipment_name_eng' => 'self', 'shipment_email' => 'self', 'shipment_phone' => 'self', 'shipment_address' => 'self',
                'consignment_name_eng' => (string) $shipment['consignee_name'], 'consignment_email' => '', 'consignment_phone' => (string) $shipment['consignee_phone'],
                'consignment_address' => (string) $shipment['consignee_address'], 'special_instructions' => (string) ($shipment['description'] ?? ''),
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'tracking_no' => null, 'status' => null, 'error' => mb_substr($e->getMessage(), 0, 300)];
        }

        if ($response->successful() && (int) $response->json('status') === 1 && $response->json('track_number') !== null) {
            return ['ok' => true, 'tracking_no' => (string) $response->json('track_number'), 'status' => 'booked', 'error' => null];
        }

        return ['ok' => false, 'tracking_no' => null, 'status' => null, 'error' => 'Leopards refused the packet: '.mb_substr((string) ($response->json('error') ?: $response->json('message') ?: 'HTTP '.$response->status()), 0, 250)];
    }

    public function track(array $credentials, string $trackingNo): array
    {
        try {
            $response = Http::acceptJson()->asForm()->timeout(25)->post(self::BASE.'/trackBookedPacket/format/json/', [
                'api_key' => $credentials['api_key'] ?? '', 'api_password' => $credentials['api_password'] ?? '', 'track_numbers' => $trackingNo,
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'tracking_no' => $trackingNo, 'status' => null, 'error' => mb_substr($e->getMessage(), 0, 300)];
        }

        $packet = $response->json('packet_list.0');

        if (! $response->successful() || (int) $response->json('status') !== 1 || ! is_array($packet)) {
            return ['ok' => false, 'tracking_no' => $trackingNo, 'status' => null, 'error' => 'Leopards could not find this packet.'];
        }

        return ['ok' => true, 'tracking_no' => $trackingNo, 'status' => (string) ($packet['booked_packet_status'] ?? 'unknown'), 'error' => null];
    }
}
