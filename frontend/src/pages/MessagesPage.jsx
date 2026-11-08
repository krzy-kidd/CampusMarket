import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Button, FormField, TextArea } from '../components/ui.jsx'
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

function formatMessageDate(date) {
  return new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(date))
}

function formatPrice(price) {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
  }).format(Number(price))
}

function signInFor(returnTo, navigate) {
  navigate('/login', { replace: true, state: { returnTo } })
}

export function MessagesPage() {
  const navigate = useNavigate()
  const [conversations, setConversations] = useState([])
  const [nextCursor, setNextCursor] = useState(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [loadingOlder, setLoadingOlder] = useState(false)
  const [reloadKey, setReloadKey] = useState(0)

  useEffect(() => {
    let active = true
    api.get('/api/auth/conversations')
      .then(({ data }) => {
        if (active) {
          setConversations(data.conversations)
          setNextCursor(data.next_cursor)
        }
      })
      .catch((requestError) => {
        if (!active) return
        if (requestError.response?.status === 401) signInFor('/messages', navigate)
        else setError(getErrorMessage(requestError))
      })
      .finally(() => {
        if (active) setLoading(false)
      })
    return () => { active = false }
  }, [navigate, reloadKey])

  function retry() {
    setError('')
    setLoading(true)
    setReloadKey((key) => key + 1)
  }

  async function loadOlder() {
    if (!nextCursor || loadingOlder) return
    setLoadingOlder(true)
    setError('')
    try {
      const { data } = await api.get('/api/auth/conversations', {
        params: { cursor: nextCursor },
      })
      setConversations((current) => {
        const existingIds = new Set(current.map((conversation) => conversation.id))
        return [
          ...current,
          ...data.conversations.filter((conversation) => !existingIds.has(conversation.id)),
        ]
      })
      setNextCursor(data.next_cursor)
    } catch (requestError) {
      if (requestError.response?.status === 401) signInFor('/messages', navigate)
      else setError(getErrorMessage(requestError))
    } finally {
      setLoadingOlder(false)
    }
  }

  return (
    <main className="marketplace-shell messages-shell">
      <div className="messages-heading">
        <div>
          <p className="eyebrow">Private conversations</p>
          <h1>Messages</h1>
          <p>Talk directly with students about marketplace listings.</p>
        </div>
        <Link className="secondary-link" to="/marketplace">Browse marketplace</Link>
      </div>
      {loading ? (
        <p className="marketplace-loading" role="status">Loading your conversations…</p>
      ) : error ? (
        <section className="marketplace-empty marketplace-error-state">
          <h2>Messages unavailable</h2>
          <p role="alert">{error}</p>
          <Button variant="secondary" onClick={retry}>Try again</Button>
        </section>
      ) : conversations.length ? (
        <>
          <section className="conversation-list" aria-label="Your conversations">
            {conversations.map((conversation) => (
              <Link
                className="conversation-card"
                key={conversation.id}
                to={`/messages/${conversation.id}`}
              >
                <span className="conversation-avatar" aria-hidden="true">
                  {conversation.participant.first_name.slice(0, 1)}
                </span>
                <span className="conversation-copy">
                  <span className="conversation-card-heading">
                    <strong>{conversation.participant.first_name} {conversation.participant.last_name}</strong>
                    {conversation.unread_count > 0 && (
                      <span className="conversation-unread">
                        {conversation.unread_count} unread
                      </span>
                    )}
                  </span>
                  <span className="conversation-listing-title">
                    {conversation.listing.title}
                    {conversation.listing.listing_status !== 'available' && ' · No longer available'}
                  </span>
                  <span className="conversation-preview">
                    {conversation.last_message?.message_text || 'No messages yet'}
                  </span>
                </span>
                <span className="conversation-date">
                  {conversation.last_message && formatMessageDate(conversation.last_message.sent_at)}
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
              {loadingOlder ? 'Loading…' : 'Load older conversations'}
            </Button>
          )}
          {error && <p className="form-error" role="alert">{error}</p>}
        </>
      ) : (
        <section className="marketplace-empty">
          <span className="marketplace-empty-mark" aria-hidden="true">cT</span>
          <h2>No conversations yet</h2>
          <p>Open a listing and send the seller a message to start a private conversation.</p>
          <Link className="primary-link" to="/marketplace">Browse listings</Link>
        </section>
      )}
    </main>
  )
}

export function MessageThreadPage() {
  const { conversationId } = useParams()
  const navigate = useNavigate()
  const [conversation, setConversation] = useState(null)
  const [messages, setMessages] = useState([])
  const [nextCursor, setNextCursor] = useState(null)
  const [messageText, setMessageText] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [loadingOlder, setLoadingOlder] = useState(false)
  const [requestingPurchase, setRequestingPurchase] = useState(false)
  const [reloadKey, setReloadKey] = useState(0)
  const messagesEndRef = useRef(null)
  const shouldScrollToBottom = useRef(true)

  useEffect(() => {
    let active = true
    api.get(`/api/auth/conversations/${conversationId}`)
      .then(({ data }) => {
        if (!active) return
        shouldScrollToBottom.current = true
        setConversation(data.conversation)
        setMessages(data.messages)
        setNextCursor(data.next_cursor)
        setError('')
      })
      .catch((requestError) => {
        if (!active) return
        if (requestError.response?.status === 401) {
          signInFor(`/messages/${conversationId}`, navigate)
        } else {
          setError(getErrorMessage(requestError))
        }
      })
      .finally(() => {
        if (active) setLoading(false)
      })
    return () => { active = false }
  }, [conversationId, navigate, reloadKey])

  useEffect(() => {
    if (!shouldScrollToBottom.current) return
    messagesEndRef.current?.scrollIntoView({ block: 'end' })
    shouldScrollToBottom.current = false
  }, [messages])

  async function loadOlder() {
    if (!nextCursor || loadingOlder) return
    setLoadingOlder(true)
    setError('')
    try {
      const { data } = await api.get(
        `/api/auth/conversations/${conversationId}/messages`,
        { params: { cursor: nextCursor } },
      )
      setMessages((current) => [...data.messages, ...current])
      setNextCursor(data.next_cursor)
    } catch (requestError) {
      if (requestError.response?.status === 401) {
        signInFor(`/messages/${conversationId}`, navigate)
      } else {
        setError(getErrorMessage(requestError))
      }
    } finally {
      setLoadingOlder(false)
    }
  }

  async function sendMessage(event) {
    event.preventDefault()
    const cleanMessage = messageText.trim()
    if (!cleanMessage || busy) return

    setBusy(true)
    setError('')
    try {
      const { data } = await api.post(
        `/api/auth/conversations/${conversationId}/messages`,
        { message_text: cleanMessage },
      )
      shouldScrollToBottom.current = true
      setMessages((current) => [...current, data.message])
      setMessageText('')
    } catch (requestError) {
      if (requestError.response?.status === 401) {
        signInFor(`/messages/${conversationId}`, navigate)
      } else {
        setError(getErrorMessage(requestError))
      }
    } finally {
      setBusy(false)
    }
  }

  async function requestPurchase() {
    setRequestingPurchase(true)
    setError('')
    try {
      const { data } = await api.post(`/api/auth/conversations/${conversationId}/transactions`)
      navigate(`/transactions/${data.transaction.id}`)
    } catch (requestError) {
      if (requestError.response?.status === 401) {
        signInFor(`/messages/${conversationId}`, navigate)
      } else {
        setError(getErrorMessage(requestError))
      }
    } finally {
      setRequestingPurchase(false)
    }
  }

  if (loading) {
    return <main className="marketplace-shell"><p className="marketplace-loading" role="status">Loading conversation…</p></main>
  }
  if (error && !conversation) {
    return (
      <main className="marketplace-shell">
        <section className="marketplace-empty marketplace-error-state">
          <h1>Conversation unavailable</h1>
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
          <Link className="secondary-link" to="/messages">Back to messages</Link>
        </section>
      </main>
    )
  }
  if (!conversation) return null

  return (
    <main className="marketplace-shell messages-shell">
      <Link className="text-link" to="/messages">← All messages</Link>
      <section className="message-thread">
        <header className="message-thread-header">
          <div>
            <p className="eyebrow">Conversation about</p>
            <h1>{conversation.listing.title}</h1>
            <p>
              With {conversation.participant.first_name} {conversation.participant.last_name}
              {conversation.listing.listing_status !== 'available' && ' · Listing no longer available'}
            </p>
          </div>
          <Button
            variant="secondary"
            onClick={() => {
              setError('')
              setLoading(true)
              shouldScrollToBottom.current = true
              setReloadKey((key) => key + 1)
            }}
          >
            Refresh
          </Button>
        </header>
        {conversation.transaction ? (
          <div className="conversation-transaction-banner">
            <p>
              Purchase request · {formatPrice(conversation.transaction.agreed_price)} ·{' '}
              {STATUS_LABELS[conversation.transaction.transaction_status] || conversation.transaction.transaction_status}
            </p>
            <Button to={`/transactions/${conversation.transaction.id}`} variant="secondary">
              View transaction
            </Button>
          </div>
        ) : conversation.current_user_role === 'buyer' && conversation.listing.listing_status === 'available' ? (
          <div className="conversation-transaction-banner">
            <p>Request to purchase at the listed price of {formatPrice(conversation.listing.price)}.</p>
            <Button disabled={requestingPurchase} onClick={requestPurchase}>
              {requestingPurchase ? 'Creating request…' : 'Request purchase'}
            </Button>
          </div>
        ) : (
          <p className="conversation-purchase-note">
            {conversation.listing.listing_status === 'available'
              ? 'The buyer can start a purchase request for this listing.'
              : 'This listing is not available for a new purchase request.'}
          </p>
        )}
        <div className="message-thread-history" aria-label="Conversation messages" aria-live="polite">
          {nextCursor && (
            <Button
              variant="ghost"
              className="load-older-messages"
              disabled={loadingOlder}
              onClick={loadOlder}
            >
              {loadingOlder ? 'Loading…' : 'Load older messages'}
            </Button>
          )}
          {messages.map((message) => {
            const isOwnMessage = Number(message.sender_id) !== Number(conversation.participant.id)
            return (
              <article
                className={`message-bubble${isOwnMessage ? ' message-bubble-own' : ''}`}
                key={message.id}
              >
                <p>{message.message_text}</p>
                <time dateTime={message.sent_at}>{formatMessageDate(message.sent_at)}</time>
              </article>
            )
          })}
          <div ref={messagesEndRef} />
        </div>
        {error && <p className="form-error message-form-error" role="alert">{error}</p>}
        <form className="message-compose" onSubmit={sendMessage}>
          <FormField label="Your message">
            <TextArea
              name="message_text"
              value={messageText}
              onChange={(event) => setMessageText(event.target.value)}
              placeholder="Write a message about this listing…"
              maxLength="2000"
              rows="3"
              required
            />
          </FormField>
          <div className="message-compose-actions">
            <span>{messageText.length}/2000</span>
            <Button type="submit" disabled={busy || !messageText.trim()}>
              {busy ? 'Sending…' : 'Send message'}
            </Button>
          </div>
        </form>
      </section>
    </main>
  )
}
