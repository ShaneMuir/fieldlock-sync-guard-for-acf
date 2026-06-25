# Development checks

Install the development tools and run every check with:

```sh
composer install
composer check
```

Build the WordPress.org submission archive with:

```sh
composer build
```

The archive is written to `dist/fieldlock-sync-guard-for-acf.zip`.

To enable the tracked pre-commit hook in this clone, run once:

```sh
composer hooks
```

Bitbucket Pipelines runs the same checks for every pushed commit. Configure a
branch restriction in Bitbucket requiring a successful pipeline before changes
can be merged into the release branch.
