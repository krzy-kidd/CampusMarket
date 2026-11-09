import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import api from '../lib/api.js'
import { getCurrentUser } from '../lib/session.js'
import CorFileViewer from '../components/CorFileViewer.jsx'

const sections = [
  ['overview', 'Overview'],
  ['verifications', 'COR reviews'],
  ['academic-years', 'Academic years'],
  ['categories', 'Categories'],
  ['students', 'Students'],
  ['admins', 'Administrators'],
  ['listings', 'Listings'],
  ['reports', 'Reports'],
  ['platform-settings', 'Platform settings'],
  ['audit-logs', 'Audit logs'],
  ['demo-emails', 'Demo email preview'],
]

function getError(error) {
  return Object.values(error.response?.data?.errors || {}).flat()[0]
    || error.response?.data?.message
    || error.message
}

async function fetchAdminData(section, search, page = 1) {
  const [{ data: me }, { data: summary }] = await Promise.all([
    getCurrentUser(),
    api.get('/api/auth/admin/dashboard'),
  ])
  const isSuperAdmin = me.user.admin_role === 'super_admin'
  const paths = {
    verifications: 'verifications',
    'academic-years': 'academic-years',
    categories: 'categories',
    students: 'students',
    admins: 'admins',
    listings: 'listings',
    reports: 'reports',
    'platform-settings': 'platform-settings',
    'audit-logs': 'audit-logs',
    'demo-emails': 'demo-emails',
  }
  const params = {
    ...(section === 'students' && search ? { search } : {}),
    ...(page > 1 ? { page } : {}),
  }
  const { data } = paths[section]
    ? await api.get(`/api/auth/admin/${paths[section]}`, { params })
    : { data: {} }
  let pendingVerifications = []
  let recentAuditLogs = []

  if (section === 'overview') {
    const requests = [api.get('/api/auth/admin/verifications')]
    if (isSuperAdmin) requests.push(api.get('/api/auth/admin/audit-logs'))
    const [verifications, auditLogs] = await Promise.all(requests)
    pendingVerifications = verifications.data.data || []
    recentAuditLogs = auditLogs?.data.data?.slice(0, 5) || []
  }

  return {
    user: me.user,
    dashboard: summary,
    records: data.platform_settings
      ? [data.platform_settings]
      : data.data || data.verifications || data.academic_years || data.categories || data.admins || data.listings || data.demo_emails || [],
    nextPage: Number.isInteger(data.current_page) && data.current_page < data.last_page
      ? data.current_page + 1
      : null,
    pendingVerifications,
    recentAuditLogs,
  }
}

