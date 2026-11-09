# ProseMirror bundle (manual content pages editor)

`assets/vendor-pinned/prosemirror.bundle.js` is a **committed, pre-built** ESM
bundle of the ProseMirror modules the manual content-page editor
(`assets/controllers/manual_content_editor_controller.js`) imports by relative
path. It is deliberately NOT a set of importmap pins: `assets/vendor/` is
rebuilt from jsDelivr on every Docker build and jsDelivr's `+esm` output is not
deterministic (a crossvent build once 404'd every page's JS on prod — see
commit `0db3c0d`). One self-contained file has no bare imports for AssetMapper
to rewrite and cannot change under us.

- `entry.js` — what the bundle exports (add an export here when the editor
  needs another ProseMirror API).
- `package.json` / `package-lock.json` — the EXACT versions; the bundle header
  lists them too.
- `build.sh` — `npm ci` + esbuild (`--format=esm --minify`), writes the bundle,
  removes `node_modules`. Needs node/npm on the host, not in the PHP container.

## Rebuild / upgrade

```bash
cd resources/prosemirror-bundle
npm install --save-exact prosemirror-view@<new>   # only when upgrading
./build.sh
git add package.json package-lock.json ../../assets/vendor-pinned/prosemirror.bundle.js
```

Then clear the dev asset cache (`bin/console cache:clear`) and re-test the
editor (`/manual/{id}/uvodni-stranka`): typing, marks, lists, links, undo.
