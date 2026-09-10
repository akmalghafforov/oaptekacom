# OAPTEKA UI guide

The interface is a clinical B2B pharmacy system: white surfaces on a calm neutral canvas, emerald for primary actions and positive states, amber for attention, and red only for destructive actions or errors. Tokens live in `resources/css/app.css`: `brand`, `canvas`, `surface`, `ink`, `muted`, `danger`, `warning`, `panel`, `control`, and `panel` shadow.

## Components

New application views must use the shared anonymous Blade components in `resources/views/components/ui` instead of one-off control or panel styling:

- `x-ui.button`: `primary`, `secondary`, `danger`, or `ghost`.
- `x-ui.input` and `x-ui.select`: always provide a visible `label`; they display validation errors and optional hints.
- `x-ui.card`: grouped surface content.
- `x-ui.page-header`: page title, optional description, and `actions` slot.
- `x-ui.alert`: `success`, `warning`, or `danger` feedback.
- `x-ui.status-badge`: order state, with green for completed/confirmed states, amber for in-progress, and red for cancelled.
- `x-ui.empty-state`: empty collections, with an optional `action` slot.

Use `.table-wrap` and `.data-table` together for responsive data tables. Keep pages inside the layout's `page-container`; use grids and wrapping layouts so controls stay usable at narrow widths. The header uses a native `details` disclosure for mobile navigation and does not require JavaScript.

Every interactive control needs visible text or an accessible label, keyboard focus must remain visible, form errors must be connected to their named field through the input/select components, and color must not be the only source of status information. Keep touch targets at least 40px high and preserve the Russian interface copy.
