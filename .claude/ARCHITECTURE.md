# Architecture Decisions Record (ADR)

_Last updated: 2026-06-18_

---

## Decision Log

| ID | Title | Status | Date | Rationale |
|---|---|---|---|---|
| ADR-001 | Geocoding: offline nearest-CITY_ANCHOR mapping | Accepted | 2026-06-18 | Map each event's lat/lng to nearest of ~78 seeder CITY_ANCHORS; store in `events.location_city`. No external API, no latency, deterministic, works offline. |
| ADR-002 | Attendee registration: authenticated-only via Fortify | Accepted | 2026-06-18 | Attendee schema = (`user_id` FK, `event_id` FK, unique constraint). No anonymous registration or token-based verification links. Confirmation email is a notification (not opt-in). |
| ADR-003 | Visual layouts: responsive card grid + interactive map | Accepted | 2026-06-18 | Visual 1 = card grid; Visual 2 = interactive map with pins. Both support date + location filters and tasteful animations. Visually and structurally distinct. |
| ADR-004 | Timezone: event-local via static CITY_ANCHOR→IANA map | Accepted | 2026-06-18 | Derive timezone from nearest CITY_ANCHOR (static ~78-entry map); display format e.g. "8:00 PM CET". No external API. Date filtering on event-local date (calendar date in event's own timezone). |
| ADR-005 | Images: 5–10 local placeholder JPEGs + `event_images` table | Accepted | 2026-06-18 | Committed to repo; served via Laravel `public` disk + `artisan storage:link`. New `event_images` table (event_id FK, path, sort_order, alt). Seeder assigns 2+ images per event via bulk chunked inserts. |

---

## Open Design Questions (Deferred to TASK-3)

- **Map library selection** — Leaflet vs. MapLibre vs. Google Maps API for Visual 2 interactive map. Decision required before frontend implementation wave.

---

## Related Domains

See `CONSTRAINTS.md` for implementation detail on each ADR's prerequisites (migration, seeding, timezone map data structure).

