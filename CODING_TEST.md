# Coding Test — Event Visuals

Build out two pages — **Event Visuals 1** and **Event Visuals 2** — into **two different
layout styles** for browsing the events, using **two modern, distinct approaches** (your
call — e.g. a card grid, a map, a calendar, a timeline). They shouldn't look like the same
page twice.

Each event should present the standard information you'd expect for something like a concert:

- **Title** and **description**
- **Location** and **date/time**
- An **image**

## Requirements

- **Images** — events don't have images yet. Add support for them end to end, with
  **two or more images per event**. You may reuse the same placeholder files, but the
  images must be served **locally** (no external/hotlinked URLs).
- **Addresses** — events only carry a latitude/longitude. Turn that into a usable,
  human-readable location.
- **Date & time** — events are global. Display the time sensibly; how and where you handle
  timezones is up to you.
- **Filtering** — any style you like, but we should at least be able to **filter by date and
  by location**.
- **Tailwind** — use it for styling.
- **Animations** — add them where they make sense; don't overdo it.

## Attendees & emails

- Let people register **interest / attendance** for an event (an attendee list).
- When someone is added, **email them** to confirm they're on the list.
- Send **reminder emails** as the event approaches — handle both **3 days before** and
  **24 hours before** the event.

## Notes

- You're working against a realistic, fully-seeded dataset — build for it as it is.
- Keep the code clean and readable, and include a short note on the decisions you made.

Keep it focused — quality over quantity.
