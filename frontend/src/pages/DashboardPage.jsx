import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Badge, Button, Card } from '../components/ui.jsx'
import api from '../lib/api.js'
import './DashboardPage.css'

const VERIFICATION_LABELS = {
  not_configured: 'Academic year not configured',
  not_submitted: 'COR not submitted',
  pending: 'COR under review',
  approved: 'Verified for this academic year',
  rejected: 'COR needs attention',
}

const TRANSACTION_LABELS = {
  pending_agreement: 'Awaiting meetup confirmation',
  meetup_arranged: 'Meetup arranged',
  completed: 'Completed',
  cancelled: 'Cancelled',
  disputed: 'Under review',
}

function getErrorMessage(error) {
  return error.response?.data?.message || 'CampusTrade could not load your overview. Please try again.'
}

export default function DashboardPage() {
  const navigate = useNavigate()
  const [overview, setOverview] = useState(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [reloadKey, setReloadKey] = useState(0)

  useEffect(() => {
    let active = true
    Promise.allSettled([
      api.get('/api/auth/me'),
      api.get('/api/auth/verification/status'),
      api.get('/api/auth/listings'),
      api.get('/api/auth/transactions'),
      api.get('/api/auth/notifications'),
      api.get('/api/auth/conversations'),
    ])
      .then((results) => {
        if (!active) return
        const authFailure = results.find((result) => (
          result.status === 'rejected' && result.reason.response?.status === 401
        ))
        if (authFailure) {
          navigate('/login', { replace: true, state: { returnTo: '/dashboard' } })
          return
        }

        const [account, verification, listings, transactions, notifications, conversations] = results
        if (account.status === 'rejected') {
          setError(getErrorMessage(account.reason))
          return
        }

        const accountData = account.value.data?.user
        if (!accountData || typeof accountData !== 'object') {
          setError('CampusTrade returned incomplete account information. Please try again.')
          return
        }

        const optionalDataFailed = results.slice(1).some((result) => result.status === 'rejected')
        const verificationData = verification.status === 'fulfilled'
          ? verification.value.data || {}
          : {}
        const listingData = listings.status === 'fulfilled'
          && Array.isArray(listings.value.data?.listings)
          ? listings.value.data.listings
          : []
        const transactionData = transactions.status === 'fulfilled'
          && Array.isArray(transactions.value.data?.transactions)
          ? transactions.value.data.transactions
          : []
        const notificationData = notifications.status === 'fulfilled'
          ? notifications.value.data || {}
          : {}
        const conversationData = conversations.status === 'fulfilled'
          && Array.isArray(conversations.value.data?.conversations)
          ? conversations.value.data.conversations
          : []
        setOverview({
          account: accountData,
          verification: verificationData,
          listings: listingData,
          transactions: transactionData,
          notifications: notificationData,
          conversations: conversationData,
        })
        setError(optionalDataFailed ? 'Some overview details are temporarily unavailable.' : '')
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => { active = false }
  }, [navigate, reloadKey])

  if (loading) {
    return <main className="dashboard-shell"><p className="marketplace-loading" role="status">Loading your overview…</p></main>
  }

  if (!overview) {
    return (
      <main className="dashboard-shell">
        <section className="marketplace-empty marketplace-error-state">
          <h1>Dashboard unavailable</h1>
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
          <Link className="text-link" to="/account">Account and verification</Link>
        </section>
      </main>
    )
  }

  const { account, verification, listings, transactions, notifications, conversations } = overview
  const status = verification.status || account.verification_status || 'not_submitted'
  const verificationVariant = status === 'approved' ? 'success'
    : status === 'rejected' ? 'danger'
      : status === 'pending' ? 'warning' : 'neutral'
  const activeListings = listings.filter((listing) => listing?.listing_status === 'available').length
  const recentTransactions = transactions.slice(0, 4)
  const recentConversations = conversations.slice(0, 3)
  const canCreateListing = account.account_status === 'active'
    && account.email_verified
    && verification.is_verified

  return (
    <main className="dashboard-shell">
      <header className="dashboard-heading">
        <div>
          <p className="eyebrow">Student dashboard</p>
          <h1>Welcome, {account.first_name}</h1>
          <p>Keep track of your verification, listings, messages, and meetups.</p>
        </div>
        <div className="dashboard-heading-actions">
          <Link className="secondary-link" to="/account">Account and verification</Link>
          <Button to="/marketplace">Browse marketplace</Button>
        </div>
      </header>

      <section className="dashboard-status-grid" aria-label="Account status">
        <Card className="dashboard-status-card">
          <span className="dashboard-status-label">Account status</span>
          <Badge variant={account.account_status === 'active' ? 'success' : 'danger'}>
            {account.account_status || 'Unavailable'}
          </Badge>
        </Card>
        <Card className="dashboard-status-card">
          <span className="dashboard-status-label">WMSU email</span>
          <Badge variant={account.email_verified ? 'success' : 'warning'}>
            {account.email_verified ? 'Verified' : 'Needs verification'}
          </Badge>
        </Card>
        <Card className="dashboard-status-card">
          <span className="dashboard-status-label">Academic verification</span>
          <Badge variant={verificationVariant}>{VERIFICATION_LABELS[status] || 'Status unavailable'}</Badge>
          {verification.academic_year && <small>{verification.academic_year}</small>}
        </Card>
        <Card className="dashboard-status-card">
          <span className="dashboard-status-label">Available listings</span>
          <strong>{activeListings}</strong>
          <Link to="/my-listings">Manage listings</Link>
        </Card>
        <Card className="dashboard-status-card">
          <span className="dashboard-status-label">Unread notifications</span>
          <strong>{notifications.unread_count || 0}</strong>
          <Link to="/notifications">Open notifications</Link>
        </Card>
      </section>

      {account.account_status !== 'active' ? (
        <section className="dashboard-verification-callout" aria-labelledby="dashboard-account-title">
          <div>
            <h2 id="dashboard-account-title">Some account actions are unavailable</h2>
            <p>Your account status is {account.account_status}. Contact a CampusTrade administrator if you think this is a mistake.</p>
          </div>
          {account.admin_role && <Link className="secondary-link" to="/admin">Admin dashboard</Link>}
        </section>
      ) : !canCreateListing && (
        <section className="dashboard-verification-callout" aria-labelledby="dashboard-verification-title">
          <div>
            <h2 id="dashboard-verification-title">Finish verification to sell</h2>
            <p>Publishing requires a verified WMSU email and an approved COR for the current academic year.</p>
          </div>
          <Link className="primary-link" to="/account">Review verification</Link>
        </section>
      )}

      {error && <p className="form-error" role="alert">{error}</p>}

      <div className="dashboard-content-grid">
        <section className="dashboard-panel" aria-labelledby="dashboard-transactions-title">
          <div className="dashboard-panel-heading">
            <div><h2 id="dashboard-transactions-title">Recent transactions</h2><p>Your latest purchase and sale activity.</p></div>
            <Link to="/transactions">View all</Link>
          </div>
          {recentTransactions.length ? (
            <ul className="dashboard-activity-list">
              {recentTransactions.map((transaction) => (
                <li key={transaction.id}>
                  <Link to={`/transactions/${transaction.id}`}>
                    <strong>{transaction.listing?.title || 'CampusTrade item'}</strong>
                    <span>{TRANSACTION_LABELS[transaction.transaction_status] || 'Transaction update'}</span>
                    <small>
                      {transaction.current_user_role === 'buyer'
                        ? `Buying from ${transaction.seller?.first_name || 'seller'}`
                        : `Selling to ${transaction.buyer?.first_name || 'buyer'}`}
                    </small>
                  </Link>
                </li>
              ))}
            </ul>
          ) : (
            <div className="dashboard-empty"><p>No transactions yet.</p><Link to="/marketplace">Explore listings</Link></div>
          )}
        </section>

        <section className="dashboard-panel" aria-labelledby="dashboard-messages-title">
          <div className="dashboard-panel-heading">
            <div><h2 id="dashboard-messages-title">Recent conversations</h2><p>Private chats with buyers and sellers.</p></div>
            <Link to="/messages">View all</Link>
          </div>
          {recentConversations.length ? (
            <ul className="dashboard-activity-list">
              {recentConversations.map((conversation) => (
                <li key={conversation.id}>
                  <Link to={`/messages/${conversation.id}`}>
                    <strong>{conversation.participant?.first_name} {conversation.participant?.last_name}</strong>
                    <span>{conversation.listing?.title || 'Marketplace conversation'}</span>
                    <small>{conversation.last_message?.message_text || 'No messages yet'}</small>
                  </Link>
                </li>
              ))}
            </ul>
          ) : (
            <div className="dashboard-empty"><p>No conversations yet.</p><Link to="/marketplace">Message a seller</Link></div>
          )}
        </section>
      </div>

      <section className="dashboard-quick-actions" aria-label="Quick actions">
        <Link to="/my-listings">Manage my listings</Link>
        <Link to="/transactions">Coordinate a meetup</Link>
        {canCreateListing && <Link to="/listings/new">Sell an item</Link>}
      </section>
    </main>
  )
}
