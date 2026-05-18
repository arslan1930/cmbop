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
}