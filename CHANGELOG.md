# Changelog

## 0.1.6 (unreleased)

- **The vaccine list's fields have names a screen reader can read.** Each row's CVX code, code type and product code field is named with its column and row ("CVX code, row 2"), and the table's headers are marked as column headers. They had none, so a screen reader announced bare edit fields.
- **Pages declare the user's language.** Each page's `lang` and `dir` follow the signed-in user's OpenEMR language (openhealthcare-common 1.4.0), falling back to English where an older copy of the library was loaded first.
- **The CC0 text ships with the module:** [LICENSES/CC0-1.0.txt](LICENSES/CC0-1.0.txt), for the labels in `data/codes.json`, which the README already marked CC0.
- **The Worklist's Charges to check tab is quicker and no longer fills OpenEMR's audit log.** Each view wrote every flag it found, one statement per flag, and OpenEMR's audit log keeps a row for each statement. Now a view writes only what changed: a new flag, a cleared flag found again, a flag no longer found. Each statement carries up to 500 flags. A flag's `last_seen` now moves only when the flag is added or found again; nothing reads it.
- **The look-back is quicker.** Reading the visits' charges, vaccines and in-clinic tests now names the visits' patients as well, so each lookup uses core's patient index. Core indexes billing, immunizations and procedure_order by patient, not by visit.
- **The look-back lists 200 findings a page.** **List** shows every check or one, with **Previous** and **Next**. **Download this list as CSV** gives every row of the chosen list, with the page's columns. A cell beginning with `=`, `+`, `-` or `@` gets an apostrophe, so a spreadsheet doesn't read it as a formula. Each download is recorded in the audit log, with its count. Before, the page rendered every finding: 19 MB for a year with 56,030 findings.
- **Measured** on a throwaway copy of OpenEMR master with 200,000 visits and 557,375 charge lines, the old code against the new:

  | | old | new |
  |---|---|---|
  | Charges to check tab, first view | 287 s, 45,611 audit rows | 7.9 s, 92 audit rows |
  | the same tab, nothing changed | 198 s, 45,611 audit rows | 6.5 s, no audit rows |
  | the same tab, 300 flags added, cleared or found again | about 198 s, 45,911 audit rows | 6.6 s, 1 to 10 audit rows |
  | look-back over 2025, as computed | 26.0 s | 6.5 s |
  | look-back page over 2025, HTTPS | 27.8 s, 19.0 MB | 6.3 s, 75 KB |
  | CSV over 2025, HTTPS | none | 7.8 s, 7.5 MB, 56,030 rows |

  The vaccine and test lookups alone, over 2025's 143 chunks of visits, went from 1.46 s to 0.35 s and from 5.68 s to 0.46 s.
- **Checked:**
  - Results are identical. The old and new code gave the same tab items and the same stored flags after each of five views: all new, nothing changed, 300 lines losing their diagnosis, the diagnoses back, then lost again. They also gave the same look-back findings for 2025 and for 2026 so far.
  - The CSV matches the old page row for row, and each new page matches its slice of the old page.
  - Unit tests on PHP 8.2 and 8.5, including batches of 0, 500 and 501 flags, the page edges and the patient filters. 14 of the 15 new tests fail on the old code; the one that passes is the empty case.
- Requires openhealthcare-common 1.4.0. The README notes that hand-copied modules need their libraries at one version.

## 0.1.5 (2026-10-26)

- **Fixed: a vaccine recorded without choosing a visit got a false flag.** OpenEMR's Immunizations screen lets the visit be left empty. Such a vaccine was never matched to a visit, so its product and administration lines were flagged "billed with none given" (check 4), and check 2 never saw it. A vaccine given here with no visit chosen now counts toward the visit the patient had that day, when there was exactly one; on a day with two visits it's left unplaced rather than guessed. Our UDS module joins such records to a same-day visit the same way.
- **Checked:** new unit tests for the same-day rule fail on 0.1.4's code. Both new queries were run on the 8.4.1 and master databases, and on rows planted in a rolled-back transaction they found a vaccine with an empty visit (NULL and 0), left out a history entry, and found the day's one visit. Fresh official 7.0.2 and 8.5.0 installs load every page and post the settings form, and the end-to-end check passes on master.

## 0.1.4 (2026-10-26)

- **Check 8: a drug billed by its J code with no NDC.** A HCPCS line whose code starts with J and carries no NDC the claim would send is flagged as a claim quality problem, in the Worklist's Charges to check tab and in the look-back. Medicaid needs the NDC on drugs given in the clinic, to claim the manufacturer's rebate (Social Security Act 1927(a)(7), added by the Deficit Reduction Act of 2005, section 6002; CMS letter SMDL 06-016), and other payers often deny the line without it. "Carries" means what core puts on the claim: `billing.ndc_info` in the N4 format the fee sheet's NDC fields write, which is all `Claim::cptNDCID()` reads on 8.4.1 and master. A number typed in any other way goes out with no NDC, so it stays flagged. Core's fee sheet offers the NDC fields on HCPCS lines only, so only those are checked. **Settings** has **Check drug J-code lines for an NDC (check 8)**, on unless a site turns it off, for one that bills no payer needing NDCs.
- **The look-back prices a charge at the patient's own price level.** It used the "standard" level for every patient. It now uses the level on the patient's record (`patient_data.pricelevel`), as the fee sheet charges a new line on 8.4.1 and as core's lookup does on master (`FeeSheetChargeLookup`), keeping the code row 0.1.2 chose. A level with no price for the code gives no amount, as before. **Known limit:** a percentage price level isn't applied. OpenEMR 8.4.1's fee sheet applies one when Billing's `enable_percent_pricing` is on, but master's lookup doesn't, and the look-back follows master, so on 8.4.1 a patient on a percentage level shows no amount.
- **A staff guide**, `GUIDE.md`, by role and task: the biller working the Charges to check tab, whoever reviews billing with the look-back, and the administrator's settings. **Help** on the look-back and settings pages opens it, as written for the installed version, in a new window. A test fails if the guide quotes a label the pages no longer have (openhealthcare-common's `GuideLabels`).
- **Checked:** unit tests, including new ones for check 8, its setting, the price level and the guide, which fail on 0.1.3's code. Each new rule was also broken on purpose, and the tests caught it: an NDC field counting whatever it holds, the J-code rule on every code type, check 8 running with the setting off, the setting off by default, a fixed price level, a renamed label in the guide. The price and line queries were run on 8.4.1's and master's databases, and a two-level price, planted in a rolled-back transaction, came out at the patient's level (40.00) where the old query gave the standard one (100.00).

## 0.1.3 (2026-10-26)

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
