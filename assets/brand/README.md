# Branding

Whatever mark this installation shows in the top bar and on the login page
(XIV-48). **Nothing in here is committed** — the directory is gitignored except
for this file and the `.gitkeep` beside it.

Two reasons, and the second is the one that generalises:

- the logo Xivi itself ships with is AI-generated, and whether such a file is
  copyrightable at all differs by jurisdiction. Committing it would have the
  repository's `LICENSE` purport to grant rights nobody has established.
- an engine should not have a brand compiled into it. Anyone running this under
  their own name wants their own mark, and one baked into the image is one they
  cannot change without a build.

## Using one

Put a file here and name it in `APP_LOGO`:

```dotenv
APP_LOGO=logo.png
```

Unset — the default — means no logo, and every page falls back to the
installation's name in text. That is what a clean checkout does, and what CI and
the production image build do, so the fallback is the normal path rather than a
rare one.

## Keeping the file somewhere else

The dev stack mounts a directory over this one, so a logo can live outside the
project and never be copied into it:

```dotenv
# .env — docker compose reads this one
XIVI_BRAND_DIR=/home/you/Pictures
```

```dotenv
# .env.local — gitignored, and where the file name belongs
APP_LOGO=xivi_logo.png
```

**The two settings live in different files, and that is not tidiness.**
`XIVI_BRAND_DIR` is substituted by docker compose, which reads `.env` and the
shell environment and **never** `.env.local` — put it there and it is ignored
without telling you. `APP_LOGO` is read by the application, so `.env.local` is
exactly right for it.

Point the directory at one that exists: Docker creates a *directory* where a bind
mount's source is missing, which is a confusing way to find out about a typo.

Simplest of all is to put the file in this directory and set only `APP_LOGO` —
it is gitignored either way, so the file never enters the repository.

## In production

There is no logo in the image. Mount a directory at `/app/assets/brand`, or add a
`COPY` in a build of your own, and set `APP_LOGO`. Then run
`bin/console asset-map:compile` so the file is fingerprinted like every other
asset.
