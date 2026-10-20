<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addPartnersColumns();
        $this->addOrderItemColumns();
        $this->addConnectionColumns();
        $this->addWithdrawalColumn();
        $this->createTables();
    }

    public function down(): void
    {
        foreach ([
            'partner_webhook_deliveries',
            'partner_webhook_events',
            'partner_webhook_endpoints',
            'partner_api_keys',
            'partner_idempotency_keys',
            'partner_order_attachments',
            'partner_order_messages',
            'partner_order_corrections',
            'partner_order_disputes',
            'partner_link_incidents',
            'partner_ledger_entries',
            'partner_ledger_transactions',
            'partner_ledger_balances',
            'partner_ledger_accounts',
            'partner_clawbacks',
            'partner_statements',
            'partner_payout_mode_changes',
            'partner_payout_mode_acceptances',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function addPartnersColumns(): void
    {
        if (! Schema::hasTable('partners')) {
            return;
        }
        Schema::table('partners', function (Blueprint $table) {
            if (! Schema::hasColumn('partners', 'payout_mode')) {
                $table->string('payout_mode', 20)->default('held');
            }
            if (! Schema::hasColumn('partners', 'payout_mode_effective_from')) {
                $table->timestamp('payout_mode_effective_from')->nullable();
            }
            if (! Schema::hasColumn('partners', 'payout_mode_requested')) {
                $table->string('payout_mode_requested', 20)->nullable();
            }
            if (! Schema::hasColumn('partners', 'instant_exposure_cap_minor')) {
                $table->unsignedInteger('instant_exposure_cap_minor')->default(50000);
            }
            if (! Schema::hasColumn('partners', 'keep_live_months')) {
                $table->unsignedTinyInteger('keep_live_months')->default(12);
            }
            if (! Schema::hasColumn('partners', 'fulfilment_channel_default')) {
                $table->string('fulfilment_channel_default', 32)->default('dashboard');
            }
            if (! Schema::hasColumn('partners', 'public_id')) {
                $table->string('public_id', 32)->nullable();
            }
        });
    }

    private function addOrderItemColumns(): void
    {
        if (! Schema::hasTable('order_items')) {
            return;
        }
        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'hold_until')) {
                $table->timestamp('hold_until')->nullable();
            }
            if (! Schema::hasColumn('order_items', 'keep_live_until')) {
                $table->timestamp('keep_live_until')->nullable();
            }
            if (! Schema::hasColumn('order_items', 'payout_mode_at_submission')) {
                $table->string('payout_mode_at_submission', 20)->nullable();
            }
            if (! Schema::hasColumn('order_items', 'first_submitted_at')) {
                $table->timestamp('first_submitted_at')->nullable();
            }
            if (! Schema::hasColumn('order_items', 'partner_corrections_used')) {
                $table->unsignedTinyInteger('partner_corrections_used')->default(0);
            }
            if (! Schema::hasColumn('order_items', 'partner_dispute_status')) {
                $table->string('partner_dispute_status', 32)->nullable();
            }
            if (! Schema::hasColumn('order_items', 'partner_earnings_status')) {
                $table->string('partner_earnings_status', 32)->nullable();
            }
            if (! Schema::hasColumn('order_items', 'hold_released_at')) {
                $table->timestamp('hold_released_at')->nullable();
            }
            if (! Schema::hasColumn('order_items', 'link_alert')) {
                $table->boolean('link_alert')->default(false);
            }
            if (! Schema::hasColumn('order_items', 'keep_live_terms_version')) {
                $table->string('keep_live_terms_version', 32)->nullable();
            }
            if (! Schema::hasColumn('order_items', 'partner_awaiting_relay')) {
                $table->boolean('partner_awaiting_relay')->default(false);
            }
            if (! Schema::hasColumn('order_items', 'partner_payout_eur')) {
                $table->decimal('partner_payout_eur', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('order_items', 'slb_price_eur_internal')) {
                $table->decimal('slb_price_eur_internal', 10, 2)->nullable();
            }
        });
    }

    private function addConnectionColumns(): void
    {
        if (! Schema::hasTable('partner_connections')) {
            return;
        }
        Schema::table('partner_connections', function (Blueprint $table) {
            if (! Schema::hasColumn('partner_connections', 'api_base_url')) {
                $table->text('api_base_url')->nullable();
            }
            if (! Schema::hasColumn('partner_connections', 'auth_header_name')) {
                $table->string('auth_header_name', 120)->nullable();
            }
            if (! Schema::hasColumn('partner_connections', 'auth_key_encrypted')) {
                $table->text('auth_key_encrypted')->nullable();
            }
            if (! Schema::hasColumn('partner_connections', 'pagination')) {
                $table->json('pagination')->nullable();
            }
            if (! Schema::hasColumn('partner_connections', 'items_path')) {
                $table->string('items_path', 191)->nullable();
            }
        });
    }

    private function addWithdrawalColumn(): void
    {
        if (! Schema::hasTable('withdrawals') || Schema::hasColumn('withdrawals', 'partner_ledger_transaction_id')) {
            return;
        }
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->unsignedBigInteger('partner_ledger_transaction_id')->nullable();
        });
    }

    private function createTables(): void
    {
        if (! Schema::hasTable('partner_api_keys')) {
            Schema::create('partner_api_keys', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('partner_id');
                $table->string('name', 120);
                $table->string('prefix', 24);
                $table->string('key_hash', 64);
                $table->json('scopes')->nullable();
                $table->string('mode', 12)->default('live');
                $table->timestamp('last_used_at')->nullable();
                $table->string('last_used_ip', 45)->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique('key_hash');
                $table->index(['partner_id', 'revoked_at']);
            });
        }

        if (! Schema::hasTable('partner_webhook_endpoints')) {
            Schema::create('partner_webhook_endpoints', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('partner_id');
                $table->text('url');
                $table->json('events')->nullable();
                $table->text('secret_encrypted');
                $table->string('status', 20)->default('enabled');
                $table->unsignedInteger('failure_streak')->default(0);
                $table->timestamp('disabled_at')->nullable();
                $table->string('description')->nullable();
                $table->timestamps();
                $table->index('partner_id');
            });
        }

        if (! Schema::hasTable('partner_webhook_events')) {
            Schema::create('partner_webhook_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('partner_id');
                $table->string('public_id', 40)->nullable();
                $table->string('type', 80);
                $table->json('payload');
                $table->timestamps();
                $table->index(['partner_id', 'type']);
            });
        }

        if (! Schema::hasTable('partner_webhook_deliveries')) {
            Schema::create('partner_webhook_deliveries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('event_id');
                $table->unsignedBigInteger('endpoint_id');
                $table->unsignedInteger('attempt')->default(0);
                $table->string('status', 20)->default('pending');
                $table->unsignedSmallInteger('response_code')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('next_attempt_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamps();
                $table->index(['status', 'next_attempt_at']);
            });
        }

        if (! Schema::hasTable('partner_idempotency_keys')) {
            Schema::create('partner_idempotency_keys', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('partner_id');
                $table->string('key', 191);
                $table->string('request_hash', 64);
                $table->unsignedSmallInteger('response_code');
                $table->longText('response_body');
                $table->timestamps();
                $table->unique(['partner_id', 'key']);
            });
        }

        if (! Schema::hasTable('partner_order_messages')) {
            Schema::create('partner_order_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_item_id');
                $table->string('author_type', 20);
                $table->unsignedBigInteger('author_id')->nullable();
                $table->string('item_type', 32)->default('message');
                $table->string('visibility', 20)->default('all');
                $table->text('body_masked');
                $table->text('body_original_encrypted')->nullable();
                $table->boolean('masked')->default(false);
                $table->json('mask_rules')->nullable();
                $table->string('relay_status', 32)->default('relayed');
                $table->timestamp('edited_at')->nullable();
                $table->timestamps();
                $table->index(['order_item_id', 'id']);
            });
        }

        if (! Schema::hasTable('partner_order_attachments')) {
            Schema::create('partner_order_attachments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('message_id')->nullable();
                $table->unsignedBigInteger('order_item_id');
                $table->unsignedBigInteger('uploaded_by')->nullable();
                $table->string('filename_original');
                $table->string('filename_masked');
                $table->string('mime', 120)->nullable();
                $table->unsignedInteger('size')->default(0);
                $table->string('storage_path');
                $table->string('scan_status', 20)->default('skipped');
                $table->timestamps();
                $table->index('order_item_id');
            });
        }

        if (! Schema::hasTable('partner_order_corrections')) {
            Schema::create('partner_order_corrections', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_item_id');
                $table->unsignedTinyInteger('number')->default(1);
                $table->text('instructions');
                $table->unsignedBigInteger('requested_by_message_id')->nullable();
                $table->unsignedBigInteger('relayed_by')->nullable();
                $table->string('status', 20)->default('pending_relay');
                $table->timestamp('due_by')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->text('decline_reason')->nullable();
                $table->timestamps();
                $table->index(['order_item_id', 'status']);
            });
        }

        if (! Schema::hasTable('partner_order_disputes')) {
            Schema::create('partner_order_disputes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_item_id');
                $table->string('opened_by_type', 32);
                $table->unsignedBigInteger('correction_id')->nullable();
                $table->string('reason', 80)->nullable();
                $table->text('message')->nullable();
                $table->string('status', 32)->default('open');
                $table->unsignedBigInteger('decided_by')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->text('decision_note')->nullable();
                $table->timestamps();
                $table->index(['order_item_id', 'status']);
            });
        }

        if (! Schema::hasTable('partner_link_incidents')) {
            Schema::create('partner_link_incidents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_item_id');
                $table->unsignedBigInteger('partner_id');
                $table->string('failure_code', 40)->nullable();
                $table->timestamp('restore_by')->nullable();
                $table->timestamp('restored_at')->nullable();
                $table->string('resolved', 20)->nullable();
                $table->unsignedInteger('fail_count')->default(1);
                $table->timestamps();
                $table->index(['order_item_id', 'resolved']);
            });
        }

        if (! Schema::hasTable('partner_ledger_accounts')) {
            Schema::create('partner_ledger_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('code', 80)->unique();
                $table->string('owner_type', 20);
                $table->unsignedBigInteger('owner_id')->nullable();
                $table->string('currency', 3)->default('EUR');
                $table->boolean('allow_negative')->default(false);
                $table->timestamps();
                $table->index(['owner_type', 'owner_id']);
            });
        }

        if (! Schema::hasTable('partner_ledger_transactions')) {
            Schema::create('partner_ledger_transactions', function (Blueprint $table) {
                $table->id();
                $table->string('type', 40);
                $table->unsignedBigInteger('order_item_id')->nullable();
                $table->unsignedBigInteger('payout_id')->nullable();
                $table->string('idempotency_key', 80)->nullable()->unique();
                $table->string('description')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->index('order_item_id');
            });
        }

        if (! Schema::hasTable('partner_ledger_entries')) {
            Schema::create('partner_ledger_entries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('transaction_id');
                $table->unsignedBigInteger('account_id');
                $table->string('direction', 8);
                $table->unsignedInteger('amount_minor');
                $table->string('currency', 3)->default('EUR');
                $table->timestamps();
                $table->index(['account_id', 'id']);
            });
        }

        if (! Schema::hasTable('partner_ledger_balances')) {
            Schema::create('partner_ledger_balances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->unique();
                $table->integer('balance_minor')->default(0);
                $table->unsignedInteger('version')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('partner_clawbacks')) {
            Schema::create('partner_clawbacks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_item_id');
                $table->unsignedBigInteger('partner_id');
                $table->unsignedInteger('amount_minor');
                $table->string('reason', 40);
                $table->unsignedBigInteger('incident_id')->nullable();
                $table->unsignedBigInteger('dispute_id')->nullable();
                $table->unsignedBigInteger('ledger_transaction_id')->nullable();
                $table->string('status', 32)->default('open');
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('recovered_at')->nullable();
                $table->timestamps();
                $table->index(['partner_id', 'status']);
            });
        }

        if (! Schema::hasTable('partner_statements')) {
            Schema::create('partner_statements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('partner_id');
                $table->string('period', 7);
                $table->string('status', 20)->default('final');
                $table->json('totals')->nullable();
                $table->json('lines')->nullable();
                $table->timestamp('issued_at')->nullable();
                $table->timestamps();
                $table->unique(['partner_id', 'period']);
            });
        }

        if (! Schema::hasTable('partner_payout_mode_acceptances')) {
            Schema::create('partner_payout_mode_acceptances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('partner_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('terms_type', 40);
                $table->string('terms_version', 40);
                $table->string('terms_text_sha256', 64)->nullable();
                $table->unsignedTinyInteger('keep_live_months')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->string('ip', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->string('method', 32)->default('click_accept');
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->string('revoked_reason')->nullable();
                $table->timestamps();
                $table->index('partner_id');
            });
        }

        if (! Schema::hasTable('partner_payout_mode_changes')) {
            Schema::create('partner_payout_mode_changes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('partner_id');
                $table->string('from_mode', 20)->nullable();
                $table->string('to_mode', 20);
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('requested_by')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->unsignedBigInteger('second_approver')->nullable();
                $table->timestamp('effective_from')->nullable();
                $table->timestamps();
                $table->index('partner_id');
            });
        }
    }
};
