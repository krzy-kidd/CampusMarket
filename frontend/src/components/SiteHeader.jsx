import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import api from '../lib/api.js'

export default function SiteHeader({ variant = 'default', pathname = '' }) {
  const isLanding = variant === 'landing'
  const isAuth = variant === 'auth'
  const isAdmin = variant === 'admin'
  const [menuOpen, setMenuOpen] = useState(false)
  const authAction = pathname === '/login'
    ? { to: '/register', label: 'Create account' }
    : pathname === '/register'
      ? { to: '/login', label: 'Sign in' }
      : { to: '/login', label: 'Back to sign in' }
  const [session, setSession] = useState({ status: 'checking' })

  useEffect(() => {
    if (isLanding || isAuth) return undefined

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
  }, [isAuth, isLanding, pathname])

  return (
    <header className={`site-header${isLanding ? ' landing-header' : ' app-site-header'}${menuOpen ? ' is-open' : ''}`}>
      <Link className="wordmark" to="/" aria-label="CampusTrade home">
        campusTrade
      </Link>
      {!isLanding && (
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
        {isLanding ? (
          <>
            <div className="landing-nav-primary">
              <Link to="/">Home</Link>
              <Link to="/marketplace">Browse</Link>
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
            <Link to={authAction.to} onClick={() => setMenuOpen(false)}>{authAction.label}</Link>
          </>
        ) : session.status === 'signed-in' ? (
          (isAdmin
            ? [
                { label: 'Admin dashboard', to: '/admin' },
                { label: 'Marketplace', to: '/marketplace' },
                { label: 'Account', to: '/account' },
              ]
            : [
                { label: 'Marketplace', to: '/marketplace' },
                { label: 'My listings', to: '/my-listings' },
                { label: 'Messages', to: '/messages' },
                { label: 'Transactions', to: '/transactions' },
                { label: 'Notifications', to: '/notifications' },
                ...(session.user.admin_role ? [{ label: 'Admin dashboard', to: '/admin' }] : []),
                { label: 'Account', to: '/account' },
              ]
          ).map(({ label, to }) => {
            const isActive = pathname === to
              || (to !== '/admin' && pathname.startsWith(`${to}/`))
              || (to === '/marketplace' && pathname.startsWith('/listings/'))
            return (
              <Link
                key={to}
                to={to}
                className={isActive ? 'is-active' : undefined}
                aria-current={isActive ? 'page' : undefined}
                onClick={() => setMenuOpen(false)}
              >
                {label}
              </Link>
            )
          })
        ) : session.status === 'inactive' ? (
          <>
            <Link to="/marketplace" onClick={() => setMenuOpen(false)}>Marketplace</Link>
            <Link to="/dashboard" onClick={() => setMenuOpen(false)}>Account status</Link>
          </>
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
