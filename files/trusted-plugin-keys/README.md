# Baked-in trusted plugin signing keys

This directory is populated at build time and is **read-only at runtime**.
Any `*.pub` file dropped here trusts the holder of the corresponding
private key to publish plugins on this node. Only first-party keys that
ship with the image should live here.

Operators who want to trust a third-party signer should instead add
`*.pub` files to the plugins-volume directory:

    /etc/eiou/plugins/trusted-keys/

That directory is scanned in addition to this one on every plugin-load
pass. Removing a key there revokes trust without a rebuild.

The runtime directory is **root-owned, mode 755** — readable by the
PHP process that verifies signatures, but not writable. Add `*.pub`
files with `docker cp` or `docker exec --user root`; never let a
plugin's own code write here.

See `docs/PLUGINS.md` → *Plugin Signatures* for the full operator guide.
