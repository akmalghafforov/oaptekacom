---
paths:
  - 'tests/**'
---

# Tests

## Admin fixtures include provisioned business identities
Creating an admin automatically provisions pharmacy and wholesaler role assignments and organizations. These accounts intentionally disappear from the direct Users listing under the business-account exclusions. Users pagination fixtures must explicitly represent administrators without business memberships; directory filter/sort fixtures must account for the extra organizations. Preserve production role provisioning.
