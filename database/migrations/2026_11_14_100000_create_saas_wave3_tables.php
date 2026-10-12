<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 3, SaaS billing and integrations. All additive: a plan with no feature list gives every feature (as before), a company with
 * no add-ons is invoiced the plan price, and nothing is suspended or deleted unless a rule is configured and a person confirmed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addColumns('subscription_plans', function (Blueprint $table): void {
            $table->json('features')->nullable();
            $table->json('settings')->nullable();
        }, ['features', 'settings']);

        $this->addColumns('companies', function (Blueprint $table): void {
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 190)->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->unsignedBigInteger('partner_id')->nullable()->index();
            $table->string('brand_name', 120)->nullable();
            $table->string('brand_color', 9)->nullable();
            $table->boolean('hide_powered_by')->default(false);
        }, ['suspended_at', 'suspension_reason', 'cancel_at_period_end', 'partner_id', 'brand_name', 'brand_color', 'hide_powered_by']);

        $this->addColumns('subscription_invoices', function (Blueprint $table): void {
            $table->decimal('addons_amount', 15, 2)->default(0);
        }, ['addons_amount']);

        $create = function (string $name, callable $definition): void {
            if (! Schema::hasTable($name)) {
                Schema::create($name, $definition);
            }
        };

        $create('tenant_usage_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->date('day');
            $table->unsignedInteger('users')->default(0);
            $table->unsignedInteger('branches')->default(0);
            $table->unsignedInteger('products')->default(0);
            $table->unsignedInteger('invoices_month')->default(0);
            $table->decimal('storage_mb', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'day']);
        });

        $create('tenant_data_exports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('status', 10)->default('done');
            $table->string('path', 255)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->json('tables')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamps();
        });

        $create('tenant_deletion_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('status', 10)->default('requested')->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->date('scheduled_for')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();
        });

        $create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->string('subject', 160);
            $table->string('category', 30)->default('general');
            $table->string('priority', 8)->default('normal');
            $table->string('status', 10)->default('open')->index();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        $create('support_ticket_messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('support_ticket_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_staff')->default(false);
            $table->text('body');
            $table->timestamps();
        });

        $create('custom_domains', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('domain', 190)->unique();
            $table->string('verification_token', 60);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        $create('partners', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 190)->nullable();
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $create('addons', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 40)->unique();
            $table->decimal('price', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $create('tenant_addons', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('addon_id');
            $table->unsignedInteger('quantity')->default(1);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamps();
        });

        $create('tenant_billing_notices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('subscription_invoice_id');
            $table->string('kind', 20);
            $table->string('status', 10);
            $table->timestamps();
            $table->unique(['subscription_invoice_id', 'kind']);
        });

        $create('integration_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('kind', 12);
            $table->string('provider', 30);
            $table->text('credentials')->nullable();
            $table->json('options')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'kind', 'provider']);
        });

        $create('courier_shipments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('transaction_id')->nullable()->index();
            $table->string('provider', 30);
            $table->string('tracking_no', 60)->nullable();
            $table->string('status', 20)->default('booked');
            $table->json('payload')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        $create('gateway_payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('subscription_invoice_id')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->string('provider', 30);
            $table->string('external_id', 120)->nullable()->index();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 8)->default('PKR');
            $table->string('status', 12)->default('created');
            $table->string('checkout_url', 500)->nullable();
            $table->timestamps();
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function addColumns(string $table, callable $definition, array $columns): void
    {
        if (Schema::hasTable($table) && ! Schema::hasColumn($table, $columns[0])) {
            Schema::table($table, $definition);
        }
    }

    public function down(): void
    {
        foreach (['gateway_payments', 'courier_shipments', 'integration_accounts', 'tenant_billing_notices', 'tenant_addons', 'addons', 'partners', 'custom_domains',
            'support_ticket_messages', 'support_tickets', 'tenant_deletion_requests', 'tenant_data_exports', 'tenant_usage_snapshots'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
