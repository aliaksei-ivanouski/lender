<?php

namespace App\Notifications;

use App\Models\Event;
use App\Services\TimezoneService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RegistrationConfirmationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Event $event) {}

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

        return (new MailMessage)
            ->subject("You're registered: {$eventName}")
            ->greeting("You're on the list!")
            ->line("You have successfully registered for **{$eventName}**.")
            ->line("Date: {$tzFields['starts_at_date']} at {$tzFields['starts_at_local']} {$tzFields['tz_label']}")
            ->line("Location: {$locationLine}")
            ->action('View Event', route('events.show', $this->event))
            ->line('See you there!');
    }
}
