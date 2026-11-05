# Deploying the Firefly Pocket fork

**The `fireflyx` branch is production.** Every push to it, in practice every merged PR, is built and deployed to the Firefly Pocket server with nothing else to do. Merge only what you want live. `main` tracks upstream and is never deployed.

## What a merge into `fireflyx` does

1. **Build** (`.github/workflows/fireflyx-deploy.yml`): `Dockerfile.fireflyx` on top of the official image of the upstream release this branch is based on, the `'version'` in `config/importer.php`. The image is pushed to GHCR as `ghcr.io/majster01/data-importer:<version>-<commit>`, for example `2.3.5-d89f2ba`.
2. **Pin**: the workflow starts *Pin fork image* in [firefly-pocket-stack](https://github.com/Majster01/firefly-pocket-stack), which writes that tag into `compose/docker-compose.yml` and commits `Bump importer to <tag>` to its `master`.
3. **Deploy**: the server checks firefly-pocket-stack's `master` every two minutes. Because the importer migrates its database on start, and that database holds the encrypted bank sessions, the server first backs up every database and proves the backup restores. Then it pulls the image and restarts the importer. A failed step arrives as a push (ntfy and the Firefly Pocket app); a deploy that moved the importer's version says "Upgrade successful" in the app.

A push that only changes `*.md` builds and deploys nothing.

## Rules

- **Work on branches off `fireflyx`; merging into `fireflyx` is the deploy button.** Never push straight to it.
- **Agents: never merge into or push to `fireflyx` unless the user asked to deploy, in this session.** Open a PR against `fireflyx` (not `main`) and stop there.
- **Tags do nothing**, and upstream's own workflows (release, labels) are not part of this pipeline.
- **Rebasing onto a new upstream release**: rebase `fireflyx` onto the upstream tag; `config/importer.php` then carries the new version, which picks the base image and the tag. `Dockerfile.fireflyx` refuses to build when the two disagree. Read the upstream release notes, since its migrations run too.
- **Server first.** A new environment variable goes into firefly-pocket-stack's `compose/docker-compose.yml` and the server's `.env` before this is merged. Keep the API Firefly Pocket calls (`/api/fireflyx/v1`) working for an older Firefly Pocket: it is deployed separately, after or before this.
- **Migrations** (`database/migrations/fireflyx`) run on start. They get a fresh, restore-tested backup first, but keep them additive anyway: a rollback of the image does not undo them.

## When something goes wrong

| Where it stopped | Look at | Then |
|---|---|---|
| Build | this repository's Actions, *Firefly Pocket deploy* | Fix and merge again. Nothing was deployed. |
| Pin | firefly-pocket-stack's Actions, *Pin fork image* | "cannot be pulled without a login": the GHCR package is private (first publish only). Make it public in its package settings and re-run. |
| Deploy | the push notification; `journalctl -u fireflyx-deploy` on the server | A failed pull, backup or restore test restarts nothing. After a failed restart, fix forward or roll back. |

**Roll back** with firefly-pocket-stack's Actions → *Pin fork image* → *Run workflow*, `importer` and an earlier tag (its `Bump importer to …` commits list them). If the newer version migrated the database, restore it too (firefly-pocket-stack README, "Restoring").

The `FIREFLY_POCKET_DISPATCH_TOKEN` secret (a fine-grained token for firefly-pocket-stack only, *Actions: Read and write*) is what lets this repository start the pin. When it expires, the deploy job fails at its last step; renew it in both forks.
