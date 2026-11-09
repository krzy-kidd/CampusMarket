import { useEffect, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { Badge, Button, Card, FormField, SelectInput, TextInput } from '../components/ui.jsx'
import api from '../lib/api.js'
import { cacheCurrentUser, clearCurrentUserCache, getCurrentUser } from '../lib/session.js'
import { safeInternalRoute } from '../lib/navigation.js'
import CorFileViewer from '../components/CorFileViewer.jsx'

const COLLEGES = [
  'College of Engineering & Technology',
  'College of Nursing & Health Sciences',
  'College of Teacher Education',
  'College of Liberal Arts',
  'College of Science and Mathematics',
  'College of Computing Studies',
  'College of Architecture',
  'College of Agriculture & Forestry',
  'College of Criminology',
  'College of Social Work & Community Development',
  'College of Asian and Islamic Studies',
  'College of Home Economics',
  'College of Sports Science & Physical Education',
  'College of Law',
  'College of Medicine',
]

const GRADUATE_COLLEGES = [
  'College of Engineering & Technology',
  'College of Nursing & Health Sciences',
  'College of Teacher Education',
  'College of Liberal Arts',
  'College of Science and Mathematics',
  'College of Agriculture & Forestry',
  'College of Social Work & Community Development',
  'College of Law',
  'College of Medicine',
  'College of Public Administration & Development Studies',
]

const YEAR_LEVELS = [
  'First year',
  'Second year',
  'Third year',
  'Fourth year',
  'Fifth year',
]

const STUDENT_LEVELS = [
  { value: 'undergraduate', label: 'Undergraduate' },
  { value: 'graduate', label: 'Graduate' },
]

const VERIFICATION_STATUSES = {
  not_configured: {
    label: 'Academic year not configured',
    variant: 'info',
    message: 'A current academic year has not been configured yet. COR submission will be available when it is set up.',
  },
  not_submitted: {
    label: 'Not submitted',
    variant: 'neutral',
    message: 'Submit your Certificate of Registration for the current academic year to begin verification.',
  },
  pending: {
    label: 'Under review',
    variant: 'warning',
    message: 'Your COR is awaiting review. You can check back here for the latest status.',
  },
  approved: {
    label: 'Verified',
    variant: 'success',
    message: 'Your COR is approved for the current academic year.',
  },
  rejected: {
    label: 'Action required',
    variant: 'danger',
    message: 'Your COR was not approved. Review the admin feedback below and submit a new copy.',
  },
}

async function fetchAccountData({ refreshUser = false } = {}) {
  const { data: me } = await getCurrentUser({ refresh: refreshUser })
  if (me.user.admin_role) return { account: me.user, verification: null }

  const { data: verification } = await api.get('/api/auth/verification/status')
  return { account: me.user, verification }
}

function errorMessage(error) {
  const validationErrors = Object.values(error.response?.data?.errors || {})
    .flat()
  return validationErrors[0] || error.response?.data?.message || error.message
}

async function csrfCookie() {
  await api.get('/sanctum/csrf-cookie')
}

function PasswordInput({
  id,
  label,
  name,
  value,
  onChange,
  autoComplete,
  visible,
  onToggle,
  ...inputProps
}) {
  return (
    <div className="auth-field">
      <label className="ui-field-label" htmlFor={id}>{label}</label>
      <div className="auth-password-control">
        <TextInput
          {...inputProps}
          id={id}
          name={name}
          type={visible ? 'text' : 'password'}
          autoComplete={autoComplete}
          value={value}
          onChange={onChange}
        />
        <button
          className="auth-password-toggle"
          type="button"
          aria-label={visible ? 'Hide password' : 'Show password'}
          aria-controls={id}
          aria-pressed={visible}
          onClick={onToggle}
        >
          <svg viewBox="0 0 24 24" aria-hidden="true">
            {visible
              ? <path d="M3 3l18 18M10.6 10.6a2 2 0 002.8 2.8M9.9 5.2A10.7 10.7 0 0112 5c5.1 0 8.7 4.1 10 7a11.8 11.8 0 01-3 4.1M6.2 6.2A12.4 12.4 0 002 12c1.3 2.9 4.9 7 10 7 1.2 0 2.4-.2 3.4-.6" />
              : <><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z" /><circle cx="12" cy="12" r="3" /></>}
          </svg>
        </button>
      </div>
    </div>
  )
}

function AuthLayout({ children }) {
  return (
    <main className="auth-page">
      <aside className="auth-promo">
        <div className="auth-promo-brand" aria-label="RECREO">
          <span className="auth-brand-icon" aria-hidden="true">cT</span>
          <span>RECREO</span>
        </div>
        <div className="auth-promo-copy">
          <p>WMSU STUDENT MARKETPLACE</p>
          <h2>Your campus.<br />Your community.</h2>
          <span>Find useful things, pass them on, and connect with fellow WMSU students.</span>
        </div>
        <div className="auth-promo-note">
          <span aria-hidden="true">✓</span>
          <span>Student email and annual COR verification help keep the community focused on WMSU.</span>
        </div>
        <div className="auth-promo-decoration" aria-hidden="true">
          <svg viewBox="0 0 500 170">
            <path d="M35 155V82h430v73M80 82 250 20l170 62M115 96h43v59h-43zm75 0h43v59h-43zm75 0h43v59h-43zm75 0h43v59h-43zM227 155v-40q23-34 46 0v40" />
          </svg>
        </div>
      </aside>
      <div className="auth-form-panel">{children}</div>
    </main>
  )
}

export function AuthPage({ mode }) {
  const navigate = useNavigate()
  const location = useLocation()
  const isRegister = mode === 'register'
  const [visiblePasswords, setVisiblePasswords] = useState(false)
  const [form, setForm] = useState({
    student_id_number: '',
    first_name: '',
    last_name: '',
    college: '',
    student_level: '',
    year_level: '',
    email: '',
    password: '',
    password_confirmation: '',
  })
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  function update(event) {
    const { name, value } = event.target
    const updatedForm = { ...form, [name]: value }

    if (name === 'student_level') {
      const availableColleges = value === 'graduate' ? GRADUATE_COLLEGES : COLLEGES
      if (!availableColleges.includes(form.college)) {
        updatedForm.college = ''
      }
    }

    if (name === 'student_level' && value === 'graduate') {
      updatedForm.year_level = ''
    }

    setForm(updatedForm)
  }

  async function submit(event) {
    event.preventDefault()
    setError('')

    if (isRegister) {
      const studentIdNumber = form.student_id_number.replace('-', '')
      const wmsuEmailNumber = form.email.trim().split('@')[0].match(/^[a-z]+(\d{9})$/i)?.[1]

      if (!/^\d{4}-?\d{5}$/.test(form.student_id_number)) {
        setError('Enter your student ID as 2027-02829 or 202702829.')
        return
      }

      if (wmsuEmailNumber !== studentIdNumber) {
        setError('Your student ID number must match the number in your WMSU email address.')
        return
      }
    }

    if (isRegister && form.password !== form.password_confirmation) {
      setError('The password confirmation does not match.')
      return
    }

    setBusy(true)
    try {
      await csrfCookie()
      const registrationForm = { ...form }
      delete registrationForm.student_level
      const { data } = await api.post(
        `/api/auth/${isRegister ? 'register' : 'login'}`,
        isRegister ? registrationForm : form,
      )
      cacheCurrentUser(data.user)
      const destination = safeInternalRoute(location.state?.returnTo, '/marketplace')
      navigate(destination, { replace: true, state: { notice: data.message } })
    } catch (requestError) {
      setError(errorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <AuthLayout>
      <Card as="section" className="auth-card" aria-labelledby="auth-title">
        <p className="auth-eyebrow">{isRegister ? 'Join the campus community' : 'Welcome back'}</p>
        <h1 id="auth-title">{isRegister ? 'Create your account' : 'Sign in to RECREO'}</h1>
        <p className="auth-intro">
          {isRegister
            ? 'Use your WMSU student details to get started.'
            : 'Log in to continue to the WMSU student marketplace.'}
        </p>
        <form className="account-form auth-form" onSubmit={submit}>
          {isRegister && (
            <>
              <FormField
                label="Student ID number"
                hint="Use 2027-02829 or 202702829. The 9 digits must match the number in your WMSU email address."
              >
                <TextInput name="student_id_number" value={form.student_id_number} onChange={update} required maxLength="10" autoComplete="off" placeholder="2027-02829" />
              </FormField>
              <div className="form-grid">
                <FormField label="First name">
                  <TextInput name="first_name" value={form.first_name} onChange={update} required maxLength="100" autoComplete="given-name" />
                </FormField>
                <FormField label="Last name">
                  <TextInput name="last_name" value={form.last_name} onChange={update} required maxLength="100" autoComplete="family-name" />
                </FormField>
              </div>
              <FormField label="Student level">
                <SelectInput name="student_level" value={form.student_level} onChange={update} required>
                  <option value="">Select your student level</option>
                  {STUDENT_LEVELS.map((studentLevel) => (
                    <option key={studentLevel.value} value={studentLevel.value}>{studentLevel.label}</option>
                  ))}
                </SelectInput>
              </FormField>
              <FormField label="College">
                <SelectInput
                  name="college"
                  value={form.college}
                  onChange={update}
                  disabled={!form.student_level}
                >
                  <option value="">
                    {form.student_level ? 'Select your college' : 'Select student level first'}
                  </option>
                  {(form.student_level === 'graduate' ? GRADUATE_COLLEGES : COLLEGES).map((college) => (
                    <option key={college} value={college}>{college}</option>
                  ))}
                </SelectInput>
              </FormField>
              {form.student_level === 'undergraduate' && (
                <FormField label="Year level">
                  <SelectInput name="year_level" value={form.year_level} onChange={update} required>
                    <option value="">Select your year level</option>
                    {YEAR_LEVELS.map((yearLevel) => (
                      <option key={yearLevel} value={yearLevel}>{yearLevel}</option>
                    ))}
                  </SelectInput>
                </FormField>
              )}
            </>
          )}
          <FormField
            label="WMSU email address"
            hint={isRegister
              ? 'Use your WMSU student account, for example ty202602829@wmsu.edu.ph. You’ll verify this email after creating your account.'
              : 'Enter the WMSU student account you registered with, for example ty202602829@wmsu.edu.ph.'}
          >
            <TextInput name="email" type="email" autoComplete="email" placeholder="ty202602829@wmsu.edu.ph" value={form.email} onChange={update} required />
          </FormField>
          <PasswordInput
            id="account-password"
            label={isRegister ? 'Create password' : 'Password'}
            name="password"
            autoComplete={isRegister ? 'new-password' : 'current-password'}
            value={form.password}
            onChange={update}
            visible={visiblePasswords}
            onToggle={() => setVisiblePasswords((visible) => !visible)}
            required
            minLength={isRegister ? 8 : undefined}
            pattern={isRegister ? '(?=.*[A-Za-z])(?=.*[0-9])[A-Za-z0-9]{8,}' : undefined}
            title={isRegister ? 'Use at least 8 characters containing only letters and numbers.' : undefined}
          />
          {isRegister && (
            <PasswordInput
              id="account-password-confirmation"
              label="Confirm password"
              name="password_confirmation"
              autoComplete="new-password"
              value={form.password_confirmation}
              onChange={update}
              visible={visiblePasswords}
              onToggle={() => setVisiblePasswords((visible) => !visible)}
              required
            />
          )}
          {!isRegister && (
            <div className="auth-form-meta">
              <Link className="text-link" to="/forgot-password">Forgot password?</Link>
            </div>
          )}
          {error && <p className="form-error" role="alert">{error}</p>}
          <Button type="submit" disabled={busy} className="auth-submit">
            {busy ? 'Please wait…' : isRegister ? 'Create account' : 'Sign in'}
          </Button>
        </form>
        <p className="auth-form-footer">
          {isRegister ? 'Already have an account? ' : 'New to RECREO? '}
          <Link className="text-link" to={isRegister ? '/login' : '/register'}>
            {isRegister ? 'Sign in' : 'Create an account'}
          </Link>
        </p>
        {isRegister && (
          <p className="auth-verification-note">
            After registration, verify your WMSU email and submit your COR for the current academic year.
          </p>
        )}
      </Card>
    </AuthLayout>
  )
}

export function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  async function submit(event) {
    event.preventDefault()
    setBusy(true)
    setError('')
    setMessage('')
    try {
      await csrfCookie()
      const { data } = await api.post('/api/auth/forgot-password', { email })
      setMessage(data.message)
    } catch (requestError) {
      setError(errorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <AuthLayout>
      <Card as="section" className="auth-card" aria-labelledby="auth-title">
        <p className="auth-eyebrow">Account recovery</p>
        <h1 id="auth-title">Reset your password</h1>
        <p className="auth-intro">Enter your WMSU email address. If an account exists, we’ll send a password reset link.</p>
        <form className="account-form auth-form" onSubmit={submit}>
          <FormField
            label="WMSU email address"
            hint="Use your WMSU student account, for example ty202602829@wmsu.edu.ph."
          >
            <TextInput type="email" autoComplete="email" placeholder="ty202602829@wmsu.edu.ph" value={email} onChange={(event) => setEmail(event.target.value)} required />
          </FormField>
          {error && <p className="form-error" role="alert">{error}</p>}
          {message && <p className="form-message" role="status">{message}</p>}
          <Button type="submit" disabled={busy} className="auth-submit">
            {busy ? 'Please wait…' : 'Send reset link'}
          </Button>
        </form>
        <p className="auth-form-footer"><Link className="text-link" to="/login">Back to sign in</Link></p>
      </Card>
    </AuthLayout>
  )
}

export function ResetPasswordPage() {
  const params = new URLSearchParams(window.location.search)
  const [form, setForm] = useState({
    token: params.get('token') || '',
    email: params.get('email') || '',
    password: '',
    password_confirmation: '',
  })
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [visiblePasswords, setVisiblePasswords] = useState(false)

  function update(event) {
    setForm({ ...form, [event.target.name]: event.target.value })
  }

  async function submit(event) {
    event.preventDefault()
    setError('')
    setMessage('')

    if (form.password !== form.password_confirmation) {
      setError('The password confirmation does not match.')
      return
    }

    setBusy(true)
    try {
      await csrfCookie()
      const { data } = await api.post('/api/auth/reset-password', form)
      setMessage(data.message)
    } catch (requestError) {
      setError(errorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <AuthLayout>
      <Card as="section" className="auth-card" aria-labelledby="auth-title">
        <p className="auth-eyebrow">Account recovery</p>
        <h1 id="auth-title">Choose a new password</h1>
        <p className="auth-intro">Use a new password that is at least 8 characters and contains letters and numbers.</p>
        {!form.token && (
          <p className="form-error" role="alert">
            This password reset link is missing or invalid. <Link to="/forgot-password">Request a new link</Link>.
          </p>
        )}
        <form className="account-form auth-form" onSubmit={submit}>
          <FormField
            label="WMSU email address"
            hint="Use your WMSU student account, for example ty202602829@wmsu.edu.ph."
          >
            <TextInput name="email" type="email" autoComplete="email" placeholder="ty202602829@wmsu.edu.ph" value={form.email} onChange={update} required />
          </FormField>
          <PasswordInput
            id="reset-password"
            label="New password"
            name="password"
            autoComplete="new-password"
            value={form.password}
            onChange={update}
            visible={visiblePasswords}
            onToggle={() => setVisiblePasswords((visible) => !visible)}
            minLength="8"
            pattern="(?=.*[A-Za-z])(?=.*[0-9])[A-Za-z0-9]{8,}"
            title="Use at least 8 characters containing only letters and numbers."
            required
          />
          <PasswordInput
            id="reset-password-confirmation"
            label="Confirm new password"
            name="password_confirmation"
            autoComplete="new-password"
            value={form.password_confirmation}
            onChange={update}
            visible={visiblePasswords}
            onToggle={() => setVisiblePasswords((visible) => !visible)}
            required
          />
          <input name="token" type="hidden" value={form.token} />
          {error && <p className="form-error" role="alert">{error}</p>}
          {message && <p className="form-message" role="status">{message}</p>}
          <Button type="submit" disabled={busy || !form.token} className="auth-submit">
            {busy ? 'Please wait…' : 'Reset password'}
          </Button>
        </form>
        <p className="auth-form-footer"><Link className="text-link" to="/login">Back to sign in</Link></p>
      </Card>
    </AuthLayout>
  )
}

export function VerifyEmailPage() {
  const location = useLocation()
  const [result, setResult] = useState({ status: 'checking', authRequired: false })

  useEffect(() => {
    let active = true
    const signedUrl = new URLSearchParams(location.search).get('url')

    async function verify() {
      try {
        const apiUrl = new URL(api.defaults.baseURL, window.location.origin)
        const verificationUrl = signedUrl ? new URL(signedUrl, apiUrl) : null
        const isVerificationEndpoint = verificationUrl
          && /^\/api\/auth\/email\/verify\/\d+\/[a-f\d]{40}$/i.test(verificationUrl.pathname)
          && verificationUrl.searchParams.has('expires')
          && verificationUrl.searchParams.has('signature')
        if (!isVerificationEndpoint || verificationUrl.origin !== apiUrl.origin) {
          throw new Error('This verification link is invalid.')
        }
        await csrfCookie()
        const { data } = await api.get(verificationUrl.href)
        if (active) {
          clearCurrentUserCache()
          setResult({ status: 'success', message: data.message })
        }
      } catch (requestError) {
        if (active) {
          setResult({
            status: 'error',
            authRequired: requestError.response?.status === 401,
            message: requestError.response?.status === 401
              ? 'Sign in to the account that requested this verification link, then try again.'
              : errorMessage(requestError),
          })
        }
      }
    }

    verify()
    return () => { active = false }
  }, [location.search])

  return (
    <main className="page-shell">
      <section className="status-card">
        <p className="eyebrow">Email verification</p>
        <h1>{result.status === 'checking' ? 'Verifying your email…' : result.status === 'success' ? 'Email verified' : 'Could not verify email'}</h1>
        {result.message && <p className={result.status === 'error' ? 'form-error' : 'form-message'} role="status">{result.message}</p>}
        <div className="status-actions">
          {result.authRequired ? (
            <Button
              to="/login"
              state={{ returnTo: `${location.pathname}${location.search}` }}
            >
              Sign in to verify
            </Button>
          ) : (
            <Button to="/account">Continue to account</Button>
          )}
        </div>
      </section>
    </main>
  )
}

export function AccountPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const [account, setAccount] = useState(null)
  const [verification, setVerification] = useState(null)
  const [error, setError] = useState('')
  const [message, setMessage] = useState(location.state?.notice || '')
  const [busy, setBusy] = useState(false)
  const [refreshing, setRefreshing] = useState(false)
  const [selectedCorName, setSelectedCorName] = useState('')
  const [loading, setLoading] = useState(true)
  const [retryKey, setRetryKey] = useState(0)

  async function loadAccount() {
    try {
      const data = await fetchAccountData({ refreshUser: true })
      setAccount(data.account)
      setVerification(data.verification)
      setLoading(false)
      setError('')
    } catch (requestError) {
      if (requestError.response?.status === 401) {
        navigate('/login', { replace: true })
      } else {
        setError(errorMessage(requestError))
        setLoading(false)
      }
    }
  }

  useEffect(() => {
    let active = true
    fetchAccountData()
      .then((data) => {
        if (active) {
          setAccount(data.account)
          setVerification(data.verification)
          setLoading(false)
          setError('')
        }
      })
      .catch((requestError) => {
        if (!active) return
        if (requestError.response?.status === 401) {
          navigate('/login', { replace: true })
        } else {
          setError(errorMessage(requestError))
          setLoading(false)
        }
      })
    return () => { active = false }
  }, [navigate, retryKey])

  async function sendVerificationEmail() {
    setBusy(true)
    setError('')
    setMessage('')
    try {
      await csrfCookie()
      const { data } = await api.post('/api/auth/email/verification-notification')
      setMessage(data.message)
    } catch (requestError) {
      setError(errorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function verifyEmailLocally() {
    setBusy(true)
    setError('')
    setMessage('')
    try {
      await csrfCookie()
      const { data } = await api.post('/api/auth/email/verify-local')
      setMessage(data.message)
      await loadAccount()
    } catch (requestError) {
      setError(errorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function submitCor(event) {
    event.preventDefault()
    const form = event.currentTarget
    const file = form.elements.cor.files[0]
    if (!file) return

    setBusy(true)
    setError('')
    setMessage('')
    try {
      await csrfCookie()
      const body = new FormData()
      body.append('cor', file)
      const { data } = await api.post('/api/auth/verification/cor', body)
      setMessage(data.message)
      form.reset()
      setSelectedCorName('')
      await loadAccount()
    } catch (requestError) {
      setError(errorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function refreshVerification() {
    setRefreshing(true)
    await loadAccount()
    setRefreshing(false)
  }

  async function logout() {
    setBusy(true)
    try {
      await csrfCookie()
      await api.post('/api/auth/logout')
      clearCurrentUserCache()
      navigate('/login', { replace: true })
    } catch (requestError) {
      setError(errorMessage(requestError))
      setBusy(false)
    }
  }

  if (loading) {
    return <main className="page-shell"><section className="status-card"><p role="status">Loading your account…</p></section></main>
  }

  if (!account) {
    return (
      <main className="page-shell">
        <section className="status-card">
          <p className="form-error" role="alert">{error || 'Your account details are unavailable.'}</p>
          <div className="status-actions">
            <Button
              variant="secondary"
              onClick={() => {
                setError('')
                setLoading(true)
                setRetryKey((key) => key + 1)
              }}
            >
              Try again
            </Button>
          </div>
        </section>
      </main>
    )
  }

  const canSubmit = account.email_verified
    && ['not_submitted', 'rejected'].includes(verification?.status)
  const currentStatus = VERIFICATION_STATUSES[verification?.status]
    || { label: 'Status unavailable', variant: 'neutral', message: 'Verification status is unavailable. Please try refreshing.' }

  return (
    <main className="page-shell verification-page">
      <section className="status-card verification-account-card">
        <p className="eyebrow">{account.admin_role ? 'Administrator account' : 'Student account'}</p>
        <h1>Welcome, {account.first_name}</h1>
        <p className="status-detail verification-account-email">{account.email}</p>
        <div className="status-actions">
          {!account.admin_role && (
            <Link className="primary-link" to="/dashboard">Open student dashboard</Link>
          )}
          {account.admin_role && (
            <Link className="primary-link" to="/admin">Admin dashboard</Link>
          )}
        </div>
        {error && <p className="form-error" role="alert">{error}</p>}
        {message && <p className="form-message" role="status">{message}</p>}
        {!account.admin_role && <Card className="verification-panel email-verification-panel">
          <div className="verification-panel-heading">
            <div>
              <p className="eyebrow">Step 1</p>
              <h2>WMSU email verification</h2>
            </div>
            <Badge variant={account.email_verified ? 'success' : 'warning'}>
              {account.email_verified ? 'Verified' : 'Not verified'}
            </Badge>
          </div>
          <p>
            {account.email_verified
              ? 'Your email is verified. You can submit a COR for the current academic year.'
              : 'Verify your WMSU email before submitting your Certificate of Registration.'}
          </p>
          {!account.email_verified && (
            <div className="status-actions">
              <Button type="button" onClick={sendVerificationEmail} disabled={busy}>
                {busy ? 'Please wait…' : 'Resend verification email'}
              </Button>
              {import.meta.env.DEV && !account.admin_role && (
                <Button type="button" variant="secondary" onClick={verifyEmailLocally} disabled={busy}>
                  Verify locally (development only)
                </Button>
              )}
            </div>
          )}
          {import.meta.env.DEV && !account.email_verified && !account.admin_role && (
            <p className="verification-year-label">
              Local testing shortcut only. It does not verify your actual WMSU email.
            </p>
          )}
        </Card>}
        {!account.admin_role && <Card className="verification-panel cor-verification-panel">
          <div className="verification-panel-heading">
            <div>
              <p className="eyebrow">Step 2 · {verification?.academic_year || 'Current academic year'}</p>
              <h2>Certificate of Registration</h2>
            </div>
            <Badge variant={currentStatus.variant}>{currentStatus.label}</Badge>
          </div>
          <p>{currentStatus.message}</p>
          {verification?.academic_year && (
            <p className="verification-year-label">Verification for academic year {verification.academic_year}</p>
          )}
          {canSubmit && (
            <form className="account-form verification-upload-form" onSubmit={submitCor}>
              <FormField
                label="Upload your COR"
                hint="Accepted formats: PDF, JPG, or PNG. Maximum file size: 10 MB."
              >
                <TextInput
                  name="cor"
                  type="file"
                  accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                  onChange={(event) => setSelectedCorName(event.target.files[0]?.name || '')}
                  required
                />
              </FormField>
              {selectedCorName && (
                <p className="verification-selected-file">Selected file: {selectedCorName}</p>
              )}
              <Button type="submit" disabled={busy}>
                {busy ? 'Submitting COR…' : verification?.status === 'rejected' ? 'Resubmit COR' : 'Submit COR for review'}
              </Button>
            </form>
          )}
          <div className="verification-status-actions">
            <Button type="button" variant="secondary" onClick={refreshVerification} disabled={refreshing || busy}>
              {refreshing ? 'Refreshing…' : 'Refresh status'}
            </Button>
          </div>
          {verification?.attempts?.length > 0 && (
            <ol className="verification-attempt-list">
              {verification.attempts.map((attempt) => (
                <li key={attempt.id}>
                  <div className="verification-attempt-heading">
                    <strong>Submission {attempt.attempt_number}</strong>
                    <Badge variant={VERIFICATION_STATUSES[attempt.submission_status]?.variant || 'neutral'}>
                      {VERIFICATION_STATUSES[attempt.submission_status]?.label || attempt.submission_status}
                    </Badge>
                  </div>
                  {attempt.reviewed_at && (
                    <p className="verification-attempt-date">
                      Reviewed {new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium' }).format(new Date(attempt.reviewed_at))}
                    </p>
                  )}
                  {attempt.admin_notes && (
                    <div className="verification-feedback">
                      <strong>Admin feedback</strong>
                      <p>{attempt.admin_notes}</p>
                    </div>
                  )}
                  <CorFileViewer attemptId={attempt.id} />
                </li>
              ))}
            </ol>
          )}
        </Card>}
        <div className="verification-account-actions">
          <Button type="button" variant="secondary" onClick={logout} disabled={busy}>Sign out</Button>
        </div>
      </section>
    </main>
  )
}
