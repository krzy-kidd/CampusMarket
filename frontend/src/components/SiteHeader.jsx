import { Link } from 'react-router-dom'

export default function SiteHeader({ variant = 'default', pathname = '' }) {
  const isLanding = variant === 'landing'
  const isAuth = variant === 'auth'
  const authAction = pathname === '/login'
    ? { to: '/register', label: 'Create account' }
    : pathname === '/register'
      ? { to: '/login', label: 'Sign in' }
      : { to: '/login', label: 'Back to sign in' }

  return (
    <header className={`site-header${isLanding ? ' landing-header' : ''}`}>
      <Link className="wordmark" to="/" aria-label="CampusTrade home">
        campusTrade
      </Link>
      <nav aria-label="Main navigation">
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
            <Link to="/marketplace">Browse marketplace</Link>
            <Link to={authAction.to}>{authAction.label}</Link>
          </>
        ) : variant === 'admin' ? (
          <>
            <Link to="/marketplace">Marketplace</Link>
            <Link to="/account">Account</Link>
          </>
        ) : (
          <>
            <Link to="/marketplace">Marketplace</Link>
            <Link to="/my-listings">My listings</Link>
            <Link to="/messages">Messages</Link>
            <Link to="/transactions">Transactions</Link>
            <Link to="/notifications">Notifications</Link>
            <Link to="/login">Sign in</Link>
          </>
        )}
      </nav>
    </header>
  )
}
