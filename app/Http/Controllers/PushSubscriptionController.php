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
    public function store(Request $request)
    {
        $validated = $request->validate([
            'endpoint' => 'required|url',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string'
        ]);

        // Check if subscription already exists
        $subscription = PushSubscription::where('endpoint', $validated['endpoint'])
            ->where('user_id', auth()->id())
            ->first();

        if ($subscription) {
            // Update existing subscription
            $subscription->update([
                'p256dh' => $validated['keys']['p256dh'],
                'auth' => $validated['keys']['auth'],
                'content_encoding' => 'aes128gcm'
            ]);
        } else {
            // Create new subscription
            $subscription = PushSubscription::create([
                'user_id' => auth()->id(),
                'endpoint' => $validated['endpoint'],
                'p256dh' => $validated['keys']['p256dh'],
                'auth' => $validated['keys']['auth'],
                'content_encoding' => 'aes128gcm'
            ]);
        }

        return response()->json([
            'message' => 'Subscription saved successfully',
            'subscription' => $subscription
        ], 201);
    }

    /**
     * Remove push subscription
     */
    public function destroy(Request $request)
    {
        $validated = $request->validate([
            'endpoint' => 'required|url'
        ]);

        $deleted = PushSubscription::where('endpoint', $validated['endpoint'])
            ->where('user_id', auth()->id())
            ->delete();

        return response()->json([
            'message' => $deleted ? 'Subscription removed successfully' : 'Subscription not found',
            'deleted' => $deleted
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
