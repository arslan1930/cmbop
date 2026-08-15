<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\WelcomeBonusClaim;
use App\Models\WelcomeBonusSetting;
use App\Services\Wallet\WelcomeBonusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WelcomeBonusServiceTest extends TestCase
{
    use RefreshDatabase;

    private WelcomeBonusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(WelcomeBonusService::class);
    }

    public function test_enabled_advertiser_from_new_ip_gets_configured_amount(): void
    {
        $this->assertTrue($this->service->isEnabled());
        $this->assertSame(20.0, $this->service->amountFor($this->request('1.2.3.4'), 'advertiser'));
    }

    public function test_disabled_flag_returns_zero(): void
    {
        $this->service->setEnabled(false);

        $this->assertFalse($this->service->isEnabled());
        $this->assertSame(0.0, $this->service->amountFor($this->request('1.2.3.4'), 'advertiser'));
    }

    public function test_publisher_role_returns_zero(): void
    {
        $this->assertSame(0.0, $this->service->amountFor($this->request('1.2.3.4'), 'publisher'));
    }

    public function test_ip_already_claimed_returns_zero(): void
    {
        $user = User::factory()->create();
        WelcomeBonusClaim::query()->create([
            'user_id' => $user->id,
            'ip_address' => '1.2.3.4',
            'source' => 'registration',
            'amount' => 20,
        ]);

        $this->assertSame(0.0, $this->service->amountFor($this->request('1.2.3.4'), 'advertiser'));
        $this->assertSame(20.0, $this->service->amountFor($this->request('9.9.9.9'), 'advertiser'));
    }

    public function test_claim_cookie_returns_zero(): void
    {
        $request = $this->request('8.8.8.8', [
            (string) config('welcome_bonus.cookie_name') => '1',
        ]);

        $this->assertSame(0.0, $this->service->amountFor($request, 'advertiser'));
    }

    public function test_record_claim_rejects_duplicate_ip(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $request = $this->request('10.0.0.1');

        $this->assertTrue($this->service->recordClaim($first, $request, 20.0, 'registration'));
        $this->assertFalse($this->service->recordClaim($second, $request, 20.0, 'registration'));
        $this->assertSame(1, WelcomeBonusClaim::query()->count());
    }

    public function test_record_claim_rejects_a_second_claim_for_the_same_user(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($this->service->recordClaim($user, $this->request('10.1.0.1'), 20.0, 'registration'));
        $this->assertFalse($this->service->recordClaim($user, $this->request('10.1.0.2'), 20.0, 'registration'));
        $this->assertSame(1, WelcomeBonusClaim::query()->count());
    }

    public function test_record_claim_rejects_an_amount_above_the_configured_bonus(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($this->service->recordClaim($user, $this->request('10.2.0.1'), 2000.0, 'registration'));
        $this->assertSame(0, WelcomeBonusClaim::query()->count());
    }

    public function test_oversized_ip_does_not_break_signup_and_does_not_grant(): void
    {
        $request = $this->request(str_repeat('1', 50));

        $this->assertNull($this->service->normalizedIp($request));
        $this->assertSame(0.0, $this->service->amountFor($request, 'advertiser'));

        $user = User::factory()->create();
        $this->assertFalse($this->service->recordClaim($user, $request, 20.0, 'registration'));
        $this->assertSame(0, WelcomeBonusClaim::query()->count());
    }

    public function test_claims_table_has_a_unique_index_on_ip_address(): void
    {
        $found = false;
        foreach (Schema::getIndexes('welcome_bonus_claims') as $index) {
            if (! empty($index['unique']) && ($index['columns'] ?? []) === ['ip_address']) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'welcome_bonus_claims.ip_address must be unique');
    }

    public function test_settings_table_has_a_unique_index_on_key(): void
    {
        $found = false;
        foreach (Schema::getIndexes('welcome_bonus_settings') as $index) {
            if (! empty($index['unique']) && ($index['columns'] ?? []) === ['key']) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'welcome_bonus_settings.key must be unique');
    }

    public function test_record_claim_still_writes_once_when_cache_lock_is_unavailable(): void
    {
        Cache::shouldReceive('lock')
            ->andThrow(new \RuntimeException('cache locks unavailable'));

        $first = User::factory()->create();
        $second = User::factory()->create();
        $request = $this->request('10.4.0.1');

        $this->assertTrue($this->service->recordClaim($first, $request, 20.0, 'registration'));
        $this->assertFalse($this->service->recordClaim($second, $request, 20.0, 'registration'));
        $this->assertSame(1, WelcomeBonusClaim::query()->count());
    }

    public function test_cache_place_lock_is_released_when_record_claim_returns(): void
    {
        $user = User::factory()->create();
        $ip = '203.0.113.50';

        $this->assertTrue($this->service->recordClaim($user, $this->request($ip), 20.0, 'registration'));

        $lock = Cache::lock('welcome-bonus-claim:'.$ip, 1);
        $this->assertTrue((bool) $lock->get(), 'cache place lock leaked after recordClaim()');
        $lock->release();
    }

    public function test_missing_claims_table_does_not_grant(): void
    {
        Schema::dropIfExists('welcome_bonus_claims');
        $this->assertFalse(Schema::hasTable('welcome_bonus_claims'));

        $this->assertFalse($this->service->canGrant());
        $this->assertSame(0.0, $this->service->amountFor($this->request('1.2.3.4'), 'advertiser'));
        $this->assertFalse($this->service->recordClaim(
            User::factory()->create(),
            $this->request('1.2.3.4'),
            20.0,
            'registration'
        ));
    }

    public function test_missing_bonus_columns_do_not_grant(): void
    {
        Schema::table('wallets', function ($table) {
            if (Schema::hasColumn('wallets', 'bonus_balance')) {
                $table->dropColumn(['bonus_balance', 'bonus_reserved']);
            }
        });
        $this->assertFalse(Schema::hasColumn('wallets', 'bonus_balance'));

        $this->assertFalse($this->service->canGrant());
        $this->assertSame(0.0, $this->service->amountFor($this->request('1.2.3.4'), 'advertiser'));
        $this->assertFalse($this->service->recordClaim(
            User::factory()->create(),
            $this->request('1.2.3.4'),
            20.0,
            'registration'
        ));
        $this->assertSame(0, WelcomeBonusClaim::query()->count());
    }

    public function test_forwarded_for_header_cannot_spoof_the_claim_ip(): void
    {
        $request = $this->request('1.2.3.4', [], [
            'HTTP_X_FORWARDED_FOR' => '9.9.9.9',
        ]);

        $this->assertSame('1.2.3.4', $this->service->normalizedIp($request));

        $first = User::factory()->create();
        $this->assertTrue($this->service->recordClaim($first, $request, 20.0, 'registration'));

        $spoofed = $this->request('1.2.3.4', [], [
            'HTTP_X_FORWARDED_FOR' => '8.8.8.8',
        ]);
        $this->assertSame(0.0, $this->service->amountFor($spoofed, 'advertiser'));
        $this->assertFalse($this->service->recordClaim(
            User::factory()->create(),
            $spoofed,
            20.0,
            'registration'
        ));
        $this->assertSame(1, WelcomeBonusClaim::query()->count());
        $this->assertSame('1.2.3.4', WelcomeBonusClaim::query()->value('ip_address'));
    }

    public function test_cloudflare_connecting_ip_is_used_when_peer_is_a_cloudflare_edge(): void
    {
        $request = $this->request('104.16.0.1', [], [
            'HTTP_CF_RAY' => 'abc123-DFW',
            'HTTP_CF_CONNECTING_IP' => '203.0.113.10',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.20',
        ]);

        $this->assertSame('203.0.113.10', $this->service->normalizedIp($request));
    }

    public function test_spoofed_cloudflare_headers_are_ignored_when_peer_is_not_cloudflare(): void
    {
        $request = $this->request('8.8.8.8', [], [
            'HTTP_CF_RAY' => 'spoofed-DFW',
            'HTTP_CF_CONNECTING_IP' => '203.0.113.10',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.20',
        ]);

        $this->assertSame('8.8.8.8', $this->service->normalizedIp($request));
    }

    public function test_ipv6_addresses_in_the_same_slash64_share_the_claim(): void
    {
        $first = $this->request('2001:db8:1:2:aaaa::1');
        $second = $this->request('2001:db8:1:2:bbbb::2');
        $otherNet = $this->request('2001:db8:1:3::1');

        $this->assertSame('2001:db8:1:2::', $this->service->normalizedIp($first));
        $this->assertSame('2001:db8:1:2::', $this->service->normalizedIp($second));
        $this->assertSame('2001:db8:1:3::', $this->service->normalizedIp($otherNet));

        $this->assertTrue($this->service->recordClaim(User::factory()->create(), $first, 20.0, 'registration'));
        $this->assertSame(0.0, $this->service->amountFor($second, 'advertiser'));
        $this->assertFalse($this->service->recordClaim(
            User::factory()->create(),
            $second,
            20.0,
            'registration'
        ));
        $this->assertSame(20.0, $this->service->amountFor($otherNet, 'advertiser'));
        $this->assertSame('2001:db8:1:2::', WelcomeBonusClaim::query()->value('ip_address'));
    }

    public function test_ipv4_mapped_ipv6_shares_the_ipv4_claim_key(): void
    {
        $mapped = $this->request('::ffff:1.2.3.4');
        $this->assertSame('1.2.3.4', $this->service->normalizedIp($mapped));

        $first = User::factory()->create();
        $this->assertTrue($this->service->recordClaim($first, $mapped, 20.0, 'registration'));
        $this->assertSame('1.2.3.4', WelcomeBonusClaim::query()->value('ip_address'));

        $this->assertSame(0.0, $this->service->amountFor($this->request('1.2.3.4'), 'advertiser'));
        $this->assertFalse($this->service->recordClaim(
            User::factory()->create(),
            $this->request('1.2.3.4'),
            20.0,
            'registration'
        ));
    }

    public function test_legacy_mapped_ipv4_claim_row_blocks_the_ipv4_key(): void
    {
        WelcomeBonusClaim::query()->create([
            'user_id' => User::factory()->create()->id,
            'ip_address' => '::ffff:1.2.3.4',
            'source' => 'registration',
            'amount' => 20,
        ]);

        $this->assertSame(0.0, $this->service->amountFor($this->request('1.2.3.4'), 'advertiser'));
    }

    public function test_legacy_uppercase_and_expanded_mapped_rows_block_the_ipv4_key(): void
    {
        WelcomeBonusClaim::query()->create([
            'user_id' => User::factory()->create()->id,
            'ip_address' => '::FFFF:1.2.3.4',
            'source' => 'registration',
            'amount' => 20,
        ]);

        $this->assertSame(0.0, $this->service->amountFor($this->request('1.2.3.4'), 'advertiser'));
        $this->assertFalse($this->service->recordClaim(
            User::factory()->create(),
            $this->request('1.2.3.4'),
            20.0,
            'registration'
        ));
        $this->assertSame(1, WelcomeBonusClaim::query()->count());

        WelcomeBonusClaim::query()->delete();
        WelcomeBonusClaim::query()->create([
            'user_id' => User::factory()->create()->id,
            'ip_address' => '0:0:0:0:0:ffff:1.2.3.4',
            'source' => 'registration',
            'amount' => 20,
        ]);

        $this->assertSame(0.0, $this->service->amountFor($this->request('1.2.3.4'), 'advertiser'));
        $this->assertFalse($this->service->recordClaim(
            User::factory()->create(),
            $this->request('1.2.3.4'),
            20.0,
            'registration'
        ));
        $this->assertSame(1, WelcomeBonusClaim::query()->count());

        WelcomeBonusClaim::query()->delete();
        WelcomeBonusClaim::query()->create([
            'user_id' => User::factory()->create()->id,
            'ip_address' => '::ffff:102:304',
            'source' => 'registration',
            'amount' => 20,
        ]);

        $this->assertSame(0.0, $this->service->amountFor($this->request('1.2.3.4'), 'advertiser'));
        $this->assertFalse($this->service->recordClaim(
            User::factory()->create(),
            $this->request('1.2.3.4'),
            20.0,
            'registration'
        ));
        $this->assertSame(1, WelcomeBonusClaim::query()->count());
    }

    public function test_ipv4_compatible_ipv6_normalizes_to_ipv4_and_leaves_loopback_as_ipv6(): void
    {
        $this->assertSame('1.2.3.4', $this->service->normalizedIp($this->request('::1.2.3.4')));
        $this->assertSame('::', $this->service->normalizedIp($this->request('::1')));
    }

    public function test_legacy_ipv4_compatible_claim_row_blocks_the_ipv4_key(): void
    {
        WelcomeBonusClaim::query()->create([
            'user_id' => User::factory()->create()->id,
            'ip_address' => '::1.2.3.4',
            'source' => 'registration',
            'amount' => 20,
        ]);

        $this->assertSame(0.0, $this->service->amountFor($this->request('1.2.3.4'), 'advertiser'));
        $this->assertFalse($this->service->recordClaim(
            User::factory()->create(),
            $this->request('1.2.3.4'),
            20.0,
            'registration'
        ));
        $this->assertSame(1, WelcomeBonusClaim::query()->count());
    }

    public function test_broken_cloudflare_cidr_config_does_not_throw_or_trust_spoofed_header(): void
    {
        config(['welcome_bonus.cloudflare_cidrs' => ['not-a-cidr', new \stdClass]]);

        $request = $this->request('8.8.8.8', [], [
            'HTTP_CF_CONNECTING_IP' => '203.0.113.10',
        ]);

        $this->assertSame('8.8.8.8', $this->service->normalizedIp($request));
        $this->assertSame(20.0, $this->service->amountFor($request, 'advertiser'));
    }

    public function test_legacy_full_ipv6_claim_row_blocks_the_slash64(): void
    {
        WelcomeBonusClaim::query()->create([
            'user_id' => User::factory()->create()->id,
            'ip_address' => '2001:db8:1:2:aaaa::1',
            'source' => 'registration',
            'amount' => 20,
        ]);

        $this->assertSame(0.0, $this->service->amountFor(
            $this->request('2001:db8:1:2:bbbb::2'),
            'advertiser'
        ));
        $this->assertFalse($this->service->recordClaim(
            User::factory()->create(),
            $this->request('2001:db8:1:2:cccc::3'),
            20.0,
            'registration'
        ));
        $this->assertSame(20.0, $this->service->amountFor(
            $this->request('2001:db8:1:3::1'),
            'advertiser'
        ));
        $this->assertSame(1, WelcomeBonusClaim::query()->count());
    }

    public function test_place_key_ignores_forwarded_for_and_locks_ipv6_at_slash64(): void
    {
        $spoofed = $this->request('1.2.3.4', [], [
            'HTTP_X_FORWARDED_FOR' => '9.9.9.9',
        ]);
        $this->assertSame('1.2.3.4', $this->service->placeKey($spoofed));

        $this->assertSame('2001:db8:1:2::', $this->service->placeKey(
            $this->request('2001:db8:1:2:aaaa::1')
        ));
        $this->assertSame('unknown', $this->service->placeKey(
            $this->request('not-an-ip-address')
        ));
        $this->assertSame('register:1.2.3.4', $this->service->registerRateLimitKey($spoofed));
    }

    public function test_invalid_ip_string_is_ignored(): void
    {
        $request = $this->request('not-an-ip-address');
        $this->assertNull($this->service->normalizedIp($request));
        $this->assertSame(0.0, $this->service->amountFor($request, 'advertiser'));
        $this->assertFalse($this->service->recordClaim(
            User::factory()->create(),
            $request,
            20.0,
            'registration'
        ));
    }

    public function test_unlocked_read_does_not_create_a_settings_row(): void
    {
        $this->assertTrue(WelcomeBonusSetting::isEnabled());
        $this->assertSame(0, WelcomeBonusSetting::query()->count());
    }

    public function test_grant_lock_creates_a_default_settings_row(): void
    {
        $this->assertSame(0, WelcomeBonusSetting::query()->count());
        $this->assertTrue(WelcomeBonusSetting::isEnabledForGrant());
        $this->assertSame(1, WelcomeBonusSetting::query()->where('key', 'config')->count());
        $this->assertTrue(WelcomeBonusSetting::isEnabled());
    }

    public function test_unlocked_read_stays_on_when_never_configured(): void
    {
        $this->assertSame(0, WelcomeBonusSetting::query()->count());
        $this->assertTrue(WelcomeBonusSetting::isEnabled());
        $this->assertSame(20.0, $this->service->amountFor($this->request('203.0.113.60'), 'advertiser'));
    }

    public function test_settings_default_enabled_until_toggled(): void
    {
        $this->assertTrue(WelcomeBonusSetting::isEnabled());

        WelcomeBonusSetting::setEnabled(false, 99);
        $this->assertFalse(WelcomeBonusSetting::isEnabled());

        $stored = WelcomeBonusSetting::getValue('config', []);
        $this->assertFalse($stored['enabled']);
        $this->assertSame(99, $stored['updated_by']);
    }

    public function test_record_claim_refuses_when_bonus_is_disabled(): void
    {
        $user = User::factory()->create();
        $this->service->setEnabled(false);

        $this->assertFalse($this->service->recordClaim($user, $this->request('5.5.5.5'), 20.0, 'registration'));
        $this->assertSame(0, WelcomeBonusClaim::query()->count());
    }

    public function test_stored_enabled_flags_are_parsed_strictly(): void
    {
        foreach ([0, '0', 'false', 'off', 'no', false] as $off) {
            WelcomeBonusSetting::setValue('config', ['enabled' => $off]);
            $this->assertFalse(WelcomeBonusSetting::isEnabled(), var_export($off, true).' should be off');
        }

        foreach ([1, '1', 'true', 'on', 'yes', true] as $on) {
            WelcomeBonusSetting::setValue('config', ['enabled' => $on]);
            $this->assertTrue(WelcomeBonusSetting::isEnabled(), var_export($on, true).' should be on');
        }
    }

    public function test_malformed_enabled_flag_fails_closed_without_throwing(): void
    {
        WelcomeBonusSetting::setValue('config', ['enabled' => null]);
        $this->assertFalse(WelcomeBonusSetting::isEnabled());

        WelcomeBonusSetting::setValue('config', ['enabled' => ['nested' => true]]);
        $this->assertFalse(WelcomeBonusSetting::isEnabled());

        WelcomeBonusSetting::setValue('config', ['enabled' => 'maybe']);
        $this->assertFalse(WelcomeBonusSetting::isEnabled());
    }

    public function test_string_false_default_is_off_when_unset(): void
    {
        config(['welcome_bonus.enabled_default' => 'false']);

        $this->assertFalse(WelcomeBonusSetting::isEnabled());
    }

    public function test_present_row_without_enabled_key_fails_closed(): void
    {
        WelcomeBonusSetting::setValue('config', ['updated_by' => 1]);

        $this->assertFalse(WelcomeBonusSetting::isEnabled());
        $this->assertFalse(WelcomeBonusSetting::isEnabledForGrant());
        $this->assertSame(0.0, $this->service->amountFor($this->request('5.6.7.8'), 'advertiser'));
    }

    public function test_duplicate_config_rows_prefer_an_explicit_disable(): void
    {
        Schema::table('welcome_bonus_settings', function ($table) {
            $table->dropUnique(['key']);
        });

        WelcomeBonusSetting::query()->delete();
        DB::table('welcome_bonus_settings')->insert([
            [
                'key' => 'config',
                'value' => json_encode(['enabled' => true]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'config',
                'value' => json_encode(['enabled' => false]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertFalse(WelcomeBonusSetting::isEnabled());
        $this->assertFalse(WelcomeBonusSetting::isEnabledForGrant());
        $this->assertSame(0.0, $this->service->amountFor($this->request('10.3.0.1'), 'advertiser'));
        $this->assertFalse($this->service->recordClaim(
            User::factory()->create(),
            $this->request('10.3.0.1'),
            20.0,
            'registration'
        ));
    }

    public function test_disable_collapses_duplicate_config_rows(): void
    {
        Schema::table('welcome_bonus_settings', function ($table) {
            $table->dropUnique(['key']);
        });

        WelcomeBonusSetting::query()->delete();
        DB::table('welcome_bonus_settings')->insert([
            [
                'key' => 'config',
                'value' => json_encode(['enabled' => true]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'config',
                'value' => json_encode(['enabled' => true]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->service->setEnabled(false, 7);

        $this->assertFalse(WelcomeBonusSetting::isEnabled());
        $this->assertSame(1, WelcomeBonusSetting::query()->where('key', 'config')->count());
        $this->assertFalse((bool) WelcomeBonusSetting::query()->value('value')['enabled']);
    }

    public function test_toggle_does_not_resurrect_a_stale_higher_amount_on_duplicate_rows(): void
    {
        Schema::table('welcome_bonus_settings', function ($table) {
            $table->dropUnique(['key']);
        });

        WelcomeBonusSetting::query()->delete();
        DB::table('welcome_bonus_settings')->insert([
            [
                'key' => 'config',
                'value' => json_encode(['enabled' => true, 'amount' => 500]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'config',
                'value' => json_encode(['enabled' => true, 'amount' => 0]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertSame(0.0, $this->service->amount());
        $this->assertSame(0.0, $this->service->amountFor($this->request('10.7.0.1'), 'advertiser'));

        $this->service->setEnabled(false);
        $this->service->setEnabled(true);

        $this->assertTrue(WelcomeBonusSetting::isEnabled());
        $this->assertSame(0.0, $this->service->amount());
        $this->assertSame(1, WelcomeBonusSetting::query()->where('key', 'config')->count());
        $this->assertSame(0.0, (float) WelcomeBonusSetting::query()->value('value')['amount']);
        $this->assertSame(0.0, $this->service->amountFor($this->request('10.7.0.1'), 'advertiser'));
        $this->assertFalse($this->service->recordClaim(
            User::factory()->create(),
            $this->request('10.7.0.1'),
            20.0,
            'registration'
        ));
    }

    public function test_later_on_row_without_amount_does_not_restore_the_default_grant(): void
    {
        Schema::table('welcome_bonus_settings', function ($table) {
            $table->dropUnique(['key']);
        });

        WelcomeBonusSetting::query()->delete();
        DB::table('welcome_bonus_settings')->insert([
            [
                'key' => 'config',
                'value' => json_encode(['enabled' => true, 'amount' => 0]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'config',
                'value' => json_encode(['enabled' => true]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertTrue(WelcomeBonusSetting::isEnabled());
        $this->assertSame(0.0, $this->service->amount());
        $this->assertSame(0.0, $this->service->amountFor($this->request('10.8.0.1'), 'advertiser'));
        $this->assertFalse($this->service->recordClaim(
            User::factory()->create(),
            $this->request('10.8.0.1'),
            20.0,
            'registration'
        ));

        $this->service->setEnabled(true);

        $this->assertSame(0.0, $this->service->amount());
        $this->assertSame(1, WelcomeBonusSetting::query()->where('key', 'config')->count());
        $this->assertSame(0.0, (float) WelcomeBonusSetting::query()->value('value')['amount']);
    }

    public function test_later_disable_row_without_amount_does_not_restore_the_default_on_toggle(): void
    {
        Schema::table('welcome_bonus_settings', function ($table) {
            $table->dropUnique(['key']);
        });

        WelcomeBonusSetting::query()->delete();
        DB::table('welcome_bonus_settings')->insert([
            [
                'key' => 'config',
                'value' => json_encode(['enabled' => true, 'amount' => 0]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'config',
                'value' => json_encode(['enabled' => false]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertFalse(WelcomeBonusSetting::isEnabled());
        $this->assertSame(0.0, $this->service->amount());
        $this->assertSame(0.0, $this->service->amountFor($this->request('10.9.0.1'), 'advertiser'));

        $this->service->setEnabled(false);
        $this->service->setEnabled(true);

        $this->assertTrue(WelcomeBonusSetting::isEnabled());
        $this->assertSame(0.0, $this->service->amount());
        $this->assertSame(1, WelcomeBonusSetting::query()->where('key', 'config')->count());
        $this->assertSame(0.0, (float) WelcomeBonusSetting::query()->value('value')['amount']);
        $this->assertFalse($this->service->recordClaim(
            User::factory()->create(),
            $this->request('10.9.0.1'),
            20.0,
            'registration'
        ));
    }

    public function test_later_row_missing_enabled_does_not_restore_the_default_on_enable(): void
    {
        Schema::table('welcome_bonus_settings', function ($table) {
            $table->dropUnique(['key']);
        });

        WelcomeBonusSetting::query()->delete();
        DB::table('welcome_bonus_settings')->insert([
            [
                'key' => 'config',
                'value' => json_encode(['enabled' => true, 'amount' => 0]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'config',
                'value' => json_encode(['updated_by' => 1]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertFalse(WelcomeBonusSetting::isEnabled());
        $this->assertSame(0.0, $this->service->amount());

        $this->service->setEnabled(true);

        $this->assertTrue(WelcomeBonusSetting::isEnabled());
        $this->assertSame(0.0, $this->service->amount());
        $this->assertFalse($this->service->recordClaim(
            User::factory()->create(),
            $this->request('10.9.0.2'),
            20.0,
            'registration'
        ));
    }

    public function test_non_numeric_stored_amount_does_not_fall_back_to_the_default(): void
    {
        WelcomeBonusSetting::setValue('config', ['enabled' => true, 'amount' => 'unlimited']);

        $this->assertSame(0.0, $this->service->amount());
        $this->assertSame(0.0, $this->service->amountFor($this->request('10.7.0.2'), 'advertiser'));
        $this->assertFalse($this->service->recordClaim(
            User::factory()->create(),
            $this->request('10.7.0.2'),
            20.0,
            'registration'
        ));
    }

    public function test_set_amount_does_not_reenable_when_duplicate_rows_include_disable(): void
    {
        Schema::table('welcome_bonus_settings', function ($table) {
            $table->dropUnique(['key']);
        });

        WelcomeBonusSetting::query()->delete();
        DB::table('welcome_bonus_settings')->insert([
            [
                'key' => 'config',
                'value' => json_encode(['enabled' => true, 'amount' => 20]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'config',
                'value' => json_encode(['enabled' => false, 'amount' => 20]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertFalse(WelcomeBonusSetting::isEnabled());

        $this->service->setAmount(75, 7);

        $this->assertFalse(WelcomeBonusSetting::isEnabled());
        $this->assertSame(75.0, $this->service->amount());
        $this->assertSame(1, WelcomeBonusSetting::query()->where('key', 'config')->count());
        $this->assertFalse((bool) WelcomeBonusSetting::query()->value('value')['enabled']);
        $this->assertSame(0.0, $this->service->amountFor($this->request('10.5.0.1'), 'advertiser'));
        $this->assertFalse($this->service->recordClaim(
            User::factory()->create(),
            $this->request('10.5.0.1'),
            75.0,
            'registration'
        ));
    }

    public function test_set_amount_does_not_turn_on_a_row_missing_the_enabled_flag(): void
    {
        WelcomeBonusSetting::setValue('config', ['amount' => 20]);
        $this->assertFalse(WelcomeBonusSetting::isEnabled());

        $this->service->setAmount(40);

        $this->assertFalse(WelcomeBonusSetting::isEnabled());
        $this->assertSame(40.0, $this->service->amount());
        $this->assertSame(0.0, $this->service->amountFor($this->request('10.5.0.2'), 'advertiser'));
    }

    public function test_stored_amount_is_used_for_grants_and_clamped_to_the_hard_max(): void
    {
        $this->service->setAmount(100);
        $this->assertSame(100.0, $this->service->amount());
        $this->assertSame(100.0, $this->service->amountFor($this->request('10.6.0.1'), 'advertiser'));

        $user = User::factory()->create();
        $this->assertTrue($this->service->recordClaim($user, $this->request('10.6.0.1'), 100.0, 'registration'));
        $this->assertSame(100.0, (float) WelcomeBonusClaim::query()->where('user_id', $user->id)->value('amount'));

        WelcomeBonusSetting::setValue('config', ['enabled' => true, 'amount' => 99999]);
        $this->assertSame(500.0, $this->service->amount());
        $this->assertSame(500.0, $this->service->amountFor($this->request('10.6.0.2'), 'advertiser'));
        $this->assertFalse($this->service->recordClaim(
            User::factory()->create(),
            $this->request('10.6.0.2'),
            501.0,
            'registration'
        ));
        $this->assertTrue($this->service->recordClaim(
            User::factory()->create(),
            $this->request('10.6.0.2'),
            500.0,
            'registration'
        ));
    }

    public function test_set_amount_clamps_values_above_the_hard_max(): void
    {
        $this->service->setAmount(99999);
        $this->assertSame(500.0, $this->service->amount());
        $this->assertSame(500.0, (float) WelcomeBonusSetting::query()->value('value')['amount']);
    }

    public function test_set_value_collapses_duplicate_config_rows(): void
    {
        Schema::table('welcome_bonus_settings', function ($table) {
            $table->dropUnique(['key']);
        });

        WelcomeBonusSetting::query()->delete();
        DB::table('welcome_bonus_settings')->insert([
            [
                'key' => 'config',
                'value' => json_encode(['enabled' => true]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'config',
                'value' => json_encode(['enabled' => false]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        WelcomeBonusSetting::setValue('config', ['enabled' => false]);

        $this->assertFalse(WelcomeBonusSetting::isEnabled());
        $this->assertSame(1, WelcomeBonusSetting::query()->where('key', 'config')->count());
    }

    public function test_present_row_with_empty_or_null_value_fails_closed(): void
    {
        WelcomeBonusSetting::setValue('config', []);
        $this->assertFalse(WelcomeBonusSetting::isEnabled());

        WelcomeBonusSetting::query()->updateOrCreate(['key' => 'config'], ['value' => null]);
        $this->assertFalse(WelcomeBonusSetting::isEnabled());
        $this->assertFalse($this->service->recordClaim(
            User::factory()->create(),
            $this->request('7.7.7.7'),
            20.0,
            'registration'
        ));
        $this->assertSame(0, WelcomeBonusClaim::query()->count());
    }

    private function request(string $ip, array $cookies = [], array $server = []): Request
    {
        return Request::create('/register', 'POST', [], $cookies, [], array_merge([
            'REMOTE_ADDR' => $ip,
        ], $server));
    }
}
