<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OnDemandContact;
use App\Support\PhoneCallingCodes;
use App\Support\UserFacingError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OnDemandContactController extends Controller
{
    public function index(Request $request): View
    {
        $this->ensureAdmin();

        $search = search_text($request->query('q'));
        $ready = OnDemandContact::tableAvailable();

        $contacts = $ready
            ? OnDemandContact::query()
                ->when($search !== '', function ($query) use ($search) {
                    $like = '%'.$search.'%';
                    $query->where(function ($inner) use ($like) {
                        $inner->where('site_url', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('whatsapp', 'like', $like)
                            ->orWhere('contacted_via_email', 'like', $like)
                            ->orWhere('notes', 'like', $like);
                    });
                })
                ->latest('id')
                ->paginate(30)
                ->withQueryString()
            : OnDemandContact::query()->whereRaw('1 = 0')->paginate(30);

        $editing = null;
        $editId = (int) $request->query('edit', 0);
        if ($editId > 0 && $ready) {
            $editing = OnDemandContact::query()->find($editId);
        }

        return view('admin.on-demand.index', [
            'contacts' => $contacts,
            'editing' => $editing,
            'search' => $search,
            'tableReady' => $ready,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureAdmin();

        if (! OnDemandContact::tableAvailable()) {
            return back()->with('error', 'On-demand contacts are not available yet.');
        }

        $data = $this->validated($request);
        $data['created_by'] = $request->user()?->id;

        try {
            OnDemandContact::query()->create($data);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', UserFacingError::message($e, 'Could not save this contact.'));
        }

        return redirect()->route('admin.sites.on-demand.index')->with('success', 'On-demand contact saved.');
    }

    public function update(Request $request, OnDemandContact $onDemandContact): RedirectResponse
    {
        $this->ensureAdmin();

        $data = $this->validated($request, $onDemandContact->id);

        try {
            $onDemandContact->update($data);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', UserFacingError::message($e, 'Could not update this contact.'));
        }

        return redirect()->route('admin.sites.on-demand.index')->with('success', 'On-demand contact updated.');
    }

    public function destroy(OnDemandContact $onDemandContact): RedirectResponse
    {
        $this->ensureAdmin();

        $onDemandContact->delete();

        return redirect()->route('admin.sites.on-demand.index')->with('success', 'On-demand contact deleted.');
    }

    /**
     * @return array{site_url: list<string>, email: list<string>, whatsapp: list<string>, contacted_via_email: list<string>, notes: ?string}
     */
    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $sites = OnDemandContact::normalizeSiteNames($request->input('site_url'));
        $emails = array_slice(OnDemandContact::decodeList($request->input('email')), 0, OnDemandContact::MAX_LIST_ITEMS);
        $whatsapp = array_slice(
            PhoneCallingCodes::combineList($request->input('whatsapp'), $request->input('whatsapp_code')),
            0,
            OnDemandContact::MAX_LIST_ITEMS
        );
        $via = array_slice(OnDemandContact::decodeList($request->input('contacted_via_email')), 0, OnDemandContact::MAX_LIST_ITEMS);

        $errors = [];
        if ($sites === []) {
            $errors['site_url'] = 'Add at least one site name.';
        }
        if ($emails === [] && $whatsapp === [] && $via === []) {
            $errors['email'] = 'Add at least one contact method: email, WhatsApp, or the inbox they wrote to.';
        }

        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Enter a valid email.';
                break;
            }
        }
        foreach ($via as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['contacted_via_email'] = 'Enter a valid mail they contacted us on.';
                break;
            }
        }
        foreach ($whatsapp as $number) {
            if (mb_strlen($number) > 80) {
                $errors['whatsapp'] = 'Each WhatsApp contact can be at most 80 characters.';
                break;
            }
        }
        foreach ($sites as $host) {
            if (! str_contains($host, '.') || str_contains($host, ' ')) {
                $errors['site_url'] = 'Enter a site name such as example.com (no http or https).';
                break;
            }
        }

        $taken = array_map('strtolower', OnDemandContact::takenSiteNames($ignoreId));
        foreach ($sites as $host) {
            if (in_array(strtolower($host), $taken, true)) {
                $errors['site_url'] = $host.' is already in the on-demand list.';
                break;
            }
        }

        $notes = $request->input('notes');
        $notes = is_scalar($notes) ? trim((string) $notes) : '';
        if (mb_strlen($notes) > 5000) {
            $errors['notes'] = 'Notes can be at most 5000 characters.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'site_url' => $sites,
            'email' => $emails,
            'whatsapp' => $whatsapp,
            'contacted_via_email' => $via,
            'notes' => $notes !== '' ? $notes : null,
        ];
    }

    private function ensureAdmin(): void
    {
        $user = auth()->user();
        if (! $user || ! $user->isAdmin()) {
            abort(403);
        }
    }
}
