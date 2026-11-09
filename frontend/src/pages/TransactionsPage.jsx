import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Badge, Button, FormField, TextArea, TextInput } from '../components/ui.jsx'
import api from '../lib/api.js'

const STATUS_LABELS = {
  pending_agreement: 'Awaiting meetup confirmation',
  meetup_arranged: 'Meetup arranged',
  completed: 'Completed',
  cancelled: 'Cancelled',
  disputed: 'Under review',
}

function getErrorMessage(error) {
  const validationErrors = Object.values(error.response?.data?.errors || {}).flat()
  return validationErrors[0] || error.response?.data?.message || error.message
}

function formatPrice(price) {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
  }).format(Number(price))
}

function formatDateTime(date) {
  return new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(date))
}

function localToday() {
  const date = new Date()
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function MeetupForm({ meetup, setMeetup, performAction, busy, ownMeetupConfirmed, rescheduling = false }) {
  const [dateConfirmed, setDateConfirmed] = useState(false)
  const [timeConfirmed, setTimeConfirmed] = useState(false)

  return (
    <form
      className="transaction-form"
      onSubmit={(event) => {
        event.preventDefault()
        performAction('meetup', meetup)
      }}
    >
      <div className="form-grid">
        <div className="ui-field meetup-confirm-field">
          <label className="ui-field-label" htmlFor="meetup-date">Meetup date</label>
          <TextInput
            id="meetup-date"
            type="date"
            name="meetup_date"
            min={localToday()}
            value={meetup.meetup_date}
            onChange={(event) => {
              setMeetup({ ...meetup, meetup_date: event.target.value })
              setDateConfirmed(false)
            }}
            required
          />
          <Button
            type="button"
            variant="secondary"
            disabled={busy || !meetup.meetup_date}
            onClick={() => setDateConfirmed(true)}
          >
            {dateConfirmed ? 'Date confirmed' : 'Confirm date'}
          </Button>
        </div>
        <div className="ui-field meetup-confirm-field">
          <label className="ui-field-label" htmlFor="meetup-time">Meetup time</label>
          <TextInput
            id="meetup-time"
            type="time"
            name="meetup_time"
            value={meetup.meetup_time}
            onChange={(event) => {
              setMeetup({ ...meetup, meetup_time: event.target.value })
              setTimeConfirmed(false)
            }}
            required
          />
          <Button
            type="button"
            variant="secondary"
            disabled={busy || !meetup.meetup_time}
            onClick={() => setTimeConfirmed(true)}
          >
            {timeConfirmed ? 'Time confirmed' : 'Confirm time'}
          </Button>
        </div>
      </div>
      <FormField label="Public meetup location">
        <TextInput
          name="meetup_location"
          value={meetup.meetup_location}
          onChange={(event) => setMeetup({ ...meetup, meetup_location: event.target.value })}
          placeholder="For example, the WMSU main library entrance"
          maxLength="255"
          minLength="3"
          required
        />
      </FormField>
      <Button type="submit" disabled={busy || !dateConfirmed || !timeConfirmed}>
        {busy
          ? 'Saving…'
          : rescheduling
            ? 'Propose new meetup'
            : ownMeetupConfirmed
              ? 'Update meetup details'
              : 'Confirm meetup details'}
      </Button>
    </form>
  )
}

function hasMeetupTimeArrived(transaction, currentTime) {
  const meetupTimestamp = Date.parse(transaction.meetup_at || '')
  return Number.isFinite(meetupTimestamp) && currentTime >= meetupTimestamp
}

function signInFor(returnTo, navigate) {
  navigate('/login', { replace: true, state: { returnTo } })
}

export function TransactionsPage() {
  const navigate = useNavigate()
  const [transactions, setTransactions] = useState([])
  const [nextCursor, setNextCursor] = useState(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [loadingOlder, setLoadingOlder] = useState(false)
  const [reloadKey, setReloadKey] = useState(0)

  useEffect(() => {
    let active = true
    api.get('/api/auth/transactions')
      .then(({ data }) => {
        if (!active) return
        setTransactions(data.transactions)
        setNextCursor(data.next_cursor)
      })
      .catch((requestError) => {
        if (!active) return
        if (requestError.response?.status === 401) signInFor('/transactions', navigate)
        else setError(getErrorMessage(requestError))
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
      const { data } = await api.get('/api/auth/transactions', {
        params: { cursor: nextCursor },
      })
      setTransactions((current) => {
        const existingIds = new Set(current.map((transaction) => transaction.id))
        return [
          ...current,
          ...data.transactions.filter((transaction) => !existingIds.has(transaction.id)),
        ]
      })
      setNextCursor(data.next_cursor)
    } catch (requestError) {
      if (requestError.response?.status === 401) signInFor('/transactions', navigate)
      else setError(getErrorMessage(requestError))
    } finally {
      setLoadingOlder(false)
    }
  }

  return (
    <main className="marketplace-shell transaction-shell">
      <div className="messages-heading">
        <div>
          <p className="eyebrow">Purchases and sales</p>
          <h1>Transactions</h1>
          <p>Track purchase requests, meetup plans, and completed sales.</p>
        </div>
        <Link className="secondary-link" to="/messages">Messages</Link>
      </div>
      {loading ? (
        <p className="marketplace-loading" role="status">Loading transactions…</p>
      ) : error && !transactions.length ? (
        <section className="marketplace-empty marketplace-error-state">
          <h2>Transactions unavailable</h2>
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
      ) : transactions.length ? (
        <>
          {error && <p className="form-error" role="alert">{error}</p>}
          <section className="transaction-list" aria-label="Your transactions">
            {transactions.map((transaction) => (
              <Link
                className="transaction-card"
                key={transaction.id}
                to={`/transactions/${transaction.id}`}
              >
                <span>
                  <strong>{transaction.listing.title}</strong>
                  <small>
                    {transaction.current_user_role === 'buyer' ? 'Buying from ' : 'Selling to '}
                    {transaction.current_user_role === 'buyer'
                      ? `${transaction.seller.first_name} ${transaction.seller.last_name}`
                      : `${transaction.buyer.first_name} ${transaction.buyer.last_name}`}
                  </small>
                </span>
                <span className="transaction-card-meta">
                  <strong>{formatPrice(transaction.agreed_price)}</strong>
                  <Badge variant={transaction.transaction_status === 'completed' ? 'success' : 'neutral'}>
                    {transaction.cancellation_requested_at
                      ? 'Cancellation requested'
                      : STATUS_LABELS[transaction.transaction_status] || transaction.transaction_status}
                  </Badge>
                </span>
              </Link>
            ))}
          </section>
          {nextCursor && (
            <Button
              className="load-older-conversations"
              variant="secondary"
              disabled={loadingOlder}
              onClick={loadOlder}
            >
              {loadingOlder ? 'Loading…' : 'Load older transactions'}
            </Button>
          )}
        </>
      ) : (
        <section className="marketplace-empty">
          <span className="marketplace-empty-mark" aria-hidden="true">cT</span>
          <h2>No transactions yet</h2>
          <p>Choose Buy now on a listing to start a request, then confirm the meetup details together.</p>
          <Link className="primary-link" to="/marketplace">Browse listings</Link>
        </section>
      )}
    </main>
  )
}

export function TransactionDetailPage() {
  const { transactionId } = useParams()
  const navigate = useNavigate()
  const [transaction, setTransaction] = useState(null)
  const [meetup, setMeetup] = useState({ meetup_date: '', meetup_time: '', meetup_location: '' })
  const [reviewDraft, setReviewDraft] = useState({ rating: '5', comment: '' })
  const [dispute, setDispute] = useState({ reason: '', description: '' })
  const [cancellationReason, setCancellationReason] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [cancelOpen, setCancelOpen] = useState(false)
  const [rescheduleOpen, setRescheduleOpen] = useState(false)
  const [reloadKey, setReloadKey] = useState(0)
  const [currentTime, setCurrentTime] = useState(() => Date.now())

  useEffect(() => {
    let active = true
    api.get(`/api/auth/transactions/${transactionId}`)
      .then(({ data }) => {
        if (!active) return
        setTransaction(data.transaction)
        window.dispatchEvent(new CustomEvent('recreo:transactions-updated'))
        setMeetup({
          meetup_date: data.transaction.meetup_date || '',
          meetup_time: data.transaction.meetup_time || '',
          meetup_location: data.transaction.meetup_location || '',
        })
        setError('')
      })
      .catch((requestError) => {
        if (!active) return
        if (requestError.response?.status === 401) {
          signInFor(`/transactions/${transactionId}`, navigate)
        } else {
          setError(getErrorMessage(requestError))
        }
      })
      .finally(() => {
        if (active) setLoading(false)
      })
    return () => { active = false }
  }, [navigate, reloadKey, transactionId])

  useEffect(() => {
    const updateCurrentTime = () => setCurrentTime(Date.now())
    const timerId = window.setInterval(updateCurrentTime, 1000)
    window.addEventListener('focus', updateCurrentTime)
    document.addEventListener('visibilitychange', updateCurrentTime)
    return () => {
      window.clearInterval(timerId)
      window.removeEventListener('focus', updateCurrentTime)
      document.removeEventListener('visibilitychange', updateCurrentTime)
    }
  }, [])

  async function performAction(action, payload = {}) {
    setBusy(true)
    setError('')
    try {
      const { data } = await api.post(
        `/api/auth/transactions/${transactionId}/${action}`,
        payload,
      )
      setTransaction(data.transaction)
      window.dispatchEvent(new CustomEvent('recreo:transactions-updated'))
      setMeetup({
        meetup_date: data.transaction.meetup_date || '',
        meetup_time: data.transaction.meetup_time || '',
        meetup_location: data.transaction.meetup_location || '',
      })
      if (action === 'reviews') setReviewDraft({ rating: '5', comment: '' })
      if (action === 'dispute') setDispute({ reason: '', description: '' })
      setCancelOpen(false)
      setCancellationReason('')
    } catch (requestError) {
      if (requestError.response?.status === 401) {
        signInFor(`/transactions/${transactionId}`, navigate)
      } else {
        setError(getErrorMessage(requestError))
      }
    } finally {
      setBusy(false)
    }
  }

  async function hideCompletedTransaction() {
    if (busy || !window.confirm('Remove this completed transaction from your history? It will remain visible to the other participant.')) {
      return
    }

    setBusy(true)
    setError('')
    try {
      await api.post(`/api/auth/transactions/${transactionId}/hide-from-history`)
      window.dispatchEvent(new CustomEvent('recreo:transactions-updated'))
      navigate('/transactions', { replace: true })
    } catch (requestError) {
      if (requestError.response?.status === 401) {
        signInFor(`/transactions/${transactionId}`, navigate)
      } else {
        setError(getErrorMessage(requestError))
      }
    } finally {
      setBusy(false)
    }
  }

  if (loading) {
    return <main className="marketplace-shell"><p className="marketplace-loading" role="status">Loading transaction…</p></main>
  }
  if (error && !transaction) {
    return (
      <main className="marketplace-shell transaction-shell">
        <section className="marketplace-empty marketplace-error-state">
          <h1>Transaction unavailable</h1>
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
          <Link className="secondary-link" to="/transactions">Back to transactions</Link>
        </section>
      </main>
    )
  }
  if (!transaction) return null

  const isPending = transaction.transaction_status === 'pending_agreement'
  const isMeetupArranged = transaction.transaction_status === 'meetup_arranged'
  const isSeller = transaction.current_user_role === 'seller'
  const hasUnlockRequest = Boolean(transaction.seller_unlock_requested_at && transaction.buyer_unlock_response_deadline)
  const participantId = isSeller ? transaction.seller.id : transaction.buyer.id
  const hasCancellationRequest = Boolean(transaction.cancellation_requested_at)
  const cancellationRequestedByMe = Number(transaction.cancellation_requested_by) === Number(participantId)
  const canCancel = !hasCancellationRequest
    && !hasUnlockRequest
    && (isPending || (isMeetupArranged && !isSeller))
  const ownMeetupConfirmed = Boolean(
    isSeller ? transaction.seller_meetup_confirmed_at : transaction.buyer_meetup_confirmed_at,
  )
  const otherMeetupConfirmed = Boolean(
    isSeller ? transaction.buyer_meetup_confirmed_at : transaction.seller_meetup_confirmed_at,
  )
  const ownCompletionConfirmed = Boolean(
    isSeller ? transaction.seller_completion_confirmed_at : transaction.buyer_completion_confirmed_at,
  )
  const anyCompletionConfirmed = Boolean(
    transaction.buyer_completion_confirmed_at || transaction.seller_completion_confirmed_at,
  )
  const meetupTimeArrived = hasMeetupTimeArrived(transaction, currentTime)
  const displayStatus = hasCancellationRequest
    ? 'Cancellation requested'
    : isMeetupArranged && meetupTimeArrived && !hasUnlockRequest
      ? 'Scheduled meetup time arrived'
      : STATUS_LABELS[transaction.transaction_status] || transaction.transaction_status
  const otherParticipant = isSeller ? transaction.buyer : transaction.seller
  const ownReview = transaction.reviews.find((review) => Number(review.reviewer_id) === Number(participantId))
  const otherReview = transaction.reviews.find((review) => Number(review.reviewer_id) !== Number(participantId))
  const reviewedParticipant = isSeller ? transaction.buyer : transaction.seller

  return (
    <main className="marketplace-shell transaction-shell">
      <section className="transaction-detail">
        <header className="transaction-detail-header">
          <div>
            <p className="eyebrow">Purchase agreement</p>
            <h1>{transaction.listing.title}</h1>
            <p>
              {transaction.current_user_role === 'buyer' ? 'Seller: ' : 'Buyer: '}
              {transaction.current_user_role === 'buyer'
                ? `${transaction.seller.first_name} ${transaction.seller.last_name}`
                : `${transaction.buyer.first_name} ${transaction.buyer.last_name}`}
            </p>
          </div>
          <Badge variant={transaction.transaction_status === 'completed' ? 'success' : 'neutral'}>
            {displayStatus}
          </Badge>
        </header>

        <section className="transaction-summary">
          <span>Agreed fixed price</span>
          <strong>{formatPrice(transaction.agreed_price)}</strong>
        </section>

        {error && <p className="form-error" role="alert">{error}</p>}

        {hasCancellationRequest && (
          <section className="transaction-notice" role="status">
            {cancellationRequestedByMe ? (
              <>
                <strong>You requested cancellation.</strong>
                <p>Waiting for {otherParticipant.first_name} to respond. The transaction remains active and the listing stays reserved.</p>
              </>
            ) : (
              <>
                <strong>{otherParticipant.first_name} requested cancellation.</strong>
                <p>
                  Reason: {transaction.cancellation_request_reason || 'No reason provided.'}
                  {' '}Accepting releases the listing; declining keeps the transaction active.
                </p>
                <div className="transaction-form-actions">
                  <Button
                    disabled={busy}
                    onClick={() => performAction('cancel-response', { decision: 'accept' })}
                  >
                    {busy ? 'Saving…' : 'Accept cancellation'}
                  </Button>
                  <Button
                    variant="secondary"
                    disabled={busy}
                    onClick={() => performAction('cancel-response', { decision: 'decline' })}
                  >
                    Decline and continue
                  </Button>
                </div>
              </>
            )}
          </section>
        )}

        {!hasCancellationRequest && isPending && (
          <section className="transaction-step">
            <h2>Arrange a meetup</h2>
            <p>
              {ownMeetupConfirmed
                ? `Your confirmation is saved. Waiting for ${otherParticipant.first_name} to confirm these same details.`
                : 'Choose a future time and a public campus meetup spot. Both participants must confirm the same details.'}
            </p>
            {(transaction.meetup_date || transaction.meetup_time || transaction.meetup_location) && (
              <div className="transaction-confirmations">
                <p>Buyer: {transaction.buyer_meetup_confirmed_at ? 'Confirmed' : 'Needs to confirm'}</p>
                <p>Seller: {transaction.seller_meetup_confirmed_at ? 'Confirmed' : 'Needs to confirm'}</p>
                {otherMeetupConfirmed && !ownMeetupConfirmed && (
                  <p>{otherParticipant.first_name} confirmed these details. Your confirmation is needed next.</p>
                )}
              </div>
            )}
            <MeetupForm
              meetup={meetup}
              setMeetup={setMeetup}
              performAction={performAction}
              busy={busy}
              ownMeetupConfirmed={ownMeetupConfirmed}
            />
          </section>
        )}

        {!hasCancellationRequest && isMeetupArranged && (
          <section className="transaction-step">
            <h2>{meetupTimeArrived ? 'Scheduled meetup time arrived' : 'Meetup arranged'}</h2>
            <p>Meet at the agreed time and public location. If you did not meet, either participant can propose a new plan; both must confirm it before handoff confirmation is available.</p>
            <dl className="meetup-details">
              <div><dt>Date</dt><dd>{transaction.meetup_date}</dd></div>
              <div><dt>Time</dt><dd>{transaction.meetup_time}</dd></div>
              <div><dt>Location</dt><dd>{transaction.meetup_location}</dd></div>
            </dl>
            {!anyCompletionConfirmed && !hasUnlockRequest && (
              <>
                {!rescheduleOpen ? (
                  <Button
                    variant="secondary"
                    disabled={busy}
                    onClick={() => setRescheduleOpen(true)}
                  >
                    Change meetup details
                  </Button>
                ) : (
                  <>
                    <p>Proposing a change reopens meetup confirmation. The other participant must confirm the new details before the transaction returns to Meetup arranged.</p>
                    <MeetupForm
                      meetup={meetup}
                      setMeetup={setMeetup}
                      performAction={performAction}
                      busy={busy}
                      ownMeetupConfirmed={ownMeetupConfirmed}
                      rescheduling
                    />
                    <Button
                      type="button"
                      variant="secondary"
                      disabled={busy}
                      onClick={() => {
                        setRescheduleOpen(false)
                        setMeetup({
                          meetup_date: transaction.meetup_date || '',
                          meetup_time: transaction.meetup_time || '',
                          meetup_location: transaction.meetup_location || '',
                        })
                      }}
                    >
                      Keep current meetup
                    </Button>
                  </>
                )}
              </>
            )}
            {anyCompletionConfirmed && (
              <p className="transaction-handoff-note" role="status">
                A participant has already confirmed the handoff, so meetup details cannot be changed. Report a problem if the exchange did not happen as planned.
              </p>
            )}
            <div className="transaction-confirmations">
              <p>Buyer completion: {transaction.buyer_completion_confirmed_at ? 'Confirmed' : 'Waiting'}</p>
              <p>Seller completion: {transaction.seller_completion_confirmed_at ? 'Confirmed' : 'Waiting'}</p>
            </div>
            <p>
              {ownCompletionConfirmed
                ? `You confirmed the handoff. Waiting for ${otherParticipant.first_name} to confirm.`
                : 'After meeting and exchanging the item, confirm the handoff here. Both participants must confirm to complete the transaction.'}
            </p>
            {!meetupTimeArrived && !ownCompletionConfirmed && (
              <p className="transaction-handoff-note" role="status">
                Handoff confirmation becomes available at or after the scheduled meetup date and time.
              </p>
            )}
            <Button
              disabled={busy || ownCompletionConfirmed || hasUnlockRequest || !meetupTimeArrived}
              onClick={() => performAction('complete')}
            >
              {busy ? 'Saving…' : ownCompletionConfirmed ? 'Handoff confirmed' : meetupTimeArrived ? 'Confirm item handoff' : 'Available at meetup time'}
            </Button>

            <div className="transaction-unlock">
              <h3>Listing release request</h3>
              {hasUnlockRequest ? (
                transaction.current_user_role === 'buyer' ? (
                  <>
                    <p>The seller asked to release the listing. Respond by {formatDateTime(transaction.buyer_unlock_response_deadline)}. Accepting releases the listing; declining keeps the meetup arranged. No response by the deadline cancels the transaction.</p>
                    <div className="transaction-form-actions">
                      <Button disabled={busy} onClick={() => performAction('unlock-response', { decision: 'accept' })}>Accept release</Button>
                      <Button variant="secondary" disabled={busy} onClick={() => performAction('unlock-response', { decision: 'decline' })}>Decline and keep meetup</Button>
                    </div>
                  </>
                ) : (
                  <p>Your release request is awaiting the buyer until {formatDateTime(transaction.buyer_unlock_response_deadline)}. If the buyer does not respond by then, the listing will be released automatically.</p>
                )
              ) : isSeller ? (
                <>
                  <p>Requesting release gives the buyer the configured response window to decline and keep the meetup. Acceptance or no response cancels the transaction and releases the listing.</p>
                  <Button variant="secondary" disabled={busy} onClick={() => performAction('unlock-request')}>Request to release listing</Button>
                </>
              ) : (
                <p>The seller can request to release the listing after a meetup is arranged.</p>
              )}
            </div>

            <form
              className="transaction-form transaction-dispute-form"
              onSubmit={(event) => {
                event.preventDefault()
                performAction('dispute', dispute)
              }}
            >
              <h3>Report a problem</h3>
              <p>Reporting pauses this transaction until a Super Admin reviews it.</p>
              <FormField label="Reason">
                <TextInput
                  value={dispute.reason}
                  onChange={(event) => setDispute({ ...dispute, reason: event.target.value })}
                  minLength="3"
                  maxLength="150"
                  required
                />
              </FormField>
              <FormField label="What happened?">
                <TextArea
                  value={dispute.description}
                  onChange={(event) => setDispute({ ...dispute, description: event.target.value })}
                  minLength="10"
                  maxLength="5000"
                  rows="4"
                  required
                />
              </FormField>
              <Button type="submit" variant="danger" disabled={busy}>
                {busy ? 'Submitting…' : 'Submit dispute'}
              </Button>
            </form>
          </section>
        )}

        {transaction.transaction_status === 'disputed' && (
          <section className="transaction-step" role="status">
            <h2>Transaction under review</h2>
            <p>The listing remains reserved while a Super Admin reviews the report. Transaction actions are paused.</p>
            {transaction.dispute && (
              <div className="transaction-dispute-summary">
                <strong>{transaction.dispute.reason}</strong>
                <p>{transaction.dispute.description}</p>
                <p>Report status: {transaction.dispute.report_status.replaceAll('_', ' ')}</p>
              </div>
            )}
          </section>
        )}

        {transaction.dispute?.resolution_notes && transaction.transaction_status !== 'disputed' && (
          <p className="transaction-notice" role="status">
            Dispute review: {transaction.dispute.resolution_notes}
          </p>
        )}

        {transaction.transaction_status === 'completed' && (
          <>
            <p className="transaction-notice" role="status">
              Both participants confirmed the handoff. This listing is now marked as sold.
            </p>
            <section className="transaction-reviews" aria-labelledby="transaction-reviews-heading">
              <h2 id="transaction-reviews-heading">Transaction reviews</h2>
              {ownReview ? (
                <article className="transaction-review-card">
                  <strong>Your review: {renderStars(ownReview.rating)} ({ownReview.rating}/5)</strong>
                  {ownReview.comment && <p>{ownReview.comment}</p>}
                </article>
              ) : (
                <form
                  className="transaction-form transaction-review-form"
                  onSubmit={(event) => {
                    event.preventDefault()
                    performAction('reviews', {
                      rating: Number(reviewDraft.rating),
                      comment: reviewDraft.comment,
                    })
                  }}
                >
                  <h3>Review {reviewedParticipant.first_name}</h3>
                  <p>Your rating helps other students make informed decisions. You can submit one review for this completed transaction.</p>
                  <FormField label="Rating">
                    <select
                      className="ui-input ui-select"
                      value={reviewDraft.rating}
                      onChange={(event) => setReviewDraft({ ...reviewDraft, rating: event.target.value })}
                      required
                    >
                      <option value="5">5 - Excellent</option>
                      <option value="4">4 - Good</option>
                      <option value="3">3 - Okay</option>
                      <option value="2">2 - Poor</option>
                      <option value="1">1 - Very poor</option>
                    </select>
                  </FormField>
                  <FormField label="Comment (optional)">
                    <TextArea
                      value={reviewDraft.comment}
                      onChange={(event) => setReviewDraft({ ...reviewDraft, comment: event.target.value })}
                      maxLength="1000"
                      rows="3"
                    />
                  </FormField>
                  <Button type="submit" disabled={busy}>
                    {busy ? 'Submitting…' : 'Submit review'}
                  </Button>
                </form>
              )}
              {otherReview && (
                <article className="transaction-review-card">
                  <strong>
                    {otherReview.reviewer?.first_name || reviewedParticipant.first_name}’s review: {renderStars(otherReview.rating)} ({otherReview.rating}/5)
                  </strong>
                  {otherReview.comment && <p>{otherReview.comment}</p>}
                </article>
              )}
              {!otherReview && (
                <p className="transaction-review-note">The other participant has not submitted a review yet.</p>
              )}
            </section>
            <section className="transaction-cancel">
              <p>Remove this completed transaction from your history. It will remain in the other participant’s history.</p>
              <Button variant="secondary" disabled={busy} onClick={hideCompletedTransaction}>
                {busy ? 'Removing…' : 'Remove from my history'}
              </Button>
            </section>
          </>
        )}
        {transaction.transaction_status === 'cancelled' && (
          <p className="transaction-notice" role="status">
            This transaction was cancelled: {transaction.cancellation_reason}
          </p>
        )}

        {canCancel && (
          <section className="transaction-cancel">
            {!cancelOpen ? (
              <>
                <p>Cancellation needs approval from the other participant. The listing stays reserved until they respond.</p>
                <Button variant="secondary" onClick={() => setCancelOpen(true)}>
                  Request cancellation
                </Button>
              </>
              ) : (
              <form
                className="transaction-form"
                onSubmit={(event) => {
                  event.preventDefault()
                  performAction('cancel', { cancellation_reason: cancellationReason.trim() })
                }}
              >
                <FormField label="Reason for cancellation">
                  <TextArea
                    value={cancellationReason}
                    onChange={(event) => setCancellationReason(event.target.value)}
                    maxLength="1000"
                    rows="3"
                    required
                  />
                </FormField>
                <div className="transaction-form-actions">
                  <Button type="submit" variant="danger" disabled={busy || !cancellationReason.trim()}>
                    {busy ? 'Sending…' : 'Send cancellation request'}
                  </Button>
                  <Button type="button" variant="secondary" onClick={() => setCancelOpen(false)}>
                    Keep transaction
                  </Button>
                </div>
              </form>
            )}
          </section>
        )}

        <section className="transaction-history">
          <h2>Activity</h2>
          {transaction.events.length ? (
            <ol>
              {transaction.events.map((event) => (
                <li key={event.id}>
                  <strong>{event.actor ? `${event.actor.first_name} ${event.actor.last_name}` : 'RECREO'}</strong>
                  <p>{event.event_description}</p>
                  <time dateTime={event.created_at}>{formatDateTime(event.created_at)}</time>
                </li>
              ))}
            </ol>
          ) : <p>No activity recorded yet.</p>}
        </section>
      </section>
    </main>
  )
}

function renderStars(rating) {
  return `${'★'.repeat(rating)}${'☆'.repeat(5 - rating)}`
}
