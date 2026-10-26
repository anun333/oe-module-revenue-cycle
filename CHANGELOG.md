# Changelog

## 0.1.3 (unreleased)

- **Text and buttons are easier to read on this module's pages.** OpenEMR's blue (#007bff) is 3.97:1 on white, under the 4.5:1 that WCAG 2.1 AA asks of normal text and that HHS's Section 504 rule requires from 2027-05-11. Under OpenEMR's light and manila themes, the module's links and primary buttons now use #0056b3 (7.0:1), and muted and warning text darker shades that stay readable on tinted rows, and a link inside text is underlined; the dark, solar and colour themes keep their own colours, and OpenEMR's own pages are unchanged.

## 0.1.2 (2026-10-25)

- **Fixed: on OpenEMR 7.0.4 and 8.0.0 to 8.0.0.3, enabling this module stopped every OpenEMR page, the login page included (HTTP 500).** Those releases have `OEGlobalsBag` without `getProjectDir()` and the other getters, which came with 8.1.0, and the module called them wherever the class existed. It now reads the values OpenEMR stores there, and openhealthcare-common 1.3.3 does the same for the web root, the kernel and the session (8.0.x's session factory has only `getWrapper()`). Checked on fresh installs of the official 7.0.2, 7.0.4 and 8.0.0 images with every module enabled, and on 8.4.1 and master.
- **Fixed: the look-back counted flags on visits that were deleted.** A visit deleted for the wrong patient or as a duplicate is never scanned again, so its open flag stayed "open" in the look-back for good. Flags now count only while their visit is there, and a visit deleted from its encounter (OpenEMR marks its visit form deleted rather than removing it) is no longer scanned. An appointment's flag, which has no visit, counts as before.
- **Fixed: a missed charge could be priced from the wrong code row.** With several rows for one code (an old inactive one, one with a modifier such as JZ), the look-back took whichever came first. It now takes the row OpenEMR's fee sheet lookup picks since #14719: an active row first, then the one without a modifier, then the oldest. A code whose only row has a modifier now gets that row's price; one whose chosen row has no price at the level gets none, as the fee sheet would.
- **"Seen" includes the fee sheet's after-save status.** OpenEMR 8.5.0's Billing setting **Fee Sheet Save: Ask to Set Appointment Status** (#14790) names the status coders set once a visit is coded, such as `$`. When a site sets it, an appointment with that status counts as seen for the "seen and not billed" check. A canceled, no-show or "left without visit" status never does. Unset, or before 8.5.0, the statuses are the same as before.
- **Dismissals are read from the Staff Worklist only when its table has the columns the count reads,** rather than whenever the table exists.
- **Unchanged after a look at #14330** (OpenEMR 8.5.0 multiplies a newly picked code's price by its units on the fee sheet): an over-charge still sums each line's stored total, which is the charge as billed on 8.4 and 8.5 alike, and the missed charges priced (a vaccine product, an in-clinic test) are one unit each.
- Requires openhealthcare-common 1.3, whose footprint sheet names the Staff Worklist's table as the Worklist's instead of OpenEMR's.
- **Checked:** unit tests on PHP 8.2 and 8.5, including new ones for the statuses, the price row and the deleted visits, which fail on 0.1.1's code.

## 0.1.1 (2026-10-25)

- **Fixed: the look-back and the settings page stopped on OpenEMR 7.0.x.** They read form fields with `InputBag::getString()`, which Symfony added in 6.3, and OpenEMR 7.0.x ships Symfony 5.4. The module says it works on 7.0 and later, but it had only been checked on 8.4.1 and master. A fresh official 7.0.2 install found it: the look-back answered HTTP 500. Fields are now read the same way on both.
- **Checked:** fresh official 7.0.2 and 8.5.0 installs, with the look-back loaded and the settings saved as the page posts them; unit tests.

## 0.1.0 (2026-10-24)

- First version: the charge review. Seven checks on visits whose claim hasn't gone out, both directions (seen and not billed; a vaccine or in-clinic test given and not billed, and their mirrors; the same code twice; modifier 25 with nothing beside it; a charge with no diagnosis pointer), as a "Charges to check" tab in the Staff Worklist; a look-back over billed visits, priced from the clinic's own fee schedule, with what became of each flag; settings for the vaccine list, the in-clinic provider, the categories never billed, the start date and the days coding gets. It never changes a claim, never asks for documentation, and ships no CPT descriptions.
