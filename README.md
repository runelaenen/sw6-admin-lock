# Admin Lock plugin for Shopware 6

Administration admin lock for **Shopware 6.6.10.15**, scoped to two entities:

- `customer`
- `order`

The plugin prevents two CSRs from making conflicting changes to the same record.
It is built for the PartsTree workflow: explicit lock acquisition, lock that
**survives save**, supervisor force-unlock, and a small dashboard listing all
currently held locks.

---

## Behavior

### Lock lifecycle

```
   no lock ──► CSR clicks "Lock for editing" ──► OWNED_BY_ME
                                                    │
                                                    │  CSR edits, saves, edits, saves...
                                                    │  (lock is NOT released by save)
                                                    │
                                                    ▼
                                       CSR clicks "Unlock"  ──► no lock
                                       OR TTL expires       ──► no lock
                                       OR supervisor forces ──► no lock
```

Other CSRs viewing the same record see a persistent warning banner naming the
lock owner, when it was locked, when it will expire, and any optional note the
owner attached.

### What the lock does NOT block

- Storefront writes (customer self-cancel, profile updates).
- API integrations (Sage 100, Klaviyo, warehouse webhooks) that authenticate
  with OAuth client credentials and do **not** send the per-tab session header.
- Versioned order draft writes (Shopware creates these on order page open).

### What the lock DOES block

- Any administration UI write to a `customer` or `order` root entity that
  carries the `sw-lae-admin-lock-token` header but does not own the lock.

---

## Architecture

```
Administration (Vue + Twig)
  ├─ lae-lock-bar (idle / owned / owned-elsewhere / foreign)
  ├─ sw-customer-detail override
  ├─ sw-order-detail override
  ├─ sw-customer-imitate-customer-modal text extension
  └─ lae-admin-lock-list admin module (Settings → System)
                  │
                  ▼  /api/_action/lae-admin-lock/*
RecordLockController
  ├─ GET    /{entityName}/{entityId}              status
  ├─ POST   /{entityName}/{entityId}/acquire      acquire (or refresh)
  ├─ POST   /{entityName}/{entityId}/heartbeat    UPDATE-only fast path
  ├─ POST   /{entityName}/{entityId}/release      owner-only release
  ├─ POST   /{entityName}/{entityId}/force-release  privileged break-glass
  ├─ POST   /bulk-status                          {entity → {id → state}}
  └─ GET    /active                               list of all live locks
                  │
                  ▼
RecordLockService (Doctrine\Connection)
  ├─ acquire():   single-statement INSERT … ON DUPLICATE KEY UPDATE
  ├─ heartbeat(): single-statement UPDATE … WHERE owner_token = ?
  ├─ release() / forceRelease():  single-statement DELETE
  └─ Throttled cleanup (≤ 1×/60s per PHP process)

lae_admin_lock table
  PRIMARY KEY (entity_name, entity_id)
  KEY idx_lae_admin_lock_expires_at (expires_at)
  KEY idx_lae_admin_lock_user_id (user_id)
  Steady-state row count = number of currently held locks.

RecordLockWriteProtectionSubscriber (PreWriteValidationEvent)
  Three early-exits keep this subscriber free for non-admin-UI traffic:
    1. Non-AdminApiSource contexts.
    2. Admin API requests without the session header.
    3. Order writes against versioned drafts.
```

---

## Lease constants

```
TTL                 = 1800 s   (30 min)
heartbeat interval  =   60 s
status poll interval=   15 s
```

These are intentionally hardcoded for v2.

---

## ACL privileges

The plugin uses the existing `customer:*` and `order:*` privileges for the
acquire / release surface, plus two new privileges:

| Privilege                      | What it enables                                              |
|--------------------------------|--------------------------------------------------------------|
| `lae_admin_lock.viewer`        | Open the active-locks dashboard at `/sw/settings/lae-admin-lock`. |
| `lae_admin_lock.force_unlock`  | Force-release a foreign lock (button on the lock bar and dashboard). Depends on `lae_admin_lock.viewer`. |

Both are registered in the role-management UI under **Permissions → System → Edit locks**.

---

## Installation

Copy the plugin into:

```
custom/plugins/LaenenAdminLock
```

Then:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate LaenenAdminLock
bin/console cache:clear
```

### Administration build

For Shopware 6.6 the administration assets must be compiled in your CI / release
image creation pipeline; do not rely on rebuilding live nodes manually.

If your project uses `shopware-cli`, ensure the plugin is included in the build.
With `.shopware-project.yml` you may need `force_extension_build: true` for
plugin admin asset bundling, otherwise pre-packaged plugin bundles are skipped
by default.

Local/dev:

```bash
bin/build-administration.sh
```

---

## Manual test plan

### Concurrency

1. Open the same customer in two admin sessions (different users, or same user
   in two browsers). Session A clicks **Lock for editing**.
2. Session B sees the foreign lock banner with A's name.
3. Session A edits and saves. Banner stays in both sessions; A still owns the lock.
4. Session A clicks **Unlock**. B can now lock.

### Lock survival

1. A locks order. A saves once. A edits more. A saves again. A still owns the lock.
2. A waits 25 minutes idle on the page. Heartbeat keeps the lock alive.
3. A closes the tab (without unlocking). After ~30 minutes the lock expires.

### Force unlock

1. A locks order. A walks away.
2. Supervisor opens the order. Sees the foreign banner with a red **Force unlock** button.
3. Supervisor clicks Force unlock. A's next heartbeat returns 0 affected rows; A's UI cancels editing.

### Same user, second tab

1. Same admin opens the same order in tabs T1 and T2.
2. T1 locks. T2 shows "owned by you in another tab" with a **Take over here** button.
3. T2 takes over. T1 loses ownership on the next heartbeat / poll.

### Sync integrations bypass

1. A locks order. Sage 100 InSynch sends an order update via OAuth integration
   token (no session header). Update succeeds. A's lock is preserved.
2. Storefront customer cancels their own order. Cancellation succeeds. A's lock is preserved.
3. Warehouse adds tracking via API integration. Succeeds. Lock preserved.
4. Another admin UI tab tries to write. Blocked.

### Active locks dashboard

1. Lock 5 records across 3 users.
2. Open Settings → System → Edit locks. All 5 listed.
3. Click Force unlock on a row (requires privilege). Row disappears within 30s.

---

## Known limitations

1. **TTL is the only safety net for crashed sessions.** 30 minutes is the
   intentional balance between "CSR is still working" and "CSR is gone for the day."
2. **Backend write protection is a coordination layer, not access control.**
   The header check is bypassable by anyone able to make admin API calls.
3. **Versioned-draft order writes are not subscribed.** UI ownership enforcement
   covers the realistic CSR flows.
4. **No browser-close release.** Closing the tab does not release the lock; this
   is by design — the lock is meant to survive accidental tab closure.
