<?php
// database/seeders/ChatTestDataSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Order;
use App\Models\User;
use App\Models\OrderChatMessage;

class ChatTestDataSeeder extends Seeder
{
    public function run()
    {
        // Get an existing order
        $order = Order::first();
        if (!$order) {
            $this->command->info('No orders found. Please create an order first.');
            return;
        }
        
        // Get users
        $advertiser = User::find($order->user_id);
        $publisher = User::whereHas('sites', function($q) use ($order) {
            $q->whereIn('id', $order->items->pluck('site_id'));
        })->first();
        
        if ($advertiser && $publisher) {
            // Create sample messages
            OrderChatMessage::create([
                'order_id' => $order->id,
                'user_id' => $advertiser->id,
                'sender_type' => 'advertiser',
                'message' => 'Hello, I have a question about this order.',
                'is_read' => true,
                'read_at' => now(),
                'created_at' => now()->subHours(2)
            ]);
            
            OrderChatMessage::create([
                'order_id' => $order->id,
                'user_id' => $publisher->id,
                'sender_type' => 'publisher',
                'message' => 'Sure! What would you like to know?',
                'is_read' => false,
                'created_at' => now()->subHour()
            ]);
            
            OrderChatMessage::create([
                'order_id' => $order->id,
                'user_id' => $advertiser->id,
                'sender_type' => 'advertiser',
                'message' => 'Can you provide the live URL for the content?',
                'is_read' => false,
                'created_at' => now()
            ]);
            
            $this->command->info('Test chat messages created successfully!');
        }
    }
}