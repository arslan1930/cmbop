<?php

namespace App\Mail;

use App\Models\User;
use App\Support\EmailCatalog;
use Illuminate\Support\Collection;

class AdminNewUsersDigest extends PlatformMailable
{
    /**
     * @param  Collection<int, User>  $newUsers
     */
    public function __construct(public ?User $admin, public Collection $newUsers)
    {
        parent::__construct();
        $this->notificationType = 'admin_new_users_digest';
        $this->recipientUser = $admin;
        $ids = $newUsers->pluck('id')->sort()->implode('-');
        $this->dedupeKey = 'admin_new_users_digest:'.$ids.':admin:'.($admin?->id ?? 'fallback');
    }

    public function build()
    {
        $count = $this->newUsers->count();
        $subject = $count === 1
            ? '1 new user registered'
            : $count.' new users registered';

        $rows = $this->newUsers->map(function (User $user) {
            $role = EmailCatalog::isPreviewUser($user)
                ? 'advertiser'
                : $user->activeRole();

            return [
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'role' => $role ? ucfirst((string) $role) : 'User',
                'registered' => optional($user->created_at)->format('M j, Y g:i A') ?? now()->format('M j, Y g:i A'),
            ];
        });

        return $this->subject($subject)
            ->markdown('emails.admin.new-users-digest')
            ->with([
                'adminFirstName' => $this->firstName($this->admin),
                'count' => $count,
                'rows' => $rows,
                'ctaUrl' => $this->publicRoute('admin.audiences.index'),
                'brand' => $this->brand(),
            ]);
    }
}
