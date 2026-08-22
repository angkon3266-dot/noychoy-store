# Working agreement

Operational rules for Claude on this repository. Project documentation lives in
`README.md`, `DEPLOY.md`, `GITHUB-GUIDE.md` and `docs/` — this file does not
repeat any of it.

## Where the work stands

`docs/ROADMAP-STATUS.md` is the running tracker for the 22 Aug 2026 storefront
review: what is done, what is left, and who can close each item (a developer, or
the store owner in the admin panel). **Read it before picking up roadmap work,
and update the row plus the changelog when you finish something** — statuses
there are re-verified against the code, so treat it as truer than the review
dossier it came from.

## Working copy

Work from **`E:\G Drive\ClaudeWorkspace\noychoy-store`**. It is the canonical
copy.

A second copy exists at `C:\Users\shami\OneDrive\Desktop\Woo Automation\noychoy-store`.
It is **hands-off**: do not edit, sync, pull, push, or run anything in it unless
the user explicitly asks. Do not create further copies. If the two ever diverge,
stop and raise it rather than reconciling them unprompted — they are separate
folders, so concurrent edits can corrupt `.git`.

## Ship it without being asked

Once a change is complete **and verified**, carry it through to production
without waiting for a prompt at each step:

1. Verify — `php artisan test`, plus a browser check via the `noychoy` preview
   config when the change is visible in the UI.
2. `git commit`
3. `git push origin main`
4. Deploy:
   ```
   ssh hostnin "cd ~/repositories/noychoy-store && bash deploy.sh"
   ```
5. Confirm the deploy actually took.

**If any stage fails, stop and diagnose it. Never continue to the next stage.**
A failed test does not get committed, a failed push does not get deployed, and a
failed migration does not get papered over.

## Deploying

Use the repo's own `deploy.sh` — do not hand-roll the commands it already
contains. Production is `~/repositories/noychoy-store` on the `hostnin` SSH host
(not `~/noychoy-store`).

Two things `deploy.sh` cannot do, so say so after every deploy rather than
calling it finished:

- **LiteSpeed page cache** — flush it in cPanel.
- **lsphp OPcache** — toggle the PHP version in cPanel to reset it.

Front-end assets ship committed in `public/build`; there is no npm step on the
server. Run `npm run build` locally and commit the result whenever JS, CSS, or a
new arbitrary Tailwind class changes — otherwise the deployed site silently uses
the old bundle.

## Scope of the standing permission

The above covers commit, push to `main`, and `deploy.sh` on this project only.
It does not extend to force-pushing, rewriting history, editing production data,
or any destructive server operation. Ask first for those.
