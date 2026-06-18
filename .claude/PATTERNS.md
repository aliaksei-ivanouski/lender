# Established Coding Patterns

_Last updated: 2026-06-18_

No patterns established yet. Patterns will be documented after the first implementation wave.

## Pattern Discovery Notes

As the team builds `VisualOne.vue`, `VisualTwo.vue`, and the attendee/email features, document:
- **Image serving**: how to reference `storage/app/public` files in Vue templates (URL path structure)
- **JSON payload decoding**: where and when to `JSON.parse(event.payload)` in Vue vs. backend
- **Email queueing**: how confirmation + reminder jobs are dispatched and structured
- **Filtering patterns**: how filters modify query state + API calls + component re-renders
- **Timezone display**: where event times are converted/formatted for the user's timezone

---

## Conventions to Establish

1. **Component organization**: where to place modal/filter components, layout wrappers, etc.
2. **API contracts**: resource/collection structures returned from `EventController`; attendee API shape
3. **Test fixtures**: factory usage for events, attendees, emails
4. **Database layer**: where to put queries (controller, model scope, repository) at this scale