function AdminPage() {
  const [user, setUser] = useState(null)
  const [dashboard, setDashboard] = useState(null)
  const [section, setSection] = useState('overview')
  const [records, setRecords] = useState([])
  const [pendingVerifications, setPendingVerifications] = useState([])
  const [recentAuditLogs, setRecentAuditLogs] = useState([])
  const [form, setForm] = useState({})
  const [search, setSearch] = useState('')
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [busy, setBusy] = useState(false)
  const [loading, setLoading] = useState(true)
  const [sectionLoading, setSectionLoading] = useState(true)
  const [nextPage, setNextPage] = useState(null)
  const [loadingMore, setLoadingMore] = useState(false)
  const sectionRequestId = useRef(0)
  const mutationRequestId = useRef(0)

  const isSuperAdmin = user?.admin_role === 'super_admin'
  const visibleSections = isSuperAdmin
    ? sections.filter(([key]) => key !== 'demo-emails' || dashboard?.demo_mailbox_enabled)
    : sections.slice(0, 2)

  async function load(
    targetSection = section,
    targetSearch = search,
    requestId = sectionRequestId.current,
  ) {
    if (requestId !== sectionRequestId.current) return
    setSectionLoading(true)
    setRecords([])
    setNextPage(null)
    try {
      const data = await fetchAdminData(targetSection, targetSearch)
      if (requestId !== sectionRequestId.current) return
      setUser(data.user)
      setDashboard(data.dashboard)
      setRecords(data.records)
      setNextPage(data.nextPage)
      setPendingVerifications(data.pendingVerifications)
      setRecentAuditLogs(data.recentAuditLogs)
    } finally {
      if (requestId === sectionRequestId.current) setSectionLoading(false)
    }
  }

  function navigateSection(nextSection) {
    sectionRequestId.current += 1
    setRecords([])
    setNextPage(null)
    setSectionLoading(true)
    setLoadingMore(false)
    setBusy(false)
    setSection(nextSection)
    setMessage('')
    setError('')
  }

  useEffect(() => {
    let active = true
    const requestId = ++sectionRequestId.current
    fetchAdminData(section, search)
      .then((data) => {
        if (!active || requestId !== sectionRequestId.current) return
        setUser(data.user)
        setDashboard(data.dashboard)
        setRecords(data.records)
        setNextPage(data.nextPage)
        setPendingVerifications(data.pendingVerifications)
        setRecentAuditLogs(data.recentAuditLogs)
        setLoading(false)
        setSectionLoading(false)
        setError('')
      })
      .catch((requestError) => {
        if (!active || requestId !== sectionRequestId.current) return
        setRecords([])
        setNextPage(null)
        setSectionLoading(false)
        setError(requestError.response?.status === 401
          ? 'Sign in with an administrator account to continue.'
          : getError(requestError))
        setLoading(false)
      })
    return () => { active = false }
  }, [section, search])

  async function loadMore() {
    if (!nextPage || loadingMore || busy) return
    const requestedSection = section
    const requestedSearch = search
    const requestId = sectionRequestId.current
    const requestedPage = nextPage
    setLoadingMore(true)
    setError('')
    try {
      const data = await fetchAdminData(requestedSection, requestedSearch, requestedPage)
      if (requestId !== sectionRequestId.current) return
      setRecords((current) => [...current, ...data.records])
      setNextPage(data.nextPage)
    } catch (requestError) {
      if (requestId === sectionRequestId.current) setError(getError(requestError))
    } finally {
      if (requestId === sectionRequestId.current) setLoadingMore(false)
    }
  }

  async function submitForm(event, endpoint, method = 'post', payload = form) {
    event.preventDefault()
    const requestId = sectionRequestId.current
    const mutationId = ++mutationRequestId.current
    setBusy(true)
    setError('')
    setMessage('')
    try {
      await api[method](`/api/auth/admin/${endpoint}`, payload)
      if (requestId !== sectionRequestId.current) return
      setForm({})
      setMessage('Changes saved.')
      await load(section, search, requestId)
    } catch (requestError) {
      if (requestId === sectionRequestId.current) setError(getError(requestError))
    } finally {
      if (mutationId === mutationRequestId.current) setBusy(false)
    }
  }

  async function update(endpoint, payload, verb = 'patch') {
    const requestId = sectionRequestId.current
    const mutationId = ++mutationRequestId.current
    setBusy(true)
    setError('')
    setMessage('')
    try {
      await api[verb](`/api/auth/admin/${endpoint}`, payload)
      const { data: account } = await getCurrentUser({ refresh: true })
      const lostSuperAdminAccess = isSuperAdmin && account.user.admin_role !== 'super_admin'
      setUser(account.user)

      if (lostSuperAdminAccess) {
        sectionRequestId.current += 1
        setDashboard(null)
        setRecords([])
        setNextPage(null)
        setPendingVerifications([])
        setRecentAuditLogs([])
        setLoading(true)
        setSectionLoading(true)
        setSection('overview')
        return
      }

      if (requestId !== sectionRequestId.current) return
      setMessage('Changes saved.')
      await load(section, search, requestId)
    } catch (requestError) {
      if (requestId === sectionRequestId.current) setError(getError(requestError))
    } finally {
      if (mutationId === mutationRequestId.current) setBusy(false)
    }
  }

  async function remove(endpoint, confirmationMessage = 'Delete this record?') {
    if (!window.confirm(confirmationMessage)) return
    const requestId = sectionRequestId.current
    const mutationId = ++mutationRequestId.current
    setBusy(true)
    setError('')
    setMessage('')
    try {
      const { data } = await api.delete(`/api/auth/admin/${endpoint}`)
      if (requestId !== sectionRequestId.current) return
      setMessage(data.warning ? `${data.message} ${data.warning}` : data.message || 'Record deleted.')
      await load(section, search, requestId)
    } catch (requestError) {
      if (requestId === sectionRequestId.current) setError(getError(requestError))
    } finally {
      if (mutationId === mutationRequestId.current) setBusy(false)
    }
  }

  async function review(item, decision) {
    const notes = decision === 'rejected'
      ? window.prompt('Enter the reason for rejection:')
      : ''
    if (decision === 'rejected' && !notes?.trim()) return

    await update(
      `verifications/${item.id}/attempts/${item.current_attempt_id}/review`,
      { decision, admin_notes: notes },
      'post',
    )
  }

  if (loading) {
    return <main className="page-shell"><section className="status-card"><p role="status">Loading admin dashboard…</p></section></main>
  }

  if (!dashboard) {
    return (
      <main className="page-shell">
        <section className="status-card">
          <h1>Admin dashboard unavailable</h1>
          <p className="form-error" role="alert">{error}</p>
          <Link className="text-link" to="/account">Back to account</Link>
        </section>
      </main>
    )
  }

  return (
    <main className="admin-shell">
      <div className="admin-layout">
        <aside className="admin-sidebar">
          <div className="admin-brand">
            <span className="admin-brand-mark" aria-hidden="true">C</span>
            <span><strong>RECREO</strong><small>ADMIN WORKSPACE</small></span>
          </div>
          <p className="admin-nav-label">WORKSPACE</p>
          <nav className="admin-nav" aria-label="Admin sections">
            {visibleSections.map(([key, title]) => (
              <button key={key} type="button" className={section === key ? 'is-selected' : ''} aria-current={section === key ? 'page' : undefined} onClick={() => navigateSection(key)}>
                <span className="admin-nav-indicator" aria-hidden="true" />
                <span>{title}</span>
                {key === 'verifications' && dashboard.pending_verifications > 0 && <span className="admin-nav-count">{dashboard.pending_verifications}</span>}
                {key === 'reports' && dashboard.pending_reports > 0 && <span className="admin-nav-count">{dashboard.pending_reports}</span>}
              </button>
            ))}
          </nav>
          <div className="admin-sidebar-footer">
            <div className="admin-profile">
              <span className="admin-avatar" aria-hidden="true">{user.first_name?.charAt(0) || 'A'}</span>
              <span><strong>{user.first_name} {user.last_name}</strong><small>{isSuperAdmin ? 'Super Admin' : 'Verification Admin'}</small></span>
            </div>
            <Link to="/account">Account</Link>
          </div>
        </aside>
        <div className="admin-main">
          <section className="admin-heading">
            <div>
              <p className="eyebrow">{isSuperAdmin ? 'SUPER ADMIN' : 'VERIFICATION ADMIN'}</p>
              <h1>{section === 'overview' ? 'Admin dashboard' : sections.find(([key]) => key === section)?.[1]}</h1>
              <p>Manage RECREO’s student marketplace.</p>
            </div>
            <span className="admin-account-email">{user.email}</span>
          </section>
          {error && <p className="form-error admin-notice" role="alert">{error}</p>}
          {message && <p className="form-message admin-notice" role="status">{message}</p>}
          <section className="admin-content" aria-live="polite">
            {sectionLoading ? (
              <p role="status">Loading {sections.find(([key]) => key === section)?.[1].toLowerCase()}…</p>
            ) : (
              <>
                {section === 'overview' && (
                  <Overview
                    dashboard={dashboard}
                    pendingVerifications={pendingVerifications}
                    recentAuditLogs={recentAuditLogs}
                    isSuperAdmin={isSuperAdmin}
                    busy={busy}
                    onReview={review}
                    onNavigate={navigateSection}
                  />
                )}
                {section === 'verifications' && (
                  <VerificationList records={records} busy={busy} onReview={review} />
                )}
                {isSuperAdmin && section === 'academic-years' && (
                  <AcademicYears records={records} form={form} setForm={setForm} busy={busy} onSubmit={(event) => submitForm(event, 'academic-years')} onUpdate={update} onDelete={remove} />
                )}
                {isSuperAdmin && section === 'categories' && (
                  <Categories records={records} form={form} setForm={setForm} busy={busy} onSubmit={(event) => submitForm(event, 'categories')} onUpdate={update} onDelete={remove} />
                )}
                {isSuperAdmin && section === 'students' && (
                  <Students
                    records={records}
                    search={search}
                    setSearch={(value) => {
                      sectionRequestId.current += 1
                      setRecords([])
                      setNextPage(null)
                      setSectionLoading(true)
                      setLoadingMore(false)
                      setBusy(false)
                      setSearch(value)
                    }}
                    busy={busy}
                    onUpdate={update}
                    onDelete={remove}
                  />
                )}
                {isSuperAdmin && section === 'admins' && (
                  <Admins records={records} currentUserId={user.id} form={form} setForm={setForm} busy={busy} onSubmit={(event) => submitForm(event, 'admins')} onUpdate={update} />
                )}
                {isSuperAdmin && section === 'listings' && (
                  <AdminListings records={records} busy={busy} onArchive={(listing) => update(`listings/${listing.id}/archive`, {})} />
                )}
                {isSuperAdmin && section === 'reports' && (
                  <Reports records={records} busy={busy} onUpdate={update} />
                )}
                {isSuperAdmin && section === 'platform-settings' && (
                  <PlatformSettings
                    key={records[0]?.seller_unlock_response_hours}
                    records={records}
                    busy={busy}
                    onUpdate={update}
                  />
                )}
                {isSuperAdmin && section === 'audit-logs' && <AuditLogs records={records} />}
                {isSuperAdmin && section === 'demo-emails' && dashboard.demo_mailbox_enabled && (
                  <DemoEmails records={records} busy={busy} onClear={() => remove('demo-emails', 'Clear all local demo email links?')} />
                )}
                {nextPage && (
                  <div className="admin-pagination">
                    <span>{records.length} records loaded</span>
                    <button type="button" disabled={loadingMore || busy} onClick={loadMore}>
                      {loadingMore ? 'Loading…' : 'Load more'}
                    </button>
                  </div>
                )}
              </>
            )}
          </section>
        </div>
      </div>
    </main>
  )
}

