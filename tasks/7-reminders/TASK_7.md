# TASK-7 — Wave 4: Scheduler + Reminder Emails

**Status:** in_progress
**Created:** 2026-06-18
**User Stories:** US-303 (scheduler setup), US-304 (3-day reminder), US-305 (24-hour reminder)

## Goal
Send reminder emails to event attendees as the event approaches: 3 days before AND 24 hours before. Add a scheduled artisan command (events:send-reminders) that runs periodically, selects published future events in each reminder window, emails each confirmed attendee, and marks them so each reminder fires exactly once (idempotent) using the reserved event_registrations columns reminder_3day_sent_at / reminder_24hour_sent_at. Mail driver is array; the concise 'Mail sent' log line (MessageSent listener) covers these. Reminders are queued notifications.

## Phases
| Phase | Status | Output |
|---|---|---|
| Design | in_progress | ARCHITECTURE.md |
| Planning | pending | PLANNING.md |
| Implementation | pending | IMPLEMENTATION.md |
| QA | pending | — |
