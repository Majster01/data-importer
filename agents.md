# agents.md

## PR instructions

AI agents must disclose what tool and model they are using in the "Assisted-by" commit footer:

`Assisted-by: [Model Name] via [Tool Name]`

Example:

`Assisted-by: GLM 4.6 via Claude Code`

## Firefly Pocket fork: the `fireflyx` branch is production

This is the Firefly Pocket fork. Every push to `fireflyx` (a merged PR) is built and deployed to a live server with real bank data; see [DEPLOYING.md](DEPLOYING.md). Never merge into or push to `fireflyx` unless the user asked to deploy, in this session: work on a branch off `fireflyx`, open a PR against it, and stop there. `main` tracks upstream and is never deployed.