function AdminListings({ records, busy, onArchive }) {
  return (
    <>
      <h2>Marketplace listings</h2>
      {!records.length && <p className="status-detail">There are no listings to review.</p>}
      <div className="admin-record-list">
        {records.map((listing) => (
          <article className="admin-record admin-record-row" key={listing.id}>
            <div>
              <h3>{listing.title} <span className="status-pill">{listing.listing_status.replaceAll('_', ' ')}</span></h3>
              <p>{listing.category?.category_name} · PHP {listing.price} · {listing.seller?.first_name} {listing.seller?.last_name} ({listing.seller?.email})</p>
            </div>
            {listing.listing_status !== 'archived' && listing.listing_status !== 'sold' && (
              <div className="admin-actions">
                <button
                  type="button"
                  className="danger-button"
                  disabled={busy}
                  onClick={() => {
                    if (window.confirm(`Archive "${listing.title}"? It will leave the marketplace, but the seller can relist it if eligible.`)) {
                      onArchive(listing)
                    }
                  }}
                >
                  Archive listing
                </button>
              </div>
            )}
          </article>
        ))}
      </div>
    </>
  )
}

function Overview({ dashboard, pendingVerifications, recentAuditLogs, isSuperAdmin, busy, onReview, onNavigate }) {
  const metrics = [
    { label: 'Pending COR reviews', value: dashboard.pending_verifications, section: 'verifications', tone: 'green' },
    ...(isSuperAdmin ? [
      { label: 'Active students', value: dashboard.active_students, section: 'students', tone: 'blue' },
      { label: 'Open reports', value: dashboard.pending_reports, section: 'reports', tone: 'amber' },
      { label: 'Marketplace categories', value: dashboard.categories, section: 'categories', tone: 'violet' },
    ] : [
      { label: 'COR decisions by you', value: dashboard.reviews_by_admin, tone: 'blue' },
      { label: 'Approved by you', value: dashboard.approvals_by_admin, tone: 'green' },
      { label: 'Rejected by you', value: dashboard.rejections_by_admin, tone: 'amber' },
    ]),
  ]

  return (
    <>
      <div className="admin-overview-heading">
        <div>
          <h2>Welcome to your workspace</h2>
          <p>Here’s what needs your attention today.</p>
        </div>
        <span className="admin-year-badge">{dashboard.current_academic_year || 'No current academic year'}</span>
      </div>
      <div className="admin-metrics">
        {metrics.map(({ label, value, section, tone }) => {
          const content = (
            <>
              <span>{label}</span>
              <strong>{value ?? 0}</strong>
              <small>{section ? <>View details <span aria-hidden="true">→</span></> : 'All time'}</small>
            </>
          )

          return section ? (
            <button type="button" className={`admin-metric admin-metric-${tone}`} key={label} onClick={() => onNavigate(section)}>
              {content}
            </button>
          ) : (
            <article className={`admin-metric admin-metric-${tone} admin-metric-static`} key={label}>
              {content}
            </article>
          )
        })}
      </div>
      {isSuperAdmin && <MonthlyAnalytics data={dashboard.monthly_analytics || []} />}
      <div className="admin-overview-grid">
        <section className="admin-panel">
          <div className="admin-panel-heading">
            <div><h3>COR priority queue</h3><p>Review the latest student submissions.</p></div>
            <button type="button" className="admin-text-button" onClick={() => onNavigate('verifications')}>View all</button>
          </div>
          {pendingVerifications.length ? (
            <div className="admin-queue">
              {pendingVerifications.slice(0, 4).map((item) => (
                <article className="admin-queue-item" key={item.id}>
                  <div className="admin-queue-student">
                    <span className="admin-avatar" aria-hidden="true">{item.user?.first_name?.charAt(0) || 'S'}</span>
                    <div>
                      <strong>{item.user?.first_name} {item.user?.last_name}</strong>
                      <span>{item.user?.student_id_number} · {item.academic_year?.academic_year}</span>
                      {item.current_attempt_id && (
                        <CorFileViewer attemptId={item.current_attempt_id} label="View private COR" />
                      )}
                    </div>
                  </div>
                  <div className="admin-queue-actions">
                    <button type="button" disabled={busy} onClick={() => onReview(item, 'approved')}>Approve</button>
                    <button type="button" className="danger-button" disabled={busy} onClick={() => onReview(item, 'rejected')}>Reject</button>
                  </div>
                </article>
              ))}
            </div>
          ) : (
            <p className="admin-empty">You’re all caught up. There are no pending COR submissions.</p>
          )}
        </section>
        <section className="admin-panel admin-activity-panel">
          <div className="admin-panel-heading">
            <div><h3>{isSuperAdmin ? 'Recent admin activity' : 'Your admin workspace'}</h3><p>{isSuperAdmin ? 'Latest recorded actions.' : 'Tools available to your role.'}</p></div>
            {isSuperAdmin && <button type="button" className="admin-text-button" onClick={() => onNavigate('audit-logs')}>View all</button>}
          </div>
          {isSuperAdmin ? (
            recentAuditLogs.length ? (
              <div className="admin-activity-list">
                {recentAuditLogs.map((log) => (
                  <article className="admin-activity-item" key={log.id}>
                    <span className="admin-activity-dot" aria-hidden="true" />
                    <div><strong>{log.action.replaceAll('_', ' ')}</strong><span>{log.admin?.user?.first_name} {log.admin?.user?.last_name} · {log.target_type} #{log.target_id ?? '—'}</span><time>{formatDateTime(log.created_at)}</time></div>
                  </article>
                ))}
              </div>
            ) : <p className="admin-empty">No admin activity has been recorded yet.</p>
          ) : (
            <div className="admin-role-summary">
              <span className="admin-role-icon" aria-hidden="true">✓</span>
              <strong>Verification Admin</strong>
              <p>Your role is limited to reviewing COR submissions. Super Admins manage administrator accounts, system settings, and platform controls.</p>
              <button type="button" className="admin-text-button" onClick={() => onNavigate('verifications')}>Open COR reviews →</button>
            </div>
          )}
        </section>
      </div>
    </>
  )
}

