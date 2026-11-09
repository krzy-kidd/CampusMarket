import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { Badge, Button, FormField, Modal, SelectInput, TextArea, TextInput } from '../components/ui.jsx'
import api from '../lib/api.js'
import { getCurrentUser } from '../lib/session.js'

const CONDITION_LABELS = {
  1: 'Very good',
  2: 'Good',
  3: 'Fair',
  4: 'Poor',
}

const MAX_LISTING_IMAGES = 12

function formatPrice(price) {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
  }).format(Number(price))
}

function getErrorMessage(error) {
  const validationErrors = Object.values(error.response?.data?.errors || {}).flat()
  return validationErrors[0] || error.response?.data?.message || error.message
}

function listingStatusLabel(status) {
  if (status === 'available') return 'Available'
  if (status === 'archived') return 'Archived'
  return status.replaceAll('_', ' ')
}

function RatingSummary({ seller }) {
  if (!seller.rating_count) return <span className="seller-rating-empty">No ratings yet</span>

  return (
    <span className="seller-rating" aria-label={`${seller.rating_average} out of 5 from ${seller.rating_count} reviews`}>
      ★ {Number(seller.rating_average).toFixed(1)} ({seller.rating_count})
    </span>
  )
}

function formatSoldTimeRemaining(soldUntil, currentTime) {
  const remainingSeconds = Math.max(0, Math.ceil((Date.parse(soldUntil) - currentTime) / 1000))
  const minutes = Math.floor(remainingSeconds / 60)
  const seconds = String(remainingSeconds % 60).padStart(2, '0')
  return `${minutes}:${seconds}`
}

async function csrfCookie() {
  await api.get('/sanctum/csrf-cookie')
}

function ListingCard({ listing, currentTime }) {
  return (
    <article className="listing-card">
      <Link className="listing-card-image" to={`/listings/${listing.id}`}>
        {listing.images[0] ? (
          <img src={listing.images[0].url} alt={listing.title} loading="lazy" decoding="async" />
        ) : (
          <span>No photo</span>
        )}
      </Link>
      <div className="listing-card-body">
        <p className="listing-category">
          {[listing.category.parent_name, listing.category.name].filter(Boolean).join(' / ')}
        </p>
        {listing.listing_status === 'sold' && (
          <Badge className="listing-sold-badge" variant="warning">
            Sold · disappears in {formatSoldTimeRemaining(listing.sold_until, currentTime)}
          </Badge>
        )}
        <h2><Link to={`/listings/${listing.id}`}>{listing.title}</Link></h2>
        <p className="listing-price">{formatPrice(listing.price)}</p>
        <p className="listing-meta">
          {CONDITION_LABELS[listing.condition_rating]} · {listing.seller.first_name} {listing.seller.last_name}
          {listing.seller.is_verified && <Badge variant="success">Verified student</Badge>}
          <RatingSummary seller={listing.seller} />
        </p>
      </div>
    </article>
  )
}

function getMarketplaceFilters(searchParams) {
  return {
    search: searchParams.get('search') || '',
    category_id: searchParams.get('category_id') || '',
    subcategory_id: searchParams.get('subcategory_id') || '',
    min_price: searchParams.get('min_price') || '',
    max_price: searchParams.get('max_price') || '',
    condition_rating: searchParams.get('condition_rating') || '',
    sort: searchParams.get('sort') || 'newest',
  }
}

