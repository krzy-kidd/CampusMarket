import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { Badge, Button, FormField, Modal, SelectInput, TextArea, TextInput } from '../components/ui.jsx'
import api from '../lib/api.js'

const CONDITION_LABELS = {
  1: 'Very good',
  2: 'Good',
  3: 'Fair',
  4: 'Poor',
}

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

async function csrfCookie() {
  await api.get('/sanctum/csrf-cookie')
}

function ListingCard({ listing }) {
  return (
    <article className="listing-card">
      <Link className="listing-card-image" to={`/listings/${listing.id}`}>
        {listing.images[0] ? (
          <img src={listing.images[0].url} alt={listing.title} />
        ) : (
          <span>No photo</span>
        )}
      </Link>
      <div className="listing-card-body">
        <p className="listing-category">
          {[listing.category.parent_name, listing.category.name].filter(Boolean).join(' / ')}
        </p>
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
  const hasActiveFilters = ['search', 'category_id', 'subcategory_id', 'min_price', 'max_price', 'condition_rating']
    .some((key) => searchParams.has(key))

  return (
    <main className="marketplace-shell">
      <section className="marketplace-heading">
        <div>
          <p className="eyebrow">WMSU student marketplace</p>
          <h1>Find something useful.</h1>
          <p>Explore available items shared by the WMSU community.</p>
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
      ) : listings.length ? (
        <>
          <p className="marketplace-result-count" role="status">
            Showing {pagination.from}–{pagination.to} of {pagination.total} {pagination.total === 1 ? 'listing' : 'listings'}
          </p>
          <section className="listing-grid" aria-label="Available products">
            {listings.map((listing) => <ListingCard key={listing.id} listing={listing} />)}
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
  const [listing, setListing] = useState(null)
  const [viewerId, setViewerId] = useState(null)
  const [viewerChecked, setViewerChecked] = useState(false)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [messageText, setMessageText] = useState('')
  const [messageError, setMessageError] = useState('')
  const [sendingMessage, setSendingMessage] = useState(false)

  useEffect(() => {
    let active = true
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
    return () => { active = false }
  }, [listingId])

  useEffect(() => {
    let active = true
    api.get('/api/auth/me')
      .then(({ data }) => {
        if (active) {
          setViewerId(data.user.id)
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

  if (loading) {
    return <main className="marketplace-shell"><p role="status">Loading listing…</p></main>
  }
  if (error || !listing) {
    return <main className="marketplace-shell"><p className="form-error" role="alert">{error || 'This listing is not available.'}</p><Link className="text-link" to="/marketplace">Back to marketplace</Link></main>
  }

  return (
    <main className="marketplace-shell">
      <p><Link className="text-link" to="/marketplace">← Marketplace</Link></p>
      <article className="listing-detail">
        <div className="listing-gallery">
          {listing.images.length ? listing.images.map((image) => (
            <img key={image.id} src={image.url} alt={listing.title} />
          )) : <div className="listing-gallery-empty">No photos available</div>}
        </div>
        <div className="listing-detail-copy">
          <p className="listing-category">
            {[listing.category.parent_name, listing.category.name].filter(Boolean).join(' / ')}
          </p>
          <h1>{listing.title}</h1>
          <p className="listing-price">{formatPrice(listing.price)}</p>
          <p className="listing-meta">Condition: {CONDITION_LABELS[listing.condition_rating]}</p>
          <p className="listing-description">{listing.description}</p>
          <div className="seller-summary">
            <strong>{listing.seller.first_name} {listing.seller.last_name}</strong>
            {listing.seller.is_verified && <Badge variant="success">Verified WMSU student</Badge>}
            <RatingSummary seller={listing.seller} />
          </div>
          {messageError && <p className="form-error" role="alert">{messageError}</p>}
          {viewerChecked && Number(listing.seller.id) !== Number(viewerId) && (
            <form className="listing-message-form" onSubmit={startConversation}>
              <p>Contact the seller privately to ask about this listing.</p>
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
              {messageError && <p className="form-error" role="alert">{messageError}</p>}
              <Button type="submit" disabled={sendingMessage || !messageText.trim()}>
                {sendingMessage ? 'Opening conversation…' : 'Message seller'}
              </Button>
            </form>
          )}
          {viewerChecked && Number(listing.seller.id) === Number(viewerId) && (
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
              <Link className="seller-listing-image" to={`/listings/${listing.id}`}>
                {listing.images[0] ? <img src={listing.images[0].url} alt={listing.title} /> : <span>No photo</span>}
              </Link>
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
  const [busy, setBusy] = useState(false)
  const [loading, setLoading] = useState(true)
  const [selectedPhotos, setSelectedPhotos] = useState([])
  const imageInputRef = useRef(null)

  useEffect(() => {
    return () => selectedPhotos.forEach((photo) => URL.revokeObjectURL(photo.previewUrl))
  }, [selectedPhotos])

  useEffect(() => {
    let active = true
    async function loadForm() {
      try {
        const [categoryResponse, accountResponse, verificationResponse] = await Promise.all([
          api.get('/api/categories'),
          api.get('/api/auth/me'),
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
        else setError(getErrorMessage(requestError))
      } finally {
        if (active) setLoading(false)
      }
    }
    loadForm()
    return () => { active = false }
  }, [isEditing, listingId, navigate])

  function update(event) {
    setForm({ ...form, [event.target.name]: event.target.value })
  }

  function toggleImageRemoval(imageId) {
    setRemoveImageIds((current) => current.includes(imageId)
      ? current.filter((id) => id !== imageId)
      : [...current, imageId])
  }

  async function submit(event) {
    event.preventDefault()
    const remainingImageCount = existingImages.length - removeImageIds.length
    const totalImageCount = remainingImageCount + selectedPhotos.length
    if (totalImageCount < 1 || totalImageCount > 5) {
      setError('A listing must have between 1 and 5 photos. Remove a current photo or choose fewer new photos.')
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

  return (
    <main className="marketplace-shell listing-form-shell">
      <p><Link className="text-link" to={isEditing ? '/my-listings' : '/marketplace'}>← {isEditing ? 'My listings' : 'Marketplace'}</Link></p>
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
            <input name="title" value={form.title} onChange={update} required maxLength="200" />
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
            <textarea name="description" value={form.description} onChange={update} required maxLength="10000" rows="6" />
          </label>
          <div className="listing-form-row">
            <label>
              Fixed price (PHP)
              <input name="price" type="number" value={form.price} onChange={update} min="0.01" max="99999999.99" step="0.01" required />
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
                    <img src={image.url} alt="" />
                    <span><input type="checkbox" checked={removeImageIds.includes(image.id)} onChange={() => toggleImageRemoval(image.id)} /> Remove</span>
                  </label>
                ))}
              </div>
            </fieldset>
          )}
          <label>
            {isEditing ? 'Add photos (optional)' : 'Product photos (1–5 required)'}
            <input
              ref={imageInputRef}
              name="images"
              type="file"
              accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"
              multiple
              required={!isEditing}
              onChange={(event) => {
                setSelectedPhotos(Array.from(event.target.files || []).map((file) => ({
                  file,
                  previewUrl: URL.createObjectURL(file),
                })))
                setError('')
              }}
            />
          </label>
          <p className="listing-form-hint">Upload clear photos of the actual item. JPG, PNG, or WebP; maximum 5 MB per photo and 5 photos total.</p>
          {selectedPhotos.length > 0 && (
            <section className="selected-image-preview" aria-label="Selected photo previews">
              <div className="selected-image-preview-heading">
                <h2>New photos ({selectedPhotos.length})</h2>
                <button
                  type="button"
                  className="text-button"
                  onClick={() => {
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
