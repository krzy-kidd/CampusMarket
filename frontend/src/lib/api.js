import axios from 'axios'

const api = axios.create({
  // Keep local SPA requests on Vite's origin so Sanctum cookies stay first-party.
  // Production builds can still point directly at their configured API origin.
  baseURL: import.meta.env.DEV
    ? ''
    : (import.meta.env.VITE_API_BASE_URL || 'http://127.0.0.1:8000'),
  headers: {
    Accept: 'application/json',
  },
  withCredentials: true,
  withXSRFToken: true,
})

export default api
