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

## Keep subscription monitoring separate from account access and lifecycle writes
Subscription monitoring counts records, not accounts or organization entitlements, and uses the account phone rather than the organization contact. Current paid records use inclusive Asia/Dushanbe dates; expiration windows include today and overlap. Overdue active records appear expired in monitoring without mutating storage. Preserve the additional account and organization guards in User::hasActiveSubscription().

## Serialize promotion terms with basket confirmation
Promotion content and moderation writes take sorted company locks before promotion/item rows, sharing CartMaintenance supplier locks. Keep promotion copies independent of offer imports and soft-delete used items. Any changed promotion terms require the exact current terms token before checkout, sharing, export or archive. Preserve a pending discount proposal across basket refreshes, especially after an agreement is cleared; supplier-default edits alone keep frozen discounts.

## Keep promotion upload batches atomic
Promotion materials[], banners[] and the optional single logo upload together; logo updates the company profile. Validate every file before storing any, then apply the batch under one PromotionWorkflow lock and increment terms once. Clean stored paths on failure, and retain the legacy role/file request shape for existing clients.

## Capture prize eligibility only at placement under supplier locks
Capture immutable promotion/order contributions only in the two checkout transactions, after saved discounted lines, for the active pharmacy identity. Never backfill historical orders or archives. Status updates and reconciliation acquire the supplier lock before order/promotion rows and recompute eligibility from captured contributions; retain first qualification when eligibility reverses. First publication locks participation settings/prizes for every promotion and dates for prize promotions; ordinary promotion dates retain existing behavior.

## Keep supplier source policy inside activation cutover
Configured supplier imports must pass SupplierSourcePolicy again under the Organization lock in ImportActivator::commit. Compare publication dates and precision, never fetch time or import IDs; unchanged fingerprints do not refresh freshness. External workbooks use their fixed source profile and cannot use mailbox replay. Keep mapping/profile/configuration changes serialized with that same supplier lock and use the shared external-prices:{source} account lock, including CLI operations. Automatic renewal never forces iCorp takeover.

## Keep supplier suggestions separate from eligibility and approval
SupplierSuggestionMatcher computes read-only name/city/phone candidates from stored directory data; never use fuzzy suggestions for ExternalSupplierEligibility or import cutover. Approval must recheck signed evidence and mapping/profile versions under sorted organization then partner locks, audit all links atomically, and preserve automatic loading, source profiles and priorities. Directory and synchronization metadata refreshes preserve known cities when responses omit them.

## Preserve supplier directory review context without weakening approval
Supplier matching popups use signed SupplierMappingApproval snapshots even without suggestions and reopen only the submitted review on failure. Keep list_context restricted to existing directory fields and build return URLs through admin.supplier-sync.index; failed approval or manual mapping must retain the submitted row even when evidence changes remove it from filtered results. Requests without list_context retain the settings redirect.

## Preserve exclusive supplier selection through configuration changes and recovery
Supplier import settings belong to Import Profile; mapping alone must neither select a source nor enable loading. selected_source is authoritative after selection or conversion, including unconfirmed configurations; legacy enabled flags and reference priorities must never restore fallback. An unselected converted/new configuration is blocked, while suppliers without synchronization configuration retain legacy email behavior. Convert only with dispatch paused and active work drained; rollback keeps versions monotonic and preserves completed catalog history. Stale external retries and recovery create work for the current selection instead of reusing old evidence.

## Opt in explicitly to newest available supplier lists
selection_mode=newest_available is the approved exception to exclusive source selection. Only explicitly enabled sources are eligible; compare reliable publication intervals before priority, and recheck under the supplier activation lock. Freshness is monitoring only in this mode. Null mode retains existing selected_source and conversion behavior. Mapping never enables loading. External fetching uses durable global download ownership, service pacing and pauses; only audited administrator recovery can reconnect.
