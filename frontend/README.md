# CampusTrade frontend

The frontend is a React application built with Vite, React Router, and Axios.
During local development, Vite proxies `/api` and `/sanctum` to Laravel so the
Sanctum session cookies remain first-party in the browser. Configure the target
with `VITE_API_PROXY_TARGET` in `.env.local` when Laravel is not running at
`http://127.0.0.1:8000`. Production builds use `VITE_API_BASE_URL`.

## Development

```sh
npm install
npm run dev
```

Run the production build and lint checks with:

```sh
npm run build
npm run lint
```

## Design system

Global color, typography, spacing, radius, shadow, and sizing tokens live in
`src/index.css`. Reusable buttons, form controls, cards, badges, dialogs, and
page containers are exported from `src/components/ui.jsx`; their styles are in
`src/components/ui.css`. The shared top navigation is in
`src/components/SiteHeader.jsx`.

Use the shared primitives for new UI and migrate existing pages incrementally
to avoid disrupting working flows.
