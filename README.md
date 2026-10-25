# Revenue Cycle

> **Status: 0.1.0, a pre-release.** Tested on OpenEMR 8.4.1 and master with synthetic data only.

A charge review inside the clinic, before claims go out: care in the record that no charge bills, and charges the record doesn't support. Both directions, from the first version, because an internal audit that only ever finds money reads like a revenue tool, and one that finds both reads like what the HHS Office of Inspector General asks for: "regular internal billing and coding audits".

## What it does

- **Seven checks** on each visit whose claim hasn't gone out:
  1. seen and not billed: a documented visit, past the days coding gets, with no charge; or an appointment marked seen with no visit;
  2. a vaccine given here with no line for the product the clinic bills for it, or with no administration line;
  3. a test run in the clinic, resulted, with no line for its code;
  4. the mirrors: a vaccine product or administration line with no vaccine given, an in-clinic test billed with no result, charges on a visit with no forms and no signature;
  5. the same code twice on one visit, where units would carry the count;
  6. modifier 25 on a visit code with no other service beside it;
  7. a charge with no diagnosis pointer.
- **The queue** is a "Charges to check" tab in the [Staff Worklist](https://github.com/anun333/oe-module-staff-worklist), for whoever bills. Each item says what was found and opens the visit. Accept by fixing the fee sheet; the flag clears on its own. Dismiss with a reason, which the Worklist logs.
- **The look-back** runs the same checks over visits already billed, both directions, with each charge from the clinic's own fee schedule and, for a missed charge, the days left in the year a Medicare claim can be corrected. It counts what became of every flag: accepted, dismissed or open, by check and direction.
- **Settings:** when the review starts, how many days coding gets, the visit categories never billed, the procedure provider whose tests are run in the clinic, and the clinic's vaccine list (each CVX with the product code it bills). The vaccine rows start from what was given here.

## What it never does

- **It never changes a claim or the fee sheet.** The person who accepts a flag makes the change in OpenEMR's own fee sheet.
- **No flag asks for documentation.** A missed-charge flag rests only on what the record already holds; notes changed after the fact to support a code are what an audit should help a clinic avoid.
- **It never refunds or reports to a payer.** A charge with nothing behind it on a paid claim is for whoever handles compliance at the clinic (the 60-day rule, 42 CFR 401.305).
- **It ships no CPT descriptions.** `data/codes.json` names a few CPT numbers (vaccine administration, the visit codes modifier 25 attaches to) with labels in our own words; the clinic's own code table supplies the names.

## Who can open what

The page, the settings and the Worklist tab need OpenEMR's billing permission (`acct/bill`). A visit marked with a sensitivity shows only to users core's own sensitivity check allows. Viewing the look-back and saving settings are in the audit log.

## Not done yet

- The dashboard (0.2.0), on the shared library's figures contract.
- NCCI pairs and units for practices billing per service (0.3.0), from files the site imports.
- Health-center claim presets and charge capture (0.4.0).
- A CSV of the look-back.

## Install

From the OpenEMR directory:

```
composer require anun333/oe-module-revenue-cycle
```

The installer plugin places it in `interface/modules/custom_modules/oe-module-revenue-cycle/`, and Composer brings [openhealthcare-common](https://github.com/anun333/openhealthcare-common) with it. Without Composer, copy this directory there and run `composer install --no-dev` in it, which puts openhealthcare-common in its own `vendor/`; the module stays switched off until it finds that package one way or the other.

Then go to Modules › Manage Modules › Unregistered, and **Register**, **Install** and **Enable** it. Open **Revenue Cycle** › Settings, fill in your vaccine list and the in-clinic provider, and save. The Charges to check tab needs the Staff Worklist.

## Compatibility

Written for OpenEMR 7.0 and later through [openhealthcare-common](https://github.com/anun333/openhealthcare-common)'s compatibility layer; its bootstrap never stops a page on any version. Checked end to end on 8.4.1 and master, and on fresh official 7.0.2 and 8.5.0 installs with its pages loaded and its forms posted (from 0.1.1: 0.1.0's forms stopped on 7.0.x).

## Development

```
composer install
vendor/bin/phpunit
php tools/footprint.php --check
```

## Sources

- 42 CFR 401.305 (reporting and returning overpayments, the 60-day rule) and 424.44 (timely filing).
- HHS OIG, General Compliance Program Guidance (November 2023).
- CMS, National Correct Coding Initiative Policy Manual, chapter I, section D (modifier 25).
- CMS, Medicare Claims Processing Manual, chapter 9 (FQHC visits and vaccines).

## Codes and their terms

CPT is a registered trademark of the American Medical Association. No CPT descriptor is reproduced here; the labels in `data/codes.json` are ours (CC0). HCPCS Level II codes are published by CMS.

## Other modules for OpenEMR

See [openhealthcare-common](https://github.com/anun333/openhealthcare-common) for the modules that share it.

## License

GPL-3.0-or-later, as OpenEMR. See [LICENSE](LICENSE).
