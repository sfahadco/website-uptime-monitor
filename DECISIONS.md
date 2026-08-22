# Decisions

Choices that shaped this codebase, with the reasoning behind them. Anything
recorded here was a fork in the road rather than a default, so the next person
does not have to re-argue it from scratch.

---

## Frontend: a Vue SPA served by Laravel, with no router and no store

The SPA is built by the Vite pipeline that already ships with the skeleton and
served from the Laravel root route as `resources/views/app.blade.php`, rather
than living in a separate frontend project. The brief targets a single LAMP
host, and keeping both halves in one application means one deployment, one
build artifact under `public/build`, and same-origin API calls -- so there is no
CORS layer, no second web server, and no separate base URL to configure per
environment. `vue-router` and Pinia are deliberately absent: the spec defines
exactly one view, so a router would add a dependency and a catch-all route to
resolve a single path, and the entire application state is four values --
`clients`, `selectedClientId`, `websites` and `dialogTarget` -- which live as
refs in `App.vue` and reach the three child components as props. A store would
be indirection around something a single component already owns. `axios` is
likewise omitted in favour of native `fetch` for two unauthenticated GETs, and
the confirmation dialog uses the native `<dialog>` element, whose `showModal()`
gives focus trapping, Esc-to-dismiss and a backdrop without a modal library.
