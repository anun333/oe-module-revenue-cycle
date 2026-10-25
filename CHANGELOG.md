# Changelog

## 0.1.1 (unreleased)

- **Fixed: the look-back and the settings page stopped on OpenEMR 7.0.x.** They read form fields with `InputBag::getString()`, which Symfony added in 6.3, and OpenEMR 7.0.x ships Symfony 5.4. The module says it works on 7.0 and later, but it had only been checked on 8.4.1 and master. A fresh official 7.0.2 install found it: the look-back answered HTTP 500. Fields are now read the same way on both.
- **Checked:** fresh official 7.0.2 and 8.5.0 installs, with the look-back loaded and the settings saved as the page posts them; unit tests.

## 0.1.0 (2026-10-24)

- First version: the charge review. Seven checks on visits whose claim hasn't gone out, both directions (seen and not billed; a vaccine or in-clinic test given and not billed, and their mirrors; the same code twice; modifier 25 with nothing beside it; a charge with no diagnosis pointer), as a "Charges to check" tab in the Staff Worklist; a look-back over billed visits, priced from the clinic's own fee schedule, with what became of each flag; settings for the vaccine list, the in-clinic provider, the categories never billed, the start date and the days coding gets. It never changes a claim, never asks for documentation, and ships no CPT descriptions.
