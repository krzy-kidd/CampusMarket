import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import api from '../lib/api.js'

function getErrorMessage(error) {
  return error.response?.data?.message || error.message
}

function HealthPage() {
  const [health, setHealth] = useState({ status: 'checking' })

  useEffect(() => {
    const controller = new AbortController()

    api.get('/api/health', { signal: controller.signal })
      .then(({ data }) => {
        setHealth(data)
      })
      .catch((error) => {
        if (controller.signal.aborted) {
          return
        }

        setHealth({
          status: 'unavailable',
          message: getErrorMessage(error),
        })
      })

    return () => controller.abort()
  }, [])

  async function checkHealth() {
    try {
      const { data } = await api.get('/api/health')
      setHealth(data)
    } catch (error) {
      setHealth({
        status: 'unavailable',
        message: getErrorMessage(error),
      })
    }
  }

  const isChecking = health.status === 'checking'
  const isHealthy = health.status === 'healthy'

  return (
    <main className="page-shell">
      <section className="status-card" aria-labelledby="status-title">
        <p className="eyebrow">RECREO services</p>
        <h1 id="status-title">System status</h1>
        <p
          className={`status-indicator ${isHealthy ? 'is-healthy' : ''} ${!isChecking && !isHealthy ? 'is-error' : ''}`}
          role="status"
          aria-live="polite"
        >
          <span aria-hidden="true" />
          {isChecking
            ? 'Checking the API and database…'
            : isHealthy
              ? 'API and database are connected'
              : 'Unable to reach the API'}
        </p>
        {!isChecking && !isHealthy && (
          <p className="error-message">{health.message}</p>
        )}
        {isHealthy && (
          <p className="status-detail">
            {health.application} · Database {health.database}
          </p>
        )}
        <div className="status-actions">
          <button
            className="primary-link"
            type="button"
            onClick={() => {
              setHealth({ status: 'checking' })
              checkHealth()
            }}
            disabled={isChecking}
          >
            {isChecking ? 'Checking…' : 'Try again'}
          </button>
          <Link className="text-link" to="/">
            Back to home
          </Link>
        </div>
      </section>
    </main>
  )
}

export default HealthPage
