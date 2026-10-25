# Revenue Cycle

> **Status: 0.1.0, a pre-release.** Tested on OpenEMR with synthetic data only.

Revenue Cycle for OpenEMR.

## What it does

- **Revenue Cycle** (menu, for users with `acct/bill`).

## Who can open what

The page needs `acct/bill` in OpenEMR's access control. Everything it does is in the audit log.

## Not done yet

## Install

From the OpenEMR directory:

```
composer require anun333/oe-module-revenue-cycle
```

The installer plugin places it in `interface/modules/custom_modules/oe-module-revenue-cycle/`, and Composer brings [openhealthcare-common](https://github.com/anun333/openhealthcare-common) with it. Without Composer, copy this directory there and run `composer install --no-dev` in it, which puts openhealthcare-common in its own `vendor/`; the module stays switched off until it finds that package one way or the other.

Then go to Modules › Manage Modules › Unregistered, and **Register**, **Install** and **Enable** it.

## Compatibility

Written for OpenEMR 7.0 and later through [openhealthcare-common](https://github.com/anun333/openhealthcare-common)'s compatibility layer; its bootstrap never stops a page on any version.

## Development

```
composer install
vendor/bin/phpunit
php tools/footprint.php --check
```

## Sources

## Other modules for OpenEMR

See [openhealthcare-common](https://github.com/anun333/openhealthcare-common) for the modules that share it.

## License

GPL-3.0-or-later, as OpenEMR. See [LICENSE](LICENSE).
