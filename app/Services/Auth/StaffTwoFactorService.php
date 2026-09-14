<?php

namespace App\Services\Auth;

use App\Models\StaffTwoFactor;
use App\Models\User;
use App\Support\Totp;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StaffTwoFactorService
{
    public const PENDING_KEY = 'staff_two_factor_pending';

    public const PASSED_KEY = 'staff_two_factor_ok';

    public const PENDING_TTL_SECONDS = 600;

    public const RECOVERY_CODE_COUNT = 8;

    public function tableReady(): bool
    {
        return StaffTwoFactor::tableReady();
    }

    public function holdsStaffRole(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('marketing');
    }

    public function isConfirmed(User $user): bool
    {
        return $this->record($user)?->isConfirmed() === true;
    }

    public function mustChallenge(User $user): bool
    {
        return $this->holdsStaffRole($user) && $this->isConfirmed($user);
    }

    public function record(User $user): ?StaffTwoFactor
    {
        if (! StaffTwoFactor::tableReady()) {
            return null;
        }

        try {
            return StaffTwoFactor::query()->where('user_id', $user->id)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    public function sessionPassed(Request $request, User $user): bool
    {
        return (int) $request->session()->get(self::PASSED_KEY) === (int) $user->id;
    }

    public function markSessionPassed(Request $request, User $user): void
    {
        $request->session()->put(self::PASSED_KEY, (int) $user->id);
        $request->session()->forget(self::PENDING_KEY);
    }

    /**
     * @return array{user_id: int, remember: bool, expires: int}
     */
    public function beginPending(Request $request, User $user, bool $remember): array
    {
        $pending = [
            'user_id' => (int) $user->id,
            'remember' => $remember,
            'expires' => time() + self::PENDING_TTL_SECONDS,
        ];
        $request->session()->put(self::PENDING_KEY, $pending);

        return $pending;
    }

    public function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get(self::PENDING_KEY);
        if (! is_array($pending)) {
            return null;
        }
        $expires = (int) ($pending['expires'] ?? 0);
        $userId = (int) ($pending['user_id'] ?? 0);
        if ($userId < 1 || $expires < time()) {
            $request->session()->forget(self::PENDING_KEY);

            return null;
        }

        $user = User::query()->find($userId);
        if (! $user || ! $this->mustChallenge($user)) {
            $request->session()->forget(self::PENDING_KEY);

            return null;
        }

        return $user;
    }

    public function pendingRemember(Request $request): bool
    {
        $pending = $request->session()->get(self::PENDING_KEY);

        return is_array($pending) && ! empty($pending['remember']);
    }

    public function startSetup(User $user): StaffTwoFactor
    {
        StaffTwoFactor::ensureTable();
        $row = $this->record($user) ?? new StaffTwoFactor(['user_id' => $user->id]);
        $row->secret = Totp::secret();
        $row->confirmed_at = null;
        $row->recovery_codes = null;
        $row->last_totp_step = null;
        $row->save();

        return $row;
    }

    /**
     * @return list<string>
     */
    public function confirmSetup(User $user, string $code): array
    {
        $row = $this->record($user);
        if (! $row || ! is_string($row->secret) || $row->secret === '') {
            throw new \RuntimeException('Two-factor setup is not started.');
        }
        if ($row->isConfirmed()) {
            throw new \RuntimeException('Two-factor authentication is already on.');
        }

        $check = Totp::verify($row->secret, $code);
        if (! $check['ok']) {
            throw new \InvalidArgumentException('That code is not valid. Try the next code from your app.');
        }

        $plain = $this->freshRecoveryCodes();
        $row->recovery_codes = array_map(fn (string $code) => Hash::make($code), $plain);
        $row->confirmed_at = now();
        $row->last_totp_step = $check['step'];
        $row->save();

        return $plain;
    }

    public function disable(User $user): void
    {
        $row = $this->record($user);
        if (! $row) {
            return;
        }
        $row->delete();
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user, string $code): array
    {
        if (! $this->verifyTotp($user, $code)) {
            throw new \InvalidArgumentException('That code is not valid. Try the next code from your app.');
        }

        $row = $this->record($user);
        if (! $row) {
            throw new \RuntimeException('Two-factor authentication is not on.');
        }

        $plain = $this->freshRecoveryCodes();
        $row->recovery_codes = array_map(fn (string $code) => Hash::make($code), $plain);
        $row->save();

        return $plain;
    }

    public function verifyLoginCode(User $user, string $code): bool
    {
        $normalized = strtoupper(preg_replace('/[\s\-]+/', '', $code) ?? '');
        if (preg_match('/^\d{6}$/', $normalized)) {
            return $this->verifyTotp($user, $normalized);
        }

        return $this->consumeRecoveryCode($user, $normalized);
    }

    public function verifyTotp(User $user, string $code): bool
    {
        $row = $this->record($user);
        if (! $row?->isConfirmed() || ! is_string($row->secret)) {
            return false;
        }

        $check = Totp::verify(
            $row->secret,
            $code,
            Totp::WINDOW,
            null,
            $row->last_totp_step
        );
        if (! $check['ok']) {
            return false;
        }

        $row->last_totp_step = $check['step'];
        $row->save();

        return true;
    }

    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $row = $this->record($user);
        if (! $row?->isConfirmed()) {
            return false;
        }

        $hashes = $row->recovery_codes;
        if (! is_array($hashes) || $hashes === []) {
            return false;
        }

        $normalized = strtoupper(preg_replace('/[\s\-]+/', '', $code) ?? '');
        foreach ($hashes as $i => $hash) {
            if (! is_string($hash) || ! Hash::check($normalized, $hash)) {
                continue;
            }
            unset($hashes[$i]);
            $row->recovery_codes = array_values($hashes);
            $row->save();

            return true;
        }

        return false;
    }

    public function qrDataUri(StaffTwoFactor $row, User $user): ?string
    {
        if (! is_string($row->secret) || $row->secret === '') {
            return null;
        }

        $url = Totp::otpAuthUrl(
            $row->secret,
            $user->email,
            (string) config('app.name', 'SEOLinkBuildings')
        );

        try {
            $svg = (new Builder(
                writer: new SvgWriter,
                data: $url,
                size: 220,
                margin: 8,
            ))->build()->getString();

            return 'data:image/svg+xml;base64,'.base64_encode($svg);
        } catch (\Throwable $e) {
            Log::warning('Staff 2FA QR generation failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function freshRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = strtoupper(Str::password(10, letters: true, numbers: true, symbols: false));
        }

        return $codes;
    }
}
