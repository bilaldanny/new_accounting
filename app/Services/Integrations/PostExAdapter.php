<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use Throwable;

class PostExAdapter implements CourierAdapter
{
    private const BASE = 'https://api.postex.pk/services/integration/api/order';

    public function book(array $credentials, array $shipment): array
    {
        $token = (string) ($credentials['token'] ?? '');

        if ($token === '') {
            return ['ok' => false, 'tracking_no' => null, 'status' => null, 'error' => 'Enter the PostEx API token.'];
        }

        try {
            $response = Http::withHeaders(['token' => $token])->acceptJson()->asJson()->timeout(20)->post(self::BASE.'/v3/create-order', [
                'cityName' => (string) $shipment['city'], 'customerName' => (string) $shipment['consignee_name'], 'customerPhone' => (string) $shipment['consignee_phone'],
                'deliveryAddress' => (string) $shipment['consignee_address'], 'invoiceDivision' => 1, 'invoicePayment' => (string) round((float) ($shipment['cod_amount'] ?? 0)),
                'items' => (int) ($shipment['pieces'] ?? 1), 'orderDetail' => (string) ($shipment['description'] ?? ''), 'orderRefNumber' => (string) ($shipment['reference'] ?? ''),
                'orderType' => 'Normal', 'transactionNotes' => '',
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'tracking_no' => null, 'status' => null, 'error' => mb_substr($e->getMessage(), 0, 300)];
        }

        $tracking = $response->json('dist.trackingNumber');

        if ($response->successful() && $tracking !== null && (string) $response->json('statusCode') === '200') {
            return ['ok' => true, 'tracking_no' => (string) $tracking, 'status' => 'booked', 'error' => null];
        }

        return ['ok' => false, 'tracking_no' => null, 'status' => null, 'error' => 'PostEx refused the order: '.mb_substr((string) ($response->json('statusMessage') ?? 'HTTP '.$response->status()), 0, 250)];
    }

    public function track(array $credentials, string $trackingNo): array
    {
        try {
            $response = Http::withHeaders(['token' => (string) ($credentials['token'] ?? '')])->acceptJson()->timeout(20)->get(self::BASE.'/v1/track-order/'.rawurlencode($trackingNo));
        } catch (Throwable $e) {
            return ['ok' => false, 'tracking_no' => $trackingNo, 'status' => null, 'error' => mb_substr($e->getMessage(), 0, 300)];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'tracking_no' => $trackingNo, 'status' => null, 'error' => 'PostEx answered '.$response->status().'.'];
        }

        return ['ok' => true, 'tracking_no' => $trackingNo, 'status' => (string) ($response->json('dist.transactionStatus') ?? 'unknown'), 'error' => null];
    }
}
