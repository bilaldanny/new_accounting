<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A bank feed for banks (or aggregators) that expose an HTTPS JSON endpoint: `GET <endpoint>?from=<date>&to=<date>` with a bearer
 * token, answering `{"transactions": [{"id", "date" (Y-m-d), "description", "amount" (signed; or "credit" / "debit"), "balance"?}]}`.
 * The lines come back in the shape of `BankStatementParser` (`txn_date`, `amount`, `description`, `reference`), so they go through
 * the same reconciliation screen as an uploaded statement; nothing is posted by fetching. Pakistani banks publish no common open
 * banking API, so this is a generic adapter and a bank-specific one is added where a bank gives one.
 */
class HttpJsonBankFeed
{
    /**
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, lines: list<array{txn_date: string, amount: float, description: ?string, reference: ?string}>, error: ?string}
     */
    public function fetch(string $endpoint, array $credentials, string $from, string $to): array
    {
        if (! str_starts_with($endpoint, 'https://')) {
            return ['ok' => false, 'lines' => [], 'error' => 'The feed address must be https.'];
        }

        try {
            $request = Http::acceptJson()->timeout(30);

            if (filled($credentials['token'] ?? null)) {
                $request = $request->withToken((string) $credentials['token']);
            }

            $response = $request->get($endpoint, ['from' => $from, 'to' => $to]);
        } catch (Throwable $e) {
            return ['ok' => false, 'lines' => [], 'error' => mb_substr($e->getMessage(), 0, 300)];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'lines' => [], 'error' => 'The bank feed answered '.$response->status().'.'];
        }

        $lines = [];

        foreach ((array) $response->json('transactions', []) as $row) {
            if (! is_array($row) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', (string) ($row['date'] ?? ''))) {
                continue;
            }

            $amount = isset($row['amount']) ? (float) $row['amount'] : ((float) ($row['credit'] ?? 0) - (float) ($row['debit'] ?? 0));
            $lines[] = [
                'txn_date' => substr((string) $row['date'], 0, 10), 'amount' => round($amount, 2),
                'description' => isset($row['description']) ? mb_substr((string) $row['description'], 0, 255) : null, 'reference' => isset($row['id']) ? (string) $row['id'] : null,
            ];
        }

        return ['ok' => true, 'lines' => $lines, 'error' => null];
    }
}
