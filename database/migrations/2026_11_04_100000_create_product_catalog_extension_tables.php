<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Products & Catalog closeout. Everything is additive and nullable / defaulted so that a company which uses none of it
 * sees exactly what it saw before:
 *
 *  - item_types.behaviour (inventory, non_inventory, service, kit, digital; NULL reads as inventory)
 *  - products: tags, custom_fields, reorder settings, ownership (own / consignment) and the consigning supplier
 *  - product_attribute_definitions, product_barcodes, product_components (kit and BOM lines)
 *  - warranty claims (+ the after-sales rule columns on warranties)
 *  - consignment settlements, product discount rules
 *  - purchase_lines.opening_cost: the unit cost an Opening Stock screen entry carries, which the average cost reads
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_types', function (Blueprint $table): void {
            if (! Schema::hasColumn('item_types', 'behaviour')) {
                $table->string('behaviour', 20)->nullable();
            }
        });

        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'tags')) {
                $table->json('tags')->nullable();
                $table->json('custom_fields')->nullable();
                $table->decimal('reorder_level', 15, 4)->nullable();
                $table->decimal('reorder_qty', 15, 4)->nullable();
                $table->decimal('min_stock', 15, 4)->nullable();
                $table->decimal('max_stock', 15, 4)->nullable();
                $table->unsignedSmallInteger('lead_time_days')->nullable();
                $table->string('ownership', 20)->default('own');
                $table->unsignedBigInteger('consignment_supplier_id')->nullable();
            }
        });

        Schema::table('warranties', function (Blueprint $table): void {
            if (! Schema::hasColumn('warranties', 'require_invoice')) {
                $table->boolean('require_invoice')->default(false);
                $table->unsignedSmallInteger('max_claims')->nullable();
                $table->text('terms')->nullable();
            }
        });

        Schema::table('purchase_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_lines', 'opening_cost')) {
                $table->decimal('opening_cost', 15, 4)->nullable();
            }
        });

        if (! Schema::hasTable('product_attribute_definitions')) {
            Schema::create('product_attribute_definitions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('name', 100);
                $table->string('field_key', 60);
                $table->string('type', 20)->default('text');
                $table->json('options')->nullable();
                $table->boolean('is_required')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['company_id', 'field_key']);
            });
        }

        if (! Schema::hasTable('product_barcodes')) {
            Schema::create('product_barcodes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('variation_id')->nullable();
                $table->string('barcode', 100);
                $table->string('label', 100)->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'barcode']);
            });
        }

        if (! Schema::hasTable('product_components')) {
            Schema::create('product_components', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('component_product_id')->index();
                $table->unsignedBigInteger('component_variation_id')->nullable();
                $table->decimal('quantity', 15, 4);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('warranty_claims')) {
            Schema::create('warranty_claims', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('claim_no', 40);
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('variation_id')->nullable();
                $table->unsignedBigInteger('contact_id')->nullable();
                $table->unsignedBigInteger('transaction_id')->nullable();
                $table->string('serial_no', 100)->nullable();
                $table->text('issue');
                $table->date('claimed_on');
                $table->date('warranty_expires_on')->nullable();
                $table->boolean('in_warranty')->default(true);
                $table->string('status', 20)->default('open');
                $table->string('resolution', 20)->nullable();
                $table->text('resolution_note')->nullable();
                $table->date('resolved_on')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'claim_no']);
            });
        }

        if (! Schema::hasTable('consignment_settlements')) {
            Schema::create('consignment_settlements', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('supplier_id')->index();
                $table->string('settlement_no', 40);
                $table->date('period_from');
                $table->date('period_to');
                $table->string('status', 20)->default('draft');
                $table->decimal('total_amount', 15, 2)->default(0);
                $table->string('reference', 100)->nullable();
                $table->text('note')->nullable();
                $table->date('settled_on')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'settlement_no']);
            });

            Schema::create('consignment_settlement_lines', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('consignment_settlement_id')->index();
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('variation_id')->nullable();
                $table->decimal('quantity_sold', 15, 4);
                $table->decimal('unit_cost', 15, 4);
                $table->decimal('amount', 15, 2);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('product_discount_rules')) {
            Schema::create('product_discount_rules', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('name', 150);
                $table->string('scope', 20);
                $table->unsignedBigInteger('product_id')->nullable();
                $table->unsignedBigInteger('category_id')->nullable();
                $table->unsignedBigInteger('brand_id')->nullable();
                $table->string('discount_type', 10)->default('percent');
                $table->decimal('value', 15, 4);
                $table->decimal('min_quantity', 15, 4)->nullable();
                $table->date('starts_on')->nullable();
                $table->date('ends_on')->nullable();
                $table->unsignedSmallInteger('priority')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (['product_discount_rules', 'consignment_settlement_lines', 'consignment_settlements', 'warranty_claims', 'product_components', 'product_barcodes', 'product_attribute_definitions'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('purchase_lines', function (Blueprint $table): void {
            if (Schema::hasColumn('purchase_lines', 'opening_cost')) {
                $table->dropColumn('opening_cost');
            }
        });

        Schema::table('warranties', function (Blueprint $table): void {
            if (Schema::hasColumn('warranties', 'require_invoice')) {
                $table->dropColumn(['require_invoice', 'max_claims', 'terms']);
            }
        });

        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'tags')) {
                $table->dropColumn(['tags', 'custom_fields', 'reorder_level', 'reorder_qty', 'min_stock', 'max_stock', 'lead_time_days', 'ownership', 'consignment_supplier_id']);
            }
        });

        Schema::table('item_types', function (Blueprint $table): void {
            if (Schema::hasColumn('item_types', 'behaviour')) {
                $table->dropColumn('behaviour');
            }
        });
    }
};
