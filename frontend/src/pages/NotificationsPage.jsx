import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Button } from '../components/ui.jsx'
import api from '../lib/api.js'
import { safeInternalRoute } from '../lib/navigation.js'

const TYPE_LABELS = {
  cor_verification_reviewed: 'COR verification update',
  new_message: 'New message',
  new_review: 'New review',
  transaction_update: 'Transaction update',
}

function getErrorMessage(error) {
  const validationErrors = Object.values(error.response?.data?.errors || {}).flat()
  return validationErrors[0] || error.response?.data?.message || error.message
}

function formatDateTime(value) {
  if (!value) return 'Date unavailable'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString()
}

function announceUnreadCount(unreadCount) {
  window.dispatchEvent(new CustomEvent('campustrade:notifications-updated', {
    detail: { unreadCount },
  }))
}

export default function NotificationsPage() {
  const navigate = useNavigate()
  const [notifications, setNotifications] = useState([])
  const [unreadCount, setUnreadCount] = useState(0)
  const [nextCursor, setNextCursor] = useState(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [loadingOlder, setLoadingOlder] = useState(false)
  const [busyId, setBusyId] = useState(null)
  const [markingAll, setMarkingAll] = useState(false)
  const [reloadKey, setReloadKey] = useState(0)

  useEffect(() => {
    let active = true
    api.get('/api/auth/notifications')
      .then(({ data }) => {
        if (!active) return
        setNotifications(data.notifications)
        setUnreadCount(data.unread_count)
        announceUnreadCount(data.unread_count)
        setNextCursor(data.next_cursor)
        setError('')
      })
      .catch((requestError) => {
        if (!active) return
        if (requestError.response?.status === 401) {
          navigate('/login', { replace: true, state: { returnTo: '/notifications' } })
        } else {
          setError(getErrorMessage(requestError))
        }
      })
      .finally(() => {
        if (active) setLoading(false)
      })
    return () => { active = false }
  }, [navigate, reloadKey])

  async function loadOlder() {
    if (!nextCursor || loadingOlder) return
    setLoadingOlder(true)
    setError('')
    try {
      const { data } = await api.get('/api/auth/notifications', {
        params: { cursor: nextCursor },
      })
      setNotifications((current) => {
        const existingIds = new Set(current.map((notification) => notification.id))
        return [
          ...current,
          ...data.notifications.filter((notification) => !existingIds.has(notification.id)),
        ]
      })
      setNextCursor(data.next_cursor)
      setUnreadCount(data.unread_count)
      announceUnreadCount(data.unread_count)
    } catch (requestError) {
      if (requestError.response?.status === 401) {
        navigate('/login', { replace: true, state: { returnTo: '/notifications' } })
      } else {
        setError(getErrorMessage(requestError))
      }
    } finally {
      setLoadingOlder(false)
    }
  }

  async function markRead(notification, open = false) {
    const destination = safeInternalRoute(notification.action_url, null)
    if (notification.is_read) {
      if (open && destination) navigate(destination)
      return
    }
    if (busyId !== null) return

    setBusyId(notification.id)
    setError('')
    try {
      const { data } = await api.patch(`/api/auth/notifications/${notification.id}/read`)
      setNotifications((current) => current.map((item) => (
        item.id === notification.id ? data.notification : item
      )))
      setUnreadCount(data.unread_count)
      announceUnreadCount(data.unread_count)
      if (open && destination) navigate(destination)
    } catch (requestError) {
      if (requestError.response?.status === 401) {
        navigate('/login', { replace: true, state: { returnTo: '/notifications' } })
      } else {
        setError(getErrorMessage(requestError))
      }
    } finally {
      setBusyId(null)
    }
  }

  async function markAllRead() {
    if (unreadCount === 0 || markingAll) return
    setMarkingAll(true)
    setError('')
    try {
      const { data } = await api.post('/api/auth/notifications/read-all')
      const readAt = new Date().toISOString()
      setNotifications((current) => current.map((notification) => (
        notification.is_read ? notification : { ...notification, is_read: true, read_at: readAt }
      )))
      setUnreadCount(data.unread_count)
      announceUnreadCount(data.unread_count)
    } catch (requestError) {
      if (requestError.response?.status === 401) {
        navigate('/login', { replace: true, state: { returnTo: '/notifications' } })
      } else {
        setError(getErrorMessage(requestError))
      }
    } finally {
      setMarkingAll(false)
    }
  }

  async function deleteNotification(notification) {
    if (busyId !== null) return
    if (!window.confirm('Delete this notification? This cannot be undone.')) return

    setBusyId(notification.id)
    setError('')
    try {
      const { data } = await api.delete(`/api/auth/notifications/${notification.id}`)
      setNotifications((current) => current.filter((item) => item.id !== notification.id))
      setUnreadCount(data.unread_count)
      announceUnreadCount(data.unread_count)
    } catch (requestError) {
      if (requestError.response?.status === 401) {
        navigate('/login', { replace: true, state: { returnTo: '/notifications' } })
      } else {
        setError(getErrorMessage(requestError))
      }
    } finally {
      setBusyId(null)
    }
  }

  return (
    <main className="marketplace-shell notifications-shell">
      <div className="messages-heading">
        <div>
          <p className="eyebrow">Your account</p>
          <h1>Notifications</h1>
          <p>{unreadCount ? `${unreadCount} unread notification${unreadCount === 1 ? '' : 's'}` : 'You’re all caught up.'}</p>
        </div>
        <Button
          variant="secondary"
          disabled={unreadCount === 0 || markingAll}
          onClick={markAllRead}
        >
          {markingAll ? 'Marking…' : 'Mark all as read'}
        </Button>
      </div>

      {error && notifications.length > 0 && <p className="form-error" role="alert">{error}</p>}
      {loading ? (
        <p className="marketplace-loading" role="status">Loading notifications…</p>
      ) : error && !notifications.length ? (
        <section className="marketplace-empty marketplace-error-state">
          <h2>Notifications unavailable</h2>
          <p role="alert">{error}</p>
          <Button
            variant="secondary"
            onClick={() => {
              setError('')
              setLoading(true)
              setReloadKey((key) => key + 1)
            }}
          >
            Try again
          </Button>
        </section>
      ) : notifications.length ? (
        <>
          <section className="notification-list" aria-label="Your notifications">
            {notifications.map((notification) => (
              <article
                className={`notification-card${notification.is_read ? '' : ' notification-card-unread'}`}
                key={notification.id}
              >
                <div className="notification-card-content">
                  <div className="notification-card-heading">
                    <strong>{TYPE_LABELS[notification.notification_type] || notification.notification_type.replaceAll('_', ' ')}</strong>
                    {!notification.is_read && <span className="notification-unread-label">Unread</span>}
                  </div>
                  <p>{notification.message}</p>
                  <time dateTime={notification.created_at}>{formatDateTime(notification.created_at)}</time>
                </div>
                <div className="notification-card-actions">
                  {safeInternalRoute(notification.action_url, null) && (
                    <Button
                      variant="secondary"
                      disabled={busyId !== null}
                      onClick={() => markRead(notification, true)}
                    >
                      Open
                    </Button>
                  )}
                  {!notification.is_read && (
                    <Button
                      variant="secondary"
                      disabled={busyId !== null}
                      onClick={() => markRead(notification)}
                    >
                      {busyId === notification.id ? 'Saving…' : 'Mark read'}
                    </Button>
                  )}
                  <Button
                    variant="danger"
                    disabled={busyId !== null}
                    onClick={() => deleteNotification(notification)}
                  >
                    {busyId === notification.id ? 'Deleting…' : 'Delete'}
                  </Button>
                </div>
              </article>
            ))}
          </section>
          {nextCursor && (
            <Button
              className="load-older-conversations"
              variant="secondary"
              disabled={loadingOlder}
              onClick={loadOlder}
            >
              {loadingOlder ? 'Loading…' : 'Load older notifications'}
            </Button>
          )}
        </>
      ) : (
        <section className="marketplace-empty">
          <span className="marketplace-empty-mark" aria-hidden="true">cT</span>
          <h2>No notifications yet</h2>
          <p>Updates about messages, transactions, reviews, and verification decisions will appear here.</p>
        </section>
      )}
    </main>
  )
}
