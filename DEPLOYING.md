# Deploying the FireflyX fork

**The `fireflyx` branch is production.** Every push to it, in practice every merged PR, is built and deployed to the FireflyX server with nothing else to do. Merge only what you want live. `main` tracks upstream and is never deployed.

## What a merge into `fireflyx` does

1. **Build** (`.github/workflows/fireflyx-deploy.yml`): `Dockerfile.fireflyx` on top of the official image of the upstream release this branch is based on, the `'version'` in `config/importer.php`. The image is pushed to GHCR as `ghcr.io/majster01/data-importer:<version>-<commit>`, for example `2.3.5-d89f2ba`.
2. **Pin**: the workflow starts *Pin fork image* in [FireflyX](https://github.com/Majster01/FireflyX), which writes that tag into `compose/docker-compose.yml` and commits `Bump importer to <tag>` to FireflyX `master`.
3. **Deploy**: the server checks FireflyX `master` every two minutes. Because the importer migrates its database on start, and that database holds the encrypted bank sessions, the server first backs up every database and proves the backup restores. Then it pulls the image and restarts the importer. A push notification (ntfy and the Pico app) says `FireflyX: deployed …`, or which step failed.

A push that only changes `*.md` builds and deploys nothing.

## Rules

- **Work on branches off `fireflyx`; merging into `fireflyx` is the deploy button.** Never push straight to it.
- **Agents: never merge into or push to `fireflyx` unless the user asked to deploy, in this session.** Open a PR against `fireflyx` (not `main`) and stop there.
- **Tags do nothing**, and upstream's own workflows (release, labels) are not part of this pipeline.
- **Rebasing onto a new upstream release**: rebase `fireflyx` onto the upstream tag; `config/importer.php` then carries the new version, which picks the base image and the tag. `Dockerfile.fireflyx` refuses to build when the two disagree. Read the upstream release notes, since its migrations run too.
- **Server first.** A new environment variable goes into FireflyX's `compose/docker-compose.yml` and the server's `.env` before this is merged. Keep the API Pico calls (`/api/fireflyx/v1`) working for an older Pico: Pico is deployed separately, after or before this.
- **Migrations** (`database/migrations/fireflyx`) run on start. They get a fresh, restore-tested backup first, but keep them additive anyway: a rollback of the image does not undo them.

## When something goes wrong

| Where it stopped | Look at | Then |
|---|---|---|
| Build | this repository's Actions, *FireflyX deploy* | Fix and merge again. Nothing was deployed. |
| Pin | FireflyX's Actions, *Pin fork image* | "cannot be pulled without a login": the GHCR package is private (first publish only). Make it public in its package settings and re-run. |
| Deploy | the push notification; `journalctl -u fireflyx-deploy` on the server | A failed pull, backup or restore test restarts nothing. After a failed restart, fix forward or roll back. |

**Roll back** with FireflyX's Actions → *Pin fork image* → *Run workflow*, `importer` and an earlier tag (FireflyX's `Bump importer to …` commits list them). If the newer version migrated the database, restore it too (FireflyX README, "Restoring").

The `FIREFLYX_DISPATCH_TOKEN` secret (a fine-grained token for FireflyX only, *Actions: Read and write*) is what lets this repository start the pin. When it expires, the deploy job fails at its last step; renew it in both forks.
