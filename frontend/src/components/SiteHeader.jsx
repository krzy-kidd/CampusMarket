import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import api from '../lib/api.js'

export default function SiteHeader({ variant = 'default', pathname = '' }) {
  const isAuth = variant === 'auth'
  const isAdmin = variant === 'admin'
  const [session, setSession] = useState({ status: 'checking' })
  const [unreadNotifications, setUnreadNotifications] = useState(0)
  const [unreadMessages, setUnreadMessages] = useState(0)
  const [activeTransactionCount, setActiveTransactionCount] = useState(0)
  const [availableListingCount, setAvailableListingCount] = useState(null)
  const isLanding = variant === 'landing' && session.status === 'guest'
  const landingSessionChecking = variant === 'landing' && session.status === 'checking'
  const [menuOpen, setMenuOpen] = useState(false)
  const authAction = pathname === '/login'
    ? { to: '/register', label: 'Create account' }
    : pathname === '/register'
      ? { to: '/login', label: 'Sign in' }
      : { to: '/login', label: 'Back to sign in' }
  const isMarketplace = pathname === '/marketplace'
    || pathname === '/listings/new'
    || (/^\/listings\/[^/]+$/.test(pathname) && !/^\/listings\/[^/]+\/edit$/.test(pathname))
  const isCurrent = (to) => pathname === to
    || (to === '/marketplace' && isMarketplace)
    || (to === '/my-listings' && /^\/listings\/[^/]+\/edit$/.test(pathname))
    || (to !== '/admin' && to !== '/marketplace' && pathname.startsWith(`${to}/`))

  useEffect(() => {
    if (isAuth) return undefined

    let active = true
    api.get('/api/auth/me')
      .then(({ data }) => {
        if (!active) return
        setSession({
          status: data.user.account_status === 'active' ? 'signed-in' : 'inactive',
          user: data.user,
        })
      })
      .catch(() => {
        if (active) setSession({ status: 'guest' })
      })

    return () => { active = false }
  }, [isAuth, pathname])

  useEffect(() => {
    if (session.status !== 'signed-in' || session.user.admin_role) {
      return undefined
    }

    let active = true
    const loadUnreadCount = () => {
      api.get('/api/auth/notifications')
        .then(({ data }) => {
          if (active) setUnreadNotifications(Number(data.unread_count) || 0)
        })
        .catch((error) => {
          if (active && error.response?.status !== 401) {
            console.error('Could not load unread notification count.', error)
          }
        })
    }
    const updateUnreadCount = (event) => {
      setUnreadNotifications(Number(event.detail?.unreadCount) || 0)
    }
    const loadUnreadMessageCount = () => {
      api.get('/api/auth/conversations')
        .then(({ data }) => {
          if (active) setUnreadMessages(Number(data.unread_count) || 0)
        })
        .catch((error) => {
          if (active && error.response?.status !== 401) {
            console.error('Could not load unread message count.', error)
          }
        })
    }
    const loadActiveTransactionCount = () => {
      api.get('/api/auth/transactions')
        .then(({ data }) => {
          if (active) setActiveTransactionCount(Number(data.active_count) || 0)
        })
        .catch((error) => {
          if (active && error.response?.status !== 401) {
            console.error('Could not load active transaction count.', error)
          }
        })
    }
    const loadAvailableListingCount = () => {
      api.get('/api/auth/listings')
        .then(({ data }) => {
          if (!active) return
          const listings = Array.isArray(data.listings) ? data.listings : []
          setAvailableListingCount(listings.filter((listing) => listing.listing_status === 'available').length)
        })
        .catch((error) => {
          if (active && error.response?.status !== 401) {
            console.error('Could not load available listing count.', error)
          }
        })
    }

    loadUnreadCount()
    loadUnreadMessageCount()
    loadActiveTransactionCount()
    loadAvailableListingCount()
    const intervalId = window.setInterval(() => {
      loadUnreadCount()
      loadUnreadMessageCount()
      loadActiveTransactionCount()
      loadAvailableListingCount()
    }, 60000)
    window.addEventListener('focus', loadUnreadCount)
    window.addEventListener('focus', loadUnreadMessageCount)
    window.addEventListener('focus', loadActiveTransactionCount)
    window.addEventListener('focus', loadAvailableListingCount)
    window.addEventListener('campustrade:notifications-updated', updateUnreadCount)
    window.addEventListener('campustrade:messages-updated', loadUnreadMessageCount)
    window.addEventListener('campustrade:transactions-updated', loadActiveTransactionCount)
    window.addEventListener('campustrade:listings-updated', loadAvailableListingCount)

    return () => {
      active = false
      window.clearInterval(intervalId)
      window.removeEventListener('focus', loadUnreadCount)
      window.removeEventListener('focus', loadUnreadMessageCount)
      window.removeEventListener('focus', loadActiveTransactionCount)
      window.removeEventListener('focus', loadAvailableListingCount)
      window.removeEventListener('campustrade:notifications-updated', updateUnreadCount)
      window.removeEventListener('campustrade:messages-updated', loadUnreadMessageCount)
      window.removeEventListener('campustrade:transactions-updated', loadActiveTransactionCount)
      window.removeEventListener('campustrade:listings-updated', loadAvailableListingCount)
    }
  }, [pathname, session.status, session.user?.admin_role])

  return (
    <header className={`site-header${isLanding ? ' landing-header' : ' app-site-header'}${menuOpen ? ' is-open' : ''}`}>
      <Link className="wordmark" to="/" aria-label="CampusTrade home">
        campusTrade
      </Link>
      {!isLanding && !landingSessionChecking && (
        <button
          className="nav-menu-toggle"
          type="button"
          aria-expanded={menuOpen}
          aria-controls="primary-navigation"
          onClick={() => setMenuOpen((open) => !open)}
        >
          <span className="nav-menu-icon" aria-hidden="true"><span /><span /><span /></span>
          <span>{menuOpen ? 'Close' : 'Menu'}</span>
        </button>
      )}
      <nav id="primary-navigation" className={isLanding ? '' : 'app-navigation'} aria-label="Main navigation">
        {landingSessionChecking ? null : isLanding ? (
          <>
            <div className="landing-nav-primary">
              <Link to="/" aria-current={pathname === '/' ? 'page' : undefined}>Home</Link>
              <Link to="/marketplace" aria-current={isMarketplace ? 'page' : undefined}>Browse</Link>
              <Link to="/#categories">Categories</Link>
              <Link to="/#how-it-works">About</Link>
            </div>
            <div className="landing-nav-actions">
              <Link className="landing-nav-login" to="/login">Log in</Link>
              <Link className="landing-nav-signup" to="/register">Sign up</Link>
            </div>
          </>
        ) : isAuth ? (
          <>
            <Link to="/marketplace" onClick={() => setMenuOpen(false)}>Browse marketplace</Link>
            <Link
              to={authAction.to}
              aria-current={pathname === authAction.to ? 'page' : undefined}
              onClick={() => setMenuOpen(false)}
            >
              {authAction.label}
            </Link>
          </>
        ) : session.status === 'signed-in' ? (
          (isAdmin || session.user.admin_role
            ? [
                { label: 'Admin dashboard', to: '/admin' },
                { label: 'Account', to: '/account' },
              ]
            : [
                { label: 'Dashboard', to: '/dashboard' },
                { label: 'Marketplace', to: '/marketplace' },
              { label: 'Sell', to: '/listings/new' },
              { label: 'My listings', to: '/my-listings' },
              { label: 'Messages', to: '/messages' },
                { label: 'Transactions', to: '/transactions' },
                { label: 'Notifications', to: '/notifications' },
                ...(session.user.admin_role ? [{ label: 'Admin dashboard', to: '/admin' }] : []),
                { label: 'Account', to: '/account' },
              ]
          ).map(({ label, to }) => {
            const isActive = isCurrent(to)
            return (
              <Link
                key={to}
                to={to}
                className={[
                  isActive ? 'is-active' : '',
                  to === '/listings/new' ? 'nav-sell-link' : '',
                ].filter(Boolean).join(' ') || undefined}
                aria-current={isActive ? 'page' : undefined}
                onClick={() => setMenuOpen(false)}
              >
                {label}
                {to === '/my-listings' && availableListingCount > 0 && (
                  <span className="notification-nav-count" aria-label={`${availableListingCount} available listings`}>
                    {availableListingCount > 99 ? '99+' : availableListingCount}
                  </span>
                )}
                {to === '/messages' && unreadMessages > 0 && (
                  <span className="notification-nav-count" aria-label={`${unreadMessages} unread messages`}>
                    {unreadMessages > 99 ? '99+' : unreadMessages}
                  </span>
                )}
                {to === '/transactions' && activeTransactionCount > 0 && (
                  <span className="notification-nav-count" aria-label={`${activeTransactionCount} active transactions`}>
                    {activeTransactionCount > 99 ? '99+' : activeTransactionCount}
                  </span>
                )}
                {to === '/notifications' && unreadNotifications > 0 && (
                  <span className="notification-nav-count" aria-label={`${unreadNotifications} unread`}>
                    {unreadNotifications > 99 ? '99+' : unreadNotifications}
                  </span>
                )}
              </Link>
            )
          })
        ) : session.status === 'inactive' ? (
          <Link to="/account" aria-current={isCurrent('/account') ? 'page' : undefined} onClick={() => setMenuOpen(false)}>Account status</Link>
        ) : (
          <>
            <Link to="/marketplace" onClick={() => setMenuOpen(false)}>Marketplace</Link>
            <Link to="/login" onClick={() => setMenuOpen(false)}>Sign in</Link>
            <Link to="/register" onClick={() => setMenuOpen(false)}>Create account</Link>
          </>
        )}
      </nav>
    </header>
  )
}
