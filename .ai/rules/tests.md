---
paths:
  - 'tests/**'
---

# Tests

## Admin fixtures include provisioned business identities
Creating an admin automatically provisions pharmacy and wholesaler role assignments and organizations. These accounts intentionally disappear from the direct Users listing under the business-account exclusions. Users pagination fixtures must explicitly represent administrators without business memberships; directory filter/sort fixtures must account for the extra organizations. Preserve production role provisioning.

## Follow JSON-session validation redirects before asserting session errors
Session serialization is JSON. assertSessionHasErrors() starts the session after a response; another HTTP request can then marshal an already restored ViewErrorBag again and lose its errors in this test process. For redirect-and-render tests, assert the redirect, follow it, and assert rendered errors/aria associations instead of inspecting session errors between requests.
