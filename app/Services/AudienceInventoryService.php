<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AudienceInventoryService
{
    public function advertiserCount(): int
    {
        return $this->queryForRole('advertiser')->count();
    }

    public function publisherCount(): int
    {
        return $this->queryForRole('publisher')->count();
    }

    public function queryForRole(string $roleName): Builder
    {
        $role = Role::query()->where('name', $roleName)->first();

        $query = User::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->with(['roles', 'activeRoleRelation'])
            ->orderBy('name');

        if (!$role) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('roles', fn (Builder $q) => $q->where('roles.id', $role->id));
    }

    public function paginate(string $roleName, ?string $search = null, int $perPage = 25): LengthAwarePaginator
    {
        $query = $this->queryForRole($roleName);

        if (filled($search)) {
            $term = '%' . trim($search) . '%';
            $query->where(function (Builder $q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * @return Collection<int, User>
     */
    public function collect(string $audience, ?array $selectedIds = null): Collection
    {
        return match ($audience) {
            'advertisers' => $this->queryForRole('advertiser')->get(),
            'publishers' => $this->queryForRole('publisher')->get(),
            'both' => $this->queryForRole('advertiser')
                ->get()
                ->merge($this->queryForRole('publisher')->get())
                ->unique('id')
                ->values(),
            'selected' => User::query()
                ->whereIn('id', $selectedIds ?: [])
                ->whereNotNull('email')
                ->orderBy('name')
                ->get(),
            default => collect(),
        };
    }

    public function exportCsv(string $roleName): StreamedResponse
    {
        $filename = $roleName . '-audience-' . now()->format('Y-m-d-His') . '.csv';
        $users = $this->queryForRole($roleName)->get();

        return response()->streamDownload(function () use ($users, $roleName) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'id',
                'name',
                'email',
                'audience_role',
                'all_roles',
                'active_role',
                'email_verified',
                'registered_at',
            ]);

            foreach ($users as $user) {
                fputcsv($out, [
                    $user->id,
                    $user->name,
                    $user->email,
                    $roleName,
                    $user->roles->pluck('name')->implode('|'),
                    $user->activeRole(),
                    $user->hasVerifiedEmail() ? 'yes' : 'no',
                    optional($user->created_at)?->toDateTimeString(),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function stats(): array
    {
        return [
            'advertisers' => $this->advertiserCount(),
            'publishers' => $this->publisherCount(),
            'both_unique' => $this->collect('both')->count(),
        ];
    }
}
