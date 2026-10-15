<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LinkCheck;
use App\Models\Order;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class LinkGuaranteeController extends Controller
{
    public function index(Request $request): View
    {
        $status = trim((string) $request->input('status', ''));
        $q = trim((string) $request->input('q', ''));

        $orders = collect();
        $checks = collect();

        if (Schema::hasColumn('orders', 'link_guarantee')) {
            $orderQuery = Order::query()
                ->where('link_guarantee', true)
                ->with(['items', 'user'])
                ->orderByDesc('id');

            if ($status !== '' && $status !== 'all') {
                $orderQuery->where('link_guarantee_status', $status);
            }
            if ($q !== '') {
                $orderQuery->where(function ($inner) use ($q) {
                    $inner->where('order_number', 'like', '%'.$q.'%')
                        ->orWhere('reference_code', 'like', '%'.$q.'%');
                });
            }

            $orders = $orderQuery->paginate(30)->withQueryString();
        }

        if (LinkCheck::tableAvailable()) {
            $checkQuery = LinkCheck::query()
                ->with(['order', 'orderItem'])
                ->orderByDesc('id');
            if ($orders instanceof Paginator && $orders->isNotEmpty()) {
                $checkQuery->whereIn('order_id', $orders->pluck('id'));
            }
            $checks = $checkQuery->limit(80)->get();
        }

        return view('admin.link-guarantees.index', [
            'orders' => $orders,
            'checks' => $checks,
            'status' => $status,
            'q' => $q,
        ]);
    }
}
