<?php

namespace App\Notifications;

use App\Models\Event;
use App\Services\TimezoneService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class EventReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Event $event,
        public readonly string $type, // '3day' | '24hour'
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var TimezoneService $tzService */
        $tzService = app(TimezoneService::class);

        $city = $this->event->city;
        $timezone = $city->timezone ?? 'UTC';
        $tzFields = $tzService->formatEventTime($this->event->created_time, $timezone);

        $payload = $this->event->payload ?? [];
        $eventName = $payload['name'] ?? 'Event';
        $venueName = $payload['venue']['name'] ?? null;
        $locationLine = $venueName
            ? "{$venueName}, {$this->event->location_city}"
            : ($this->event->location_city ?? '');

        $subject = $this->type === '3day'
            ? "Reminder: {$eventName} is in 3 days"
            : "Reminder: {$eventName} is tomorrow";

        $urgencyLine = $this->type === '3day'
            ? "**{$eventName}** is coming up in 3 days — don't forget!"
            : "**{$eventName}** is tomorrow — see you there!";

        return (new MailMessage)
            ->subject($subject)
            ->greeting("Reminder for {$eventName}")
            ->line($urgencyLine)
            ->line("Date: {$tzFields['starts_at_date']} at {$tzFields['starts_at_local']} {$tzFields['tz_label']}")
            ->line("Location: {$locationLine}")
            ->action('View Event', route('events.show', $this->event))
            ->line('We look forward to seeing you!');
    }
}
