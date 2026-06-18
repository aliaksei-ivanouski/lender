# Architecture Decisions Record (ADR)

_Last updated: 2026-06-18_

No architectural decisions made yet. Candidates will establish patterns during implementation.

## Expected future ADRs

1. **Event image storage** — table schema vs. payload column vs. external CDN
2. **Address/location denormalization** — precomputed city vs. on-demand reverse-geocoding
3. **Attendee registration auth** — required login vs. email capture vs. token-based
4. **Visual page layouts** — specific component hierarchy and state management
5. **Timezone handling** — UTC storage + viewer local display, or event-local times
6. **Reminder email scheduling** — scheduler (cron vs. schedule:work) + job windowing strategy
