import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import api from '../lib/api.js'

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
]

function getError(error) {
  return Object.values(error.response?.data?.errors || {}).flat()[0]
    || error.response?.data?.message
    || error.message
}

async function fetchAdminData(section, search) {
  const [{ data: me }, { data: summary }] = await Promise.all([
    api.get('/api/auth/me'),
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
  }
  const { data } = paths[section]
    ? await api.get(`/api/auth/admin/${paths[section]}`, {
      params: section === 'students' && search ? { search } : {},
    })
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
      : data.data || data.verifications || data.academic_years || data.categories || data.admins || data.listings || [],
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

  const isSuperAdmin = user?.admin_role === 'super_admin'
  const visibleSections = isSuperAdmin ? sections : sections.slice(0, 2)

  async function load() {
    const data = await fetchAdminData(section, search)
    setUser(data.user)
    setDashboard(data.dashboard)
    setRecords(data.records)
    setPendingVerifications(data.pendingVerifications)
    setRecentAuditLogs(data.recentAuditLogs)
  }

  useEffect(() => {
    let active = true
    fetchAdminData(section, search)
      .then((data) => {
        if (!active) return
        setUser(data.user)
        setDashboard(data.dashboard)
        setRecords(data.records)
        setPendingVerifications(data.pendingVerifications)
        setRecentAuditLogs(data.recentAuditLogs)
        setLoading(false)
        setError('')
      })
      .catch((requestError) => {
        if (!active) return
        setError(requestError.response?.status === 401
          ? 'Sign in with an administrator account to continue.'
          : getError(requestError))
        setLoading(false)
      })
    return () => { active = false }
  }, [section, search])

  async function submitForm(event, endpoint, method = 'post', payload = form) {
    event.preventDefault()
    setBusy(true)
    setError('')
    setMessage('')
    try {
      await api[method](`/api/auth/admin/${endpoint}`, payload)
      setForm({})
      setMessage('Changes saved.')
      await load()
    } catch (requestError) {
      setError(getError(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function update(endpoint, payload, verb = 'patch') {
    setBusy(true)
    setError('')
    setMessage('')
    try {
      await api[verb](`/api/auth/admin/${endpoint}`, payload)
      setMessage('Changes saved.')
      await load()
    } catch (requestError) {
      setError(getError(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function remove(endpoint) {
    if (!window.confirm('Delete this record?')) return
    setBusy(true)
    setError('')
    setMessage('')
    try {
      const { data } = await api.delete(`/api/auth/admin/${endpoint}`)
      setMessage(data.message || 'Record deleted.')
      await load()
    } catch (requestError) {
      setError(getError(requestError))
    } finally {
      setBusy(false)
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
            <span><strong>campusTrade</strong><small>ADMIN WORKSPACE</small></span>
          </div>
          <p className="admin-nav-label">WORKSPACE</p>
          <nav className="admin-nav" aria-label="Admin sections">
            {visibleSections.map(([key, title]) => (
              <button key={key} type="button" className={section === key ? 'is-selected' : ''} aria-current={section === key ? 'page' : undefined} onClick={() => { setSection(key); setMessage(''); setError('') }}>
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
            <Link to="/account">Student account</Link>
            <Link to="/marketplace">Back to marketplace</Link>
          </div>
        </aside>
        <div className="admin-main">
          <section className="admin-heading">
            <div>
              <p className="eyebrow">{isSuperAdmin ? 'SUPER ADMIN' : 'VERIFICATION ADMIN'}</p>
              <h1>{section === 'overview' ? 'Admin dashboard' : sections.find(([key]) => key === section)?.[1]}</h1>
              <p>Manage CampusTrade’s student marketplace.</p>
            </div>
            <span className="admin-account-email">{user.email}</span>
          </section>
          {error && <p className="form-error admin-notice" role="alert">{error}</p>}
          {message && <p className="form-message admin-notice" role="status">{message}</p>}
          <section className="admin-content" aria-live="polite">
            {section === 'overview' && (
              <Overview
                dashboard={dashboard}
                pendingVerifications={pendingVerifications}
                recentAuditLogs={recentAuditLogs}
                isSuperAdmin={isSuperAdmin}
                busy={busy}
                onReview={review}
                onNavigate={setSection}
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
              <Students records={records} search={search} setSearch={setSearch} busy={busy} onUpdate={update} />
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
                <button type="button" className="danger-button" disabled={busy} onClick={() => onArchive(listing)}>Archive listing</button>
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
    ] : []),
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
        {metrics.map(({ label, value, section, tone }) => (
          <button type="button" className={`admin-metric admin-metric-${tone}`} key={label} onClick={() => onNavigate(section)}>
            <span>{label}</span><strong>{value ?? 0}</strong><small>View details <span aria-hidden="true">→</span></small>
          </button>
        ))}
      </div>
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
                        <a href={`${api.defaults.baseURL}/api/auth/verification-attempts/${item.current_attempt_id}/file`} target="_blank" rel="noreferrer">View private COR</a>
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
              <p>You can review COR submissions for the current academic year.</p>
              <button type="button" className="admin-text-button" onClick={() => onNavigate('verifications')}>Open COR reviews →</button>
            </div>
          )}
        </section>
      </div>
    </>
  )
}

function formatDateTime(value) {
  if (!value) return 'Date unavailable'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString()
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
            <p>{item.academic_year?.academic_year} · Submitted {item.current_attempt?.created_at}</p>
            {item.current_attempt_id && (
              <a className="text-link" href={`${api.defaults.baseURL}/api/auth/verification-attempts/${item.current_attempt_id}/file`} target="_blank" rel="noreferrer">View private COR</a>
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
            <div><h3>{year.academic_year} {year.is_current && <span className="status-pill">Current</span>}</h3><p>{year.start_date} to {year.end_date}</p></div>
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
        <label>Parent category<select value={form.parent_category_id || ''} onChange={(event) => setForm({ ...form, parent_category_id: event.target.value || null })}><option value="">Top-level category</option>{records.map((category) => <option key={category.id} value={category.id}>{category.category_name}</option>)}</select></label>
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

function Students({ records, search, setSearch, busy, onUpdate }) {
  return (
    <>
      <h2>Student management</h2>
      <label className="admin-search">Search students<input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Name, student ID, or email" /></label>
      <div className="admin-record-list">
        {records.map((student) => (
          <article className="admin-record admin-record-row" key={student.id}>
            <div><h3>{student.first_name} {student.last_name}</h3><p>{student.student_id_number} · {student.email}</p><p>{student.email_verified_at ? 'Email verified' : 'Email not verified'}</p></div>
            <label className="admin-inline-field">Account status<select value={student.account_status} disabled={busy} onChange={(event) => onUpdate(`students/${student.id}`, { account_status: event.target.value })}>{['active', 'restricted', 'suspended', 'deactivated'].map((status) => <option key={status}>{status}</option>)}</select></label>
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
            <p>Reported by {report.reporter?.first_name} {report.reporter?.last_name} · {report.created_at}</p>
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
            <time>{log.created_at}</time>
          </article>
        ))}
        {!records.length && <p className="status-detail">No audit entries yet.</p>}
      </div>
    </>
  )
}

export default AdminPage
