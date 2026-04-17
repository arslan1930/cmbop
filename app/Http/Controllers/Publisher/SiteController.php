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
    // Show the main view with form and sites list
    public function index()
    {
        // Get data directly from database without caching
        $countries = Country::orderBy('name')->get();
        $categories = Category::orderBy('group')->orderBy('name')->get();
        $languages = Language::orderBy('name')->get();
        
        return view('publisher.websites', compact('countries', 'categories', 'languages'));
    }

    // GET LANGUAGES FOR SELECTED COUNTRY (AJAX)
    public function getCountryLanguages($countryCode)
    {
        $country = Country::where('code', $countryCode)->first();
        
        if (!$country) {
            return response()->json([]);
        }
        
        // Get all languages spoken in this country
        $languages = DB::table('country_language')
            ->join('languages', 'country_language.language_id', '=', 'languages.id')
            ->where('country_language.country_id', $country->id)
            ->select('languages.code', 'languages.name')
            ->get();
        
        return response()->json($languages);
    }

    // STORE NEW SITE
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

        $validator = Validator::make($request->all(), [
            'siteName'        => 'required|string|max:255',
            'siteUrl'         => 'required|url|max:255',
            'exampleUrl'      => 'required|url|max:255',
            'da'              => 'required|integer|min:0|max:100',
            'dr'              => 'required|integer|min:0|max:100',
            'traffic'         => 'required|integer|min:0',
            'country'         => 'required|string|size:2',
            'language'        => 'required|string|size:2',
            'category'        => 'required|string|max:100',
            'price'           => 'required|numeric|min:0',
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

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        // Sanitize description
        $cleanDescription = strip_tags($request->siteDescription, '<p><a><b><strong><i><ul><ol><li><br>');

        $site = null;

        DB::transaction(function () use ($request, $domain, $cleanDescription, &$site) {

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
            $site->category          = $request->category;
            $site->price             = $request->price;
            $site->publication_time  = $request->publicationTime; // Stored as string: '6months', '1year', 'permanent'
            $site->link_type         = $request->link_type;

            // Tags (boolean columns)
            $site->sponsored         = $request->has('sponsored');
            $site->partner_material  = $request->has('partner_material');
            $site->as_you_prefer     = $request->has('as_you_prefer');

            $site->description       = $cleanDescription;
            $site->verified          = false;
            $site->active            = false;

            // Sensitive prices (encode array as JSON)
            $sensitivePrices = [];
            foreach (['crypto','trading','CBD','forex'] as $topic) {
                if ($request->input("sensitive.$topic")) {
                    $sensitivePrices[$topic] = $request->input("price_sensitive.$topic");
                }
            }
            $site->sensitive_prices = !empty($sensitivePrices) ? json_encode($sensitivePrices) : null;

            $site->save();
        });

        // Send email synchronously (immediately) - Find admin users
        if ($site) {
            try {
                // Find users with admin role using active_role_id
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
                    // Fallback: Send to default admin email if no admin users found
                    $defaultAdminEmail = config('mail.admin_email', 'admin@yourdomain.com');
                    Mail::to($defaultAdminEmail)->send(new NewSiteNotification($site));
                }
                
            } catch (\Exception $e) {
                Log::error('Failed to send email notification: ' . $e->getMessage());
            }
        }

        return redirect()->back()->with('success', 'Site submitted successfully! Admin will review and activate it within 24-48 hours.');
    }

    // AJAX LISTING
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

    // UPDATE SITE
    public function update(Request $request, $id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'exampleUrl'      => 'required|url|max:255',
            'da'              => 'required|integer|min:0|max:100',
            'dr'              => 'required|integer|min:0|max:100',
            'traffic'         => 'required|integer|min:0',
            'country'         => 'required|string|size:2',
            'language'        => 'required|string|size:2',
            'category'        => 'required|string|max:100',
            'price'           => 'required|numeric|min:0',
            'publicationTime' => 'required|string|max:20|in:6months,1year,permanent',
            'link_type'       => 'required|in:dofollow,nofollow',
            'siteDescription' => 'required|string|min:50',
            'price_sensitive.*' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $cleanDescription = strip_tags($request->siteDescription, '<p><a><b><strong><i><ul><ol><li><br>');

        DB::transaction(function () use ($site, $request, $cleanDescription) {

            $site->example_url       = $request->exampleUrl;
            $site->da                = $request->da;
            $site->dr                = $request->dr;
            $site->traffic           = $request->traffic;
            $site->country           = $request->country;
            $site->language          = $request->language;
            $site->category          = $request->category;
            $site->price             = $request->price;
            $site->publication_time  = $request->publicationTime; // Stored as string: '6months', '1year', 'permanent'
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

        // Send email synchronously for update
        try {
            // Find admin users
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

    // DELETE SITE
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