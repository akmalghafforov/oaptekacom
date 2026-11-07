---
paths:
  - 'resources/views/promotions/**'
---

# Promotions

## Keep promotion editor forms independent
Wrap each editable promotion form in x-ui.form-scope and submit its _promotion_form key so old values and errors stay local. Restore select values explicitly and reopen the submitted disclosure. Product/material redirects retain the editor query and return to their section. Date-picker enhancement removes the visible input name and adds a hidden canonical input; dirty tracking must include visible data-date-input controls.