function MarketplaceFilters({ categories, initialFilters, categoryError, onApply, onClear }) {
  const [filters, setFilters] = useState(initialFilters)
  const [priceError, setPriceError] = useState('')
  const parentCategories = categories.filter((category) => !category.parent_category_id)
  const subcategories = categories.filter(
    (category) => String(category.parent_category_id) === filters.category_id,
  )

  function update(event) {
    const { name, value } = event.target
    setPriceError('')
    setFilters((current) => ({
      ...current,
      [name]: value,
      ...(name === 'category_id' ? { subcategory_id: '' } : {}),
    }))
  }

  function submit(event) {
    event.preventDefault()
    if (
      filters.min_price !== ''
      && filters.max_price !== ''
      && Number(filters.max_price) < Number(filters.min_price)
    ) {
      setPriceError('Maximum price must be at least the minimum price.')
      return
    }
    onApply(filters)
  }

  function clear() {
    setFilters({
      search: '',
      category_id: '',
      subcategory_id: '',
      min_price: '',
      max_price: '',
      condition_rating: '',
      sort: 'newest',
    })
    setPriceError('')
    onClear()
  }

  return (
    <section className="marketplace-filters" aria-label="Search and filter listings">
      <form className="marketplace-filter-form" onSubmit={submit}>
        <FormField label="Search by keyword" className="marketplace-filter-search">
          <TextInput
            type="search"
            name="search"
            value={filters.search}
            onChange={update}
            placeholder="Try textbooks or calculators"
            maxLength="100"
          />
        </FormField>
        <div className="marketplace-filter-grid">
          <FormField label="Category">
            <SelectInput name="category_id" value={filters.category_id} onChange={update}>
              <option value="">All categories</option>
              {parentCategories.map((category) => (
                <option key={category.id} value={category.id}>{category.category_name}</option>
              ))}
            </SelectInput>
          </FormField>
          <FormField label="Subcategory">
            <SelectInput
              name="subcategory_id"
              value={filters.subcategory_id}
              onChange={update}
              disabled={!filters.category_id || subcategories.length === 0}
            >
              <option value="">All subcategories</option>
              {subcategories.map((category) => (
                <option key={category.id} value={category.id}>{category.category_name}</option>
              ))}
            </SelectInput>
          </FormField>
          <FormField label="Minimum price (PHP)">
            <TextInput name="min_price" type="number" min="0" max="99999999.99" step="0.01" value={filters.min_price} onChange={update} />
          </FormField>
          <FormField label="Maximum price (PHP)">
            <TextInput name="max_price" type="number" min="0" max="99999999.99" step="0.01" value={filters.max_price} onChange={update} />
          </FormField>
          <FormField label="Condition">
            <SelectInput name="condition_rating" value={filters.condition_rating} onChange={update}>
              <option value="">All conditions</option>
              {Object.entries(CONDITION_LABELS).map(([rating, label]) => (
                <option key={rating} value={rating}>{label}</option>
              ))}
            </SelectInput>
          </FormField>
          <FormField label="Sort by">
            <SelectInput name="sort" value={filters.sort} onChange={update}>
              <option value="newest">Newest listings</option>
              <option value="price_asc">Price: low to high</option>
              <option value="price_desc">Price: high to low</option>
            </SelectInput>
          </FormField>
        </div>
        {categoryError && <p className="form-error marketplace-filter-error" role="alert">{categoryError}</p>}
        {priceError && <p className="form-error marketplace-filter-error" role="alert">{priceError}</p>}
        <div className="marketplace-filter-actions">
          <Button type="submit">Apply filters</Button>
          <Button type="button" variant="secondary" onClick={clear}>Clear filters</Button>
        </div>
      </form>
    </section>
  )
}

