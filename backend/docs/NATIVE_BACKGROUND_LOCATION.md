# Background location contract for the native shell

The Vue PWA already sends a consented courier position to `POST /app/location`.
No second API, database table, or dashboard change is needed when creating the
Android app.

## Native bridge

The Android shell must expose `window.AlMunjazNativeLocation` to the web view:

```ts
interface AlMunjazNativeLocation {
  start(options: {
    distanceFilterMeters: number
    intervalMilliseconds: number
  }): Promise<void>
  stop(): Promise<void>
}
```

For every accepted native GPS point, dispatch this browser event in the web
view:

```ts
window.dispatchEvent(new CustomEvent('almunjaz:native-location', {
  detail: {
    latitude: 33.3152,
    longitude: 44.3661,
    accuracy: 18,
  },
}))
```

The existing Vue tracker rate-limits uploads to at least 25 metres or 45
seconds and sends the point using the authenticated mobile session. The server
stores only the latest point and hides it from the dashboard after 15 minutes.

## Android requirements

The native implementation must request foreground location first, then the
user-visible background-location setting. It must run as a foreground service
with a persistent Android notification while sharing is enabled, respect the
profile sharing switch, and stop immediately when the courier disables it.

The PWA remains a best-effort fallback while it is in memory. A browser cannot
guarantee location delivery after the OS fully terminates the web app.
