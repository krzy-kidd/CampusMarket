import { Component } from 'react'
import { Link } from 'react-router-dom'

export default class ErrorBoundary extends Component {
  state = { hasError: false }

  static getDerivedStateFromError() {
    return { hasError: true }
  }

  render() {
    if (this.state.hasError) {
      return (
        <main className="page-shell">
          <section className="status-card" aria-labelledby="app-error-title">
            <p className="eyebrow">CampusTrade</p>
            <h1 id="app-error-title">This page ran into a problem</h1>
            <p className="status-detail">Refresh the page to try again, or return to the home page.</p>
            <div className="status-actions">
              <button className="primary-link" type="button" onClick={() => window.location.reload()}>
                Refresh page
              </button>
              <Link className="text-link" to="/">Go to home</Link>
            </div>
          </section>
        </main>
      )
    }

    return this.props.children
  }
}
