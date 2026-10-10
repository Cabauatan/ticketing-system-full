# Support Desk Vue frontend

This is the standalone Vue 3 + Vite client for the Laravel ticketing API.

## Local development

1. Start Laravel from the repository root: `php artisan serve --host=127.0.0.1 --port=8000`.
2. Start Reverb in another terminal: `php artisan reverb:start`.
3. In `frontend-ticketing`, install dependencies with `npm ci` and start the client with `npm run dev`.
4. Open `http://127.0.0.1:5173`.

The frontend `.env` sets `VITE_API_PROXY_TARGET` for the same-origin Laravel proxy. The proxy forwards API, login, email verification, password reset, and broadcast authorization requests so Laravel sessions and CSRF protection work without cross-origin cookies. Public Reverb connection values come from the root Laravel `.env` (`VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, and `VITE_REVERB_SCHEME`). Keep `REVERB_APP_SECRET` server-side; it must never use a `VITE_` prefix.

For another environment, copy `.env.example` to `.env` and set the backend URL. The Laravel `.env` should set `FRONTEND_URL` to the Vue origin so password reset and email verification links return to the frontend.
