<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\CatalogExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CatalogExportController extends Controller
{
    public function export(Request $request, CatalogExportService $export): StreamedResponse
    {
        return $export->download($request, auth()->user());
    }
}
