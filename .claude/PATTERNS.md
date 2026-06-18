# Established Coding Patterns

_Last updated: 2026-06-18_
_Updated after: TASK-6 (Wave 3, attendees & confirmation email) merged_

---

## Geocoding & Reverse Lookup (Port/Adapter)

**Pattern**: External service behind a port interface; default adapter is database-backed.

```php
// App\Ports\ReverseGeocoder.php
interface ReverseGeocoder {
  public function findCity(float $lat, float $lng): ?City;
}

// App\Adapters\DatabaseReverseGeocoder.php — bounded search (bbox) + 24h cache
public function findCity(float $lat, float $lng): ?City {
  $cached = Cache::store('database')
    ->get("geocode:${lat}:${lng}");
  if ($cached !== null) return $cached; // null or City

  $city = City::query()
    ->whereBetween('lat', [$lat - 0.5, $lat + 0.5])
    ->whereBetween('lng', [$lng - 0.5, $lng + 0.5])
    ->orderByRaw('ABS(lat - ?) + ABS(lng - ?)', [$lat, $lng])
    ->first();

  Cache::store('database')->put("geocode:${lat}:${lng}", $city, 24 * 60);
  return $city;
}
```

**Use case**: Bind `ReverseGeocoder` interface to `DatabaseReverseGeocoder` in service container; future waves can swap in an API adapter (e.g. OpenStreetMap, Google Geocoding) without changing consumer code.

---

## Timezone-Local Event Display (Service)

**Pattern**: Centralized time formatting service using CITY_ANCHOR → IANA TZ map.

```php
// App\Services\TimezoneService.php
public function formatEventTime(int $unixTimestamp, string $cityName): array {
  $tz = CityTimezoneMap::getIanaId($cityName); // lookup, static fallback to UTC
  $dt = Carbon::createFromTimestamp($unixTimestamp, 'UTC')
    ->setTimezone($tz);

  return [
    'starts_at_local' => $dt->format('Y-m-d H:i:s'),
    'starts_at_date' => $dt->format('Y-m-d'),
    'tz_label' => $dt->getTimezoneAbbr(),
    'tz_identifier' => $tz,
    'utc_timestamp' => $unixTimestamp,
  ];
}
```

**Use case**: Always call `TimezoneService::formatEventTime($event->created_time, $event->location_city)` in controller responses and Vue templates. Never hardcode TZ conversions.

---

## Large-Table ETL: Chunked Bulk Updates

**Pattern**: For tables >1M rows, chunk by ID and perform grouped bulk UPDATE/INSERT; use SQLite pragmas only during seeding.

```php
// Example: Backfill location_city from ReverseGeocoder
php artisan event:geocode-cities

// Artisan command structure:
$this->query->lazy(chunkSize: 5000)->chunk(100, function (Collection $events) {
  $updates = [];
  foreach ($events as $event) {
    $city = $this->geocoder->findCity($event->latitude, $event->longitude);
    $updates[] = ['id' => $event->id, 'location_city' => $city?->name ?? 'Unknown'];
  }
  Event::query()->upsert($updates, ['id'], ['location_city']);
});

// Seeder only: use inline pragmas for speed
DB::statement('PRAGMA journal_mode=MEMORY');
DB::statement('PRAGMA synchronous=OFF');
// ... bulk insert logic ...
```

**Use case**: Never load 1.25M rows into memory. Never use `->get()` or `collect()` on large tables. Always `lazy()` + `chunk()`. Pragmas only in `DatabaseSeeder::up()`, not in migrations or runtime code.

---

## Types & Validation (PHPStan L7)

**Pattern**: All model relations and factory return types use generics; env() only in config files.

```php
// App\Models\Event.php
/** @return HasMany<EventImage> */
public function images(): HasMany {
  return $this->hasMany(EventImage::class);
}

/** @return BelongsTo<City> */
public function city(): BelongsTo {
  return $this->belongsTo(City::class, 'location_city', 'name');
}

// App\Database\Factories\EventFactory.php
/** @return array<string, mixed> */
public function definition(): array {
  return [
    'name' => fake()->words(3, asText: true),
    'created_time' => fake()->unixTime(),
  ];
}

// config/app.php (correct)
'debug' => (bool) env('APP_DEBUG', false),

// ❌ app/Http/Controllers/EventController.php (wrong)
// env('APP_DEBUG') — never! only in config/
```

**Use case**: Run `composer types:check` (runs `phpstan analyse --memory-limit=512M`) before marking code complete. All relation types MUST have generics; all factory methods MUST return `array<string, mixed>` or specific shape.

---

## Testing: In-Memory DB + Selective Seeding

**Pattern**: Tests use `:memory:` SQLite; do NOT seed 1.25M rows. Use factories for individual test events; call seed only when necessary (e.g. CitySeeder for geocoding tests).

```php
// tests/Feature/EventGeocodingTest.php
use RefreshDatabase;

public function test_reverse_geocoder_finds_nearest_city() {
  $this->seed(CitySeeder::class); // 78 cities only
  $event = Event::factory()
    ->create(['latitude' => 40.7128, 'longitude' => -74.0060]); // NYC

  $city = app(ReverseGeocoder::class)->findCity(40.7128, -74.0060);
  $this->assertNotNull($city);
  $this->assertEquals('New York', $city->name);
}
```