export function MarketplacePage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const queryString = searchParams.toString()
  const [listings, setListings] = useState([])
  const [currentTime, setCurrentTime] = useState(() => Date.now())
  const [categories, setCategories] = useState([])
  const [categoryError, setCategoryError] = useState('')
  const [pagination, setPagination] = useState({
    current_page: 1,
    last_page: 1,
    total: 0,
    from: null,
    to: null,
  })
  const [error, setError] = useState('')
  const [filterError, setFilterError] = useState(false)
  const [loading, setLoading] = useState(true)
  const [reloadKey, setReloadKey] = useState(0)
  const expiredListingIds = useRef(new Set())

  useEffect(() => {
    let active = true
    api.get('/api/categories')
      .then(({ data }) => {
        if (active) setCategories(data.categories)
      })
      .catch((requestError) => {
        if (active) setCategoryError(getErrorMessage(requestError))
      })
    return () => { active = false }
  }, [])

  useEffect(() => {
    let active = true
    const params = Object.fromEntries(new URLSearchParams(queryString).entries())
    api.get('/api/listings', { params })
      .then(({ data }) => {
        if (!active) return
        setListings(data.listings)
        setPagination(data.pagination)
        setError('')
        setFilterError(false)
      })
      .catch((requestError) => {
        if (active) {
          setError(getErrorMessage(requestError))
          setFilterError(requestError.response?.status === 422)
        }
      })
      .finally(() => {
        if (active) setLoading(false)
      })
    return () => { active = false }
  }, [queryString, reloadKey])

  useEffect(() => {
    if (!listings.some((listing) => listing.listing_status === 'sold' && listing.sold_until)) return undefined

    const intervalId = window.setInterval(() => setCurrentTime(Date.now()), 1000)
    return () => window.clearInterval(intervalId)
  }, [listings])

  useEffect(() => {
    const hasNewlyExpiredListing = listings.some((listing) => {
      if (
        listing.listing_status !== 'sold'
        || !listing.sold_until
        || Date.parse(listing.sold_until) > currentTime
        || expiredListingIds.current.has(listing.id)
      ) {
        return false
      }

      expiredListingIds.current.add(listing.id)
      return true
    })

    if (hasNewlyExpiredListing) setReloadKey((key) => key + 1)
  }, [currentTime, listings])

  function retry() {
    setError('')
    setFilterError(false)
    setLoading(true)
    setReloadKey((key) => key + 1)
  }

  function applyFilters(filters) {
    const params = new URLSearchParams()
    Object.entries(filters).forEach(([key, value]) => {
      const cleanValue = String(value).trim()
      if (cleanValue && !(key === 'sort' && cleanValue === 'newest')) {
        params.set(key, cleanValue)
      }
    })
    setError('')
    setFilterError(false)
    setLoading(true)
    if (params.toString() === queryString) {
      setReloadKey((key) => key + 1)
    } else {
      setSearchParams(params)
    }
  }

  function clearFilters() {
    setError('')
    setFilterError(false)
    setLoading(true)
    if (queryString) setSearchParams({})
    else setReloadKey((key) => key + 1)
  }

  function goToPage(page) {
    const params = new URLSearchParams(searchParams)
    if (page === 1) params.delete('page')
    else params.set('page', String(page))
    setError('')
    setFilterError(false)
    setLoading(true)
    setSearchParams(params)
  }

  const filters = getMarketplaceFilters(searchParams)
  const visibleListings = listings.filter((listing) => (
    listing.listing_status !== 'sold'
    || !listing.sold_until
    || Date.parse(listing.sold_until) > currentTime
  ))
  const hasActiveFilters = ['search', 'category_id', 'subcategory_id', 'min_price', 'max_price', 'condition_rating']
    .some((key) => searchParams.has(key))

  return (
    <main className="marketplace-shell">
      <section className="marketplace-heading">
        <div>
          <p className="eyebrow">WMSU student marketplace</p>
          <h1>Find something useful.</h1>
          <p>Explore items shared by the WMSU community. Recently sold items remain visible for 10 minutes.</p>
        </div>
        <div className="marketplace-heading-actions">
          <Link className="secondary-link" to="/my-listings">My listings</Link>
          <Link className="primary-link" to="/listings/new">Sell an item</Link>
        </div>
      </section>
        <MarketplaceFilters
          key={queryString}
          categories={categories}
          initialFilters={filters}
          categoryError={categoryError}
          onApply={applyFilters}
          onClear={clearFilters}
        />
        {error ? (
          <section className="marketplace-empty marketplace-error-state">
            <h2>{filterError ? 'Check your filters' : 'Marketplace unavailable'}</h2>
            <p role="alert">{error}</p>
            {filterError
              ? <Button variant="secondary" onClick={clearFilters}>Clear filters</Button>
              : <Button variant="secondary" onClick={retry}>Try again</Button>}
        </section>
      ) : loading ? (
        <p className="marketplace-loading" role="status">Loading available listings…</p>
      ) : visibleListings.length ? (
        <>
          <p className="marketplace-result-count" role="status">
            Showing {pagination.from}–{pagination.to} of {pagination.total} {pagination.total === 1 ? 'listing' : 'listings'}
          </p>
          <section className="listing-grid" aria-label="Available products">
            {visibleListings.map((listing) => (
              <ListingCard key={listing.id} listing={listing} currentTime={currentTime} />
            ))}
          </section>
          {pagination.last_page > 1 && (
            <nav className="marketplace-pagination" aria-label="Marketplace pages">
              <Button
                variant="secondary"
                disabled={pagination.current_page <= 1 || loading}
                onClick={() => goToPage(pagination.current_page - 1)}
              >
                Previous
              </Button>
              <span aria-live="polite">Page {pagination.current_page} of {pagination.last_page}</span>
              <Button
                variant="secondary"
                disabled={pagination.current_page >= pagination.last_page || loading}
                onClick={() => goToPage(pagination.current_page + 1)}
              >
                Next
              </Button>
            </nav>
          )}
        </>
      ) : pagination.total > 0 ? (
        <section className="marketplace-empty">
          <span className="marketplace-empty-mark" aria-hidden="true">cT</span>
          <h2>This marketplace page is unavailable</h2>
          <p>There are listings on other pages. Return to the last available page to continue browsing.</p>
          <Button variant="secondary" onClick={() => goToPage(pagination.last_page)}>
            Go to page {pagination.last_page}
          </Button>
        </section>
      ) : (
        <section className="marketplace-empty">
          <span className="marketplace-empty-mark" aria-hidden="true">cT</span>
          <h2>{hasActiveFilters ? 'No listings match your filters' : 'No available listings yet'}</h2>
          <p>
            {hasActiveFilters
              ? 'Try a different keyword or broaden your filters.'
              : 'There are no items for sale right now. Check back later or share something with the campus community.'}
          </p>
          {hasActiveFilters
            ? <Button variant="secondary" onClick={clearFilters}>Clear filters</Button>
            : <Link className="primary-link" to="/listings/new">Create a listing</Link>}
        </section>
      )}
    </main>
  )
}

