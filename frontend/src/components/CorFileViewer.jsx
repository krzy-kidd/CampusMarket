import { useEffect, useState } from 'react'
import { Button, Modal } from './ui.jsx'
import api from '../lib/api.js'

const PREVIEW_TYPES = new Set(['application/pdf', 'image/jpeg', 'image/png'])

function getFileError(error) {
  const responseData = error.response?.data
  if (responseData instanceof Blob) {
    return responseData.text().then((text) => {
      try {
        return JSON.parse(text).message || 'The submitted document could not be loaded.'
      } catch {
        return 'The submitted document could not be loaded.'
      }
    })
  }

  return Promise.resolve(
    responseData?.message || error.message || 'The submitted document could not be loaded.',
  )
}

export default function CorFileViewer({ attemptId, label = 'View submitted COR' }) {
  const [file, setFile] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => () => {
    if (file?.url) URL.revokeObjectURL(file.url)
  }, [file])

  async function openFile() {
    setLoading(true)
    setError('')

    try {
      const { data, headers } = await api.get(
        `/api/auth/verification-attempts/${attemptId}/file`,
        { responseType: 'blob' },
      )
      const contentType = (headers['content-type'] || '').split(';')[0].trim().toLowerCase()

      if (!PREVIEW_TYPES.has(contentType)) {
        throw new Error('The server returned a file that cannot be previewed as an image or PDF.')
      }

      setFile({
        url: URL.createObjectURL(new Blob([data], { type: contentType })),
        contentType,
      })
    } catch (requestError) {
      setError(await getFileError(requestError))
    } finally {
      setLoading(false)
    }
  }

  return (
    <>
      <Button type="button" variant="secondary" onClick={openFile} disabled={loading}>
        {loading ? 'Loading document…' : label}
      </Button>
      {error && <p className="form-error" role="alert">{error}</p>}
      <Modal
        open={Boolean(file)}
        onClose={() => setFile(null)}
        title="Submitted COR document"
        className="cor-file-modal"
        actions={file && (
          <Button
            href={file.url}
            target="_blank"
            rel="noreferrer"
            variant="secondary"
          >
            Open in new tab
          </Button>
        )}
      >
        {file?.contentType === 'application/pdf' ? (
          <iframe className="cor-file-pdf" src={file.url} title="Submitted Certificate of Registration" />
        ) : file ? (
          <img className="cor-file-image" src={file.url} alt="Submitted Certificate of Registration" />
        ) : null}
      </Modal>
    </>
  )
}