**Use case**: `RefreshDatabase` + factories are fast. Never call the full seeder in tests (even with `SEED_ROWS=100`, it causes Pest to hang). If a test needs CityAnchor data, explicitly seed `CitySeeder`. If the seeder issues inline pragmas in a transaction, wrap the test in `app(DatabaseMigrations::class)->refresh()` instead.

---

## Frontend: Shared Events Data & Filtering Composable

**Pattern**: Centralized fetch, filter, and paginate logic extracted to `resources/js/composables/useEventsData.ts`.

```typescript
// resources/js/composables/useEventsData.ts
export function useEventsData() {
  const filters = ref({ dateFrom: null, dateTo: null, locationCity: null });
  const events = ref([]);
  const loading = ref(false);
  const hasMore = ref(true);
  const page = ref(1);

  const loadEvents = async () => {
    loading.value = true;
    const response = await route('event.list', { page: page.value, ...filters.value });
    events.value = [...events.value, ...response.data];
    hasMore.value = response.links.next !== null;
    page.value += 1;
    loading.value = false;
  };

  const applyFilters = () => {
    events.value = [];
    page.value = 1;
    loadEvents();
  };

  const loadAll = async () => {
    while (hasMore.value && events.value.length < 2000) {
      await loadEvents();
    }
  };

  return { filters, events, loading, hasMore, applyFilters, loadEvents, loadAll };
}
```

**Use case**: Both `EventsVisualOne.vue` (card grid) and `EventsVisualTwo.vue` (map) use this composable. Card grid renders all loaded events; map uses viewport-synced windowing of the list.

---

## Frontend: Date Picker Component (en-US, Bounded, Teleported)

**Pattern**: reka-ui + shadcn-vue date-picker with en-US localization, min/max bounds, calendar teleport, and keyboard-only focus styling.

```vue
<!-- resources/js/components/ui/date-picker/index.vue -->
<script setup lang="ts">
import { ref } from 'vue';
import { Calendar, X } from 'lucide-vue-next';
import { format } from 'date-fns';
import enUS from 'date-fns/locale/en-US';

const props = defineProps<{ modelValue?: Date; min?: Date; max?: Date }>();
const emit = defineEmits<{ 'update:modelValue': [Date] }>();

const isOpen = ref(false);

const handleSelect = (date: Date) => {
  emit('update:modelValue', date);
  isOpen.value = false; // close on select
};

const isDateDisabled = (date: Date) => {
  if (props.min && date < props.min) return true;
  if (props.max && date > props.max) return true;
  return false;
};
</script>

<template>
  <div class="relative">
    <button @click="isOpen = !isOpen" class="focus:ring-2 ring-blue-400/30">
      {{ props.modelValue ? format(props.modelValue, 'MM/dd/yyyy', { locale: enUS }) : 'Select date' }}
    </button>
    <Teleport to="body" v-if="isOpen">
      <div class="fixed inset-0 z-50 flex items-center justify-center">
        <Calendar :min="min" :max="max" :disabled="isDateDisabled" @select="handleSelect" />
      </div>
    </Teleport>
  </div>
</template>
```

**Use case**: Event list filters (Visual 1 & 2) use this picker for dateFrom/dateTo. Teleport ensures calendar sits above map; bounds prevent invalid date selection; MM/DD/YYYY matches en-US convention.

---

## Frontend: Leaflet Map with Marker Clustering & Viewport Sync

**Pattern**: Dynamic import + lazy-init + viewport-change event syncs list pagination.

```typescript
// resources/js/components/EventsVisualTwo.vue
import { defineAsyncComponent, ref, onMounted, watch } from 'vue';
import L from 'leaflet';
import 'leaflet.markercluster';

const mapContainer = ref(null);
let mapInstance = null;
const mapBounds = ref(null);

onMounted(async () => {
  // Lazy-load map library
  const LeafletMap = defineAsyncComponent(() => import('leaflet/dist/leaflet.css'));
  
  mapInstance = L.map(mapContainer.value).setView([20, 0], 2);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(mapInstance);
  
  const markerCluster = L.markerClusterGroup();
  events.value.forEach(event => {
    const marker = L.marker([event.latitude, event.longitude])
      .bindPopup(event.name);
    markerCluster.addLayer(marker);
  });
  mapInstance.addLayer(markerCluster);

  mapInstance.on('moveend', () => {
    mapBounds.value = mapInstance.getBounds();
    // Filter list to events within bounds + load next page
    const visibleEvents = events.value.filter(e =>
      mapBounds.value.contains([e.latitude, e.longitude])
    );
    loadNextPage();
  });
});
```

**Use case**: Visual 2 renders 2000-event cap (all on load; viewport event listeners filter display in side list). Marker clustering prevents pin overlap; dynamic import keeps bundle size lean.

---

## Frontend: Status Humanizer (Utility)

**Pattern**: Format enum status values to readable labels.

