<?php

namespace App\Http\Controllers;

use App\Models\DepositRequest;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InvoiceController extends Controller
{
    /**
     * Show invoice page for both deposits and orders
     */
    public function showInvoice($referenceCode)
    {
        try {
            $userId = auth()->id();
            $user = auth()->user();
            
            // First check if it's a deposit
            $deposit = DepositRequest::where('reference_code', $referenceCode)
                ->where('user_id', $userId)
                ->first();
            
            if ($deposit) {
                return $this->showDepositInvoice($deposit, $user);
            }
            
            // Check if it's an order
            $order = Order::where('reference_code', $referenceCode)
                ->where('user_id', $userId)
                ->with('items')
                ->first();
            
            if ($order) {
                return $this->showOrderInvoice($order, $user);
            }
            
            return redirect()->route('advertiser.dashboard')
                ->with('error', 'Invoice not found');
            
        } catch (\Exception $e) {
            Log::error('Error showing invoice: ' . $e->getMessage());
            return redirect()->route('advertiser.dashboard')
                ->with('error', 'Invoice not found');
        }
    }
    
    /**
     * Show deposit invoice
     */
    private function showDepositInvoice($deposit, $user)
    {
        return view('advertiser.invoice', [
            'invoiceType' => 'deposit',
            'referenceCode' => $deposit->reference_code,
            'amount' => $deposit->amount,
            'billingName' => $user->billing_name ?? $user->name,
            'companyName' => $user->company_name ?? '',
            'country' => $user->country ?? '',
            'state' => $user->state ?? '',
            'city' => $user->city ?? '',
            'address' => $user->address ?? '',
            'postalCode' => $user->postal_code ?? '',
            'vatNumber' => $user->vat_number ?? '',
            'userName' => $user->name,
            'userEmail' => $user->email,
            'userId' => $user->id,
            'status' => $deposit->status,
            'paymentMethod' => $deposit->payment_method,
            'orderDate' => $deposit->created_at,
            'orderItems' => [],
            'totalBaseAmount' => 0,
            'totalSensitiveAmount' => 0
        ]);
    }
    
    /**
     * Show order invoice
     */
    private function showOrderInvoice($order, $user)
    {
        $orderItems = [];
        $totalBaseAmount = 0;
        $totalSensitiveAmount = 0;
        
        foreach ($order->items as $item) {
            $additionalPrice = $item->additional_price ?? 0;
            $basePrice = $item->price - $additionalPrice;
            $totalBaseAmount += $basePrice;
            $totalSensitiveAmount += $additionalPrice;
            
            $orderItems[] = [
                'site_name' => $item->site_name,
                'site_url' => $item->site_url,
                'price' => $item->price,
                'base_price' => $basePrice,
                'additional_price' => $additionalPrice,
                'sensitive_type' => $item->sensitive_type,
                'content_link' => $item->content_link,
                'live_url' => $item->live_url ?? ''
            ];
        }
        
        return view('advertiser.invoice', [
            'invoiceType' => 'order',
            'referenceCode' => $order->reference_code,
            'amount' => $order->total_amount,
            'billingName' => $user->billing_name ?? $user->name,
            'companyName' => $user->company_name ?? '',
            'country' => $user->country ?? '',
            'state' => $user->state ?? '',
            'city' => $user->city ?? '',
            'address' => $user->address ?? '',
            'postalCode' => $user->postal_code ?? '',
            'vatNumber' => $user->vat_number ?? '',
            'userName' => $user->name,
            'userEmail' => $user->email,
            'userId' => $user->id,
            'status' => $order->status,
            'paymentMethod' => $order->payment_method,
            'orderDate' => $order->created_at,
            'orderItems' => $orderItems,
            'totalBaseAmount' => $totalBaseAmount,
            'totalSensitiveAmount' => $totalSensitiveAmount
        ]);
    }
}