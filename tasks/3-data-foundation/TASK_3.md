# TASK-3 — Wave 0: Data Foundation

**Status:** ✓ MERGED (PR #7)
**Created:** 2026-06-18
**User Stories:** US-101 (images), US-102 (city derivation), US-103 (indexes), US-105 (bug fix), US-106 (city→IANA tz map), US-401 (timezone display strategy)

## Goal
Build the data foundation the two Visual pages and the attendee/email features depend on: image storage end-to-end (event_images table + model + 2+ local placeholder images per event, bulk-seeded), offline derivation of a human-readable city from lat/lng (events.location_city), supporting DB indexes for performant filtering/sorting at ~1.25M rows, the static CITY_ANCHOR→IANA timezone map, the timezone display strategy, and the planted Filter-button bug fix.

## Phases
| Phase | Status | Output |
|---|---|---|
| Design | ✓ completed | ARCHITECTURE.md |
| Planning | ✓ completed | PLANNING.md |
| Implementation | ✓ completed | IMPLEMENTATION.md |
| QA | ✓ completed | — |