```typescript
// resources/js/lib/format.ts
export function formatStatus(status: string): string {
  const labels: Record<string, string> = {
    'draft': 'Draft',
    'scheduled': 'Scheduled',
    'ongoing': 'In Progress',
    'completed': 'Completed',
    'cancelled': 'Cancelled',
  };
  return labels[status] ?? 'Unknown';
}
```

**Use case**: Event list rendering; attendee status display in later waves.

---

## Backend: One-Command Setup (bin/setup)

**Pattern**: Single entry point for fresh environment setup — install, migrate, seed, build.

```bash
#!/bin/bash
# bin/setup
composer install
php artisan key:generate --force
php artisan migrate:fresh --seed
npm install && npm run build
echo "✓ Setup complete"
```

**Use case**: New developers run `./bin/setup` once; idempotent (safe to re-run). Logs setup noise via `2>/dev/null` for clean output.

---

## Queued Notifications with Dedup (firstOrCreate + wasRecentlyCreated)

**Pattern**: Dispatch a `Notifiable` notification to a queue, deduplicated by unique attribute(s).

```php
// app/Listeners/EventRegistrationListener.php (in event listener or controller action)
public function handle(EventRegistrationCreated $event): void {
  $registration = $event->registration;
  
  // Dispatch queued notification only if this is a fresh registration (not a re-fetch)
  if ($registration->wasRecentlyCreated) {
    $registration->user->notify(new RegistrationConfirmationNotification($registration));
  }
}

// app/Notifications/RegistrationConfirmationNotification.php
public function via(object $notifiable): array {
  return ['mail'];
}

public function toMail(object $notifiable): MailMessage {
  return (new MailMessage)
    ->subject("Event Registration Confirmed")
    ->line("You are registered for {$this->registration->event->name}");
}

// Controller dispatch (alternative pattern):
$registration = Event::find($eventId)
  ->registrations()
  ->firstOrCreate(
    ['user_id' => auth()->id()],
    ['status' => 'confirmed']
  );

if ($registration->wasRecentlyCreated) {
  $registration->user->notify(new RegistrationConfirmationNotification($registration));
}
```

**Use case**: Attendee registration confirmation emails; reminder emails (Wave 4 will dispatch at scheduled thresholds, not on creation). Ensures each attendee gets exactly one confirmation email even if the form is double-submitted.

---

## Auth-Gated Inertia POST/DELETE with Redirect-Back + Flash Messaging

**Pattern**: Fortify-protected form actions with flash feedback.

```php
// routes/web.php
Route::post('/events/{event}/registrations', [EventRegistrationController::class, 'store'])
  ->middleware('auth')
  ->name('event.register');

Route::delete('/events/{event}/registrations', [EventRegistrationController::class, 'destroy'])
  ->middleware('auth')
  ->name('event.unregister');

// app/Http/Controllers/EventRegistrationController.php
public function store(Event $event): RedirectResponse {
  $registration = $event->registrations()
    ->firstOrCreate(['user_id' => auth()->id()]);
  
  if ($registration->wasRecentlyCreated) {
    auth()->user()->notify(new RegistrationConfirmationNotification($registration));
  }
  
  return back()
    ->with('flash', [
      'type' => 'success',
      'message' => 'You are now registered for this event!',
    ]);
}

public function destroy(Event $event): RedirectResponse {
  auth()->user()->registrations()->where('event_id', $event->id)->delete();
  
  return back()
    ->with('flash', [
      'type' => 'success',
      'message' => 'Unregistered from event.',
    ]);
}

// Frontend (Inertia/Vue): Events/Show.vue uses route() + form-submission
<form @submit.prevent="registerEvent" method="POST">
  <input type="hidden" name="_method" value="POST" />
  <button type="submit">Register</button>
</form>

const registerEvent = async () => {
  router.post(route('event.register', event.id));
};
```

**Use case**: User registration/unregistration flows; conditional button display based on `isRegistered` prop.

---

## Concise Mail Logging (MAIL_MAILER=array + MessageSent Listener)

**Pattern**: Log email metadata (to, subject) without full HTML dump.

```php
// config/mail.php or .env
MAIL_MAILER=array  # or 'log' for file-based logging

// app/Providers/AppServiceProvider.php
use Illuminate\Mail\Events\MessageSent;

public function boot(): void {
  \Illuminate\Support\Facades\Mail::listen(function (MessageSent $event) {
    \Log::info('Mail sent', [
      'to' => $event->message->getTo(),
      'subject' => $event->message->getSubject(),
    ]);
  });
}

// Result in logs:
// [2026-06-18 14:32:01] local.INFO: Mail sent {"to":{"user@example.com":"User Name"},"subject":"Event Registration Confirmed"}
```

**Use case**: Local dev + testing. Acceptable alternative to `Mail::fake()` for end-to-end flows. In production, switch to real mailer (SES, Mailgun, Postmark).

---

## Conventions to Establish in Later Waves

1. **Scheduler integration**: how `schedule:work` dispatches reminder jobs at fixed intervals
2. **Idempotent reminder dispatch**: how to track reminder-sent without duplicates
3. **Infinite scroll state**: persistence of filter + page state across navigation
