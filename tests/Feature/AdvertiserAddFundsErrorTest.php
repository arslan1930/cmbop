<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdvertiserAddFundsErrorTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'billing_name' => 'Jane Advertiser',
            'company_name' => 'Acme SEO Ltd',
            'country' => 'DE',
            'city' => 'Berlin',
            'address' => 'Main Street 1',
        ]);
        $user->roles()->attach($role->id);

        Wallet::firstOrCreate(
            ['user_id' => $user->id, 'role_id' => $role->id],
            [
                'balance' => 0,
                'reserved_balance' => 0,
                'bonus_balance' => 0,
                'bonus_reserved' => 0,
                'currency' => 'EUR',
            ]
        );

        return $user->fresh();
    }

    public function test_invoice_store_validation_is_422_not_500(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.add-funds.store'), [
                'amount' => 5,
                'payment_method' => 'wise',
                'reference_code' => '123456',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount')
            ->assertJsonMissingPath('exception');
    }

    public function test_stripe_checkout_validation_is_422_not_wrapped_success(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.create-checkout-session'), [
                'amount' => 5,
                'reference_code' => '123456',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_paypal_create_validation_is_422(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.add-funds.paypal.create'), [
                'amount' => 5,
                'reference_code' => '123456',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_add_funds_page_survives_leftover_deposit_dates(): void
    {
        $advertiser = $this->advertiser();
        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DATE500',
            'amount' => 40,
            'payment_method' => 'wise',
            'status' => 'completed',
        ]);
        DB::table('deposit_requests')->where('id', $deposit->id)->update([
            'created_at' => 'not-a-date',
            'updated_at' => 'also-bad',
            'paid_at' => 'leftover',
            'approved_at' => '0000-00-00',
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->assertSee('Add funds', false)
            ->assertDontSee('SQLSTATE', false)
            ->assertDontSee('Something went wrong', false);

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.balance.analytics', ['range' => 'month']))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.balance.transactions'))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_add_funds_page_survives_missing_deposit_requests_table(): void
    {
        $advertiser = $this->advertiser();
        Schema::dropIfExists('deposit_requests');
        $this->assertFalse(Schema::hasTable('deposit_requests'));

        try {
            $this->actingAs($advertiser)
                ->get(route('advertiser.add-funds'))
                ->assertOk()
                ->assertSee('Add funds', false)
                ->assertDontSee('SQLSTATE', false)
                ->assertDontSee('Something went wrong', false);

            $this->actingAs($advertiser)
                ->postJson(route('advertiser.add-funds.store'), [
                    'amount' => 50,
                    'payment_method' => 'wise',
                    'reference_code' => '654321',
                ])
                ->assertStatus(503)
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', 'Deposits are temporarily unavailable. Please try again shortly.')
                ->assertJsonMissingPath('exception')
                ->assertDontSee('SQLSTATE');

            $this->actingAs($advertiser)
                ->getJson(route('advertiser.add-funds.status', 1))
                ->assertStatus(503)
                ->assertJsonPath('success', false)
                ->assertJsonMissingPath('exception')
                ->assertDontSee('SQLSTATE');

            $this->actingAs($advertiser)
                ->getJson(route('advertiser.balance.transactions'))
                ->assertOk()
                ->assertJsonPath('success', true);
        } finally {
            $this->restoreDepositRequestsTable();
        }
    }

    public function test_missing_invoice_json_does_not_leak_model_class(): void
    {
        $advertiser = $this->advertiser();

        foreach ([
            ['POST', route('advertiser.add-funds.cancel', 999999)],
            ['POST', route('advertiser.add-funds.mark-paid', 999999)],
            ['GET', route('advertiser.add-funds.status', 999999)],
        ] as [$method, $url]) {
            $this->actingAs($advertiser)
                ->json($method, $url)
                ->assertNotFound()
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', 'Invoice not found.')
                ->assertJsonMissingPath('exception')
                ->assertDontSee('SQLSTATE')
                ->assertDontSee('App\\Models')
                ->assertDontSee('DepositRequest')
                ->assertDontSee('No query results');
        }
    }

    private function restoreDepositRequestsTable(): void
    {
        foreach ([
            'database/migrations/2026_04_21_115734_create_deposit_requests_table.php',
            'database/migrations/2026_04_22_113004_add_stripe_fields_to_deposit_requests_table.php',
            'database/migrations/2026_07_21_140000_add_user_marked_paid_to_deposit_requests.php',
            'database/migrations/2026_08_14_160000_unique_deposit_stripe_ids.php',
            'database/migrations/2026_08_18_160000_add_paypal_columns_to_deposit_requests.php',
        ] as $path) {
            $this->artisan('migrate', [
                '--path' => $path,
                '--force' => true,
            ]);
        }
    }
}
