function coordinate(value, minimum, maximum) {
    // Number(null) and Number('') both equal zero. Treat empty values as
    // absent so an order without a saved point never becomes a false pin at
    // the Gulf of Guinea.
    if (value === null || value === undefined || String(value).trim() === '') return null

    const parsed = Number(value)

    return Number.isFinite(parsed) && parsed >= minimum && parsed <= maximum ? parsed : null
}

export function hasPickupLocation(order) {
    const latitude = coordinate(order?.pickup_latitude, -90, 90)
    const longitude = coordinate(order?.pickup_longitude, -180, 180)

    return latitude !== null && longitude !== null
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
    const userAgent = typeof navigator === 'undefined' ? '' : navigator.userAgent || ''
    const platform = typeof navigator === 'undefined' ? '' : navigator.platform || ''
    const isIos = /iPad|iPhone|iPod/.test(userAgent)
        || (platform === 'MacIntel' && (navigator.maxTouchPoints || 0) > 1)

    // Android's native geo intent shows the courier's installed navigation
    // apps. Apple Maps is the matching native hand-off on iOS; a normal Maps
    // URL keeps navigation usable when a courier opens the PWA on desktop.
    if (/Android/i.test(userAgent)) {
        const query = encodeURIComponent(label ? `${destination} (${label})` : destination)
        return `geo:0,0?q=${query}`
    }

    if (isIos) {
        const query = encodeURIComponent(label || destination)
        return `https://maps.apple.com/?ll=${destination}&q=${query}`
    }

    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(destination)}`
}
