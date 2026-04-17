<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Site;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class SiteController extends Controller
{
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
            'country'         => 'required|string|max:10',
            'language'        => 'required|string|max:10',
            'category'        => 'required|string|max:50',
            'price'           => 'required|numeric|min:0',
            'publicationTime' => 'required|string|max:20',
            'link_type'       => 'required|in:dofollow,nofollow',
            'siteDescription' => 'required|string',
            'price_sensitive.*' => 'nullable|numeric|min:0',
        ], [
            'siteName.required'   => 'Site name is required.',
            'siteUrl.required'    => 'Site URL is required.',
            'siteUrl.url'         => 'Please enter a valid URL.',
            'exampleUrl.required' => 'Example URL is required.',
            'exampleUrl.url'      => 'Example URL must be valid.',
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

        DB::transaction(function () use ($request, $domain, $cleanDescription) {

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
            $site->publication_time  = $request->publicationTime;
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

        return redirect()->back()->with('success', 'Site added successfully! It will be reviewed and approved within 24-48 hours.');
    }

    // AJAX LISTING (KEEP THIS)
    public function ajax(Request $request)
    {
        $query = $request->get('query');

        $sites = Site::where('publisher_id', auth()->id())
            ->when($query, function ($q) use ($query) {
                $q->where(function ($sub) use ($query) {
                    $sub->where('site_name', 'like', "%{$query}%")
                        ->orWhere('site_url', 'like', "%{$query}%");
                });
            })
            ->latest()
            ->paginate(20);

        return view('publisher.sites.partials.table', compact('sites'))->render();
    }

    // UPDATE SITE (NOW LARAVEL STYLE)
    public function update(Request $request, $id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'exampleUrl'      => 'required|url|max:255',
            'da'              => 'required|integer|min:0|max:100',
            'dr'              => 'required|integer|min:0|max:100',
            'traffic'         => 'required|integer|min:0',
            'country'         => 'required|string|max:10',
            'language'        => 'required|string|max:10',
            'category'        => 'required|string|max:50',
            'price'           => 'required|numeric|min:0',
            'publicationTime' => 'required|string|max:20',
            'link_type'       => 'required|in:dofollow,nofollow',
            'siteDescription' => 'required|string',
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
            $site->publication_time  = $request->publicationTime;
            $site->link_type         = $request->link_type;

            // Tags
            $site->sponsored         = $request->has('sponsored');
            $site->partner_material  = $request->has('partner_material');
            $site->as_you_prefer     = $request->has('as_you_prefer');

            $site->description       = $cleanDescription;

            // Reset approval
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

        return redirect()->back()->with('success', 'Site updated successfully!');
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