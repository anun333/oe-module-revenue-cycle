# Revenue Cycle: staff guide

For the staff who use the module day to day, task by task. What it checks and why, and how to install it, are in the [README](README.md).

The module finds; it never changes a claim or the fee sheet. Whoever accepts a finding makes the change in OpenEMR's own fee sheet. It has been tested only on made-up patients.

## The biller

### Work the Charges to check tab

*Where:* the Staff Worklist, on the **Charges to check** tab, for users with OpenEMR's billing permission. The tab needs the Staff Worklist module.

Each item is one finding on one visit whose claim hasn't gone out yet: what was found, then whether it's a **Missed charge**, a **Charge with nothing behind it** or a **Claim quality** problem, the check's number and the visit's date.

1. Choose **Open the visit** to see the fee sheet and the record. An appointment marked seen with no visit opens with **Open chart**.
2. If the finding is right, fix the fee sheet in OpenEMR: add the missed line, remove the line with nothing behind it, or fill in what the line lacks. The item clears by itself the next time the tab is read.
3. If it isn't (a repeat the clinic intends, a visit billed some other way), dismiss it in the Worklist with a reason. It stays dismissed, and the look-back counts it as dismissed.

What each check says, and where to look:

| Check | The item says | Look at |
|---|---|---|
| 1 | Documented some days ago with no charge, or an appointment marked seen with no visit for that day | Whether the visit should be billed, or the visit is missing |
| 2 | A vaccine given with no line for the product your vaccine list bills for it, or no administration line | The fee sheet's vaccine and administration lines |
| 3 | An in-clinic test resulted with no line for it | The test's line on the fee sheet |
| 4 | A line with nothing behind it: a vaccine billed with none given, an in-clinic test billed with no result, or charges on a visit with no forms and no signature | The record first; the line may be a mistake |
| 5 | The same code on two lines | Whether units should carry the count |
| 6 | Modifier 25 with no other service on the visit | Whether the modifier belongs |
| 7 | A line with no diagnosis pointer | The line's justify column |
| 8 | A drug billed by its J code with no NDC (new in 0.1.4) | The line's NDC fields on the fee sheet: the number, unit and quantity from the package |
| 9 | A referring provider on the claim with no NPI, and where the claim takes that provider from: Misc Billing Options box 17, the visit form, or Demographics (new in 0.1.8) | That user's NPI in Administration › Users, or a referring provider with an NPI where the item says the claim takes it from |

On check 8: Medicaid needs the NDC on drugs given in the clinic, and other payers often deny the line without it. An NDC typed into the line any other way than the fee sheet's NDC fields doesn't reach the claim, so the line stays flagged.

On check 9: only a visit with a charge, for a patient with a primary insurance in force on the visit date, is checked, since only that visit's claim goes to a payer. OpenEMR's Demographics lets you pick a user with no NPI as the referring provider; the visit form's list leaves such a user out, so a new visit shows no referring provider and the claim falls back to the one in Demographics, and goes out with the name and no NPI. When your site sends the rendering provider as the Medicare referrer, the claim carries no referring provider of its own, and this check leaves it alone.

On confidential Title X visits: when the Title X Report module is installed and names the visit categories it uses for confidential services, checks 8 and 9 leave those visits alone, since both exist to get a claim through to an insurer. Their charges are still checked by the others. Title X Report's own tab in the Staff Worklist covers a confidential visit that's queued or billed to insurance.

*What it doesn't do:* it never names a code to add and never asks for documentation. A missed charge rests only on what the record already holds.

## The person who reviews billing

### Look back over billed visits

*Where:* the **Revenue Cycle** menu.

1. Set **From** and **To**, and choose **Show**. The period starts at 1 January of this year.
2. Read the counts by direction: **Missed charge**, **Charge with nothing behind it** and **Claim quality**, with the charges they concern.
3. Each finding is a row: **Patient**, **Visit**, **Check**, **Found**, **Charge** and **Days left to correct**.
   - **Charge** comes from your own fee schedule, at the patient's own price level, the way the fee sheet charges them (new in 0.1.4; earlier versions used the standard level for everyone). It's a charge, not an expected payment. It's blank where the patient's level has no price for the code.
   - **Days left to correct** is shown for a missed charge: the days left in the year a Medicare claim can still be corrected.
4. The list shows 200 findings a page; **Previous** and **Next** move between pages. To see one check, choose it under **List** (each shows how many it found) and choose **Show**. The counts above the list always cover every check.
5. **Download this list as CSV** saves every row of the list you're looking at, all checks or the one chosen, for a spreadsheet. The download is recorded in the audit log, like viewing the page.
6. Under **What became of the flags first found in this period**, each check's flags are counted as **Found**, **Accepted**, **Dismissed** and **Open**. Accepted means the finding cleared after the fee sheet changed.

*What it doesn't do:* it never refunds or reports to a payer. A charge with nothing behind it on a paid claim is for whoever handles compliance at the clinic (the 60-day rule).

## The administrator

### Set up the review

*Where:* the **Revenue Cycle** page, then **Settings**.

1. **Check visits from**: the first visit date the Worklist tab checks.
2. **Days coding gets before a visit counts as not billed**: check 1 waits this long.
3. **Visit categories never billed (a phone note, a chart update)**: visits in these categories are never flagged.
4. **Procedure providers whose tests are run in the clinic**: their orders feed checks 3 and 4.
5. **Your vaccine list**: each vaccine you stock by **CVX**, with the **Code type** and **Product code** of the line you bill for it. A vaccine given here that isn't on the list is never flagged. A vaccine recorded without choosing a visit counts toward the patient's visit that day when there was only one; on a day with two visits, pick the visit on the Immunizations screen so the checks can see it.
6. **Check drug J-code lines for an NDC (check 8)**: on unless you turn it off. Turn it off if no payer you bill needs an NDC on drug lines; the lines are then never flagged for it.
7. Choose **Save**. **Back to the charge review** returns to the look-back.

Patients' price levels and the prices at each level are OpenEMR's own: the price level on the patient's Demographics, and the fee schedule's prices by level. A percentage price level isn't applied: a patient on one shows no amount in the look-back (see the README's known limits).

### Find this guide

Each module page has **Help**, which opens this guide, as written for the version you have installed, in a new window.
