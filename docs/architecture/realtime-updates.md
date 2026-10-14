# Real-Time Architecture: Mercure Usage

This document outlines the real-time update architecture of the Barcamp Schedule application and specifies how the Mercure Hub is utilized.

## Architectural Goal
The goal of the real-time feature is to ensure that all active, open browser tabs viewing or editing a schedule are updated immediately when a change is saved. 

## Non-Reliance on Mercure History
Unlike some real-time setups that leverage Mercure's message history to recover missed events (e.g., during connection drops), this application **does not rely on Mercure history**. It is designed around the principles of **latest-state propagation** and **REST API fallbacks**.

### 1. Full State Payloads
The backend (`ChangeTracker.php`) does not send delta patches or incremental updates. Instead, whenever a schedule is mutated, it broadcasts the entire, updated schedule state in a single JSON payload. This payload includes a monotonically increasing `version` number.

### 2. Idempotent Frontend Updates
The frontend SSE consumer (`frontend/src/utils/sseUtils.js`) processes incoming messages by comparing versions:
```javascript
if (newVersion > currentVersion) {
  // Overwrite local schedule state with the incoming full state
}
```
Because each message is self-contained and idempotent, missing intermediate events is harmless. The client only ever needs the latest state.

### 3. Reconnection & Staleness Fallback
Instead of using Mercure's `Last-Event-ID` feature to catch up on missed history when recovering from a sleep state, the application leverages a dedicated **Staleness Detector**:
*   The `stalenessDetector` monitors tab visibility and window focus.
*   When a tab becomes visible after being hidden (e.g., computer wakes up from sleep), the detector fires a stale event.
*   The client then triggers `refreshSchedule()`, making a standard **REST API call** to fetch the absolute latest state from the database.
*   The client then closes the old SSE stream and establishes a brand new one (`setupSSE()`) from scratch without sending `Last-Event-ID`.

## Authentication and Scope
The Mercure Hub is strictly locked down and does **not** allow anonymous subscriptions. Real-time updates are only delivered to clients that hold a valid JWT:
- **Administrators and Users:** Have valid JWTs allowing them to request a Mercure subscription token.
- **Guests via Share Links:** Are issued a scoped guest JWT when accessing the link, allowing them to subscribe to real-time updates for that specific schedule.
- **Public Viewers (No Share Link):** Do not receive a JWT and are blocked from the subscription endpoint. They only view the schedule statically without real-time refreshes. This is an intentional design choice to conserve resources.

## Summary
By keeping Mercure as a **fire-and-forget** publisher and relying on standard HTTP requests for state recovery, the system avoids complex server-side history state maintenance. This makes the Mercure hub highly scalable, cheap to host, and straightforward to manage.