export function ListingDetailPage() {
  const { listingId } = useParams()
  const navigate = useNavigate()
  const isPageActive = useRef(false)
  const [listing, setListing] = useState(null)
  const [viewer, setViewer] = useState(null)
  const [viewerChecked, setViewerChecked] = useState(false)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [messageText, setMessageText] = useState('Hi, is this item still available?')
  const [messageError, setMessageError] = useState('')
  const [sendingMessage, setSendingMessage] = useState(false)
  const [purchaseOpen, setPurchaseOpen] = useState(false)
  const [buying, setBuying] = useState(false)
  const [reportOpen, setReportOpen] = useState(false)
  const [reportReason, setReportReason] = useState('')
  const [reportDescription, setReportDescription] = useState('')
  const [reportError, setReportError] = useState('')
  const [reportSubmitted, setReportSubmitted] = useState(false)
  const [sendingReport, setSendingReport] = useState(false)
  const [reloadKey, setReloadKey] = useState(0)

  useEffect(() => {
    let active = true
    isPageActive.current = true

    api.get(`/api/listings/${listingId}`)
      .then(({ data }) => {
        if (active) setListing(data.listing)
      })
      .catch((requestError) => {
        if (active) setError(getErrorMessage(requestError))
      })
      .finally(() => {
        if (active) setLoading(false)
      })
    return () => {
      active = false
      isPageActive.current = false
    }
  }, [listingId, reloadKey])

  useEffect(() => {
    let active = true
    getCurrentUser()
      .then(({ data }) => {
        if (active) {
          setViewer(data.user)
          setViewerChecked(true)
        }
      })
      .catch((requestError) => {
        if (active) {
          if (requestError.response?.status !== 401) {
            setMessageError(getErrorMessage(requestError))
          }
          setViewerChecked(true)
        }
      })
    return () => { active = false }
  }, [])

  async function startConversation(event) {
    event.preventDefault()
    setMessageError('')
    setSendingMessage(true)
    try {
      const { data } = await api.post('/api/auth/conversations', {
        listing_id: listing.id,
        message_text: messageText.trim(),
      })
      navigate(`/messages/${data.conversation_id}`)
    } catch (requestError) {
      if (requestError.response?.status === 401) {
        navigate('/login', {
          state: { returnTo: `/listings/${listingId}` },
        })
      } else {
        setMessageError(getErrorMessage(requestError))
      }
    } finally {
      setSendingMessage(false)
    }
  }

  async function startPurchase() {
    if (!viewer) {
      navigate('/login', { state: { returnTo: `/listings/${listingId}` } })
      return
    }

    setMessageError('')
    setBuying(true)
    try {
      await csrfCookie()
      const { data } = await api.post(`/api/auth/listings/${listing.id}/purchase`)
      navigate(`/transactions/${data.transaction.id}`)
    } catch (requestError) {
      if (requestError.response?.status === 401) {
        navigate('/login', { state: { returnTo: `/listings/${listingId}` } })
      } else {
        setMessageError(getErrorMessage(requestError))
      }
    } finally {
      setBuying(false)
    }
  }

  function openPurchaseDialog() {
    if (!viewer) {
      navigate('/login', { state: { returnTo: `/listings/${listingId}` } })
      return
    }

    setPurchaseOpen(true)
  }

  async function submitListingReport(event) {
    event.preventDefault()
    const submittedListingId = listing.id
    setReportError('')
    setSendingReport(true)
    try {
      await csrfCookie()
      await api.post('/api/auth/reports', {
        listing_id: submittedListingId,
        reason: reportReason,
        description: reportDescription.trim(),
      })
      if (isPageActive.current) setReportSubmitted(true)
    } catch (requestError) {
      if (requestError.response?.status === 401 && isPageActive.current) {
        navigate('/login', { state: { returnTo: `/listings/${listingId}` } })
      } else if (isPageActive.current) {
        setReportError(getErrorMessage(requestError))
      }
    } finally {
      if (isPageActive.current) setSendingReport(false)
    }
  }

  function closeReport() {
    if (sendingReport) return
    setReportOpen(false)
    setReportError('')
    setReportSubmitted(false)
    setReportReason('')
    setReportDescription('')
  }

  function retryListing() {
    setError('')
    setListing(null)
    setLoading(true)
    setReloadKey((key) => key + 1)
  }

  if (loading) {
    return <main className="marketplace-shell"><p role="status">Loading listing…</p></main>
  }
  if (error || !listing) {
    return (
      <main className="marketplace-shell">
        <section className="marketplace-empty marketplace-error-state">
          <h1>Listing unavailable</h1>
          <p role="alert">{error || 'This listing is not available.'}</p>
          <Button variant="secondary" onClick={retryListing}>Try again</Button>
          <Link className="secondary-link" to="/marketplace">Back to marketplace</Link>
        </section>
      </main>
    )
  }

  return (
    <main className="marketplace-shell">
      <p><Link className="text-link" to="/marketplace">← Marketplace</Link></p>
      <article className="listing-detail">
        <div className="listing-gallery">
          {listing.images.length ? listing.images.map((image) => (
            <img key={image.id} src={image.url} alt={listing.title} loading="lazy" decoding="async" />
          )) : <div className="listing-gallery-empty">No photos available</div>}
        </div>
        <div className="listing-detail-copy">
          <p className="listing-category">
            {[listing.category.parent_name, listing.category.name].filter(Boolean).join(' / ')}
          </p>
          <h1>{listing.title}</h1>
          {listing.listing_status === 'sold' && (
            <Badge className="listing-sold-badge" variant="warning">Sold · recently</Badge>
          )}
          <p className="listing-price">{formatPrice(listing.price)}</p>
          <p className="listing-meta">Condition: {CONDITION_LABELS[listing.condition_rating]}</p>
          <p className="listing-description">{listing.description}</p>
          <div className="seller-summary">
            <strong>{listing.seller.first_name} {listing.seller.last_name}</strong>
            {listing.seller.is_verified && <Badge variant="success">Verified WMSU student</Badge>}
            <RatingSummary seller={listing.seller} />
          </div>
          {messageError && !purchaseOpen && <p className="form-error" role="alert">{messageError}</p>}
          {viewerChecked && Number(listing.seller.id) !== Number(viewer?.id) && (
            listing.listing_status === 'available' ? (
              <>
                <section className="listing-purchase-action" aria-labelledby="listing-purchase-heading">
                  <h2 id="listing-purchase-heading">Ready to buy?</h2>
                  <p>Start a purchase request at the listed fixed price. The item is reserved after the seller responds.</p>
                  <Button onClick={openPurchaseDialog} disabled={buying}>
                    Buy now · {formatPrice(listing.price)}
                  </Button>
                </section>
                <form className="listing-message-form" onSubmit={startConversation}>
                  <p>Have a question first? Message the seller before starting a purchase.</p>
                  <FormField label="Your message">
                    <TextArea
                      name="message_text"
                      value={messageText}
                      onChange={(event) => setMessageText(event.target.value)}
                      placeholder="Hi, is this item still available?"
                      maxLength="2000"
                      rows="3"
                      required
                    />
                  </FormField>
                  <Button type="submit" disabled={sendingMessage || !messageText.trim()}>
                    {sendingMessage ? 'Opening conversation…' : 'Message seller'}
                  </Button>
                  <Button variant="ghost" onClick={() => setReportOpen(true)}>Report listing</Button>
                </form>
                <Modal
                  open={purchaseOpen}
                  onClose={() => {
                    if (!buying) setPurchaseOpen(false)
                  }}
                  title="Start purchase request?"
                  actions={(
                    <>
                      <Button variant="secondary" onClick={() => setPurchaseOpen(false)} disabled={buying}>Not yet</Button>
                      <Button onClick={startPurchase} disabled={buying}>
                        {buying ? 'Starting request…' : 'Confirm purchase request'}
                      </Button>
                    </>
                  )}
                >
                  <p>
                    This creates a purchase request for <strong>{formatPrice(listing.price)}</strong>. No payment is taken.
                    You and the seller will confirm the same meetup details before meeting.
                  </p>
                  {messageError && <p className="form-error" role="alert">{messageError}</p>}
                </Modal>
                <Modal
                  open={reportOpen}
                  onClose={() => {
                    if (!sendingReport) closeReport()
                  }}
                  title={reportSubmitted ? 'Report submitted' : 'Report this listing'}
                  actions={!reportSubmitted && (
                    <>
                      <Button variant="secondary" onClick={closeReport} disabled={sendingReport}>Cancel</Button>
                      <Button type="submit" form="listing-report-form" disabled={sendingReport || !reportReason || !reportDescription.trim()}>
                        {sendingReport ? 'Submitting…' : 'Submit report'}
                      </Button>
                    </>
                  )}
                >
                  {reportSubmitted ? (
                    <p role="status">Thank you. Your report has been sent to the admin team for review.</p>
                  ) : (
                    <form id="listing-report-form" onSubmit={submitListingReport}>
                      <p>Use this form to flag a listing that may violate campus marketplace rules. Admins will review your report.</p>
                      <FormField label="Reason">
                        <SelectInput value={reportReason} onChange={(event) => setReportReason(event.target.value)} required>
                          <option value="">Choose a reason</option>
                          <option value="Spam or misleading listing">Spam or misleading listing</option>
                          <option value="Prohibited item">Prohibited item</option>
                          <option value="Inappropriate content">Inappropriate content</option>
                          <option value="Safety concern">Safety concern</option>
                          <option value="Other">Other</option>
                        </SelectInput>
                      </FormField>
                      <FormField label="Details" hint="Do not include private contact or account information.">
                        <TextArea
                          value={reportDescription}
                          onChange={(event) => setReportDescription(event.target.value)}
                          maxLength="5000"
                          rows="5"
                          required
                        />
                      </FormField>
                      {reportError && <p className="form-error" role="alert">{reportError}</p>}
                    </form>
                  )}
                  {reportSubmitted && <Button onClick={closeReport}>Done</Button>}
                </Modal>
              </>
            ) : (
              <p className="status-detail" role="status">
                This item has been sold. Its marketplace listing will be removed after the 10-minute visibility period.
              </p>
            )
          )}
          {viewerChecked && Number(listing.seller.id) === Number(viewer?.id) && (
            <p className="status-detail">This is your listing.</p>
          )}
        </div>
      </article>
    </main>
  )
}

