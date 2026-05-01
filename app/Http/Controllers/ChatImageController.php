<?php
// app/Http/Controllers/ChatImageController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Models\Order;

class ChatImageController extends Controller
{
    public function upload(Request $request)
    {
        try {
            $request->validate([
                'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
                'order_id' => 'required|exists:orders,id'
            ]);
            
            $orderId = $request->order_id;
            $user = auth()->user();
            
            $order = Order::findOrFail($orderId);
            $isAdvertiser = $order->user_id === $user->id;
            $isPublisher = $order->items()->whereHas('site', function($q) use ($user) {
                $q->where('publisher_id', $user->id);
            })->exists();
            
            if (!$isAdvertiser && !$isPublisher) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }
            
            $image = $request->file('image');
            $filename = Str::random(40) . '.' . $image->getClientOriginalExtension();
            $folder = "chat_images/{$orderId}/" . date('Y/m');
            
            $path = $image->storeAs($folder, $filename, 'public');
            $url = Storage::url($path);
            
            return response()->json([
                'success' => true,
                'url' => $url,
                'filename' => $filename,
                'path' => $path
            ]);
            
        } catch (\Exception $e) {
            Log::error('Image upload error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload image: ' . $e->getMessage()
            ], 500);
        }
    }
}