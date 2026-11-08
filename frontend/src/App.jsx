import { Link, Navigate, Route, Routes, useLocation } from 'react-router-dom'
import SiteHeader from './components/SiteHeader.jsx'
import { Badge, Button, Card } from './components/ui.jsx'
import {
  AccountPage,
  AuthPage,
  ForgotPasswordPage,
  ResetPasswordPage,
  VerifyEmailPage,
} from './pages/AccountPage.jsx'
import AdminPage from './pages/AdminPage.jsx'
import HealthPage from './pages/HealthPage.jsx'
import {
  ListingDetailPage,
  ListingFormPage,
  MarketplacePage,
  MyListingsPage,
} from './pages/MarketplacePage.jsx'
import { MessageThreadPage, MessagesPage } from './pages/MessagesPage.jsx'
import { TransactionDetailPage, TransactionsPage } from './pages/TransactionsPage.jsx'
import NotificationsPage from './pages/NotificationsPage.jsx'
import './App.css'

function HomePage() {
  return (
    <main className="landing-page landing-home">
      <section className="landing-hero">
        <div className="landing-hero-inner">
          <div className="landing-hero-copy">
            <Badge variant="danger" className="landing-label">WMSU STUDENT MARKETPLACE</Badge>
            <h1>Buy and Sell Within Your <span>Campus Community.</span></h1>
            <p>
              Discover affordable student essentials and sell items you no
              longer need—all with verified WMSU students.
            </p>
            <div className="landing-actions">
              <Button to="/marketplace">Browse marketplace <span aria-hidden="true">→</span></Button>
              <Button to="/register" variant="secondary">Create account</Button>
            </div>
          </div>
          <div className="landing-campus-visual" aria-hidden="true">
            <div className="landing-campus-glow" />
            <svg className="landing-campus-art" viewBox="0 0 640 440" role="presentation">
              <path className="campus-art-ground" d="M25 350h590v65H25z" />
              <path className="campus-art-building" d="M150 345V174h340v171z" />
              <path className="campus-art-wing" d="M88 345V231h74v114zm403 0V231h61v114z" />
              <path className="campus-art-roof" d="M125 176 320 75l196 101z" />
              <path className="campus-art-cornice" d="M140 183h370v14H140z" />
              <path className="campus-art-entry" d="M278 345v-96q42-58 84 0v96z" />
              <path className="campus-art-step" d="M260 345h120v15H260zm-15 16h150v12H245z" />
              <path className="campus-art-window" d="M180 217h40v43h-40zm72 0h40v43h-40zm128 0h40v43h-40zm72 0h33v43h-33zM180 283h40v43h-40zm72 0h40v43h-40zm128 0h40v43h-40zm72 0h33v43h-33z" />
              <path className="campus-art-lines" d="M320 125v47m-176 25h370M162 345V215m316 130V215" />
              <path className="campus-art-palm" d="M72 345q7-82-9-132m8 6q-23-21-26-41 22 9 32 28 3-29 21-43 4 25-13 48 23-19 45-17-12 22-46 26m-27 56q-18-17-18-34 19 9 30 25m427 117q8-75-6-122m6 11q-20-17-23-35 20 8 29 24 2-25 18-37 4 22-12 42 21-17 40-15-11 20-40 23m-28 47q-17-15-17-30 17 8 27 22" />
            </svg>
            <div className="landing-campus-caption">
              <span className="landing-caption-mark">cT</span>
              <span><strong>Made for campus.</strong><small>Buy, sell, connect.</small></span>
            </div>
          </div>
        </div>
      </section>
      <section className="landing-proof-row" aria-label="Marketplace highlights">
        <div><span aria-hidden="true">✓</span><strong>Verified students</strong><small>A safer campus marketplace</small></div>
        <div><span aria-hidden="true">₱</span><strong>Fixed prices</strong><small>Clear and straightforward</small></div>
        <div><span aria-hidden="true">⌖</span><strong>Meet on campus</strong><small>Built around the WMSU community</small></div>
      </section>
      <section className="landing-section">
        <div className="landing-section-heading" id="how-it-works">
          <p className="landing-eyebrow">Get started</p>
          <h2>Campus selling, made simple.</h2>
        </div>
        <div className="landing-steps">
          <Card as="article" className="landing-step">
            <span>01</span>
            <h3>Verify your account</h3>
            <p>Sign up with your WMSU email and submit your COR for the current academic year.</p>
          </Card>
          <Card as="article" className="landing-step">
            <span>02</span>
            <h3>Browse or list</h3>
            <p>Explore campus items, or publish a fixed-price listing after verification.</p>
          </Card>
          <Card as="article" className="landing-step">
            <span>03</span>
            <h3>Find what you need</h3>
            <p>Discover books, school supplies, electronics, and more from the WMSU community.</p>
          </Card>
        </div>
      </section>
      <section className="landing-categories">
        <div className="landing-section-heading" id="categories">
          <p className="landing-eyebrow">Explore</p>
          <h2>Shop by category</h2>
        </div>
        <div className="landing-category-list">
          {[
            'Textbooks and Educational Materials',
            'Electronics and Gadgets',
            'Clothing and Accessories',
            'Dormitory Essentials',
            'School Supplies',
          ].map((category) => (
            <Link key={category} to="/marketplace" className="landing-category">
              {category}
            </Link>
          ))}
        </div>
        <Link className="landing-text-link" to="/marketplace">See the marketplace <span aria-hidden="true">→</span></Link>
      </section>
    </main>
  )
}

function App() {
  const { pathname } = useLocation()
  const isLanding = pathname === '/'
  const isAdmin = pathname === '/admin'
  const isAuth = ['/login', '/register', '/forgot-password', '/reset-password', '/verify-email'].includes(pathname)

  return (
    <div className={`app-shell${isLanding ? ' landing-shell' : ''}${isAdmin ? ' admin-site' : ''}`}>
      <SiteHeader
        variant={isLanding ? 'landing' : isAdmin ? 'admin' : isAuth ? 'auth' : 'default'}
        pathname={pathname}
      />
      <Routes>
        <Route path="/" element={<HomePage />} />
        <Route path="/marketplace" element={<MarketplacePage />} />
        <Route path="/listings/new" element={<ListingFormPage />} />
        <Route path="/listings/:listingId/edit" element={<ListingFormPage />} />
        <Route path="/listings/:listingId" element={<ListingDetailPage />} />
        <Route path="/my-listings" element={<MyListingsPage />} />
        <Route path="/messages" element={<MessagesPage />} />
        <Route path="/messages/:conversationId" element={<MessageThreadPage />} />
        <Route path="/transactions" element={<TransactionsPage />} />
        <Route path="/transactions/:transactionId" element={<TransactionDetailPage />} />
        <Route path="/notifications" element={<NotificationsPage />} />
        <Route path="/health" element={<HealthPage />} />
        <Route path="/login" element={<AuthPage mode="login" />} />
        <Route path="/register" element={<AuthPage mode="register" />} />
        <Route path="/forgot-password" element={<ForgotPasswordPage />} />
        <Route path="/reset-password" element={<ResetPasswordPage />} />
        <Route path="/verify-email" element={<VerifyEmailPage />} />
        <Route path="/account" element={<AccountPage />} />
        <Route path="/admin" element={<AdminPage />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
      <footer className={`site-footer${isLanding ? ' landing-footer' : ''}`}>
        {isLanding
          ? 'CampusTrade is a student project for WMSU. It is not run by the university.'
          : 'A student marketplace for the WMSU community'}
      </footer>
    </div>
  )
}

export default App