export function MyListingsPage() {
  const navigate = useNavigate()
  const [listings, setListings] = useState([])
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [loading, setLoading] = useState(true)
  const [busyId, setBusyId] = useState(null)
  const [archiveTarget, setArchiveTarget] = useState(null)
  const [reloadKey, setReloadKey] = useState(0)

  async function loadListings() {
    const { data } = await api.get('/api/auth/listings')
    setListings(data.listings)
  }

  useEffect(() => {
    let active = true
    api.get('/api/auth/listings')
      .then(({ data }) => {
        if (active) setListings(data.listings)
      })
      .catch((requestError) => {
        if (!active) return
        if (requestError.response?.status === 401) navigate('/login', { replace: true })
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

  async function changeStatus(listing, action) {
    setBusyId(listing.id)
    setError('')
    setMessage('')
    try {
      await csrfCookie()
      if (action === 'archive') {
        await api.delete(`/api/auth/listings/${listing.id}`)
      } else {
        await api.post(`/api/auth/listings/${listing.id}/relist`)
      }
      window.dispatchEvent(new CustomEvent('campustrade:listings-updated'))
      setMessage(action === 'archive' ? 'Listing archived.' : 'Listing relisted.')
    } catch (requestError) {
      setError(getErrorMessage(requestError))
      setBusyId(null)
      return false
    }
    try {
      await loadListings()
    } catch (requestError) {
      setError(`The listing was updated, but the list could not be refreshed: ${getErrorMessage(requestError)}`)
    } finally {
      setBusyId(null)
    }
    return true
  }

  async function confirmArchive() {
    if (!archiveTarget) return
    const archived = await changeStatus(archiveTarget, 'archive')
    if (archived) setArchiveTarget(null)
  }

  return (
    <main className="marketplace-shell">
      <section className="marketplace-heading">
        <div>
          <p className="eyebrow">Seller workspace</p>
          <h1>My listings</h1>
          <p>Update your items and manage their marketplace status.</p>
        </div>
        <Link className="primary-link" to="/listings/new">Create listing</Link>
      </section>
      {error && listings.length > 0 && <p className="form-error" role="alert">{error}</p>}
      {message && <p className="form-message" role="status">{message}</p>}
      {loading ? <p className="marketplace-loading" role="status">Loading your listings…</p> : listings.length ? (
        <section className="seller-listing-grid">
          {listings.map((listing) => (
            <article className="seller-listing" key={listing.id}>
              {listing.listing_status === 'available' ? (
                <Link className="seller-listing-image" to={`/listings/${listing.id}`}>
                  {listing.images[0] ? <img src={listing.images[0].url} alt={listing.title} loading="lazy" decoding="async" /> : <span>No photo</span>}
                </Link>
              ) : (
                <div className="seller-listing-image">
                  {listing.images[0] ? <img src={listing.images[0].url} alt={listing.title} loading="lazy" decoding="async" /> : <span>No photo</span>}
                </div>
              )}
              <div className="seller-listing-info">
                <Badge variant={
                  listing.listing_status === 'available'
                    ? 'success'
                    : listing.listing_status === 'archived' ? 'neutral' : 'warning'
                }>
                  {listingStatusLabel(listing.listing_status)}
                </Badge>
                <h2>{listing.title}</h2>
                <p className="listing-price">{formatPrice(listing.price)}</p>
                {listing.listing_status === 'available' && (
                  <div className="listing-actions">
                    <Link className="secondary-link" to={`/listings/${listing.id}/edit`}>Edit</Link>
                    <button className="text-button danger-text" type="button" disabled={busyId === listing.id} onClick={() => setArchiveTarget(listing)}>
                      {busyId === listing.id ? 'Please wait…' : 'Archive'}
                    </button>
                  </div>
                )}
                {listing.listing_status === 'archived' && (
                  <button className="secondary-link" type="button" disabled={busyId === listing.id} onClick={() => changeStatus(listing, 'relist')}>
                    {busyId === listing.id ? 'Please wait…' : 'Relist'}
                  </button>
                )}
              </div>
            </article>
          ))}
        </section>
      ) : error ? (
        <section className="marketplace-empty marketplace-error-state">
          <h2>Could not load your listings</h2>
          <p role="alert">{error}</p>
          <Button variant="secondary" onClick={retry}>Try again</Button>
        </section>
      ) : (
        <section className="marketplace-empty">
          <span className="marketplace-empty-mark" aria-hidden="true">cT</span>
          <h2>You have no listings</h2>
          <p>Create a listing to offer an item to WMSU students. You can manage or archive it here.</p>
          <Link className="primary-link" to="/listings/new">Create a listing</Link>
        </section>
      )}
      <Modal
        open={Boolean(archiveTarget)}
        onClose={() => setArchiveTarget(null)}
        title="Archive this listing?"
        className="archive-listing-dialog"
        actions={(
          <>
            <Button variant="secondary" onClick={() => setArchiveTarget(null)} disabled={busyId !== null}>Keep listing</Button>
            <Button variant="danger" onClick={confirmArchive} disabled={busyId !== null}>
              {busyId !== null ? 'Archiving…' : 'Archive listing'}
            </Button>
          </>
        )}
      >
        <p>
          <strong>{archiveTarget?.title}</strong> will no longer appear in the marketplace. The listing and its history will be kept, and you can relist it later.
        </p>
      </Modal>
    </main>
  )
}

export function ListingFormPage() {
  const { listingId } = useParams()
  const navigate = useNavigate()
  const isEditing = Boolean(listingId)
  const [form, setForm] = useState({
    category_id: '',
    title: '',
    description: '',
    price: '',
    condition_rating: '2',
  })
  const [categories, setCategories] = useState([])
  const [existingImages, setExistingImages] = useState([])
  const [removeImageIds, setRemoveImageIds] = useState([])
  const [isVerified, setIsVerified] = useState(false)
  const [error, setError] = useState('')
  const [loadError, setLoadError] = useState('')
  const [busy, setBusy] = useState(false)
  const [loading, setLoading] = useState(true)
  const [retryKey, setRetryKey] = useState(0)
  const [selectedPhotos, setSelectedPhotos] = useState([])
  const imageInputRef = useRef(null)
  const selectedPhotoUrlsRef = useRef(new Set())

  useEffect(() => {
    const selectedPhotoUrls = selectedPhotoUrlsRef.current
    return () => selectedPhotoUrls.forEach((url) => URL.revokeObjectURL(url))
  }, [])

  useEffect(() => {
    let active = true
    async function loadForm() {
      try {
        const [categoryResponse, accountResponse, verificationResponse] = await Promise.all([
          api.get('/api/categories'),
          getCurrentUser(),
          api.get('/api/auth/verification/status'),
        ])
        let currentListing = null
        if (isEditing) {
          const { data } = await api.get(`/api/auth/listings/${listingId}`)
          currentListing = data.listing
        }
        if (!active) return
        setCategories(categoryResponse.data.categories.filter((category) => category.children_count === 0))
        setIsVerified(
          accountResponse.data.user.account_status === 'active'
          && verificationResponse.data.is_verified,
        )
        if (currentListing) {
          setForm({
            category_id: String(currentListing.category.id),
            title: currentListing.title,
            description: currentListing.description,
            price: currentListing.price,
            condition_rating: String(currentListing.condition_rating),
          })
          setExistingImages(currentListing.images)
        }
      } catch (requestError) {
        if (!active) return
        if (requestError.response?.status === 401) navigate('/login', { replace: true })
        else setLoadError(getErrorMessage(requestError))
      } finally {
        if (active) setLoading(false)
      }
    }
    loadForm()
    return () => { active = false }
  }, [isEditing, listingId, navigate, retryKey])

  function update(event) {
    setForm({ ...form, [event.target.name]: event.target.value })
  }

  function toggleImageRemoval(imageId) {
    setRemoveImageIds((current) => current.includes(imageId)
      ? current.filter((id) => id !== imageId)
      : [...current, imageId])
  }

  function selectPhotos(files) {
    const photos = Array.from(files || [])
    if (photos.length === 0) return

    const allowedTypes = new Set(['image/jpeg', 'image/png', 'image/webp'])
    const remainingImageCount = existingImages.length - removeImageIds.length + selectedPhotos.length
    const validationMessage = photos.some((file) => !allowedTypes.has(file.type))
      ? 'Choose JPG, PNG, or WebP photos only.'
      : photos.some((file) => file.size > 5 * 1024 * 1024)
        ? 'Each photo must be 5 MB or smaller.'
        : remainingImageCount + photos.length > MAX_LISTING_IMAGES
          ? `A listing can have no more than ${MAX_LISTING_IMAGES} photos in total.`
          : ''

    if (validationMessage) {
      setError(validationMessage)
      return
    }

    const newPhotos = photos.map((file) => {
      const previewUrl = URL.createObjectURL(file)
      selectedPhotoUrlsRef.current.add(previewUrl)
      return { file, previewUrl }
    })
    setSelectedPhotos((current) => [...current, ...newPhotos])
    setError('')
  }

  async function submit(event) {
    event.preventDefault()
    const remainingImageCount = existingImages.length - removeImageIds.length
    const totalImageCount = remainingImageCount + selectedPhotos.length
    if (totalImageCount < 1 || totalImageCount > MAX_LISTING_IMAGES) {
      setError(`A listing must have between 1 and ${MAX_LISTING_IMAGES} photos. Add at least one photo, remove a current photo, or choose fewer new photos.`)
      return
    }
    if (selectedPhotos.some(({ file }) => file.size > 5 * 1024 * 1024)) {
      setError('Each photo must be 5 MB or smaller.')
      return
    }
    setBusy(true)
    setError('')
    try {
      await csrfCookie()
      const body = new FormData()
      Object.entries(form).forEach(([key, value]) => body.append(key, value))
      selectedPhotos.forEach(({ file }) => body.append('images[]', file))
      removeImageIds.forEach((id) => body.append('remove_image_ids[]', id))
      if (isEditing) {
        body.append('_method', 'PATCH')
        await api.post(`/api/auth/listings/${listingId}`, body)
      } else {
        await api.post('/api/auth/listings', body)
      }
      navigate('/my-listings', { replace: true })
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  if (loading) {
    return <main className="marketplace-shell"><p role="status">Loading listing form…</p></main>
  }

  if (loadError) {
    return (
      <main className="marketplace-shell">
        <section className="marketplace-empty marketplace-error-state">
          <h1>Listing form unavailable</h1>
          <p role="alert">{loadError}</p>
          <Button
            variant="secondary"
            onClick={() => {
              setLoadError('')
              setLoading(true)
              setRetryKey((key) => key + 1)
            }}
          >
            Try again
          </Button>
          <Link className="secondary-link" to={isEditing ? '/my-listings' : '/marketplace'}>
            Back to {isEditing ? 'my listings' : 'marketplace'}
          </Link>
        </section>
      </main>
    )
  }

  return (
    <main className="marketplace-shell listing-form-shell">
      <section className="listing-form-card">
        <p className="eyebrow">Fixed-price selling</p>
        <h1>{isEditing ? 'Edit listing' : 'Create a listing'}</h1>
        <p className="listing-form-intro">
          {isEditing
            ? 'Keep your item details and photos up to date.'
            : 'Share a useful item with WMSU students. Add clear photos and a fair fixed price.'}
        </p>
        {!isVerified && (
          <p className="account-notice">
            Your email and current academic-year COR must be approved before you can publish or relist an item. <Link className="text-link" to="/account">Check verification</Link>
          </p>
        )}
        {error && <p className="form-error" role="alert">{error}</p>}
        <form className="listing-form" onSubmit={submit}>
          <label>
            Product title
            <input name="title" value={form.title} onChange={update} required maxLength="120" />
          </label>
          <label>
            Category or subcategory
            <select name="category_id" value={form.category_id} onChange={update} required>
              <option value="">Choose a category</option>
              {categories.map((category) => (
                <option key={category.id} value={category.id}>
                  {[category.parent?.category_name, category.category_name].filter(Boolean).join(' / ')}
                </option>
              ))}
            </select>
          </label>
          <label>
            Description
            <textarea name="description" value={form.description} onChange={update} required maxLength="800" rows="6" />
          </label>
          <div className="listing-form-row">
            <label>
              Fixed price (PHP)
              <span className="listing-price-controls">
                <button
                  type="button"
                  aria-label="Decrease price by 1 peso"
                  onClick={() => setForm((current) => ({
                    ...current,
                    price: (Math.max(1, Math.round((Number(current.price) || 0) * 100) - 100) / 100).toFixed(2),
                  }))}
                >
                  −₱1
                </button>
                <input name="price" type="number" value={form.price} onChange={update} min="0.01" max="99999999.99" step="0.01" required />
                <button
                  type="button"
                  aria-label="Increase price by 10 pesos"
                  onClick={() => setForm((current) => ({
                    ...current,
                    price: ((Math.round((Number(current.price) || 0) * 100) + 1000) / 100).toFixed(2),
                  }))}
                >
                  +₱10
                </button>
              </span>
            </label>
            <label>
              Condition
              <select name="condition_rating" value={form.condition_rating} onChange={update} required>
                {Object.entries(CONDITION_LABELS).map(([rating, label]) => (
                  <option key={rating} value={rating}>{label}</option>
                ))}
              </select>
            </label>
          </div>
          {isEditing && existingImages.length > 0 && (
            <fieldset className="existing-images">
              <legend>Current photos</legend>
              <div className="existing-image-grid">
                {existingImages.map((image) => (
                  <label key={image.id} className={removeImageIds.includes(image.id) ? 'existing-image is-removed' : 'existing-image'}>
                    <img src={image.url} alt="" loading="lazy" decoding="async" />
                    <span><input type="checkbox" checked={removeImageIds.includes(image.id)} onChange={() => toggleImageRemoval(image.id)} /> Remove</span>
                  </label>
                ))}
              </div>
            </fieldset>
          )}
          <label>
            {isEditing ? 'Add photos' : `Product photos (1–${MAX_LISTING_IMAGES} required)`}
            <input
              ref={imageInputRef}
              name="images"
              type="file"
              accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"
              multiple
              aria-required={!isEditing}
              onChange={(event) => {
                selectPhotos(event.target.files)
                event.target.value = ''
              }}
            />
          </label>
          <p className="listing-form-hint">Select multiple photos at once, or add more later. At least 1 and no more than {MAX_LISTING_IMAGES} photos are required per listing. JPG, PNG, or WebP; maximum 5 MB per photo.</p>
          {selectedPhotos.length > 0 && (
            <section className="selected-image-preview" aria-label="Selected photo previews">
              <div className="selected-image-preview-heading">
                <h2>New photos ({selectedPhotos.length})</h2>
                <button
                  type="button"
                  className="text-button"
                  onClick={() => {
                    selectedPhotoUrlsRef.current.forEach((url) => URL.revokeObjectURL(url))
                    selectedPhotoUrlsRef.current.clear()
                    setSelectedPhotos([])
                    if (imageInputRef.current) imageInputRef.current.value = ''
                  }}
                >
                  Clear selection
                </button>
              </div>
              <div className="existing-image-grid">
                {selectedPhotos.map(({ file, previewUrl }, index) => (
                  <figure className="selected-image" key={`${file.name}-${file.lastModified}-${index}`}>
                    <img src={previewUrl} alt={`Preview ${index + 1}: ${file.name}`} />
                    <figcaption>{file.name}</figcaption>
                  </figure>
                ))}
              </div>
            </section>
          )}
          <button className="primary-link" type="submit" disabled={busy || (!isVerified && !isEditing)}>
            {busy ? 'Please wait…' : isEditing ? 'Save changes' : 'Publish listing'}
          </button>
        </form>
      </section>
    </main>
  )
}