function MonthlyAnalytics({ data }) {
  const width = 720
  const height = 260
  const padding = { top: 16, right: 20, bottom: 42, left: 42 }
  const plotWidth = width - padding.left - padding.right
  const plotHeight = height - padding.top - padding.bottom
  const maximum = Math.max(1, ...data.flatMap((month) => [month.students, month.listings]))
  const x = (index) => padding.left + (data.length > 1 ? (index * plotWidth) / (data.length - 1) : plotWidth / 2)
  const y = (value) => padding.top + plotHeight - (value / maximum) * plotHeight
  const series = [
    { key: 'students', label: 'Student registrations', color: '#7a1c2e' },
    { key: 'listings', label: 'New listings', color: '#087a50' },
  ]

  return (
    <section className="admin-panel admin-analytics" aria-labelledby="admin-analytics-title">
      <div className="admin-panel-heading">
        <div><h3 id="admin-analytics-title">Marketplace growth</h3><p>Student registrations and new listings by month.</p></div>
        <span className="admin-analytics-range">Last 6 months</span>
      </div>
      <div className="admin-chart-legend" aria-hidden="true">
        {series.map((item) => <span key={item.key}><i style={{ backgroundColor: item.color }} />{item.label}</span>)}
      </div>
      <svg className="admin-chart" viewBox={`0 0 ${width} ${height}`} role="img" aria-labelledby="admin-chart-title admin-chart-description">
        <title id="admin-chart-title">Monthly student registrations and new listings</title>
        <desc id="admin-chart-description">A line chart showing the number of student registrations and new listings for each of the last six months.</desc>
        {[0, 0.5, 1].map((fraction) => {
          const value = Math.round(maximum * fraction)
          const gridY = y(value)
          return (
            <g key={fraction}>
              <line x1={padding.left} x2={width - padding.right} y1={gridY} y2={gridY} className="admin-chart-gridline" />
              <text x={padding.left - 10} y={gridY + 4} textAnchor="end" className="admin-chart-label">{value}</text>
            </g>
          )
        })}
        {data.map((month, index) => (
          <text key={month.month} x={x(index)} y={height - 12} textAnchor="middle" className="admin-chart-label">{month.month}</text>
        ))}
        {series.map((item) => (
          <g key={item.key}>
            {data.length > 1 && <polyline
              points={data.map((month, index) => `${x(index)},${y(month[item.key])}`).join(' ')}
              fill="none"
              stroke={item.color}
              strokeWidth="3"
              strokeLinejoin="round"
              strokeLinecap="round"
            />}
            {data.map((month, index) => (
              <circle key={month.month} cx={x(index)} cy={y(month[item.key])} r="4.5" fill={item.color}>
                <title>{`${month.month}: ${month[item.key]} ${item.label.toLowerCase()}`}</title>
              </circle>
            ))}
          </g>
        ))}
      </svg>
      <table className="admin-chart-data">
        <caption>Monthly analytics data</caption>
        <thead><tr><th scope="col">Month</th>{series.map((item) => <th scope="col" key={item.key}>{item.label}</th>)}</tr></thead>
        <tbody>{data.map((month) => <tr key={month.month}><th scope="row">{month.month}</th>{series.map((item) => <td key={item.key}>{month[item.key]}</td>)}</tr>)}</tbody>
      </table>
    </section>
  )
}

