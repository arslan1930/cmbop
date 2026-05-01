<?php
// app/Http/Controllers/ChatController.php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderChatMessage;
use App\Models\User;
use App\Mail\NewChatMessageNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    public function getMessages($orderId)
    {
        try {
            $order = Order::findOrFail($orderId);
            $user = auth()->user();
            
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
            
            $messages = OrderChatMessage::where('order_id', $orderId)
                ->with('user')
                ->orderBy('created_at', 'asc')
                ->get();
            
            // Mark messages as read
            if ($isAdvertiser) {
                OrderChatMessage::where('order_id', $orderId)
                    ->where('sender_type', 'publisher')
                    ->where('is_read', false)
                    ->update(['is_read' => true, 'read_at' => now()]);
            } else {
                OrderChatMessage::where('order_id', $orderId)
                    ->where('sender_type', 'advertiser')
                    ->where('is_read', false)
                    ->update(['is_read' => true, 'read_at' => now()]);
            }
            
            return response()->json([
                'success' => true,
                'messages' => $messages,
                'current_user_id' => $user->id
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching messages: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch messages'
            ], 500);
        }
    }
    
    public function sendMessage(Request $request, $orderId)
    {
        try {
            $request->validate([
                'message' => 'required|string|max:5000'
            ]);
            
            $order = Order::findOrFail($orderId);
            $user = auth()->user();
            
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
            
            $senderType = $isAdvertiser ? 'advertiser' : 'publisher';
            
            DB::beginTransaction();
            
            $message = OrderChatMessage::create([
                'order_id' => $orderId,
                'user_id' => $user->id,
                'sender_type' => $senderType,
                'message' => $request->message,
                'is_read' => false
            ]);
            
            // Send email notification
            if ($isAdvertiser) {
                // Message from advertiser to publisher
                $site = $order->items()->first()->site;
                if ($site && $site->publisher_id) {
                    $receiver = User::find($site->publisher_id);
                    if ($receiver && $receiver->email) {
                        Mail::to($receiver->email)->send(new NewChatMessageNotification(
                            $order, $user, $request->message, $receiver->name
                        ));
                    }
                }
            } else {
                // Message from publisher to advertiser
                $receiver = User::find($order->user_id);
                if ($receiver && $receiver->email) {
                    Mail::to($receiver->email)->send(new NewChatMessageNotification(
                        $order, $user, $request->message, $receiver->name
                    ));
                }
            }
            
            DB::commit();
            $message->load('user');
            
            return response()->json([
                'success' => true,
                'message' => $message,
                'current_user_id' => $user->id
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error sending message: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to send message: ' . $e->getMessage()
            ], 500);
        }
    }
}