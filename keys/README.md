# License signing keys

`license-public.pem` matches the public key in `includes/class-api-license.php`. It is safe to publish.

The private key is not in this repository and must not be placed in the plugin directory. Keep it at `~/.radius/license-private.pem` (or set `RADIUS_LICENSE_PRIVATE_KEY` to another path outside the web root). Back it up. It cannot be rebuilt from the public key.

Print a key from a terminal:

```bash
php bin/sign-license.php
```

Site owners paste that key. They do not run this script. A key already saved on a site keeps working until someone replaces it. A new or replaced key must be signed. There is no license server yet.
