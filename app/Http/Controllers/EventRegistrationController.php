<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Notifications\RegistrationConfirmationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EventRegistrationController extends Controller
{
    public function store(Request $request, Event $event): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{0: EventRegistration, 1: bool} $result */
        $result = EventRegistration::firstOrCreate(
            ['event_id' => $event->id, 'user_id' => $user->id],
            ['status' => 'confirmed'],
        );

        [, $created] = $result;

        $payload = $event->payload ?? [];
        $eventName = $payload['name'] ?? 'this event';

        if ($created) {
            $user->notify(new RegistrationConfirmationNotification($event));
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
