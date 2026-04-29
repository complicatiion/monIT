# Third-Party Licenses

This project may include or optionally use third-party runtime components to provide a portable, self-contained experience.

The third-party components listed below are not owned by this project. They remain the property of their respective copyright holders and are distributed under their own license terms.

This file is intended to document the third-party components used by this project. Full license texts should be included in this repository or in the downloadable release package.

## Included / Optional Third-Party Components

| Component | Purpose | License | Included in repository | Included in release package |
|---|---|---|---|---|
| PHP | Portable local web/runtime environment | PHP License / BSD-style license, depending on version | No | Optional |
| Net-SNMP | SNMP tools and utilities | BSD-style / multi-part Net-SNMP license | No | Optional |

## PHP

PHP may be used by this project as a portable runtime for running the local web interface or backend scripts.

PHP is developed and maintained by The PHP Group and contributors.

- Project: PHP
- Website: https://www.php.net/
- License information: https://www.php.net/license/
- Distribution guidelines: https://www.php.net/license/distrib-guidelines-code.php

If PHP is bundled with a binary or portable release of this project, the full PHP license text must be included with that release package.

Recommended location:

```text
third_party_licenses/PHP-LICENSE.txt
```

This project does not claim ownership of PHP and is not endorsed by The PHP Group.

## Net-SNMP

Net-SNMP may be used by this project to perform SNMP queries, collect device information, or provide SNMP-related monitoring functionality.

Net-SNMP is developed and maintained by the Net-SNMP project and its contributors.

- Project: Net-SNMP
- Website: https://www.net-snmp.org/
- License / copying information: https://github.com/net-snmp/net-snmp/blob/master/COPYING

If Net-SNMP binaries or tools are bundled with a binary or portable release of this project, the full Net-SNMP license / COPYING text must be included with that release package.

Recommended location:

```text
third_party_licenses/NET-SNMP-COPYING.txt
```

This project does not claim ownership of Net-SNMP and is not endorsed by the Net-SNMP project or its contributors.

## Release Packaging Notice

If this project is published as a portable release package, for example:

```text
project-name-portable-win64.zip
```

the package should include the following structure:

```text
project-name/
├─ app/
├─ public/
├─ runtime/
│  ├─ php/
│  └─ net-snmp/
├─ third_party_licenses/
│  ├─ PHP-LICENSE.txt
│  └─ NET-SNMP-COPYING.txt
├─ THIRD_PARTY_LICENSES.md
├─ LICENSE.md
└─ README.md
```

The `runtime/` directory may be omitted from the source repository and provided only in release packages.

## No Warranty for Third-Party Components

Third-party components are provided under their own license terms and warranty disclaimers.

This project provides no additional warranty, support promise, or liability coverage for PHP, Net-SNMP, or any other third-party component bundled with or referenced by this project.

## Notes for Maintainers

Before publishing a release package:

1. Verify the exact PHP version included.
2. Verify the exact Net-SNMP version included.
3. Include the matching license files.
4. Do not remove copyright notices.
5. Do not imply endorsement by PHP, Net-SNMP, or their contributors.
6. Prefer GitHub Releases for bundled binaries instead of committing runtime binaries directly into the source repository.
