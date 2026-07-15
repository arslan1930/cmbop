<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Site;
use App\Models\Country;
use App\Models\Language;
use App\Models\Category;
use App\Models\User;
use App\Models\Role;
use App\Mail\NewSiteNotification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SiteController extends Controller
{
    public function index()
    {
        $countries = Country::orderBy('name')->get();
        $categories = Category::orderBy('group')->orderBy('name')->get();
        $languages = Language::orderBy('name')->get();
        
        return view('publisher.websites', compact('countries', 'categories', 'languages'));
    }

    public function getCountryLanguages($countryCode)
    {
        $country = Country::where('code', $countryCode)->first();
        
        if (!$country) {
            return response()->json([]);
        }
        
        $languages = DB::table('country_language')
            ->join('languages', 'country_language.language_id', '=', 'languages.id')
            ->where('country_language.country_id', $country->id)
            ->select('languages.code', 'languages.name')
            ->get();
        
        return response()->json($languages);
    }

    public function store(Request $request)
    {
        // Normalize URL
        $url = $request->siteUrl;
        if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
            $url = "https://" . $url;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (!$host) {
            return back()->withErrors(['siteUrl' => 'Invalid URL'])->withInput();
        }

        $domain = preg_replace('/^www\./', '', strtolower($host));

        // Handle categories - get as array from multi-select
        $categories = $request->categories;
        if (is_string($categories)) {
            $categories = json_decode($categories, true);
        }
        
        // If categories is a comma-separated string, explode it
        if (is_string($categories) && str_contains($categories, ',')) {
            $categories = array_map('trim', explode(',', $categories));
        }
        
        // If no categories array, try to get from old category field
        if (empty($categories) && $request->has('category')) {
            if (str_contains($request->category, ',')) {
                $categories = array_map('trim', explode(',', $request->category));
            } else {
                $categories = [$request->category];
            }
        }
        
        // Store ALL categories as comma-separated string in category column (for backward compatibility and easy searching)
        $primaryCategory = !empty($categories) ? implode(',', $categories) : $request->category;
        
        // Store as array (model cast will handle JSON conversion)
        $categoriesArray = !empty($categories) ? $categories : null;

        $validator = Validator::make($request->all(), [
            'siteName'        => 'required|string|max:255',
            'siteUrl'         => 'required|url|max:255',
            'exampleUrl'      => 'required|url|max:255',
            'da'              => 'required|integer|min:0|max:100',
            'dr'              => 'required|integer|min:0|max:100',
            'traffic'         => 'required|integer|min:0',
            'country'         => 'required|string|size:2',
            'language'        => 'required|string|size:2',
            'categories'      => 'required|array|min:1|max:7',
            'price'           => 'required|numeric|min:0',
            'turnaround_time' => 'required|string|in:24h,48h,3days,5days,7days',
            'publicationTime' => 'required|string|max:20|in:6months,1year,permanent',
            'link_type'       => 'required|in:dofollow,nofollow',
            'siteDescription' => 'required|string|min:50',
            'price_sensitive.*' => 'nullable|numeric|min:0',
        ]);

        // Prevent duplicates for same publisher
        $validator->after(function ($validator) use ($domain) {
            if (Site::where('publisher_id', auth()->id())->where('domain', $domain)->exists()) {
                $validator->errors()->add('siteUrl', 'You have already added this website.');
            }
        });

        // Prevent adding a domain that already exists in the system
        $validator->after(function ($validator) use ($domain) {
            $existingSite = Site::where('domain', $domain)->exists();
            if ($existingSite) {
                $validator->errors()->add('siteUrl', 'This website domain is already registered in our system by another publisher. Each domain can only be listed once.');
            }
        });

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        // Sanitize description
        $cleanDescription = strip_tags($request->siteDescription, '<p><a><b><strong><i><ul><ol><li><br>');

        $site = null;

        DB::transaction(function () use ($request, $domain, $cleanDescription, $categoriesArray, $primaryCategory, &$site) {
            $site = new Site();

            $site->publisher_id      = auth()->id();
            $site->site_name         = $request->siteName;
            $site->site_url          = $request->siteUrl;
            $site->domain            = $domain;
            $site->example_url       = $request->exampleUrl;
            $site->da                = $request->da;
            $site->dr                = $request->dr;
            $site->traffic           = $request->traffic;
            $site->country           = $request->country;
            $site->language          = $request->language;
            $site->category          = $primaryCategory; // Store all categories as comma-separated string
            $site->categories        = $categoriesArray;   // Store as array (model cast handles JSON)
            $site->price             = $request->price;
            $site->turnaround_time   = $request->turnaround_time;
            $site->publication_time  = $request->publicationTime;
            $site->link_type         = $request->link_type;

            // Tags
            $site->sponsored         = $request->has('sponsored');
            $site->partner_material  = $request->has('partner_material');
            $site->as_you_prefer     = $request->has('as_you_prefer');

            $site->description       = $cleanDescription;
            $site->verified          = false;
            $site->active            = false;

            // Sensitive prices
            $sensitivePrices = [];
            foreach (['crypto','trading','CBD','forex'] as $topic) {
                if ($request->input("sensitive.$topic")) {
                    $sensitivePrices[$topic] = $request->input("price_sensitive.$topic");
                }
            }
            $site->sensitive_prices = !empty($sensitivePrices) ? json_encode($sensitivePrices) : null;

            $site->save();
        });

        // Send email notification
        if ($site) {
            try {
                $admins = User::where('active_role_id', function($query) {
                    $query->select('id')
                          ->from('roles')
                          ->where('name', 'admin')
                          ->limit(1);
                })->get();
                
                if ($admins->count() > 0) {
                    foreach ($admins as $admin) {
                        Mail::to($admin->email)->send(new NewSiteNotification($site));
                    }
                } else {
                    $defaultAdminEmail = config('mail.admin_email', 'admin@yourdomain.com');
                    Mail::to($defaultAdminEmail)->send(new NewSiteNotification($site));
                }
            } catch (\Exception $e) {
                Log::error('Failed to send email notification: ' . $e->getMessage());
            }
        }

        return redirect()->back()->with('success', 'Site submitted successfully! Admin will review and activate it within 24-48 hours.');
    }

    public function ajax(Request $request)
    {
        $query = $request->get('query');

        $sites = Site::where('publisher_id', auth()->id())
            ->when($query, function ($q) use ($query) {
                $q->where(function ($sub) use ($query) {
                    $sub->where('site_name', 'like', "%{$query}%")
                        ->orWhere('site_url', 'like', "%{$query}%")
                        ->orWhere('domain', 'like', "%{$query}%");
                });
            })
            ->latest()
            ->paginate(20);

        return view('publisher.sites.partials.table', compact('sites'))->render();
    }

    public function update(Request $request, $id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);

        // Handle categories - get as array from multi-select
        $categories = $request->categories;
        if (is_string($categories)) {
            $categories = json_decode($categories, true);
        }
        
        // If categories is a comma-separated string, explode it
        if (is_string($categories) && str_contains($categories, ',')) {
            $categories = array_map('trim', explode(',', $categories));
        }
        
        if (empty($categories) && $request->has('category')) {
            if (str_contains($request->category, ',')) {
                $categories = array_map('trim', explode(',', $request->category));
            } else {
                $categories = [$request->category];
            }
        }
        
        // Store ALL categories as comma-separated string in category column
        $primaryCategory = !empty($categories) ? implode(',', $categories) : $site->category;
        
        // Store as array (model cast handles JSON)
        $categoriesArray = !empty($categories) ? $categories : null;

        $validator = Validator::make($request->all(), [
            'exampleUrl'      => 'required|url|max:255',
            'da'              => 'required|integer|min:0|max:100',
            'dr'              => 'required|integer|min:0|max:100',
            'traffic'         => 'required|integer|min:0',
            'country'         => 'required|string|size:2',
            'language'        => 'required|string|size:2',
            'categories'      => 'required|array|min:1|max:7',
            'price'           => 'required|numeric|min:0',
            'turnaround_time' => 'required|string|in:24h,48h,3days,5days,7days',
            'publicationTime' => 'required|string|max:20|in:6months,1year,permanent',
            'link_type'       => 'required|in:dofollow,nofollow',
            'siteDescription' => 'required|string|min:50',
            'price_sensitive.*' => 'nullable|numeric|min:0',
        ]);

        // Check if domain is being changed
        $validator->after(function ($validator) use ($request, $site) {
            $newDomain = null;
            if ($request->has('siteUrl')) {
                $url = $request->siteUrl;
                if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
                    $url = "https://" . $url;
                }
                $host = parse_url($url, PHP_URL_HOST);
                if ($host) {
                    $newDomain = preg_replace('/^www\./', '', strtolower($host));
                }
            }
            
            if ($newDomain && $newDomain !== $site->domain) {
                $existingSite = Site::where('domain', $newDomain)
                    ->where('id', '!=', $site->id)
                    ->exists();
                if ($existingSite) {
                    $validator->errors()->add('siteUrl', 'This website domain is already registered in our system by another publisher.');
                }
            }
        });

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $cleanDescription = strip_tags($request->siteDescription, '<p><a><b><strong><i><ul><ol><li><br>');

        DB::transaction(function () use ($site, $request, $cleanDescription, $categoriesArray, $primaryCategory) {
            $site->example_url       = $request->exampleUrl;
            $site->da                = $request->da;
            $site->dr                = $request->dr;
            $site->traffic           = $request->traffic;
            $site->country           = $request->country;
            $site->language          = $request->language;
            $site->category          = $primaryCategory; // Store all categories as comma-separated string
            $site->categories        = $categoriesArray;   // Store as array (model cast handles JSON)
            $site->price             = $request->price;
            $site->turnaround_time   = $request->turnaround_time;
            $site->publication_time  = $request->publicationTime;
            $site->link_type         = $request->link_type;

            // Tags
            $site->sponsored         = $request->has('sponsored');
            $site->partner_material  = $request->has('partner_material');
            $site->as_you_prefer     = $request->has('as_you_prefer');

            $site->description       = $cleanDescription;

            // Reset approval when edited
            $site->verified = false;
            $site->active = false;

            $sensitivePrices = [];
            foreach (['crypto','trading','CBD','forex'] as $topic) {
                if ($request->input("sensitive.$topic")) {
                    $sensitivePrices[$topic] = $request->input("price_sensitive.$topic");
                }
            }
            $site->sensitive_prices = !empty($sensitivePrices) ? json_encode($sensitivePrices) : null;

            $site->save();
        });

        // Send email notification for update
        try {
            $admins = User::where('active_role_id', function($query) {
                $query->select('id')
                      ->from('roles')
                      ->where('name', 'admin')
                      ->limit(1);
            })->get();
            
            if ($admins->count() > 0) {
                foreach ($admins as $admin) {
                    Mail::to($admin->email)->send(new NewSiteNotification($site, 'update'));
                }
            } else {
                $defaultAdminEmail = config('mail.admin_email', 'admin@yourdomain.com');
                Mail::to($defaultAdminEmail)->send(new NewSiteNotification($site, 'update'));
            }
        } catch (\Exception $e) {
            Log::error('Failed to send email notification: ' . $e->getMessage());
        }

        return redirect()->back()->with('success', 'Site updated successfully! It will be reviewed again.');
    }

    public function destroy($id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);

        if ($site->verified || $site->active) {
            return redirect()->back()->with('error', 'You cannot delete an active or verified site.');
        }

        $site->delete();

        return redirect()->back()->with('success', 'Site deleted successfully!');
    }

    /**
     * Download a CSV template for agency bulk site import (150+ sites).
     */
    public function bulkTemplate()
    {
        $headers = [
            'site_name',
            'site_url',
            'example_url',
            'da',
            'dr',
            'traffic',
            'country',
            'language',
            'categories',
            'price',
            'turnaround_time',
            'publication_time',
            'link_type',
            'description',
            'sponsored',
            'partner_material',
            'as_you_prefer',
            'price_crypto',
            'price_trading',
            'price_CBD',
            'price_forex',
        ];

        $example = [
            'My Agency Blog',
            'https://example-agency-blog.com',
            'https://example-agency-blog.com/sample-post',
            '45',
            '40',
            '15000',
            'US',
            'en',
            'Business & Finance|Technology',
            '120',
            '3days',
            'permanent',
            'dofollow',
            'High-quality editorial site covering business and technology topics for professional audiences.',
            '0',
            '1',
            '0',
            '',
            '',
            '',
            '',
        ];

        $callback = function () use ($headers, $example) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel
            fputcsv($out, $headers);
            fputcsv($out, $example);
            fclose($out);
        };

        return response()->streamDownload($callback, 'agency-sites-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Bulk-import websites from CSV for agencies that manage many domains.
     */
    public function bulkImport(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:5120',
        ], [
            'csv_file.required' => 'Please upload a CSV file.',
            'csv_file.mimes'    => 'Upload a .csv file.',
        ]);

        $maxRows = 200;
        $handle = fopen($request->file('csv_file')->getRealPath(), 'r');
        if ($handle === false) {
            return back()->with('error', 'Could not read the uploaded file.');
        }

        // Skip UTF-8 BOM if present
        $firstBytes = fread($handle, 3);
        if ($firstBytes !== chr(0xEF) . chr(0xBB) . chr(0xBF)) {
            rewind($handle);
        }

        $headerRow = fgetcsv($handle);
        if (!$headerRow) {
            fclose($handle);
            return back()->with('error', 'CSV is empty.');
        }

        $headers = array_map(function ($h) {
            return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)));
        }, $headerRow);

        $requiredHeaders = [
            'site_name', 'site_url', 'example_url', 'da', 'dr', 'traffic',
            'country', 'language', 'categories', 'price', 'turnaround_time',
            'publication_time', 'link_type', 'description',
        ];

        foreach ($requiredHeaders as $required) {
            if (!in_array($required, $headers, true)) {
                fclose($handle);
                return back()->with('error', "CSV is missing required column: {$required}. Download the template and try again.");
            }
        }

        $validCategoryNames = Category::pluck('name')->map(fn ($n) => strtolower($n))->all();
        $publisherId = auth()->id();

        $created = 0;
        $failed = [];
        $seenDomainsInFile = [];
        $rowNumber = 1; // header is row 1

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            // Skip completely empty rows
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            if (($created + count($failed)) >= $maxRows) {
                $failed[] = [
                    'row' => $rowNumber,
                    'site' => '',
                    'errors' => ["Maximum {$maxRows} rows per upload. Remaining rows were skipped."],
                ];
                break;
            }

            // Pad/truncate to header length
            if (count($row) < count($headers)) {
                $row = array_pad($row, count($headers), '');
            }
            $data = array_combine($headers, array_slice($row, 0, count($headers)));
            if ($data === false) {
                $failed[] = ['row' => $rowNumber, 'site' => '', 'errors' => ['Could not parse row.']];
                continue;
            }

            $data = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $data);

            // Skip the sample template row if left unchanged
            if (($data['site_url'] ?? '') === 'https://example-agency-blog.com') {
                continue;
            }

            $parsed = $this->normalizeBulkRow($data, $validCategoryNames);

            if (!empty($parsed['errors'])) {
                $failed[] = [
                    'row' => $rowNumber,
                    'site' => $data['site_url'] ?? ($data['site_name'] ?? ''),
                    'errors' => $parsed['errors'],
                ];
                continue;
            }

            $domain = $parsed['domain'];

            if (isset($seenDomainsInFile[$domain])) {
                $failed[] = [
                    'row' => $rowNumber,
                    'site' => $data['site_url'],
                    'errors' => ["Duplicate domain in this file (also on row {$seenDomainsInFile[$domain]})."],
                ];
                continue;
            }
            $seenDomainsInFile[$domain] = $rowNumber;

            if (Site::where('domain', $domain)->exists()) {
                $failed[] = [
                    'row' => $rowNumber,
                    'site' => $data['site_url'],
                    'errors' => ['This domain is already registered in the system.'],
                ];
                continue;
            }

            try {
                DB::transaction(function () use ($parsed, $publisherId) {
                    $site = new Site();
                    $site->publisher_id     = $publisherId;
                    $site->site_name        = $parsed['site_name'];
                    $site->site_url         = $parsed['site_url'];
                    $site->domain           = $parsed['domain'];
                    $site->example_url      = $parsed['example_url'];
                    $site->da               = $parsed['da'];
                    $site->dr               = $parsed['dr'];
                    $site->traffic          = $parsed['traffic'];
                    $site->country          = $parsed['country'];
                    $site->language         = $parsed['language'];
                    $site->category         = $parsed['primary_category'];
                    $site->categories       = $parsed['categories'];
                    $site->price            = $parsed['price'];
                    $site->turnaround_time  = $parsed['turnaround_time'];
                    $site->publication_time = $parsed['publication_time'];
                    $site->link_type        = $parsed['link_type'];
                    $site->sponsored        = $parsed['sponsored'];
                    $site->partner_material = $parsed['partner_material'];
                    $site->as_you_prefer    = $parsed['as_you_prefer'];
                    $site->description      = $parsed['description'];
                    $site->sensitive_prices = $parsed['sensitive_prices'];
                    $site->verified         = false;
                    $site->active           = false;
                    $site->save();
                });
                $created++;
            } catch (\Exception $e) {
                Log::error('Bulk site import row failed: ' . $e->getMessage(), [
                    'row' => $rowNumber,
                    'user_id' => $publisherId,
                ]);
                $failed[] = [
                    'row' => $rowNumber,
                    'site' => $data['site_url'] ?? '',
                    'errors' => ['Could not save this row. Please check the data.'],
                ];
            }
        }

        fclose($handle);

        if ($created > 0) {
            try {
                $user = auth()->user();
                $admins = User::where('active_role_id', function ($query) {
                    $query->select('id')->from('roles')->where('name', 'admin')->limit(1);
                })->get();

                $subject = "Bulk site import: {$created} site(s) from {$user->name}";
                $body = "Publisher {$user->name} ({$user->email}) submitted {$created} website(s) via bulk CSV import.\n"
                    . "Failed rows: " . count($failed) . "\n"
                    . "Please review them in the admin Sites panel.";

                $recipients = $admins->count() > 0
                    ? $admins->pluck('email')->all()
                    : [config('mail.admin_email', 'admin@yourdomain.com')];

                foreach ($recipients as $email) {
                    Mail::raw($body, function ($message) use ($email, $subject) {
                        $message->to($email)->subject($subject);
                    });
                }
            } catch (\Exception $e) {
                Log::error('Bulk import admin notification failed: ' . $e->getMessage());
            }
        }

        $message = "{$created} site(s) submitted for review.";
        if (count($failed) > 0) {
            $message .= ' ' . count($failed) . ' row(s) failed — see details below.';
        }

        return back()
            ->with($created > 0 ? 'success' : 'error', $message)
            ->with('bulk_import_created', $created)
            ->with('bulk_import_failures', $failed);
    }

    /**
     * Normalize + validate one CSV row into site attributes.
     */
    private function normalizeBulkRow(array $data, array $validCategoryNamesLower): array
    {
        $errors = [];

        $siteUrl = $data['site_url'] ?? '';
        if ($siteUrl !== '' && !preg_match('~^(?:f|ht)tps?://~i', $siteUrl)) {
            $siteUrl = 'https://' . $siteUrl;
        }

        $host = parse_url($siteUrl, PHP_URL_HOST);
        $domain = $host ? preg_replace('/^www\./', '', strtolower($host)) : null;
        if (!$domain) {
            $errors[] = 'Invalid site_url.';
        }

        $exampleUrl = $data['example_url'] ?? '';
        if ($exampleUrl !== '' && !preg_match('~^(?:f|ht)tps?://~i', $exampleUrl)) {
            $exampleUrl = 'https://' . $exampleUrl;
        }

        $categoryRaw = $data['categories'] ?? '';
        $categories = array_values(array_filter(array_map('trim', preg_split('/[|,]/', $categoryRaw) ?: [])));
        if (count($categories) < 1) {
            $errors[] = 'At least one category is required (use | or , between names).';
        } elseif (count($categories) > 7) {
            $errors[] = 'Maximum 7 categories allowed.';
        } else {
            foreach ($categories as $cat) {
                if (!in_array(strtolower($cat), $validCategoryNamesLower, true)) {
                    $errors[] = "Unknown category: {$cat}";
                }
            }
        }

        $description = strip_tags((string) ($data['description'] ?? ''), '<p><a><b><strong><i><ul><ol><li><br>');

        $payload = [
            'site_name'        => $data['site_name'] ?? '',
            'site_url'         => $siteUrl,
            'example_url'      => $exampleUrl,
            'da'               => $data['da'] ?? null,
            'dr'               => $data['dr'] ?? null,
            'traffic'          => $data['traffic'] ?? null,
            'country'          => strtoupper($data['country'] ?? ''),
            'language'         => strtolower($data['language'] ?? ''),
            'categories'       => $categories,
            'price'            => $data['price'] ?? null,
            'turnaround_time'  => $data['turnaround_time'] ?? '',
            'publication_time' => $data['publication_time'] ?? '',
            'link_type'        => strtolower($data['link_type'] ?? ''),
            'description'      => $description,
        ];

        $validator = Validator::make($payload, [
            'site_name'        => 'required|string|max:255',
            'site_url'         => 'required|url|max:255',
            'example_url'      => 'required|url|max:255',
            'da'               => 'required|integer|min:0|max:100',
            'dr'               => 'required|integer|min:0|max:100',
            'traffic'          => 'required|integer|min:0',
            'country'          => 'required|string|size:2',
            'language'         => 'required|string|size:2',
            'categories'       => 'required|array|min:1|max:7',
            'price'            => 'required|numeric|min:0',
            'turnaround_time'  => 'required|in:24h,48h,3days,5days,7days',
            'publication_time' => 'required|in:6months,1year,permanent',
            'link_type'        => 'required|in:dofollow,nofollow',
            'description'      => 'required|string|min:50',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $msg) {
                $errors[] = $msg;
            }
        }

        $sensitivePrices = [];
        foreach (['crypto' => 'price_crypto', 'trading' => 'price_trading', 'CBD' => 'price_CBD', 'forex' => 'price_forex'] as $topic => $col) {
            $val = $data[$col] ?? '';
            if ($val !== '' && $val !== null) {
                if (!is_numeric($val) || $val < 0) {
                    $errors[] = "{$col} must be a number ≥ 0.";
                } else {
                    $sensitivePrices[$topic] = (float) $val;
                }
            }
        }

        if (!empty($errors)) {
            return ['errors' => array_values(array_unique($errors))];
        }

        return [
            'errors'            => [],
            'site_name'         => $payload['site_name'],
            'site_url'          => $payload['site_url'],
            'domain'            => $domain,
            'example_url'       => $payload['example_url'],
            'da'                => (int) $payload['da'],
            'dr'                => (int) $payload['dr'],
            'traffic'           => (int) $payload['traffic'],
            'country'           => $payload['country'],
            'language'          => $payload['language'],
            'primary_category'  => implode(',', $categories),
            'categories'        => $categories,
            'price'             => $payload['price'],
            'turnaround_time'   => $payload['turnaround_time'],
            'publication_time'  => $payload['publication_time'],
            'link_type'         => $payload['link_type'],
            'sponsored'         => $this->csvBool($data['sponsored'] ?? '0'),
            'partner_material'  => $this->csvBool($data['partner_material'] ?? '0'),
            'as_you_prefer'     => $this->csvBool($data['as_you_prefer'] ?? '0'),
            'description'       => $description,
            'sensitive_prices'  => !empty($sensitivePrices) ? json_encode($sensitivePrices) : null,
        ];
    }

    private function csvBool($value): bool
    {
        $v = strtolower(trim((string) $value));
        return in_array($v, ['1', 'true', 'yes', 'y'], true);
    }
}