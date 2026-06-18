<?php

namespace App\Console\Commands;

use App\Models\EventRegistration;
use App\Notifications\EventReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendEventReminders extends Command
{
    protected $signature = 'events:send-reminders';

    protected $description = 'Send 3-day and 24-hour reminder emails for upcoming events';

    public function handle(): int
    {
        $now = (int) Carbon::now()->timestamp;

        $thresholds = [
            '3day' => [
                'low' => $now + (71 * 3600),
                'high' => $now + (73 * 3600),
            ],
            '24hour' => [
                'low' => $now + (23 * 3600),
                'high' => $now + (25 * 3600),
            ],
        ];

        foreach ($thresholds as $type => $window) {
            $sent = 0;

            EventRegistration::query()
                ->where('status', 'confirmed')
                ->whereNull("reminder_{$type}_sent_at")
                ->whereHas('event', fn ($q) => $q
                    ->where('status', 'published')
                    ->whereBetween('created_time', [$window['low'], $window['high']])
                )
                ->with(['event.city', 'user'])
                ->chunkById(500, function ($registrations) use ($type, &$sent) {
                    foreach ($registrations as $reg) {
                        $reg->user->notify(new EventReminderNotification($reg->event, $type));
                        $reg->forceFill(["reminder_{$type}_sent_at" => now()])->save();
                        $sent++;
                    }
                });

            $this->info("Sent {$type} reminders: {$sent}");
        }

        return self::SUCCESS;
    }
}
