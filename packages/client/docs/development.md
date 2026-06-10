# Development

The repository includes local copies of the relevant PSR and multipart RFC documents under `docs/`, with translated coverage requirements in [testing-requirements.md](testing-requirements.md).

```bash
composer install
composer audit
composer format:check
composer refactor:check
composer analyze
composer test
```

CI runs the same checks on pull requests and pushes to `main` for PHP 8.4 and 8.5.
