export function safeInternalRoute(value, fallback = '/account') {
  if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//')) {
    return fallback
  }

  try {
    const destination = new URL(value, window.location.origin)
    if (destination.origin !== window.location.origin) return fallback
    if (['/login', '/register'].includes(destination.pathname)) return fallback
    return `${destination.pathname}${destination.search}${destination.hash}`
  } catch {
    return fallback
  }
}
