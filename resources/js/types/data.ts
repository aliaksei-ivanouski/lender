/**
 * EventImage interface — represents an image associated with an event
 * @see AC-101-5 (Eloquent relation ordered by sort_order)
 */
export interface EventImage {
  id: number;
  path: string;
  url: string;
  sort_order: number;
  alt: string | null;
}

/**
 * EventListItem interface — event data shape in Inertia listing responses
 * @see US-401 (Timezone display with formatted fields)
 * @see §10 of ARCHITECTURE.md for TimezoneService field definitions
 */
export interface EventListItem {
  id: string;
  name: string;
  description: string;
  type: string;
  status: string;
  created_time: number; // raw UTC unix timestamp (JS fallback)
  location_city: string | null;
  venue_name: string | null;
  latitude: number | null;
  longitude: number | null;
  starts_at_local: string; // e.g. "8:00 PM" (from TimezoneService)
  starts_at_date: string; // e.g. "Tue, Jan 7, 2025" (from TimezoneService)
  ends_at_local: string | null;
  tz_label: string; // e.g. "CET" (from TimezoneService)
  tz_identifier: string; // e.g. "Europe/Paris" (from TimezoneService)
  utc_timestamp: number; // raw unix timestamp for timezone context
  cover_image_url: string | null; // first image URL, or null
  images?: EventImage[]; // optional on listing, present on detail view
}

/**
 * Event resource interface — may be used as an alias or extension point
 */
export type Event = EventListItem;

/**
 * EventFilters interface — filter parameters for the event listing
 * @see useEventsData composable
 */
export interface EventFilters {
  status: string | null;
  from: string | null;
  to: string | null;
  location_city: string | null;
}

/**
 * DateBounds interface — dataset event date range from the backend
 * min/max are ISO 'YYYY-MM-DD' strings
 */
export interface DateBounds {
  min: string;
  max: string;
}
