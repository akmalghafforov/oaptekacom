---
paths:
  - 'app/**'
---

# App

## Preserve baskets across supplier import replacement
Price-list activation must reconcile basket items by supplier_product_id inside the cutover transaction, never delete stale items. Preserve requested quantity and original snapshot; retain missing or ambiguous replacements as unavailable. Replacement pricing uses SupplierDiscountPrice with the saved basket discount, matching frozen-discount rules. CartMaintenance serializes customer actions using user, then sorted supplier, then item locks; activation takes supplier then item locks. Reload route-bound models after locks and audit removals in the same transaction as archive/checkout creation. Delayed cleanup must verify the supplier active import before changing offer flags.

## Bound category evidence across repeated imports
Category candidates and pivot evidence must never embed prior evidence payloads; retain outer classification and matching metadata only. Keep preview finalization processing until summaries and candidate extraction succeed, and persist the preview transition with activation dispatch in one transaction. Preserve inventory/upload ordering during recovery; import IDs do not establish recency.

## Reprice pharmacy baskets when agreements change
An individual pharmacy agreement overrides the supplier default, including 0%. Agreement creation, edits and clearing reprice all pharmacy-member baskets in the same transaction as agreement and audit writes; supplier-default edits alone preserve frozen prices. Lock sorted users (including users without carts), carts, sorted suppliers, then items, and reconcile imports before repricing. Preserve historical orders, archives, quantities, availability and original snapshots. Pending import increases retain acceptance only while the recalculated proposal exceeds the accepted price. Ordinary maintenance repairs mismatched existing agreements; identify pharmacy purchases using snapshot pharmacy ownership and legacy role-assignment conventions.
