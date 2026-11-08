import { cloneElement, useEffect, useId, useRef } from 'react'
import { Link } from 'react-router-dom'
import './ui.css'

export function Button({
  to,
  href,
  variant = 'primary',
  className = '',
  type = 'button',
  disabled = false,
  onClick,
  ...props
}) {
  const Component = to ? Link : href ? 'a' : 'button'
  const isNavigation = Boolean(to || href)
  const destination = to ? { to } : href ? { href } : { type, disabled }
  const navigationState = isNavigation && disabled
    ? { 'aria-disabled': true, tabIndex: -1 }
    : {}
  const handleClick = (event) => {
    if (disabled && isNavigation) {
      event.preventDefault()
      event.stopPropagation()
      return
    }

    onClick?.(event)
  }

  return (
    <Component
      {...destination}
      {...props}
      onClick={isNavigation ? handleClick : onClick}
      {...navigationState}
      className={`ui-button ui-button-${variant} ${className}`.trim()}
    />
  )
}

export function FormField({ id, label, hint, error, children, className = '' }) {
  const generatedId = useId()
  const controlId = id || generatedId
  const childDescriptionIds = children.props['aria-describedby']
  const descriptionIds = [
    childDescriptionIds,
    hint ? `${controlId}-hint` : '',
    error ? `${controlId}-error` : '',
  ].filter(Boolean).join(' ') || undefined

  return (
    <div className={`ui-field ${className}`.trim()}>
      {label && <label className="ui-field-label" htmlFor={controlId}>{label}</label>}
      {cloneElement(children, {
        id: controlId,
        'aria-describedby': descriptionIds,
        'aria-invalid': error ? true : children.props['aria-invalid'],
      })}
      {hint && <p className="ui-field-hint" id={`${controlId}-hint`}>{hint}</p>}
      {error && <p className="ui-field-error" id={`${controlId}-error`} role="alert">{error}</p>}
    </div>
  )
}

export function TextInput({ className = '', ...props }) {
  return <input {...props} className={`ui-input ${className}`.trim()} />
}

export function SelectInput({ className = '', children, ...props }) {
  return (
    <select {...props} className={`ui-input ui-select ${className}`.trim()}>
      {children}
    </select>
  )
}

export function TextArea({ className = '', ...props }) {
  return <textarea {...props} className={`ui-input ui-textarea ${className}`.trim()} />
}

export function Card({ as: Component = 'section', className = '', ...props }) {
  return <Component {...props} className={`ui-card ${className}`.trim()} />
}

export function Badge({ variant = 'neutral', className = '', ...props }) {
  return <span {...props} className={`ui-badge ui-badge-${variant} ${className}`.trim()} />
}

export function PageContainer({ as: Component = 'main', className = '', ...props }) {
  return <Component {...props} className={`ui-page-container ${className}`.trim()} />
}

export function Modal({ open, onClose, title, children, actions, className = '' }) {
  const titleId = useId()
  const dialogRef = useRef(null)
  const onCloseRef = useRef(onClose)

  useEffect(() => {
    onCloseRef.current = onClose
  }, [onClose])

  useEffect(() => {
    if (!open) return undefined

    const previousFocus = document.activeElement
    const previousOverflow = document.body.style.overflow
    const dialog = dialogRef.current
    const focusableSelector = [
      'a[href]',
      'button:not([disabled])',
      'input:not([disabled])',
      'select:not([disabled])',
      'textarea:not([disabled])',
      '[tabindex]:not([tabindex="-1"])',
    ].join(',')

    document.body.style.overflow = 'hidden'
    dialog?.querySelector(focusableSelector)?.focus()
    if (!dialog?.contains(document.activeElement)) dialog?.focus()

    function handleKeyDown(event) {
      if (event.key === 'Escape') {
        onCloseRef.current()
        return
      }

      if (event.key !== 'Tab' || !dialog) return
      const focusable = [...dialog.querySelectorAll(focusableSelector)]
        .filter((element) => element.getClientRects().length > 0)
      if (!focusable.length) {
        event.preventDefault()
        dialog.focus()
        return
      }

      const first = focusable[0]
      const last = focusable[focusable.length - 1]
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', handleKeyDown)
    return () => {
      document.removeEventListener('keydown', handleKeyDown)
      document.body.style.overflow = previousOverflow
      if (previousFocus instanceof HTMLElement) previousFocus.focus()
    }
  }, [open])

  if (!open) return null

  return (
    <div
      className="ui-modal-backdrop"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onCloseRef.current()
      }}
    >
      <section
        ref={dialogRef}
        className={`ui-modal ${className}`.trim()}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
      >
        <header className="ui-modal-header">
          <h2 id={titleId}>{title}</h2>
          <Button variant="ghost" aria-label="Close dialog" onClick={onClose}>×</Button>
        </header>
        <div className="ui-modal-body">{children}</div>
        {actions && <footer className="ui-modal-actions">{actions}</footer>}
      </section>
    </div>
  )
}
