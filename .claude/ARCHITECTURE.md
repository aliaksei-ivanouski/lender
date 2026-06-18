# Architecture Decisions Record (ADR)

_Last updated: 2026-06-18_

---

## Decision Log

| ID | Title | Status | Date | Rationale |
|---|---|---|---|---|
| ADR-001 | Geocoding: offline nearest-CITY_ANCHOR mapping | Superseded by ADR-010/011/012 (R1, 2026-06-18) | 2026-06-18 | Map each event's lat/lng to nearest of ~78 seeder CITY_ANCHORS; store in `events.location_city`. No external API, no latency, deterministic, works offline. (Superseded: reference data moved from in-memory class to queryable DB table per user feedback on architecture boundary.) |
| ADR-002 | Attendee registration: authenticated-only via Fortify | Accepted | 2026-06-18 | Attendee schema = (`user_id` FK, `event_id` FK, unique constraint). No anonymous registration or token-based verification links. Confirmation email is a notification (not opt-in). |
| ADR-003 | Visual layouts: responsive card grid + interactive map | Accepted | 2026-06-18 | Visual 1 = card grid; Visual 2 = interactive map with pins. Both support date + location filters and tasteful animations. Visually and structurally distinct. |
| ADR-004 | Timezone: event-local via static CITY_ANCHOR→IANA map | Accepted | 2026-06-18 | Derive timezone from nearest CITY_ANCHOR (static ~78-entry map); display format e.g. "8:00 PM CET". No external API. Date filtering on event-local date (calendar date in event's own timezone). |
| ADR-005 | Images: 5–10 local placeholder JPEGs + `event_images` table | Accepted | 2026-06-18 | Committed to repo; served via Laravel `public` disk + `artisan storage:link`. New `event_images` table (event_id FK, path, sort_order, alt). Seeder assigns 2+ images per event via bulk chunked inserts. |
| ADR-013 | Map library: Leaflet + OpenStreetMap (OSM) | Accepted | 2026-06-18 | Visual 2 uses Leaflet (lightweight, Vue-friendly) with OSM tiles + Leaflet.MarkerCluster for event pins. Dynamic import prevents SSR bloat. Viewport-synced list pagination (cap 2000 events per load). See TASK-5 for implementation. |
| ADR-010 | Geocoding reference data in `cities` DB table | Accepted | 2026-06-18 | Schema: `cities(id, name, region, country, lat, lng, timezone)` indexed on lat/lng; seeded once from CityAnchor data. Rationale: queryable table scales and is extensible without code changes; loading the entire dataset into application memory is the wrong boundary (per user feedback). See TASK-3 ARCHITECTURE.md R1 section for full design. |
| ADR-011 | Geocoding behind `ReverseGeocoder` interface (port) | Accepted | 2026-06-18 | Default adapter: `DatabaseReverseGeocoder`; interface enables swap-in binding to external API (Nominatim/Google Maps) at container configuration. Rationale: reverse-geocoding is an external/replaceable concern, not in-process data. See TASK-3 ARCHITECTURE.md R1 section for full design. |
| ADR-012 | Bounded SQL query + 24h coordinate cache for nearest-city resolution | Accepted | 2026-06-18 | `DatabaseReverseGeocoder` resolves nearest city via bounding-box prefilter + `ORDER BY squared_distance LIMIT 1`; result cached 24h on coordinates rounded to 2 decimals. 1.25M-row backfill clusters to ~75 unique cache keys; no separate load-all path. Rationale: never loads whole table at request time; ETL throughput preserved via caching, not by reintroducing in-memory scan. See TASK-3 ARCHITECTURE.md R1 section for full design. |

---

## Related Domains

See `CONSTRAINTS.md` for implementation detail on each ADR's prerequisites (migration, seeding, timezone map data structure).