function formatDateTime(value) {
  if (!value) return 'Date unavailable'
  const date = new Date(value)
  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' }).format(date)
}

function formatDate(value) {
  if (!value) return 'Date unavailable'
  const date = new Date(value)
  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeZone: 'UTC' }).format(date)
}

function VerificationList({ records, busy, onReview }) {
  return (
    <>
      <h2>Pending COR reviews</h2>
      {!records.length && <p className="status-detail">There are no pending submissions.</p>}
      <div className="admin-record-list">
        {records.map((item) => (
          <article className="admin-record" key={item.id}>
            <h3>{item.user?.first_name} {item.user?.last_name}</h3>
            <p>{item.user?.student_id_number} · {item.user?.email}</p>
            <p>{item.academic_year?.academic_year} · Submitted {formatDateTime(item.current_attempt?.created_at)}</p>
            {item.current_attempt_id && (
              <CorFileViewer attemptId={item.current_attempt_id} label="View private COR" />
            )}
            <div className="admin-actions">
              <button type="button" disabled={busy} onClick={() => onReview(item, 'approved')}>Approve</button>
              <button type="button" className="danger-button" disabled={busy} onClick={() => onReview(item, 'rejected')}>Reject</button>
            </div>
          </article>
        ))}
      </div>
    </>
  )
}

