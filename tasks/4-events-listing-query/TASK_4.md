# TASK-4 — US-104: Events Listing Query + Date & Location Filters

**Status:** ✓ MERGED (PR #8)
**Created:** 2026-06-18
**User Story:** US-104 — Fix and extend the events listing query (apply date filter, add location filter, enriched response, performant at scale)

## Goal
Fix the never-applied `from` date filter and add date-range + location filtering to the `/events/data` endpoint, performant against ~1.25M rows using the new indexes. Return a fully-enriched event shape via an Event API Resource (payload name/description/venue, location_city, event-local timezone fields, cover image URL) so the Wave 2 Visual pages are purely presentational. Wire the filters into the existing Events/Index.vue debug table.

## Phases
| Phase | Status | Output |
|---|---|---|
| Implementation | ✓ completed | EventController, EventResource, Index.vue |
| QA | ✓ completed | EventListingTest |
