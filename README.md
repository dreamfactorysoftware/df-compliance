# df-compliance
DreamFactory Compliance Configuration Package

This is a service library for the DreamFactory platform containing API data compliance service and resources.
This is an add on to the DreamFactory Core library and requires the [df-core repository] (http://github.com/dreamfactorysoftware/df-core).


## Overview

DreamFactory is a secure, self-hosted enterprise data access platform that provides governed API access to any data source, connecting enterprise applications and on-prem LLMs with role-based access and identity passthrough.

## Root administrator

Admin management (creating, changing and removing administrators and assigning their roles) is reserved for the root administrator. Exactly one administrator is marked as root.

When the package's migrations run (for example when an open-source install moves to a commercial tier) and no administrator is marked as root yet, the active administrator with the lowest id is designated root automatically. If a root administrator already exists, or there are no active administrators, nothing changes.

To choose a different root administrator, run:

```bash
php artisan df:root_admin --admin_id=<id>
```

Without `--admin_id` the command lists the administrators and asks for an id (with a single administrator, that administrator is chosen). The previous root administrator is unmarked.
