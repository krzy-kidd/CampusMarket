# CampusTrade frontend

The frontend is a React application built with Vite, React Router, and Axios.
It uses the Laravel API configured by `VITE_API_BASE_URL` in `.env.local`.

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
