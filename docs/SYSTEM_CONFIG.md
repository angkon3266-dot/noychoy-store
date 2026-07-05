# System Configuration Manager

A Super-Admin module to manage platform configuration from the admin panel
instead of editing `.env`. Values are stored **encrypted in the database** and
applied as **runtime overrides** on top of the `.env`-loaded config. Every
change is **versioned, audited and backed up automatically**.

Admin → **System Config**.

---

## Architecture (hybrid, fail-safe)

- **DB-stored runtime overrides.** Editable settings live in `system_configs`
  (sensitive values Crypt-encrypted). `ConfigApplier` applies them over the
  loaded config at every boot (`AppServiceProvider::boot`). The `.env` file
  stays as bootstrap + fallback and is **not** rewritten for these.
- **`.env` is never rewritten for normal changes.** The one exception is the
  **Database** section (a DB connection can't be bootstrapped from the DB), which
  uses the safe wizard below.
- **`APP_KEY` is never editable** and never exposed.
- **Secrets never bake into the config cache.** `ConfigApplier` skips during
  `config:cache` / `optimize`, so decrypted values are never written to
  `bootstrap/cache/config.php`. They are applied in-memory per request instead.

### Files

```
config store            app/Services/SystemConfig/ConfigSchema.php         (single source of truth)
                        app/Services/SystemConfig/SystemConfigRepository.php (encrypt/decrypt, memoise)
                        app/Services/SystemConfig/ConfigApplier.php          (runtime overrides at boot)
orchestration           app/Services/SystemConfig/SystemConfigService.php
database wizard          app/Services/SystemConfig/DatabaseConfigManager.php + EnvWriter.php
testing                 app/Services/SystemConfig/ConnectionTester.php
versioning / backup      app/Services/SystemConfig/ConfigVersionService.php + ConfigBackupService.php
controllers             app/Http/Controllers/Admin/SystemConfigController.php
                        app/Http/Controllers/Admin/ConfigHistoryController.php
                        app/Http/Controllers/Admin/ConfigBackupController.php
security                app/Http/Controllers/Concerns/ConfirmsAdminPassword.php
                        app/Policies/SystemConfigPolicy.php ("system-config.access" gate)
requests                app/Http/Requests/SystemConfig/*
events / listener / job  ConfigurationChanged, ConfigurationRestored → RebuildConfigurationCache → RebuildConfigCache job
models                  SystemConfig, ConfigVersion, ConfigBackup, ConfigAuditLog
views                   resources/views/admin/system-config/*
```

---

## Sections

General · Database · Mail (SMTP) · Queue · Cache · Redis · SMS · Payment ·
Storage · Google · **Meta** · Security · SEO.

Adding a field is a one-line entry in `ConfigSchema::sections()` (define its
`config` dot-path, `sensitive` flag, `type` and optional `test` group).

> **Meta:** setting App ID / App Secret / Webhook token here makes the Marketing →
> Meta "Connect with Facebook" (Production OAuth) work **without editing `.env`**.

---

## Database section — Test → Validate → Apply → Rollback

You cannot be locked out:

1. New credentials are tested on a **throwaway connection** (nothing changes).
2. Only on success are the current `.env` values snapshotted.
3. New values are written to `.env`.
4. The write is **re-verified**; if it fails, the previous values are **rolled
   back** automatically.
5. The config cache is cleared so the next request uses the new connection.

The live request keeps its existing connection throughout.

---

## Security

- Sensitive values encrypted with `Crypt` (AES-256); password/API-key fields are
  **masked** in the UI (show `•••• saved`, never the value).
- **Password confirmation** (your admin login password) is required to **save**,
  **restore** and **import**. Failures are **rate-limited** (5 / 5 min) and
  **audited**.
- Super-Admin only — enforced by the `admin` middleware section gate, the
  `system-config.access` gate/policy, and the Form Requests.

---

## Versioning & History

A snapshot is written **before every change** with timestamp, user, IP, section,
encrypted previous + new values and optional notes. The **History** tab lets you
search, filter (user / module / date), inspect one change, **compare two
versions**, and **download** the history as CSV.

## Backup & Restore

- A backup is auto-created **before every save, restore and import**; manual
  named backups any time. Each shows date, creator, size and affected modules.
- **Restore** shows a confirmation + change preview + warning, requires password,
  auto-backs-up the current state first, then restores and **rebuilds the config
  cache** (queued job). Nothing is ever lost.

## Import / Export

- **Export** the whole config, or selected modules, as an **encrypted JSON**
  file (readable only by this installation's `APP_KEY`).
- **Import** validates the schema version (rejects incompatible files with a
  clear message), **previews** the exact changes, auto-backs-up first, then
  applies on password confirmation.

## Audit Log

Every save / restore / import / export / test / failed-confirmation is recorded
with who, what (keys only — never secret values), result (success/failure), IP,
browser and time. Filter by user / action / module / date.

---

## Notes

- Restart queue workers after changing values workers rely on (they cache config
  at boot, like any Laravel worker).
- Some app-specific integrations (SMS, Steadfast) also have their own admin
  pages; the values here override `config()` where the service reads it.
