# Absorbing a library

One command runs the whole playbook:

```sh
bin/monorepo absorb database
```

It imports the library with full history, strips the QA tooling the monorepo hoists (pint/phpstan/rector/phpunit dependencies, QA scripts and `pint.json`, points test scripts at the root `phpunit`, refreshes a committed `composer.lock`), removes the library's own CI workflows and writes a single `mirror.yml` that redirects pull requests opened against the mirror back here, banners the README, and normalises the mirror's branch ruleset (PR-only, no force-push, split app bypassed). Every step is idempotent — re-run it freely; the ruleset step updates an existing, even differently-named, ruleset in place rather than skipping it.

Then, by hand:

1. `bin/monorepo check <name> --fix` — apply the canonical style; fix whatever phpstan and rector surface.
2. `bin/monorepo test <name>` — make the package satisfy the test contract (see the README's Testing section): unit tier in `composer test`, services tier in `composer test:e2e`.
3. Commit and push, then confirm the Split run is green. Land it as a **merge commit, never a squash** — the import's subtree annotation lives in that merge, and a squash drops it (which would orphan the package from its mirror history).
4. Triage pull requests already open on the mirror (the redirect only covers new ones), then make the library's next release from the monorepo.

If the first split is rejected, the mirror is protected by something `absorb` doesn't manage:

- default branch is `master` — rename it: `gh api -X POST repos/utopia-php/<name>/branches/master/rename -f new_name=main`
- classic branch protection (not a ruleset) — let the app bypass it: `echo '{"bypass_pull_request_allowances":{"apps":["utopia-php-monorepo"]}}' | gh api -X PATCH repos/utopia-php/<name>/branches/main/protection/required_pull_request_reviews --input -`
