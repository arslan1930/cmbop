<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Catalog\CatalogFaviconResolver;
use Symfony\Component\HttpFoundation\Response;

class CatalogFaviconController extends Controller
{
    public function __invoke(int $site, CatalogFaviconResolver $favicons): Response
    {
        $listing = Site::query()->catalogVisible()->find($site);
        if (! $listing) {
            return $favicons->fallbackResponse();
        }

        return $favicons->response($listing);
    }
}
