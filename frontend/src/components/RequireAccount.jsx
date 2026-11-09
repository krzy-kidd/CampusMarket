import { useEffect, useState } from 'react'
import { Link, Navigate, Outlet, useLocation } from 'react-router-dom'
import { Button } from './ui.jsx'
import { getCurrentUser } from '../lib/session.js'

export default function RequireAccount({
  adminOnly = false,
  studentOnly = false,
  allowGuest = false,
  redirectAuthenticated = false,
}) {
  const location = useLocation()
  const [access, setAccess] = useState({ status: 'checking' })
  const [retryKey, setRetryKey] = useState(0)

  useEffect(() => {
    let active = true
    getCurrentUser()
      .then(({ data }) => {
        if (!active) return
        const user = data.user
        if (user.account_status !== 'active') {
          setAccess({ status: 'inactive', user })
        } else if (adminOnly && !user.admin_role) {
          setAccess({ status: 'forbidden' })
        } else if (studentOnly && user.admin_role) {
          setAccess({ status: 'admin_disallowed' })
        } else {
          setAccess({ status: 'allowed', user })
        }
      })
      .catch((error) => {
        if (!active) return
        setAccess({
          status: error.response?.status === 401 ? 'guest' : 'unavailable',
          message: error.response?.status === 403
            ? 'Your account cannot access this section.'
            : 'We could not confirm your account access. Check your connection and try again.',
        })
      })

    return () => { active = false }
  }, [adminOnly, location.pathname, retryKey, studentOnly])

  if (access.status === 'checking') {
    return <main className="page-shell"><section className="status-card"><p role="status">Checking account access…</p></section></main>
  }

  if (access.status === 'guest') {
    if (allowGuest) return <Outlet />

    return (
      <Navigate
        to="/login"
        replace
        state={{ returnTo: `${location.pathname}${location.search}${location.hash}` }}
      />
    )
  }

  if (access.status === 'allowed' && redirectAuthenticated) {
    return <Navigate to={access.user.admin_role ? '/admin' : '/marketplace'} replace />
  }

  if (access.status === 'inactive') {
    return (
      <main className="page-shell">
        <section className="status-card" aria-labelledby="inactive-account-title">
          <p className="eyebrow">Account access</p>
          <h1 id="inactive-account-title">Your account is not active</h1>
          <p className="status-detail">
            Current status: <strong>{access.user.account_status}</strong>. Contact a RECREO administrator if you think this is a mistake.
          </p>
          <div className="status-actions"><Link className="text-link" to="/">Return home</Link></div>
        </section>
      </main>
    )
  }

  if (access.status === 'forbidden') return <Navigate to="/account" replace />
  if (access.status === 'admin_disallowed') return <Navigate to="/admin" replace />

  if (access.status === 'unavailable') {
    return (
      <main className="page-shell">
        <section className="status-card" aria-labelledby="account-check-title">
          <p className="eyebrow">Account access</p>
          <h1 id="account-check-title">Could not check your access</h1>
          <p className="form-error" role="alert">{access.message}</p>
          <div className="status-actions">
            <Button variant="secondary" onClick={() => setRetryKey((key) => key + 1)}>Try again</Button>
            <Link className="text-link" to="/">Return home</Link>
          </div>
        </section>
      </main>
    )
  }

  return <Outlet />
}