function AcademicYears({ records, form, setForm, busy, onSubmit, onUpdate, onDelete }) {
  return (
    <>
      <h2>Academic years</h2>
      <form className="admin-form" onSubmit={onSubmit}>
        <label>Academic year<input value={form.academic_year || ''} onChange={(event) => setForm({ ...form, academic_year: event.target.value })} placeholder="2026-2027" required /></label>
        <label>Start date<input type="date" value={form.start_date || ''} onChange={(event) => setForm({ ...form, start_date: event.target.value })} required /></label>
        <label>End date<input type="date" value={form.end_date || ''} onChange={(event) => setForm({ ...form, end_date: event.target.value })} required /></label>
        <label className="admin-checkbox"><input type="checkbox" checked={Boolean(form.is_current)} onChange={(event) => setForm({ ...form, is_current: event.target.checked })} /> Set as current</label>
        <button className="primary-link" disabled={busy}>Add academic year</button>
      </form>
      <div className="admin-record-list">
        {records.map((year) => (
          <article className="admin-record admin-record-row" key={year.id}>
            <div><h3>{year.academic_year} {year.is_current && <span className="status-pill">Current</span>}</h3><p>{formatDate(year.start_date)} to {formatDate(year.end_date)}</p></div>
            <div className="admin-actions">
              {!year.is_current && <button type="button" disabled={busy} onClick={() => onUpdate(`academic-years/${year.id}`, { is_current: true })}>Set current</button>}
              <button type="button" className="danger-button" disabled={busy} onClick={() => onDelete(`academic-years/${year.id}`)}>Delete</button>
            </div>
          </article>
        ))}
      </div>
    </>
  )
}

function Categories({ records, form, setForm, busy, onSubmit, onUpdate, onDelete }) {
  return (
    <>
      <h2>Categories</h2>
      <form className="admin-form" onSubmit={onSubmit}>
        <label>Category name<input value={form.category_name || ''} onChange={(event) => setForm({ ...form, category_name: event.target.value })} required maxLength="150" /></label>
        <label>Parent category (top-level only)<select value={form.parent_category_id || ''} onChange={(event) => setForm({ ...form, parent_category_id: event.target.value || null })}><option value="">Top-level category</option>{records.filter((category) => !category.parent_category_id).map((category) => <option key={category.id} value={category.id}>{category.category_name}</option>)}</select></label>
        <label>Description<textarea value={form.description || ''} onChange={(event) => setForm({ ...form, description: event.target.value })} rows="2" /></label>
        <button className="primary-link" disabled={busy}>Add category</button>
      </form>
      <div className="admin-record-list">
        {records.map((category) => (
          <article className="admin-record admin-record-row" key={category.id}>
            <div><h3>{category.category_name}</h3><p>{category.parent?.category_name || 'Top-level'} · {category.listings_count} listings · {category.children_count} subcategories</p></div>
            <div className="admin-actions">
              <button type="button" disabled={busy} onClick={() => { const name = window.prompt('Category name:', category.category_name); if (name?.trim()) onUpdate(`categories/${category.id}`, { category_name: name.trim() }) }}>Rename</button>
              <button type="button" className="danger-button" disabled={busy} onClick={() => onDelete(`categories/${category.id}`)}>Delete</button>
            </div>
          </article>
        ))}
      </div>
    </>
  )
}

function Students({ records, search, setSearch, busy, onUpdate, onDelete }) {
  return (
    <>
      <h2>Student management</h2>
      <label className="admin-search">Search students<input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Name, student ID, or email" /></label>
      <div className="admin-record-list">
        {records.map((student) => (
          <article className="admin-record admin-student-record" key={student.id}>
            <div className="admin-student-identity">
              <span className="admin-student-avatar" aria-hidden="true">
                {student.first_name?.charAt(0) || '?'}
              </span>
              <div>
                <h3>{student.first_name} {student.last_name}</h3>
                <p>{student.student_id_number} <span aria-hidden="true">·</span> {student.email}</p>
                <span className={`admin-student-email-state${student.email_verified_at ? ' is-verified' : ''}`}>
                  <span aria-hidden="true">{student.email_verified_at ? '✓' : '!'}</span>
                  {student.email_verified_at ? 'Email verified' : 'Email not verified'}
                </span>
              </div>
            </div>
            <label className="admin-inline-field admin-student-status">
              <span>Account status</span>
              <select
                value={student.account_status}
                disabled={busy}
                onChange={(event) => onUpdate(`students/${student.id}`, { account_status: event.target.value })}
              >
                {['active', 'restricted', 'suspended', 'deactivated'].map((status) => (
                  <option key={status}>{status}</option>
                ))}
              </select>
            </label>
            <div className="admin-student-actions">
              {student.account_status === 'deactivated' ? (
                <button
                  type="button"
                  className="admin-student-delete"
                  disabled={busy}
                  onClick={() => onDelete(
                    `students/${student.id}`,
                    `Permanently delete ${student.first_name} ${student.last_name}'s account, including listings, messages, transactions, reviews, verification records, and uploaded files? This cannot be undone.`,
                  )}
                >
                  <svg viewBox="0 0 20 20" aria-hidden="true">
                    <path d="M3.5 5.5h13m-11.5 0 .7 11h7.6l.7-11m-7.5 0v-2h4v2m-3 3v5m2-5v5" />
                  </svg>
                  Delete account
                </button>
              ) : (
                <span className="admin-student-delete-placeholder" aria-hidden="true" />
              )}
            </div>
          </article>
        ))}
      </div>
    </>
  )
}

