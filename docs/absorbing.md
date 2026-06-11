# Absorbing a library

One command runs most of the playbook:

```sh
bin/monorepo absorb database
```

It imports the library with full history, strips the QA tooling the monorepo hoists (pint/phpstan/rector/phpunit dependencies, QA scripts and `pint.json`, pointing test scripts at the root `phpunit`, refreshing `composer.lock` if one is committed), writes a `mirror.yml` workflow that closes pull requests opened against the mirror with a redirect to the monorepo, banners the README, and creates the mirror ruleset (PR-only, no force-push, split app bypassed). Every step is idempotent — re-run it freely.

Then, by hand:

1. `bin/monorepo check <name> --fix` — apply the canonical style; fix whatever phpstan and rector surface.
2. `bin/monorepo test <name>` — and make the package satisfy the test contract (see the README's Testing section): unit tier in `composer test`, services tier in `composer test:e2e`.
3. Review `packages/<name>/.github/workflows/` — delete QA-only workflows (pint, phpstan, linters); keep test workflows, they validate the mirror after each split.
4. Commit, push, and confirm the Split run is green. If the mirror push is rejected:
   - `master` default branch — rename first: `gh api -X POST repos/utopia-php/<name>/branches/master/rename -f new_name=main`
   - `protected branch hook declined` — classic branch protection; let the app bypass it:
     `echo '{"bypass_pull_request_allowances":{"apps":["utopia-php-monorepo"]}}' | gh api -X PATCH repos/utopia-php/<name>/branches/main/protection/required_pull_request_reviews --input -`
   - diverged mirror (a commit landed upstream after the import) — re-import, or `bin/monorepo split <name> --force` once.
5. Triage pull requests already open on the mirror (the redirect only covers new ones), then make the library's next release from the monorepo.
