<?php

namespace App\Services\System;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Exchange rate sync from an HTTPS JSON feed, with the manual Exchange Rates page as the fallback. The feed is configured per company
 * as an integration account (`kind` rates, `provider` http_json): the URL (with `{base}` for the base currency code), an optional
 * bearer token (stored encrypted) and the dotted path to the object of `CODE => rate` in the answer. A rate is "units of the
 * foreign currency for one unit of the base currency", the meaning the Exchange Rates page and the voucher currency columns use.
 *
 * A failed or malformed answer changes nothing: the rates already entered stay, the failure is recorded on the account and shown on
 * the page. Only currencies that exist in the application and are active are written, and only with a rate above zero.
 */
class ExchangeRateSync
{
    /**
     * @return array{ok: bool, updated: int, skipped: int, error: string|null}
     */
    public function run(int $companyId, ?callable $fetch = null): array
    {
        $account = DB::table('integration_accounts')->where('company_id', $companyId)->where('kind', 'rates')->where('provider', 'http_json')->where('is_active', true)->first();

        if ($account === null) {
            return ['ok' => false, 'updated' => 0, 'skipped' => 0, 'error' => 'No rate feed is set up for this company.'];
        }

        $options = (array) json_decode((string) $account->options, true);
        $credentials = $account->credentials === null ? [] : (array) json_decode(Crypt::decryptString((string) $account->credentials), true);
        $baseId = (int) DB::table('company_settings')->where('company_id', $companyId)->value('currency_id');
        $base = $baseId > 0 ? DB::table('currencies')->where('id', $baseId)->value('code') : null;

        if ($base === null) {
            return $this->finish($account, ['ok' => false, 'updated' => 0, 'skipped' => 0, 'error' => 'Set the base currency in Company Settings first.']);
        }

        $answer = $fetch !== null ? $fetch((string) ($options['url'] ?? ''), $credentials, (string) $base, $options) : $this->fetch((string) ($options['url'] ?? ''), $credentials, (string) $base, $options);

        if (! $answer['ok']) {
            return $this->finish($account, ['ok' => false, 'updated' => 0, 'skipped' => 0, 'error' => $answer['error']]);
        }

        $currencies = DB::table('currencies')->where('is_active', true)->whereNull('deleted_at')->where('id', '!=', $baseId)->pluck('id', 'code');
        $updated = 0;
        $skipped = 0;
        $today = now()->toDateString();

        DB::transaction(function () use ($answer, $currencies, $companyId, &$updated, &$skipped, $today): void {
            foreach ($answer['rates'] as $code => $rate) {
                $id = $currencies[strtoupper((string) $code)] ?? null;

                if ($id === null || ! is_numeric($rate) || (float) $rate <= 0 || (float) $rate > 1000000000) {
                    $skipped++;

                    continue;
                }

                $value = round((float) $rate, 6);
                DB::table('currency_rates')->updateOrInsert(['company_id' => $companyId, 'currency_id' => $id], ['rate' => $value, 'updated_at' => now(), 'created_at' => now()]);

                if (DB::getSchemaBuilder()->hasTable('currency_rate_history')) {
                    DB::table('currency_rate_history')->updateOrInsert(
                        ['company_id' => $companyId, 'currency_id' => $id, 'effective_on' => $today],
                        ['rate' => $value, 'updated_at' => now(), 'created_at' => now()],
                    );
                }

                $updated++;
            }
        });

        return $this->finish($account, ['ok' => true, 'updated' => $updated, 'skipped' => $skipped, 'error' => null]);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $options
     * @return array{ok: bool, rates: array<string, mixed>, error: string|null}
     */
    public function fetch(string $url, array $credentials, string $base, array $options): array
    {
        if (! str_starts_with(strtolower($url), 'https://')) {
            return ['ok' => false, 'rates' => [], 'error' => 'The feed address must start with https://.'];
        }

        try {
            $request = Http::acceptJson()->timeout(20);

            if (! empty($credentials['token'])) {
                $request = $request->withToken((string) $credentials['token']);
            }

            $response = $request->get(str_replace('{base}', rawurlencode($base), $url));
        } catch (Throwable $e) {
            return ['ok' => false, 'rates' => [], 'error' => mb_substr($e->getMessage(), 0, 200)];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'rates' => [], 'error' => 'The feed answered HTTP '.$response->status().'.'];
        }

        $rates = data_get($response->json(), (string) ($options['rates_path'] ?? 'rates'));

        if (! is_array($rates) || $rates === []) {
            return ['ok' => false, 'rates' => [], 'error' => 'The answer has no rates at "'.($options['rates_path'] ?? 'rates').'".'];
        }

        return ['ok' => true, 'rates' => $rates, 'error' => null];
    }

    /**
     * @param  array{ok: bool, updated: int, skipped: int, error: string|null}  $result
     * @return array{ok: bool, updated: int, skipped: int, error: string|null}
     */
    private function finish(object $account, array $result): array
    {
        $options = (array) json_decode((string) $account->options, true);
        $options['last_sync_at'] = now()->toDateTimeString();
        $options['last_status'] = $result['ok'] ? "updated {$result['updated']}, skipped {$result['skipped']}" : 'failed: '.$result['error'];

        DB::table('integration_accounts')->where('id', $account->id)->update(['options' => json_encode($options), 'last_used_at' => now(), 'updated_at' => now()]);

        return $result;
    }
}
