# JCORE Siirto

Migrate a WordPress site to another installation: themes, plugins, media, files and database, using only administrator accounts.

This file covers the repository and the development workflow. What the plugin does, and how to use it, is in [readme.txt](readme.txt).

## Requirements

- PHP 8.2+
- WordPress 6.7+
- Node 22+ and [pnpm](https://pnpm.io/)
- [Composer](https://getcomposer.org/) and [WP-CLI](https://wp-cli.org/) (WP-CLI is only needed for the translation targets)

## Getting started

```sh
pnpm install
composer install
pnpm build
```

Then run a throwaway WordPress with the plugin mounted:

```sh
pnpm playground
```

That serves [WordPress Playground](https://wordpress.org/playground/) on <http://localhost:8829> from `.wp/blueprint.json`, logged in as `admin` / `password`. Plugin Check is installed alongside it.

## Scripts

| Command                                  | What it does                                                 |
| ---------------------------------------- | ------------------------------------------------------------ |
| `pnpm build`                             | Build `src/` into `build/`.                                  |
| `pnpm start`                             | Same, in watch mode.                                         |
| `pnpm check`                             | Everything CI lints: ESLint, Stylelint and PHPCS.            |
| `pnpm lint:js` / `lint:css` / `lint:php` | One linter at a time.                                        |
| `pnpm format`                            | Format `src/` with `wp-scripts format`.                      |
| `composer lint:fix`                      | Fix what PHPCBF can fix.                                     |
| `pnpm i18n`                              | Regenerate the POT, the MO files and the JS translation JSON. |
| `pnpm playground`                        | Serve the plugin in WordPress Playground.                    |

A `Makefile` wraps the same scripts (`make ci` is the entry point the shared publish workflow calls); it is a shim, not a second build system.

## Layout

```
jcore-siirto.php      Plugin header, constants, autoloader, bootstrap
uninstall.php         Removes stored data on delete
includes/             Classes; Jcore\Siirto\Foo_Bar lives in includes/class-foo-bar.php
  class-crypto.php    Key format, request signatures, response encryption
  class-roots.php     Which directories may be read/written, and what is always excluded
  class-replacer.php  URL/path rewriting that walks serialized data without unserialize()
  source/             Keys and the signed endpoints a target reads from
  target/             Job state, client, file/database importers, finalize/rollback
  rest/               Admin REST routes for the React screen
src/admin/            The Tools → Site migration screen
languages/            .po sources; .pot, .mo and .json are generated
```

## How a migration works

The **target** (the site being overwritten) pulls from the **source**. Pulling means the
endpoints exposed to the network are read-only, and the decision to overwrite a site
is made by an administrator of that site.

1. On the source, an administrator re-enters their password and creates a **migration key**
   (`siirto1.<base64url>`): the source URL, a key ID and a 256-bit secret. It is shown once.
2. On the target, an administrator pastes the key. The target calls `hello` and shows what
   the source has.
3. The target copies the selected file roots into `wp-content/jcore-siirto-<random>/` and the
   database into tables named `jt<tag>_<table>`, rewriting URLs, paths and prefix-dependent
   keys (`wp_user_roles`, `wp_capabilities`…) as rows arrive. The browser drives this with
   short `step` requests, so no long request or WP-Cron is needed and closing the tab pauses it.
4. Nothing live has changed yet. The administrator types the site's host name and their
   password to **finalize**: staged top-level entries are `rename()`d into place (what they
   replace is moved aside) and the tables are swapped with one atomic `RENAME TABLE`
   (the replaced ones become `jo<tag>_<table>`). The source is told to revoke the key.
5. Until **Clean up**, everything replaced is kept and **Roll back** restores it.

The plugin's own tables (`*_jcore_siirto_*`) and options are never exported or replaced,
so job state survives the swap and the source's key never ends up on the target.

### Source endpoints

`POST /jcore-siirto/v1/source/{hello|list|read|schema|rows|finish}`, called with
`?rest_route=` so permalinks don't matter. These routes are **only registered while an
unexpired key exists**.

| Action   | Scope    | Returns                                                     |
| -------- | -------- | ----------------------------------------------------------- |
| `hello`  | any      | Versions, URLs, paths, table prefix, roots, table list      |
| `list`   | files    | One page of a directory (names, sizes, mtimes)              |
| `read`   | files    | Framed file contents or byte ranges, up to 4 MB per call    |
| `schema` | database | `SHOW CREATE TABLE`, columns, primary key                   |
| `rows`   | database | A batch of rows (keyset paging on single-column keys)       |
| `finish` | any      | Revokes the key                                             |

### Admin routes

| Route                                      | Purpose                                              |
| ------------------------------------------ | ---------------------------------------------------- |
| `GET /send`                                | Source checks, keys, audit log                       |
| `POST /send/keys`                          | Create a key (password required)                     |
| `DELETE /send/keys/{id}`                   | Revoke a key                                         |
| `GET /receive`                             | Target checks, current job, audit log                |
| `POST /receive/connect`                    | Validate a key against its source                    |
| `POST /receive/start`                      | Choose what to copy and start                        |
| `POST /receive/step`                       | Do a few seconds of copying                          |
| `POST /receive/resume`                     | Retry after a failure                                |
| `POST /receive/finalize`                   | Replace this site (password + typed host name)       |
| `POST /receive/rollback`                   | Undo finalize (password)                             |
| `POST /receive/cleanup`                    | Cancel, or delete what a finished job left behind    |

All admin routes require `manage_options` and are disabled on multisite.

## Security model

Anyone holding a valid key can read the whole source site, and finalizing overwrites the
whole target, so both ends are locked down:

- **Keys** are random 256-bit secrets, valid 1–72 hours, scoped to files and/or database,
  stored encrypted with a key derived from the `wp-config.php` salts, revoked when the
  migration finishes, and locked after 10 bad signatures. Creating one needs the
  administrator's password; the site admin email is notified when a key is first used.
- **Binding**: the first target to present a valid signature binds the key to its random
  client ID; a copy of the key used anywhere else is refused.
- **Requests** are signed with HMAC-SHA256 over the route, key ID, client ID, a strictly
  increasing sequence number, a timestamp (±5 min) and the body hash. Replays, reordering,
  changed bodies and redirected actions all fail. Redirects are not followed.
- **Responses** are encrypted and authenticated with XChaCha20-Poly1305 bound to the
  request (key ID, sequence, route), so they can't be read, altered or swapped in transit,
  even through a TLS-terminating proxy.
- **HTTPS** is required on the source. `define( 'JCORE_SIIRTO_ALLOW_HTTP', true );`
  lifts this for local development only.
- **Paths**: every path is relative to a fixed root, validated on both sides (no `..`,
  control characters, backslashes or absolute paths), and symlinks are never followed.
  `wp-config.php`, `.htaccess`, core files, caches, backups, logs (`debug.log`),
  host-specific drop-ins and this plugin's own directory are never transferred.
- **Composer packages** (`vendor/` in the WordPress root, where JCORE themes load Timber
  from) are a separate option, off by default and shown with a warning: they are PHP code
  that will run on the target, built for the source's PHP version.
- **Executable files** (`.php`, `.phtml`, `.phar`, `.htaccess`, `.user.ini`, double
  extensions such as `x.php.jpg`…) in uploads and other wp-content files are skipped by
  default and listed in the report: in those places they are usually backdoors.
- **Database**: only tables with the source's prefix are exported, never this plugin's.
  Serialized data is rewritten by a tokenizer, never `unserialize()`d, so imported data
  can't instantiate objects. Identifiers go through `%i`, values through `esc_sql()` or
  hex literals.
- **Finalize, rollback and key creation** require the user's password (5 tries per
  15 minutes); finalize also needs the host name typed in.
- **Audit log** of key creation, use, refusals, and every migration step on both sites,
  with IP addresses, kept 90 days.

Known limits: multisite is not supported; URL replacement is a plain string match (so
`old.com` would also match inside `old.com.au`); binary (BLOB) columns are copied as-is
without URL rewriting; the staging directory's deny rules only work on servers that read
`.htaccess`, so its name has 128 random bits.

## Releasing

Pushing to `main` runs `.github/workflows/release.yml`:

1. **check** – lint, build, then run Plugin Check against the tree minus `.distignore`. Also runs on pull requests.
2. **release** – [foonver](https://github.com/foonly/foonver) reads the conventional commits since the last tag, bumps the version, syncs it into `jcore-siirto.php`, `readme.txt` and `package.json`, writes the `== Changelog ==` section of `readme.txt` and pushes the tag.
3. **publish** – the reusable workflow in [jcore-update](https://github.com/JCO-Digital/jcore-update) builds the zip, attaches it to a GitHub release, registers the version with `update.jcore.fi`, pushes to the dist repository and posts to Slack.

Every commit that reaches `main` produces a release, so work on branches and merge through pull requests. Commit messages must follow [Conventional Commits](https://www.conventionalcommits.org/).

`.distignore` is the single list of what does not ship.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
