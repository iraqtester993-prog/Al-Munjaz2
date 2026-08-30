/**
 * A merchant pickup point is deliberately opened through the platform's
 * native `geo:` protocol instead of a Google/Apple Maps URL.  On Android the
 * operating system presents the installed map/navigation handlers, so the
 * courier chooses the app they already use.  We do not record a route or
 * destination history in the web app.
 */
export function hasPickupLocation(order) {
    const latitude = Number(order?.pickup_latitude)
    const longitude = Number(order?.pickup_longitude)

    return Number.isFinite(latitude)
        && Number.isFinite(longitude)
        && latitude >= -90
        && latitude <= 90
        && longitude >= -180
        && longitude <= 180
}

export function pickupLocationLabel(order, fallback = '') {
    return String(order?.pickup_location_label || '').trim() || fallback
}

export function pickupNavigationHref(order) {
    if (!hasPickupLocation(order)) return null

    const latitude = Number(order.pickup_latitude).toFixed(6)
    const longitude = Number(order.pickup_longitude).toFixed(6)
    const label = pickupLocationLabel(order)
    const destination = `${latitude},${longitude}`
    // `geo:0,0?q=` is the Android navigation-intent form. It lets the
    // operating system offer the map/navigation apps installed by the courier
    // instead of hard-coding one provider inside the PWA.
    const query = encodeURIComponent(label ? `${destination} (${label})` : destination)

    return `geo:0,0?q=${query}`
}
