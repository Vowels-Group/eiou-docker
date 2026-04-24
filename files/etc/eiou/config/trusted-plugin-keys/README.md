# Baked-in trusted plugin signing keys

This directory is populated at build time and is **read-only at runtime**.
Any `*.pub` file dropped here trusts the holder of the corresponding
private key to publish plugins on this node. Only first-party keys that
ship with the image should live here.

Operators who want to trust a third-party signer should instead add
`*.pub` files to the config-volume directory:

    /etc/eiou/config/trusted-plugin-keys/

That directory is scanned in addition to this one on every plugin-load
pass. Removing a key there revokes trust without a rebuild.

See `docs/PLUGINS.md` → *Plugin Signatures* for the full operator guide.
