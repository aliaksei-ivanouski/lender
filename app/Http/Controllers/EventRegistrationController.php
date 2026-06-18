<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Notifications\RegistrationConfirmationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class EventRegistrationController extends Controller
{
    public function store(Request $request, Event $event): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $registration = EventRegistration::firstOrCreate(
            ['event_id' => $event->id, 'user_id' => $user->id],
            ['status' => 'confirmed'],
        );

        $payload = $event->payload ?? [];
        $eventName = $payload['name'] ?? 'this event';

        if ($registration->wasRecentlyCreated) {
            $user->notify(new RegistrationConfirmationNotification($event));
            Log::info('Registration confirmation email queued', [
                'recipient' => $user->email,
                'user_id' => $user->id,
                'event_id' => $event->id,
                'event_name' => $event->payload['name'] ?? null,
            ]);
            Inertia::flash('toast', ['type' => 'success', 'message' => "You're registered for {$eventName}!"]);
        } else {
            Inertia::flash('toast', ['type' => 'info', 'message' => 'You are already registered for this event.']);
        }

        return redirect()->to(route('events.show', $event));
    }

    public function destroy(Request $request, Event $event): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        EventRegistration::where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->delete();

        Inertia::flash('toast', ['type' => 'info', 'message' => 'You have unregistered from this event.']);

        return redirect()->to(route('events.show', $event));
    }
}
