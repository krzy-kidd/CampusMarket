import api from './api.js'

const SESSION_CACHE_DURATION_MS = 10000

let currentUserRequest = null
let currentUserCachedAt = 0
let currentUserRequestPending = false

export function getCurrentUser({ refresh = false } = {}) {
  const cacheIsFresh = Date.now() - currentUserCachedAt < SESSION_CACHE_DURATION_MS
  if (!refresh && currentUserRequest && (currentUserRequestPending || cacheIsFresh)) {
    return currentUserRequest
  }

  const request = api.get('/api/auth/me')
  currentUserRequest = request
  currentUserRequestPending = true

  request.then(
    () => {
      if (currentUserRequest === request) {
        currentUserCachedAt = Date.now()
        currentUserRequestPending = false
      }
    },
    (error) => {
      if (currentUserRequest !== request) return
      currentUserRequestPending = false
      if (error.response?.status === 401) {
        currentUserCachedAt = Date.now()
      } else {
        currentUserRequest = null
        currentUserCachedAt = 0
      }
    },
  )

  return request
}

export function cacheCurrentUser(user) {
  currentUserRequest = Promise.resolve({ data: { user } })
  currentUserCachedAt = Date.now()
  currentUserRequestPending = false
}

export function clearCurrentUserCache() {
  currentUserRequest = null
  currentUserCachedAt = 0
  currentUserRequestPending = false
}
