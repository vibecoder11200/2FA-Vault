<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PushSubscription;
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

class PushSubscriptionController extends Controller
{
    /**
     * Get VAPID public key
     */
    public function publicKey()
    {
        return response()->json([
            'publicKey' => config('webpush.vapid.public_key')
        ]);
    }

    /**
     * Store push subscription
     */
    public function subscribe(Request $request)
    {
        $validated = $request->validate([
            'endpoint' => 'required|url',
            'p256dh' => 'required|string',
            'auth' => 'required|string',
            'content_encoding' => 'nullable|string'
        ]);

        // Check if subscription already exists
        $subscription = PushSubscription::where('endpoint', $validated['endpoint'])
            ->where('user_id', auth()->id())
            ->first();

        if ($subscription) {
            // Update existing subscription
            $subscription->update([
                'p256dh' => $validated['p256dh'],
                'auth' => $validated['auth'],
                'content_encoding' => $validated['content_encoding'] ?? 'aesgcm',
            ]);
        } else {
            // Create new subscription
            $subscription = PushSubscription::create([
                'user_id' => auth()->id(),
                'endpoint' => $validated['endpoint'],
                'p256dh' => $validated['p256dh'],
                'auth' => $validated['auth'],
                'content_encoding' => $validated['content_encoding'] ?? 'aesgcm',
            ]);
        }

        return response()->json([
            'id' => $subscription->id,
            'endpoint' => $subscription->endpoint,
            'created_at' => $subscription->created_at,
        ], 201);
    }

    /**
     * Store push subscription (legacy method)
     */
    public function store(Request $request)
    {
        return $this->subscribe($request);
    }

    /**
     * List user's subscriptions
     */
    public function index(Request $request)
    {
        $subscriptions = PushSubscription::where('user_id', auth()->id())
            ->get()
            ->map(function ($sub) {
                return [
                    'id' => $sub->id,
                    'endpoint' => $sub->endpoint,
                    'created_at' => $sub->created_at,
                ];
            });

        return response()->json([
            'data' => $subscriptions
        ]);
    }

    /**
     * Remove push subscription
     */
    public function unsubscribe(Request $request)
    {
        $validated = $request->validate([
            'endpoint' => 'required|url'
        ]);

        $subscription = PushSubscription::where('endpoint', $validated['endpoint'])
            ->where('user_id', auth()->id())
            ->first();

        if (!$subscription) {
            return response()->json([
                'message' => 'Subscription not found'
            ], 404);
        }

        $subscription->delete();

        return response()->json([
            'message' => 'Subscription removed successfully'
        ]);
    }

    /**
     * Send test notification
     */
    public function sendTest(Request $request)
    {
        $user = auth()->user();

        // Get user's subscriptions
        $subscriptions = PushSubscription::where('user_id', $user->id)->get();

        if ($subscriptions->isEmpty()) {
            return response()->json([
                'error' => 'No subscriptions found'
            ], 404);
        }

        // Prepare notification payload
        $payload = json_encode([
            'title' => '2FA-Vault Test',
            'body' => 'This is a test notification from 2FA-Vault',
            'icon' => '/icons/pwa-192x192.png',
            'badge' => '/icons/pwa-96x96.png',
            'tag' => 'test-notification',
            'requireInteraction' => false
        ]);

        // Initialize WebPush
        $webPush = new WebPush([
            'VAPID' => [
                'subject' => config('app.url'),
                'publicKey' => config('webpush.vapid.public_key'),
                'privateKey' => config('webpush.vapid.private_key')
            ]
        ]);

        $sentCount = 0;
        $failedCount = 0;

        // Send to all user subscriptions
        foreach ($subscriptions as $sub) {
            $subscription = Subscription::create([
                'endpoint' => $sub->endpoint,
                'publicKey' => $sub->p256dh,
                'authToken' => $sub->auth,
                'contentEncoding' => $sub->content_encoding
            ]);

            $report = $webPush->sendOneNotification($subscription, $payload);

            if ($report->isSuccess()) {
                $sentCount++;
            } else {
                $failedCount++;
                
                // Remove invalid subscriptions
                if ($report->isSubscriptionExpired()) {
                    $sub->delete();
                }
            }
        }

        return response()->json([
            'message' => 'Test notifications sent',
            'sent' => $sentCount,
            'failed' => $failedCount
        ]);
    }

    /**
     * Send notification to user
     * 
     * @param int $userId
     * @param string $title
     * @param string $body
     * @param array $options
     */
    public static function sendNotification($userId, $title, $body, $options = [])
    {
        $subscriptions = PushSubscription::where('user_id', $userId)->get();

        if ($subscriptions->isEmpty()) {
            return false;
        }

        $payload = json_encode(array_merge([
            'title' => $title,
            'body' => $body,
            'icon' => '/icons/pwa-192x192.png',
            'badge' => '/icons/pwa-96x96.png'
        ], $options));

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => config('app.url'),
                'publicKey' => config('webpush.vapid.public_key'),
                'privateKey' => config('webpush.vapid.private_key')
            ]
        ]);

        $sent = 0;

        foreach ($subscriptions as $sub) {
            $subscription = Subscription::create([
                'endpoint' => $sub->endpoint,
                'publicKey' => $sub->p256dh,
                'authToken' => $sub->auth,
                'contentEncoding' => $sub->content_encoding
            ]);

            $report = $webPush->sendOneNotification($subscription, $payload);

            if ($report->isSuccess()) {
                $sent++;
            } elseif ($report->isSubscriptionExpired()) {
                $sub->delete();
            }
        }

        return $sent > 0;
    }
}