function Admins({ records, currentUserId, form, setForm, busy, onSubmit, onUpdate }) {
  return (
    <>
      <h2>Administrator accounts</h2>
      <form className="admin-form" onSubmit={onSubmit}>
        <label>Student ID number<input value={form.student_id_number || ''} onChange={(event) => setForm({ ...form, student_id_number: event.target.value })} required /></label>
        <label>First name<input value={form.first_name || ''} onChange={(event) => setForm({ ...form, first_name: event.target.value })} required /></label>
        <label>Last name<input value={form.last_name || ''} onChange={(event) => setForm({ ...form, last_name: event.target.value })} required /></label>
        <label>WMSU email<input type="email" value={form.email || ''} onChange={(event) => setForm({ ...form, email: event.target.value })} required /></label>
        <label>Temporary password<input type="password" minLength="8" pattern="(?=.*[A-Za-z])(?=.*[0-9])[A-Za-z0-9]{8,}" value={form.password || ''} onChange={(event) => setForm({ ...form, password: event.target.value })} required /></label>
        <label>Confirm password<input type="password" value={form.password_confirmation || ''} onChange={(event) => setForm({ ...form, password_confirmation: event.target.value })} required /></label>
        <p className="status-detail">New administrators start as Verification Admins. Transfer Super Admin access from an administrator below.</p>
        <button className="primary-link" disabled={busy}>Create Verification Admin</button>
      </form>
      <div className="admin-record-list">
        {records.map((admin) => (
          <article className="admin-record admin-record-row" key={admin.id}>
            <div><h3>{admin.user?.first_name} {admin.user?.last_name}</h3><p>{admin.user?.email} · {admin.role.replace('_', ' ')}</p><p>Admin: {admin.account_status} · Account: {admin.user?.account_status}</p></div>
            <div className="admin-actions">
              <button type="button" disabled={busy || admin.user_id === currentUserId} onClick={() => {
                if (admin.role !== 'super_admin' && !window.confirm('Transfer Super Admin access to this administrator? Your account will become a Verification Admin.')) return
                onUpdate(`admins/${admin.id}`, { role: admin.role === 'super_admin' ? 'verification_admin' : 'super_admin' })
              }}>{admin.role === 'super_admin' ? 'Demote' : 'Promote to Super Admin'}</button>
              <button type="button" className="danger-button" disabled={busy || admin.user_id === currentUserId} onClick={() => onUpdate(`admins/${admin.id}`, { account_status: admin.account_status === 'active' ? 'deactivated' : 'active' })}>{admin.account_status === 'active' ? 'Deactivate' : 'Reactivate'}</button>
            </div>
          </article>
        ))}
      </div>
    </>
  )
}

