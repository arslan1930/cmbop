<?php

namespace App\Http\Controllers\ApiDocs;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class AdvertiserApiDocsController extends Controller
{
    public function show(): View
    {
        return view('api.docs.advertiser', [
            'baseUrl' => rtrim((string) config('app.url'), '/'),
        ]);
    }
}
