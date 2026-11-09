import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Button } from '../components/ui.jsx'
import api from '../lib/api.js'
import { getCurrentUser } from '../lib/session.js'
import './DashboardPage.css'

const TRANSACTION_LABELS = {
  pending_agreement: 'Awaiting meetup confirmation',
  meetup_arranged: 'Meetup arranged',
  completed: 'Completed',
  cancelled: 'Cancelled',
  disputed: 'Under review',
}

const TRANSACTION_CHART_STATUSES = [
  { key: 'pending_agreement', label: 'Awaiting confirmation', tone: 'pending' },
  { key: 'meetup_arranged', label: 'Meetup arranged', tone: 'meetup' },
  { key: 'disputed', label: 'Under review', tone: 'disputed' },
  { key: 'completed', label: 'Completed', tone: 'completed' },
  { key: 'cancelled', label: 'Cancelled', tone: 'cancelled' },
]

function getErrorMessage(error) {
  return error.response?.data?.message || 'RECREO could not load your overview. Please try again.'
}

export default function DashboardPage() {
  const navigate = useNavigate()
  const [overview, setOverview] = useState(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [reloadKey, setReloadKey] = useState(0)

  function retryOverview() {
    setError('')
    setLoading(true)
    setReloadKey((key) => key + 1)
  }

  useEffect(() => {
    let active = true
    Promise.allSettled([
      getCurrentUser(),
      api.get('/api/auth/verification/status'),
      api.get('/api/auth/transactions'),
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

        const [account, verification, transactions, conversations] = results
        if (account.status === 'rejected') {
          setError(getErrorMessage(account.reason))
          return
        }

        const accountData = account.value.data?.user
        if (!accountData || typeof accountData !== 'object') {
          setError('RECREO returned incomplete account information. Please try again.')
          return
        }

        const optionalDataFailed = results.slice(1).some((result) => result.status === 'rejected')
        const verificationData = verification.status === 'fulfilled'
          ? verification.value.data || {}
          : { status: 'unavailable' }
        const transactionData = transactions.status === 'fulfilled'
          && Array.isArray(transactions.value.data?.transactions)
          ? transactions.value.data.transactions
          : null
        const activeTransactionCount = transactions.status === 'fulfilled'
          && Number.isFinite(Number(transactions.value.data?.active_count))
          ? Number(transactions.value.data.active_count)
          : null
        const conversationData = conversations.status === 'fulfilled'
          && Array.isArray(conversations.value.data?.conversations)
          ? conversations.value.data.conversations
          : null
        setOverview({
          account: accountData,
          verification: verificationData,
          transactions: transactionData,
          activeTransactionCount,
          transactionsHaveMore: Boolean(transactions.value?.data?.next_cursor),
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
          <Button variant="secondary" onClick={retryOverview}>Try again</Button>
          <Link className="text-link" to="/account">Account and verification</Link>
        </section>
      </main>
    )
  }

  const { account, verification, transactions, conversations } = overview
  const status = verification.status || account.verification_status || 'not_submitted'
  const recentTransactions = transactions?.slice(0, 4) ?? []
  const recentConversations = conversations?.slice(0, 3) ?? []
  const transactionCounts = TRANSACTION_CHART_STATUSES.map((item) => ({
    ...item,
    count: transactions?.filter((transaction) => transaction.transaction_status === item.key).length ?? 0,
  }))
  const maxTransactionCount = Math.max(1, ...transactionCounts.map((item) => item.count))
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
          <Button to="/marketplace">Browse marketplace</Button>
        </div>
      </header>

      {account.account_status !== 'active' ? (
        <section className="dashboard-verification-callout" aria-labelledby="dashboard-account-title">
          <div>
            <h2 id="dashboard-account-title">Some account actions are unavailable</h2>
            <p>Your account status is {account.account_status}. Contact a RECREO administrator if you think this is a mistake.</p>
          </div>
          {account.admin_role && <Link className="secondary-link" to="/admin">Admin dashboard</Link>}
        </section>
      ) : status === 'unavailable' ? (
        <section className="dashboard-verification-callout" aria-labelledby="dashboard-verification-unavailable-title">
          <div>
            <h2 id="dashboard-verification-unavailable-title">Verification status is unavailable</h2>
            <p>Refresh this overview or check your account page for the latest verification status.</p>
          </div>
          <Link className="secondary-link" to="/account">Open account</Link>
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

      {error && (
        <div className="dashboard-overview-warning">
          <p className="form-error" role="alert">{error}</p>
          <Button variant="secondary" onClick={retryOverview}>Refresh overview</Button>
        </div>
      )}

      <section className="dashboard-transaction-insights" aria-labelledby="dashboard-insights-title">
        <div className="dashboard-insights-heading">
          <div>
            <p className="eyebrow">Your activity</p>
            <h2 id="dashboard-insights-title">Transaction overview</h2>
            <p>
              {overview.transactionsHaveMore
                ? 'Status breakdown for your 20 most recently updated transactions.'
                : 'Status breakdown of your transaction activity.'}
            </p>
          </div>
          <Link className="dashboard-active-summary" to="/transactions">
            <span>Active transactions</span>
            <strong>{overview.activeTransactionCount ?? '—'}</strong>
            <small>View transaction list <span aria-hidden="true">→</span></small>
          </Link>
        </div>
        {transactions === null ? (
          <div className="dashboard-empty">
            <p>Transaction statistics are temporarily unavailable.</p>
          </div>
        ) : transactions.length ? (
          <ul className="dashboard-transaction-chart" aria-label="Transactions grouped by status">
            {transactionCounts.map((item) => (
              <li key={item.key}>
                <span className="dashboard-chart-label">{item.label}</span>
                <span className="dashboard-chart-track" aria-hidden="true">
                  <span
                    className={`dashboard-chart-bar is-${item.tone}`}
                    style={{ width: `${(item.count / maxTransactionCount) * 100}%` }}
                  />
                </span>
                <strong>{item.count}</strong>
              </li>
            ))}
          </ul>
        ) : (
          <div className="dashboard-empty dashboard-chart-empty">
            <p>No transaction activity yet. Browse the marketplace to find something you need.</p>
            <Link to="/marketplace">Explore listings</Link>
          </div>
        )}
      </section>

      <div className="dashboard-content-grid">
        <section className="dashboard-panel" aria-labelledby="dashboard-transactions-title">
          <div className="dashboard-panel-heading">
            <div><h2 id="dashboard-transactions-title">Recent transactions</h2><p>Your latest purchase and sale activity.</p></div>
            <Link to="/transactions">View all</Link>
          </div>
          {transactions === null ? (
            <div className="dashboard-empty"><p>Transaction details are temporarily unavailable.</p></div>
          ) : recentTransactions.length ? (
            <ul className="dashboard-activity-list">
              {recentTransactions.map((transaction) => (
                <li key={transaction.id}>
                  <Link to={`/transactions/${transaction.id}`}>
                    <strong>{transaction.listing?.title || 'RECREO item'}</strong>
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
          {conversations === null ? (
            <div className="dashboard-empty"><p>Conversation details are temporarily unavailable.</p></div>
          ) : recentConversations.length ? (
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
