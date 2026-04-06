<?php

namespace Database\Factories;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PushSubscriptionFactory extends Factory
{
    protected $model = PushSubscription::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/' . $this->faker->uuid(),
            'p256dh' => base64_encode(random_bytes(32)),
            'auth' => base64_encode(random_bytes(16)),
            'content_encoding' => 'aes128gcm',
        ];
    }
}
