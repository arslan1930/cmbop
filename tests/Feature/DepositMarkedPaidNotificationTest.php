<?php

namespace Tests\Feature;

use App\Mail\DepositMarkedPaid;
use App\Models\DepositRequest;
use App\Models\InAppNotification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Clicking "I paid" used to only flash a dialog that the following page reload
 * threw away: the advertiser was left with no record, and no admin was ever
 * told a transfer needed checking, so the deposit just sat there.
 */
class DepositMarkedPaidNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(['email_verified_at' => now(), 'name' => 'Dana Depositor']);
        $user->roles()->syncWithoutDetaching([$role->id]);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    private function pendingDeposit(User $user, string $method = 'wise'): DepositRequest
    {
        return DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => (string) random_int(100000, 999999),
            'amount' => 150,
            'payment_method' => $method,
            'status' => 'pending',
        ]);
    }

    private function markPaid(User $user, DepositRequest $deposit, array $payload = [])
    {
        return $this->actingAs($user)->postJson(
            route('advertiser.add-funds.mark-paid', $deposit),
            $payload
        );
    }

    public function test_advertiser_gets_a_bell_notification_confirming_the_report(): void
    {
        Mail::fake();
        $user = $this->advertiser();
        $deposit = $this->pendingDeposit($user);

        $this->markPaid($user, $deposit)->assertOk();

        $note = InAppNotification::where('user_id', $user->id)->latest('id')->first();

        $this->assertNotNull($note, 'The advertiser needs a durable record that the click landed.');
        $this->assertStringContainsString('Payment reported', $note->title);
        $this->assertStringContainsString($deposit->reference_code, (string) $note->message);
        $this->assertSame(InAppNotification::STATUS_UNREAD, $note->status);
    }

    public function test_admins_are_alerted_so_the_transfer_gets_checked(): void
    {
        Mail::fake();
        $user = $this->advertiser();
        $admin = $this->admin();
        $deposit = $this->pendingDeposit($user, 'bank');

        $this->markPaid($user, $deposit, ['user_payment_note' => 'WISE-REF-991'])->assertOk();

        $note = InAppNotification::where('user_id', $admin->id)->latest('id')->first();

        $this->assertNotNull($note, 'Admins must be told a payment was reported.');
        $this->assertSame('Advertiser reported a payment', $note->title);
        $this->assertStringContainsString('Dana Depositor', (string) $note->message);
        $this->assertStringContainsString('WISE-REF-991', (string) $note->message);
        $this->assertSame(InAppNotification::PRIORITY_HIGH, $note->priority);

        Mail::assertQueued(DepositMarkedPaid::class, fn (DepositMarkedPaid $mail) => $mail->hasTo($admin->email));
    }

    public function test_every_admin_is_emailed_not_just_the_first(): void
    {
        Mail::fake();
        $user = $this->advertiser();
        $adminA = $this->admin();
        $adminB = $this->admin();
        $deposit = $this->pendingDeposit($user);

        $this->markPaid($user, $deposit)->assertOk();

        // A dedupe key keyed only on the deposit would collapse this to one send.
        Mail::assertQueued(DepositMarkedPaid::class, fn (DepositMarkedPaid $m) => $m->hasTo($adminA->email));
        Mail::assertQueued(DepositMarkedPaid::class, fn (DepositMarkedPaid $m) => $m->hasTo($adminB->email));
        Mail::assertQueued(DepositMarkedPaid::class, 2);
    }

    public function test_clicking_again_does_not_re_alert_anyone(): void
    {
        Mail::fake();
        $user = $this->advertiser();
        $this->admin();
        $deposit = $this->pendingDeposit($user);

        $this->markPaid($user, $deposit)->assertOk();
        $after = InAppNotification::count();

        $this->markPaid($user, $deposit)->assertOk();

        $this->assertSame($after, InAppNotification::count(), 'Re-reporting must not spam the bell.');
        Mail::assertQueued(DepositMarkedPaid::class, 1);
    }

    public function test_nothing_is_announced_when_the_deposit_cannot_be_reported(): void
    {
        Mail::fake();
        $user = $this->advertiser();
        $this->admin();
        $deposit = $this->pendingDeposit($user);
        $deposit->update(['status' => 'completed']);

        $this->markPaid($user, $deposit)->assertStatus(422);

        $this->assertSame(0, InAppNotification::count());
        Mail::assertNothingQueued();
    }

    public function test_reporting_still_leaves_the_deposit_pending(): void
    {
        Mail::fake();
        $user = $this->advertiser();
        $deposit = $this->pendingDeposit($user);

        $this->markPaid($user, $deposit)->assertOk()->assertJsonPath('status', 'pending');

        $deposit->refresh();
        $this->assertSame('pending', $deposit->status);
        $this->assertNotNull($deposit->user_marked_paid_at);
    }

    public function test_the_i_paid_button_still_reaches_its_click_handler(): void
    {
        $view = file_get_contents(resource_path('views/advertiser/add-funds.blade.php'));

        // The button is wired with a delegated handler on document. An inline
        // stopPropagation stops the click ever getting there, which silently
        // turned the whole control into a no-op.
        $this->assertMatchesRegularExpression(
            '/mark-deposit-paid-btn(?:(?!<\/button>).)*?>/s',
            $view,
            'Expected to find the "I paid" button markup.'
        );

        preg_match('/mark-deposit-paid-btn(?:(?!<\/button>).)*?>/s', $view, $button);

        $this->assertStringNotContainsString('stopPropagation', $button[0]);
        $this->assertStringContainsString("\$(document).on('click', '.mark-deposit-paid-btn'", $view);
    }

    public function test_admin_email_states_the_funds_are_not_credited_yet(): void
    {
        $user = $this->advertiser();
        $deposit = $this->pendingDeposit($user, 'bank');
        $deposit->update(['user_marked_paid_at' => now(), 'user_payment_note' => 'TRF-7781']);

        $body = (new DepositMarkedPaid($deposit->fresh('user')))->render();

        $this->assertStringContainsString('Payment reported', $body);
        $this->assertStringContainsString('not', $body);
        $this->assertStringContainsString('REF'.$deposit->reference_code, $body);
        $this->assertStringContainsString('TRF-7781', $body);
        $this->assertStringContainsString('Approve &amp; credit wallet', $body);
        $this->assertStringContainsString(
            parse_url(route('admin.deposits.approve-confirm.show', $deposit->id), PHP_URL_PATH),
            $body
        );
        $this->assertStringContainsString('signature=', $body);
        $this->assertStringContainsString(
            parse_url(route('admin.deposits'), PHP_URL_PATH),
            $body
        );
        preg_match_all('#/admin/deposits/'.$deposit->id.'(/[a-z0-9\-]*)?#', $body, $matches);
        $this->assertNotEmpty($matches[0]);
        foreach ($matches[0] as $path) {
            $this->assertStringContainsString('/approve-confirm', $path);
        }
    }
}
