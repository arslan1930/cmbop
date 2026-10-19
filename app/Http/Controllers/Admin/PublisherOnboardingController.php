<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BulkSiteRequest;
use App\Models\Category;
use App\Models\User;
use App\Services\Marketplace\CountryLanguagePairs;
use App\Services\Partners\PartnerAccountService;
use App\Services\Staff\PublisherOnboardingService;
use App\Services\Staff\StaffAssignedListingService;
use App\Support\MarketplacePicker;
use App\Support\UserFacingError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PublisherOnboardingController extends Controller
{
    public function __construct(
        private PublisherOnboardingService $onboarding,
        private StaffAssignedListingService $listings,
    ) {}

    public function create(Request $request): View
    {
        return view('admin.publisher-onboard', $this->formPayload($request));
    }

    public function store(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
            'name' => 'nullable|string|max:255',
            'country' => 'nullable|string|size:2',
            'language' => 'nullable|string|size:2',
            'rows' => 'nullable|string|max:500000',
            'csv_file' => 'nullable|file|max:5120',
            'written_request' => 'accepted',
        ], [
            'written_request.accepted' => 'Confirm you have a written request from this publisher’s account email.',
        ]);

        $mode = $this->inputMode($request);
        $validator->after(function ($validator) use ($request, $mode) {
            if ($mode === 'bulk') {
                $this->assertBulkSource($validator, $request);
            }
        });

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $rawRows = $mode === 'bulk'
            ? $this->publisherSiteRows($request)
            : [$this->listings->fieldsFromStaffForm($this->singleFormInput($request))];

        if (count($rawRows) > BulkSiteRequest::MAX_SITES_PER_REQUEST) {
            return back()->withErrors([
                'rows' => 'Add at most '.BulkSiteRequest::MAX_SITES_PER_REQUEST.' sites at once.',
            ])->withInput();
        }

        $email = strtolower(trim((string) $request->input('email')));
        foreach ($rawRows as $index => $row) {
            $rawRows[$index]['email'] = $email;
            if (trim((string) ($row['name'] ?? '')) === '') {
                $rawRows[$index]['name'] = trim((string) $request->input('name', ''));
            }
        }

        $preview = $this->onboarding->previewRows(
            $rawRows,
            $this->optionalCode($request->input('country')),
            $this->optionalCode($request->input('language'))
        );
        if ($preview['failures'] !== []) {
            return back()
                ->withErrors(['rows' => 'Fix the rows below. Nothing was saved.'])
                ->with('bulk_row_errors', $preview['failures'])
                ->withInput();
        }

        try {
            $result = $this->onboarding->commitReadyRows(
                $preview['ready'],
                $request->user(),
                $this->optionalCode($request->input('country')),
                trim((string) $request->input('name', '')) ?: null
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors([
                'save' => UserFacingError::message($e, 'We could not create this publisher. Nothing was saved.'),
            ])->withInput();
        }

        $publisher = $result['publishers'][0] ?? null;
        $count = count($result['sites']);
        $created = $publisher && in_array((int) $publisher->id, $result['created_user_ids'], true);
        $this->markCatalogPartners($result['publishers'] ?? [], $request);

        return redirect()
            ->route('admin.publishers.onboardings')
            ->with('success', $this->successMessage($count, $created, $publisher))
            ->with('success_actions', $this->successActions($publisher));
    }

    public function createBulk(): View
    {
        return view('admin.publisher-onboard-bulk', [
            'preview' => session('onboard_preview'),
        ]);
    }

    public function previewBulk(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'rows' => 'nullable|string|max:500000',
            'csv_file' => 'nullable|file|max:5120',
        ]);
        $validator->after(fn ($v) => $this->assertBulkSource($v, $request));
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $rawRows = $this->onboardingRows($request);
        if (count($rawRows) > BulkSiteRequest::MAX_SITES_PER_REQUEST) {
            return back()->withErrors([
                'rows' => 'Add at most '.BulkSiteRequest::MAX_SITES_PER_REQUEST.' sites at once.',
            ])->withInput();
        }

        $preview = $this->onboarding->previewRows($rawRows);

        return back()
            ->withInput()
            ->with('onboard_preview', [
                'groups' => $preview['groups'],
                'failures' => $preview['failures'],
                'ready_count' => count($preview['ready']),
                'publisher_count' => count($preview['groups']),
                'can_commit' => $preview['failures'] === [] && $preview['ready'] !== [],
            ]);
    }

    public function storeBulk(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'rows' => 'nullable|string|max:500000',
            'csv_file' => 'nullable|file|max:5120',
            'confirm' => 'accepted',
        ], [
            'confirm.accepted' => 'Preview the file first, then confirm to create the accounts.',
        ]);
        $validator->after(fn ($v) => $this->assertBulkSource($v, $request));
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $rawRows = $this->onboardingRows($request);
        if (count($rawRows) > BulkSiteRequest::MAX_SITES_PER_REQUEST) {
            return back()->withErrors([
                'rows' => 'Add at most '.BulkSiteRequest::MAX_SITES_PER_REQUEST.' sites at once.',
            ])->withInput();
        }

        $preview = $this->onboarding->previewRows($rawRows);
        if ($preview['failures'] !== []) {
            return back()
                ->withInput()
                ->withErrors(['rows' => 'Fix the rows below. Nothing was saved.'])
                ->with('onboard_preview', [
                    'groups' => $preview['groups'],
                    'failures' => $preview['failures'],
                    'ready_count' => count($preview['ready']),
                    'publisher_count' => count($preview['groups']),
                    'can_commit' => false,
                ]);
        }

        try {
            $result = $this->onboarding->commitReadyRows($preview['ready'], $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors([
                'save' => UserFacingError::message($e, 'We could not onboard these publishers. Nothing was saved.'),
            ])->withInput();
        }

        $publisherCount = count($result['publishers']);
        $siteCount = count($result['sites']);
        $this->markCatalogPartners($result['publishers'] ?? [], $request);

        return redirect()
            ->route('admin.publishers.onboardings')
            ->with('success', $publisherCount.' publisher'.($publisherCount === 1 ? '' : 's').' onboarded with '.$siteCount.' site'.($siteCount === 1 ? '' : 's').'. One invite email was sent per publisher.');
    }

    public function index(Request $request): View
    {
        $status = (string) $request->query('status', '');
        $query = $this->onboarding->onboardedPublishers();
        if ($status === PublisherOnboardingService::STATUS_INVITED) {
            $query->whereNull('staff_onboarded_at');
        } elseif ($status === PublisherOnboardingService::STATUS_ACTIVATED) {
            $query->whereNotNull('staff_onboarded_at');
        }

        $publishers = $query->paginate(25)->withQueryString();

        return view('admin.publisher-onboardings', compact('publishers', 'status'));
    }

    public function resend(Request $request, User $user): RedirectResponse
    {
        if (! $user->isStaffCreated() && ! $user->hasPendingStaffInvites()) {
            return back()->with('error', 'This account is not waiting on a staff onboarding invite.');
        }

        $sites = $user->sites()
            ->whereNull('publisher_accepted_at')
            ->whereNotNull('assigned_by_user_id')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->all();

        $sent = $this->onboarding->sendOnboardingMail($user, $sites, resend: true);
        $this->onboarding->notifyPublisher($user, $sites);

        return $sent
            ? back()->with('success', 'Invite resent to '.$user->email.'.')
            : back()->with('error', 'The listing is still waiting, but we could not email the publisher.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formPayload(Request $request): array
    {
        $languages = MarketplacePicker::cachedLanguages();
        $countries = MarketplacePicker::cachedCountries();
        $categories = Cache::remember('admin.marketplace.catalog_picker_names', 300, fn () => Category::catalogPickerNames());
        $countryLanguageMap = Cache::remember(
            'admin.marketplace.country_language_map',
            300,
            fn () => app(CountryLanguagePairs::class)->mapWithNames()
        );

        return [
            'languages' => $languages,
            'countries' => $countries,
            'categories' => $categories,
            'countryLanguageMap' => $countryLanguageMap,
            'mode' => old('mode', $request->query('mode', 'single')),
        ];
    }

    private function inputMode(Request $request): string
    {
        $mode = (string) $request->input('mode', 'single');

        return $mode === 'bulk' ? 'bulk' : 'single';
    }

    /**
     * @return list<array<string, string>>
     */
    private function publisherSiteRows(Request $request): array
    {
        $upload = $request->file('csv_file');

        return $upload instanceof UploadedFile
            ? $this->listings->rowsFromCsv($upload)
            : $this->listings->rowsFromPaste((string) $request->input('rows', ''));
    }

    /**
     * @return list<array<string, string>>
     */
    private function onboardingRows(Request $request): array
    {
        $upload = $request->file('csv_file');

        return $upload instanceof UploadedFile
            ? $this->listings->onboardingRowsFromCsv($upload)
            : $this->listings->onboardingRowsFromPaste((string) $request->input('rows', ''));
    }

    private function assertBulkSource($validator, Request $request): void
    {
        $hasFile = $request->hasFile('csv_file');
        $hasRows = trim((string) $request->input('rows', '')) !== '';
        if ($hasFile) {
            $upload = $request->file('csv_file');
            $ext = strtolower((string) ($upload instanceof UploadedFile ? $upload->getClientOriginalExtension() : ''));
            if (! $upload instanceof UploadedFile || $upload->getRealPath() === false) {
                $validator->errors()->add('csv_file', 'We could not read that file.');
            } elseif (! in_array($ext, ['csv', 'txt'], true)) {
                $validator->errors()->add('csv_file', 'Upload a .csv or .txt file.');
            }
        }
        if (! $hasFile && ! $hasRows) {
            $validator->errors()->add('rows', 'Paste the sites or upload a CSV.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function singleFormInput(Request $request): array
    {
        $input = $request->all();
        $input['country'] = $this->optionalCode($request->input('site_country'))
            ?: $this->optionalCode($request->input('country'));
        $input['language'] = $this->optionalCode($request->input('site_language'))
            ?: $this->optionalCode($request->input('language'));

        return $input;
    }

    private function optionalCode(mixed $value): ?string
    {
        $code = strtolower(trim((string) $value));

        return strlen($code) === 2 ? $code : null;
    }

    private function successMessage(int $siteCount, bool $created, ?User $publisher): string
    {
        $who = $publisher?->email ?? 'the publisher';
        $account = $created ? 'Created a publisher account for '.$who.'.' : 'Reused the existing account for '.$who.'.';
        $sites = $siteCount.' '.($siteCount === 1 ? 'site' : 'sites').' added as Invite to Accept.';

        return $account.' '.$sites.' One onboarding email was sent.';
    }

    /**
     * @return list<array{url: string, label: string}>
     */
    private function successActions(?User $publisher): array
    {
        $actions = [
            ['url' => route('admin.publishers.onboard', [], false), 'label' => 'Add another publisher'],
        ];
        if ($publisher) {
            $actions[] = ['url' => route('admin.users.show', $publisher, false), 'label' => 'Open user'];
            $actions[] = ['url' => route('admin.sites.index', ['publisher' => $publisher->id], false), 'label' => 'Open sites'];
        }

        return $actions;
    }

    /**
     * @param  list<User>  $publishers
     */
    private function markCatalogPartners(array $publishers, Request $request): void
    {
        if (! $request->boolean('as_partner') || $publishers === []) {
            return;
        }
        $accounts = app(PartnerAccountService::class);
        foreach ($publishers as $publisher) {
            if ($publisher instanceof User) {
                $accounts->mark($publisher, $request->user(), [
                    'legal_name' => $publisher->name,
                    'agreement_signed' => false,
                ]);
            }
        }
    }
}