function Reports({ records, busy, onUpdate }) {
  function resolve(report, reportStatus, transactionResolution) {
    const notes = window.prompt('Add resolution notes:')
    if (!notes?.trim()) return
    onUpdate(`reports/${report.id}`, {
      report_status: reportStatus,
      resolution_notes: notes.trim(),
      ...(report.transaction_id ? { transaction_resolution: transactionResolution } : {}),
    })
  }

  return (
    <>
      <h2>Reports and disputes</h2>
      <div className="admin-record-list">
        {records.map((report) => (
          <article className="admin-record" key={report.id}>
            <h3>{report.reason} <span className="status-pill">{report.report_status.replaceAll('_', ' ')}</span></h3>
            <p>Reported by {report.reporter?.first_name} {report.reporter?.last_name} · <time dateTime={report.created_at}>{formatDateTime(report.created_at)}</time></p>
            <p>{report.description}</p>
            {report.listing && <p>Listing: {report.listing.title}</p>}
            {report.transaction && (
              <p>
                Transaction #{report.transaction.id} · {report.transaction.transaction_status.replaceAll('_', ' ')}
                {' · '}{report.transaction.buyer?.first_name} {report.transaction.buyer?.last_name}
                {' / '}{report.transaction.seller?.first_name} {report.transaction.seller?.last_name}
              </p>
            )}
            {report.resolution_notes && <p>Resolution: {report.resolution_notes}</p>}
            {!['resolved', 'dismissed'].includes(report.report_status) && (
              <div className="admin-actions">
                <button type="button" disabled={busy} onClick={() => onUpdate(`reports/${report.id}`, { report_status: 'in_review' })}>Mark in review</button>
                {report.transaction_id ? (
                  <>
                    <button type="button" disabled={busy} onClick={() => resolve(report, 'resolved', 'resume')}>Resolve and resume</button>
                    <button type="button" className="danger-button" disabled={busy} onClick={() => resolve(report, 'resolved', 'cancel')}>Resolve and cancel</button>
                    <button type="button" disabled={busy} onClick={() => resolve(report, 'dismissed', 'resume')}>Dismiss and resume</button>
                  </>
                ) : (
                  <>
                    <button type="button" disabled={busy} onClick={() => resolve(report, 'resolved')}>Resolve</button>
                    <button type="button" disabled={busy} onClick={() => resolve(report, 'dismissed')}>Dismiss</button>
                  </>
                )}
              </div>
            )}
          </article>
        ))}
        {!records.length && <p className="status-detail">No reports found.</p>}
      </div>
    </>
  )
}

function DemoEmails({ records, busy, onClear }) {
  const [copiedId, setCopiedId] = useState(null)
  const [copyError, setCopyError] = useState('')

  async function copyVerificationLink(email) {
    try {
      await navigator.clipboard.writeText(email.action_url)
      setCopiedId(email.id)
      setCopyError('')
    } catch {
      setCopyError('Clipboard access is unavailable. Expand “Show link” and copy it manually.')
    }
  }

  return (
    <>
      <div className="admin-record-row">
        <div>
          <h2>Demo email preview</h2>
          <p>Local presentation links for password resets and WMSU email verification.</p>
        </div>
        <button type="button" className="danger-button" disabled={busy || !records.length} onClick={onClear}>
          Clear inbox
        </button>
      </div>
      <p className="status-detail">
        Links expire after 60 minutes. Password reset links can change the recipient’s password. Email verification links must be opened in a session signed in as that student.
      </p>
      {copyError && <p className="form-error" role="alert">{copyError}</p>}
      <div className="admin-record-list">
        {records.map((email) => (
          <article className="admin-record" key={email.id}>
            <h3>{email.email_type === 'password_reset' ? 'Password reset' : 'Email verification'}</h3>
            <p>To {email.recipient_email} · generated {formatDateTime(email.created_at)} · expires {formatDateTime(email.expires_at)}</p>
            {email.email_type === 'password_reset' ? (
              <a className="secondary-link" href={email.action_url} target="_blank" rel="noreferrer">
                Open reset form
              </a>
            ) : (
              <button type="button" className="secondary-link" onClick={() => copyVerificationLink(email)}>
                {copiedId === email.id ? 'Link copied' : 'Copy verification link'}
              </button>
            )}
            <details>
              <summary>Show link</summary>
              <textarea className="demo-email-url" readOnly value={email.action_url} onFocus={(event) => event.target.select()} rows={3} />
            </details>
          </article>
        ))}
        {!records.length && <p className="status-detail">No demo emails yet. Request a password reset or resend email verification from a student account.</p>}
      </div>
    </>
  )
}

function PlatformSettings({ records, busy, onUpdate }) {
  const setting = records[0]
  const [hours, setHours] = useState(setting?.seller_unlock_response_hours || 24)

  return (
    <>
      <h2>Platform settings</h2>
      <form
        className="admin-form"
        onSubmit={(event) => {
          event.preventDefault()
          onUpdate('platform-settings', { seller_unlock_response_hours: Number(hours) })
        }}
      >
        <label>
          Seller unlock response window (hours)
          <input
            type="number"
            min="1"
            max="168"
            step="1"
            value={hours}
            onChange={(event) => setHours(event.target.value)}
            required
          />
        </label>
        <p className="status-detail">If the buyer does not respond before the deadline, the transaction is cancelled and the listing is released. Allowed range: 1–168 hours.</p>
        <button className="primary-link" disabled={busy}>Save response window</button>
      </form>
    </>
  )
}

function AuditLogs({ records }) {
  return (
    <>
      <h2>Audit logs</h2>
      <div className="admin-record-list">
        {records.map((log) => (
          <article className="admin-record admin-record-row" key={log.id}>
            <div><h3>{log.action.replaceAll('_', ' ')}</h3><p>{log.admin?.user?.email} · {log.target_type} #{log.target_id ?? '—'}</p></div>
            <time dateTime={log.created_at}>{formatDateTime(log.created_at)}</time>
          </article>
        ))}
        {!records.length && <p className="status-detail">No audit entries yet.</p>}
      </div>
    </>
  )
}

export default AdminPage
