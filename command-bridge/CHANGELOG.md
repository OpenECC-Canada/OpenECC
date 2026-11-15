# Changelog

## Unreleased

* README files and the page footer now say plainly that Command Bridge is provided as is, with no warranty,
  and that you use it at your own risk (AGPL-3.0 sections 15 and 16).

### Fixed
* Phones and tablets: the side menu can now be scrolled all the way down to Logout. It was sized to the full screen
  height including the browser's toolbars, which hid the last links.

### Wording: Command Bridge is an ECC support system
* README files now describe Command Bridge as an Emergency Coordination Centre (ECC) support system with
  ICS-compatible records, not as software for running the on-scene ICS response. A new "What Command Bridge is (and
  is not)" section covers ICS positions vs ECC roles, working without the internet (but not without its own server
  and network), and the limited scope of the check-in gateway.
* Help for the Position catalogue explains keeping ICS positions and locally added ECC roles apart.
* README explains that the ECC uses the ICS organization for its own staff: the Incident Commander and Section
  Chiefs in Command Bridge are the ECC's, not the on-scene IC or field IMT.
* Form help says the forms are the ECC's records (Working with ICS forms, ICS 201, 207 and 211).
* ICS 211: Check-In Location no longer defaults to ICP; nothing is ticked until you choose. Gateway check-ins with no
  location are recorded as "Incident Check-In" instead of "ICP".
* "ICS application" wording replaced with "Command Bridge" on the Settings page, the Microsoft sign-in error and the
  Entra setup notes.

* **Lookup lists are used on forms now.** The values in Settings > Lookups are suggested as you type, and free text
  still works:
  - **Agencies:** ICS 211 Home Unit / Agency, position assignments, and the ICS 213RR and Resources supplier boxes
  - **Jurisdictions:** ICS 209 blocks 20 and 21
  - **Incident Types:** incident setup
  - **Facility Types:** the Facilities page
* **Lookups page:** each list now says where it's used. Adding a value that already exists shows a message instead
  of an error.
* **Retired lists:** Facilities, Resource Kinds, Resource Types and Positions are retired from the page; they're
  kept in the Facilities page, Resource Catalogue and Role Catalogue.
* **Starter lists:** optional, province-specific, loaded from the Lookups page. The first is **Alberta** (24 common
  agencies, names checked November 2026). They aren't in the seed, because agencies differ by province. Review them
  twice a year with the ICS Canada forms check.
* **Contact details for Agencies and Jurisdictions:** main phone, 24/7 line, email, website, notes, and when they
  were last verified (and by whom), set on the Lookups page with a new **Edit** button. Organization contacts only:
  the help explains why a named person's own number belongs on the incident's ICS 205A instead.
  * **Agency Directory:** a new page, readable by anyone signed in. It's searchable, has tap-to-call numbers, and
    flags details not checked in 12 months.
  * **Suggestions:** when picking an agency in a suggestion box, its phone numbers show next to the name.
  * **Starter lists:** they don't include phone numbers; you add and verify them.
* **Database:** migration `0018_lookup_lists.sql` adds default Incident Types (also in seed.sql);
  `0019_lookup_contacts.sql` adds the contact columns on `lookup_list_items` (also in schema.sql). Help for Lookups and the Agency Directory written. Smoke test
  extended.
* **Facilities (#44): check-in locations become the incident facility list.**
  - **New Facilities page:** replaces Settings > Check-in Locations, which now redirects there. It lists every
    facility: ECC, reception centres, staging areas, camps and so on.
  - **Facility details:** type (from an admin-editable "Facility Types" lookup list), address, map position,
    capacity, hours, contact, services, accessibility and notes. Each facility is Planned, Open, Closed or Archived,
    and the open and close times are recorded.
  - **Check-in points:** only facilities marked as check-in points are used for Companion Check-In, the ICS 213RR
    receiving location and the Check-In Gateway. Existing locations stay check-in points.
  - **Privacy:** contact details and notes are never sent to the gateway.
  - **Access:** managed by the Facilities Unit Leader, LSC, PSC and IC (Form Permissions: FACILITIES). Everyone in
    the incident can read it.
  - **Elsewhere:** open facilities are suggested in the ICS 204, 215 and 230 location boxes, and there's a new
    **Open facilities** dashboard panel.
* **Staging board (#45).** A new Staging page for the Staging Area Manager.
  - **The board:** every received resource by facility, set to Available, Assigned (to what), Out of service or
    Released, with notes. Every change is kept with who made it and when.
  - **Access:** Operations, the Resources Unit and IC can update it; others can view it (Form Permissions: STAGING).
  - **Dashboard:** a new **Staging** panel with counts per facility.
* **Database:** migration `0017_facilities_staging.sql`:
  - facility columns on `external_checkin_locations`
  - tables `resource_staging` and `resource_staging_history`
  - the FACILITIES and STAGING page permissions
  - the "Facility Types" defaults
  - dashboard panels for FAC and SAM

  Matching schema.sql and seed.sql. Help added for Facilities and Staging. Smoke test extended, including a check
  that facility contact details aren't sent to the gateway.
* **Fix: Check-In Recorder and Staging Area Manager have a section and a supervisor.** The app and the Check-In
  Gateway use the codes CIR and SAM, but the position list only had them as inactive CHECK_IN_RECORDER /
  STAGING_AREA_MANAGER entries, so CIR and SAM had no section and no supervisor (their ICS 214 went to the IC). CIR is
  now in Planning, reporting to the Resources Unit Leader; SAM is in Operations, reporting to the Operations Section
  Chief. Any assignments on the old long-code roles are moved to CIR/SAM. **Database:** migration
  `0016_cir_sam_positions.sql`, with matching seed.sql.
* **Fix: the Supply Unit Leader (SPUL) can now be assigned.** The app already used SPUL (the Logistics queue, the
  ICS 213RR "order placed by" box, the ICS 203 and 207), but it was missing from the position list. It's added under
  Logistics (reports to the LSC), with edit access to the ICS 213RR like the rest of Logistics. **Database:** migration
  `0015_supply_unit_leader.sql`, with matching seed.sql.
* **Print forms straight to a network printer.** A **Print** button next to **Download PDF** sends the form to a
  printer on your network. Nothing needs installing on anyone's computer, and it works from phones and tablets.
  - **Adding printers:** printers are added on the server with the new `tools/setup_printer.sh`, which has screens and
    a menu like the Check-In Gateway setup. It checks for CUPS and offers to install it, then adds, lists, tests or
    removes printers. Printing goes straight to the printer's IP address: driverless (IPP Everywhere) first, generic
    PostScript as a fallback, with Letter paper and fit-to-page. The same steps also work from the command line.
  - **Settings > Printers:** a friendly name for each printer, which ones people can pick, who may print (anyone in
    the incident by default) and a test page.
  - **Same PDF as the download:** the print is exactly what Download PDF makes, run as the person printing, so the
    same permissions apply. Each job is in the audit log. Printing is off in the demo.
  - **Where the button is:** every form's banner and Form Actions, the ICS 213 and 213RR, the ICS 233, and the
    ICS 221 in Resources.
  - **Database:** migration `0014_server_printing.sql` (settings `print_access` and `printer_labels`) and seed.sql.
    No schema change. Help for Printers and Print added. Smoke test extended.
* **ICS 221 Demobilization Check-Out from the Resources Unit page.** An **ICS 221** button next to each received
  resource (and on the resource itself) prints the official form.
  - **Filled in:** the incident, the resource, a demob number and any release details recorded.
  - **Ticked:** the units that must sign the resource out (Ground Support only for vehicles and equipment). The rest
    is completed by hand.
  - **Access:** the Demobilization Unit Leader and Planning Section Chief can now open the Resources Unit page (read
    only) to print them.
* **Fix: recording a resource's receipt could fail.** The receipt adds the resource to the demobilization tracker
  with a status the database column didn't allow, so MariaDB refused it.
* **Database:** migration `0013_ics221_demob_checkout.sql` (demob tracker status, Resources Unit read access for DMOB
  and PSC), with matching schema.sql and seed.sql. Help and smoke test updated.
* **New form: ICS 215 Operational Planning Worksheet.** The Operations Section Chief's plan for the next operational
  period.
  - **Resources:** up to 12 resource columns. For each work assignment, enter the resources required and on hand, and
    "Need" is worked out. The totals (blocks 11-13) add up every assignment.
  - **Sign-off:** signed by the preparer (usually the OSC) from the banner and approved by the Planning Section Chief,
    like the other forms. LSC, FSC, SOFR, RESL, SITL and DOCL can read it.
  - **PDF:** fills the official ICS 215, 6 assignments a page, with a continuation sheet for long text.
  - **Database:** migration `0012_ics215_planning_worksheet.sql` (tables `form_215_headers` and `form_215_rows`, the
    form, its definition and permissions), with matching schema.sql and seed.sql. Help and smoke test added (including
    the check that every PDF field exists on the template).
* **New form: ICS 233 Open Action Tracker.** It tracks tasks the IC gives out that aren't incident objectives.
  - **One list per incident:** open actions carry over between operational periods.
  - **Kept by the PSC:** the Planning Section Chief keeps it, along with the IC, Unified Command, Deputy IC and
    Documentation Unit Leader. Everyone in the incident can read it.
  - **The POC is notified:** "For / POC" is a position, and everyone holding it gets a dashboard notice.
  - **The POC updates it:** they mark it briefed, update the status and mark it completed, and whoever added it is
    told when it's done.
  - **Due dates:** rows turn yellow the day before the target date and red when it's overdue.
  - **PDF:** prints on the official ICS 233. It has no fillable fields, so the text is laid over the form. It can
    print the open actions only, or include completed ones.
  - **Database:** migration `0011_ics233_action_tracker.sql` (table `form_233_actions`, the form, its definition and
    permissions), with matching schema.sql and seed.sql. Help and smoke test added.
* **The side menu groups forms by position.** Instead of one long list, each person sees:
  - **Your forms**: forms their position can create or edit
  - **Action plan**: the Incident Action Plan forms they can read
  - **More forms**: everything else they can open, folded closed

  The groups come from Form Permissions, so they follow any change made there. The group holding the current page
  opens, and each browser remembers which groups were opened. This works the same in the phone and tablet menu drawer.
* **Default form access fixed for positions that could see almost nothing.** On a new install the Logistics and
  Finance/Admin Section Chiefs couldn't open the ICS 202 or 204, and only the IC, Operations, Planning and General Staff
  could write an ICS 213. New role **INCIDENT_POSITION** means anyone holding a position in the incident. Rights added:
  - everyone in the incident: read the action plan forms (201, 202, 203, 204, 205, 206, 207, 208, 215A, 230) and
    write an ICS 213
  - Unified Command: the same as the IC. Deputy IC: read and edit every form.
  - Documentation Unit Leader: read every form
  - Logistics Section Chief: edit the 205, 205A, 206 and 218, edit the 213RR, read the 209, 210 and 211
  - Finance/Admin Section Chief: read the 209, 210, 211 and 218. Cost, Time and Compensation unit leaders: the
    Finance/Admin queue, and read the 213RR, 211 and 218
  - Situation Unit Leader: read the 205A, 210 and 211. PIO and Liaison: read the 209. Safety: read the 209 and 210.

  No existing right is removed. **Database:** migration `0010_form_access_by_position.sql` and the same rows in
  seed.sql. Help for Form Permissions written. Smoke test extended.
* **The installer and updater now say what each step is.** The progress box read "Step 1 of 4" but the step's name was
  cut off below it. The name is now in the box title and on the first line, and the updater also shows each database
  migration as it runs. The same change is in the Check-In Gateway's installer and updater (`deploy/lib/ui.sh`).
* **ICS 213 messages reach the person they are for, in the app or by email.** "2. To" used to be free text only,
  so nobody was told about a message.
  - **Dashboard:** pick the "To" position from those filled in the incident. Everyone holding it gets a notice under
    **My action items**; opening the message clears it. The sender is notified of the first reply.
  - **Email:** when writing a message you can also email it:
    - to the people holding the "To" position (tick "Also email", or always, if an admin chooses that under
      Email Delivery > ICS 213 messages)
    - to contacts with an email on this period's ICS 205A
    - to any typed addresses

    The email has the incident and a reference code (`CB-213-XXXXX`) in the subject, and the full message as plain
    text in the body. The official ICS 213 is attached as a PDF (the form itself is not changed). Reply-To is the
    sender's own email.
  - **Writing tips:** a note above "6. Message" explains who will see the message and asks the sender to say how to
    reply, with examples.
  - **Each message keeps a send log:** who it went to, sent or failed, when, by whom. **Resend** retries a failed
    email; **Send to more people** emails it later.
  - **Replies that come back by email are typed in by hand.** **Find a message** on the list searches by reference
    code, and the reply records "received by email" and the address it came from. Command Bridge does not read any
    mailbox.
  - **ICS 205A:** phone, email and notes now have their own columns, and the email is checked. **Fix:** the 205A
    Notes box was never saved.
  - **Email:** the app's email sender can now attach files and set Reply-To (SMTP and Microsoft Graph). Subjects
    with accented letters are encoded properly.
  - **Database:**
    - migrations `0007_ics213_addressee.sql` (`to_role_code`, `from_user_id`) and `0008_ics213_email.sql`
      (`ref_code`, reply-received fields, the `form_213_sends` table, the 205A phone/email/notes columns, filled in
      from existing entries)
    - the `ics213_email_position_holders` setting in seed.sql
  - New file `includes/ics213_messages.php`. Help updated for the ICS 213, ICS 205A and Email Delivery. Smoke test
    extended.
* **ICS 213: sign and send, with approval before anything is emailed.** The 213 no longer uses the general
  form sign-off.
  - **No Save button.** A message that only goes to the dashboard is sent with **Sign and Send ICS 213**: it records
    the sender and time, notifies the "To" position and locks. Block 7 stays optional.
  - **An emailed message is approved first** (**Sign and Request Approval**), because an email can't be recalled. It
    goes to the sender's supervisor (falling back to the IC), and the sender can pick another approver. The approver
    sees the message and recipients and can **Approve and Send** (fills Block 7 with their name, position and time),
    **Return for changes** with a reason, or **Cancel**. The approver can't edit the message. Nothing is sent until
    it is approved.
  - **The IC's own messages** are sent straight away, with the IC in Block 7.
  - **After sending, only the reply can be changed** (**Save reply**).
  - **Statuses:** Awaiting approval, Returned, Sent, Replied, Cancelled, shown on the list and the message.
  - **Send to more people** also needs approval unless the IC sends it. **Resend** stays.
  - **The send log says "Accepted by mail server"**, not "sent": bounces go to the app's sending mailbox. The help
    says so.
  - **Removed from the 213:** the form status banner and sign-off buttons. The 213 no longer appears in the
    dashboard's forms-to-sign lists or the operational period closeout.
  - **Database:** migration `0009_ics213_approval.sql` adds the status, signing, approval and sent columns to
    `form_213_messages` and `approver_user_id` to `form_213_sends`. Existing messages become Sent.
* **Fix: the ICS 202 could not be approved while it was waiting for approval.** The new lock refused the Incident
  Commander's **Approve** ("ICS 202 has been signed and is waiting for approval, so it is locked"). Approving is
  now allowed on a locked ICS 202, as it already was for the ICS 204.
* **Fix: the ICS 203, 205, 206, 208 and 210 locked with no signature and no approver.** Their own **Submit**
  button marked the form submitted, but it didn't record who signed it or pick an approver, so nobody was notified.
  They now sign and route to the approver like the other forms: new `signoff_record_direct_submit()` in
  `includes/signoff.php`. The smoke test checks both fixes.
* **Dashboard panels per position.** The dashboard is now made of panels, and each position sees the ones that
  matter to it:
  - **Everyone:** My action items, My forms, My ICS 214 and Scheduled meetings.
    - **My forms** is new. It shows forms waiting for your approval, your unsigned forms this operational period,
      and copied forms assigned to you.
    - **My ICS 214** is new.
  - **IC/UC, Deputy IC, PSC:** also operational period form status, forms to submit before the period ends,
    staffing, and copied forms to review.
  - **SITL and DOCL:** also the form status, closeout and review panels.
  - **RESL:** also the Resources Unit intake (a count) and staffing. **OSC:** also staffing.
  - **LSC and Supply:** resource requests waiting for Logistics. These used to be mixed into the notifications.
  - **FSC, Procurement and Cost:** a Finance/Admin review count.

  Someone holding several positions gets every panel from each one. SysAdmins see everything. **Show everything**
  on the dashboard shows all panels to anyone; panels don't change what people may open. The operational period
  buttons moved to the top of the dashboard, so they stay visible even if the meetings panel is hidden.
  - **New settings page: Settings > Dashboard Panels** (`admin/dashboard_settings.php`). A grid of positions and
    panels, with Save and Reset to defaults; changes are audit logged.
  - Database: the defaults are stored in `app_settings.dashboard_panel_map`, in `seed.sql` and the new migration
    `0006_dashboard_panels.sql` (which keeps a map an administrator has already saved).
  - The dashboard help page is written. The 213RR Logistics items now show their real "updated" time.
* **Phones and tablets, part 3: every other page.** The rest of the app now works on phones.
  - **More tables become labelled cards on phones:**
    - the editable rows on the ICS 201 (actions, resources), 204 (resources), 205 (channels), 206 (aid stations,
      ambulances, hospitals), 210 (status changes) and 215A (risk rows); rows added with the "Add row" buttons are
      labelled as they appear
    - the lists on the ICS 201/202 attachments, 203, 205A, 207 and 218
    - the Resources Unit queues, the Finance/Admin queue and the audit log
    - position assignments
    - the admin lists: users, roles catalogue, lookups, locations, resource catalogue, sign-off approvers, sync status
  - **Two- and four-column layouts drop to one column on phones:** ICS 209, 214, 215A, the 213RR, the role and
    resource catalogues, and filter bars. Date pickers stay on screen.
  - Grids that only make sense as grids stay as sideways-scrolling boxes: Form Permissions, and the ICS 209
    casualty and resource tables.

  The ECC displays, printing and the PDFs are unchanged. No database changes.
* **Phones and tablets, part 2: lists as cards.** On phones, list tables turn into one card per row, and each value
  is labelled with its column name, so nothing needs sideways scrolling. This covers:
  - the dashboard lists
  - the ICS 214 log and everyone's logs
  - ICS 213 messages
  - ICS 230 meetings
  - ICS 211 check-ins
  - the ICS 213RR request lists and item table

  Tables opt in with `class="cb-cards"`; `assets/js/app_shell.js` adds the labels. Other phone fixes:
  - pop-up notifications fit the screen
  - the Form Actions buttons are spaced out
  - the ICS 213RR steps show two per row with their buttons wrapping
  - the form status steps stay in one row on upright tablets

  Tablets and desktops are unchanged. No database changes.
* **Phones and tablets, part 1: the page frame.** The app now has three layouts, chosen by screen width (a tablet
  switches as soon as it is turned):
  - **Desktop (1024 px and wider):** tablets held sideways, laptops and monitors. Same as before.
  - **Tablet (600-1023 px):** most tablets held upright. The side menu hides behind a **Menu** button in a bar at
    the top and slides in over the page; tap a link, the backdrop, the close button or Esc to close it. The page
    gets the full width.
  - **Phone (under 600 px):** the same menu, plus one-column forms, smaller headings, tighter cards, and the status
    banner and Form Actions buttons stacked.

  On phones and tablets, buttons are at least 44 px tall. Fields use 16 px text, so phones don't zoom in when one is
  tapped. Wide tables scroll sideways in their own box instead of making the whole page scroll sideways. New file:
  `assets/js/app_shell.js`. The ECC displays, printing and the PDFs are not affected. No database changes.
* **Signed forms are locked, and "Revise" is the only way to change them.** Before this, forms didn't follow one rule:
  the ICS 205, 206, 208, 210, 215A and 218 could still be changed after they were signed, and the 205A, 207, 211
  and 230 unlocked again once approved (so "Add Meeting" came back on an approved ICS 230). Now every form uses the
  same rule (`includes/form_lock.php`):
  - A form is locked from the moment its preparer signs it, while it waits for approval and after approval. Each form
    page refuses any save, add or delete on a locked form, and its inputs are greyed out.
  - **Revise** (reason required) clears both signatures and opens the form as a draft with the next revision
    number. The approver's "Return for changes" does the same. It replaces "Return for changes" and "Reopen form"
    on every form, including the ICS 202 and 204, which have their own approval steps.
    - Who can revise: the preparer, the approver, anyone who can edit or sign the form, and a SysAdmin. ICS 214:
      the owner, the approver while it waits, and a SysAdmin.
    - Before the change, a PDF of the version being replaced is saved by the new `bin/form_snapshot.php`
      (under `storage/uploads/incidents/<id>/form_revisions/`). A **Revision history** lists each earlier version:
      who revised it and why, who signed and approved it, and a PDF link (`forms/revision_pdf.php`). Revisions are
      audit logged, and the preparer and approver are notified.
  - Still allowed on a locked form:
    - marking an ICS 230 meeting held or cancelled
    - new ICS 213 messages and replies
    - Resources Unit adds to the ICS 211/218
  - The ICS 213RR keeps its own workflow (no change).
  - **New status banner** at the top of every form, in the ICS 213RR style:
    - status ("Waiting for Dana Whitford", "Approved")
    - steps (Prepare, Sign, Approval, Approved)
    - **Next action** box with the right buttons for the person looking: approve and return for the approver;
      change approver and revise for the preparer; just who approved it once it's approved
    - revision history

    A signed form that isn't approved yet says **Not approved yet** and names the approver. The dashboard shows
    "Signed, waiting for approval" instead of "Submitted". The old sign-off box under the form is gone.
  - The ICS 201 and ICS 230 had no way to sign them from their pages; they now get **Sign and submit** in the
    banner. The ICS 204 now records who submitted it.
  - Database: migration `0005_form_revisions.sql` adds `form_records.revision` and the `form_revisions` table.
    Smoke test: checks the migration and that every form page has the lock check, and runs a revise on its test
    form.
  - Style sheets and scripts are now loaded with a `?v=` version stamp, so browsers pick up new styles right after
    an update instead of showing a cached copy (the new banner showed up unstyled until the cache was cleared).
* **Meeting reminders can be answered.** The "Was this meeting held?" reminder said to mark the meeting on the ICS 230,
  but there was no way to do it once the ICS 230 was signed, for meetings in an earlier operational period, or for a
  chair who can't edit the ICS 230. The reminder now opens a new page, `forms/ics230/meeting_status.php`, with
  **Completed** and **Cancelled** buttons and an optional note. The ICS 230 list also shows a **Was it held?** button
  for past meetings. The chair, IC/UC, PSC, anyone who can edit the ICS 230 and a SysAdmin can use it; each change is
  written to the audit log. No database changes. Reminders already sent still link to the ICS 230.
* **ICS 201: clearer message when the form is locked.** A submitted or approved ICS 201 told the Incident Commander
  "You do not have permission to edit this form". It now says the form is submitted (or approved) and locked, and shows the Preview PDF and
  Download PDF buttons, which were hidden along with the edit form, so a locked ICS 201 can still be read.
* **Full help for every ICS form page.** The help for the ICS 201, 202, 203, 204, 205, 205A, 206, 207, 208, 209, 210,
  211, 213, 213RR, 215A, 218 and 230, the Finance/Admin 213RR queue and the Resources Unit queue is now complete. Each
  page covers what the form is for, who prepares it, who gets it, what goes in each block, and how Command Bridge fills
  it in, signs it and prints it. The form guidance is based on the ICS Canada Forms Catalogue (2022), written in our
  own words; no ICS Canada text is copied.
  - New help pages: "Working with ICS forms" (saving, sign-off and default approvers, printing) and the ICS 209-WF.
  - The "Help / Instructions" links on the forms now open the form's help page. The old short instruction pages are
    gone, and the broken link on the ICS 210 now works.
  - No database or code changes beyond the `forms/*/help.php` redirects.

* **ICS 202 now uses the ICS Canada form.** The bundled `assets/pdf/official/ICS202.pdf` was not the ICS Canada
  version (no ICS logo or "ICS 202-CAN", and US-only items). It is replaced with ICS Canada's Form-202.pdf, and the field
  map is updated for it:
  - Check boxes now line up with the ICS Canada items: ICS 208, Map/Chart, Weather, Blank ICS 214.
  - The operational period dates and the preparer's position now print. Both were blank before.
  - Uploaded attachments are listed under "Other Attachments" and still follow the form as extra pages.
  - Long objectives go on a continuation sheet.
  The smoke test checks that the ICS 202 template is the ICS Canada one.
* **ICS 208 and ICS 230 updated to ICS Canada's current files** (posted 2026). The ICS 208 is unchanged in layout and
  field names. The ICS 230 is the new fillable version with 12 rows and different field names; its field map is
  rewritten. Each row is taller now, so less text goes to the continuation sheet.
* **Every form's PDF is now the official ICS Canada form.** Before, only the ICS 202, 205, 205A, 207, 209, 209-WF,
  210, 211, 213 and 213-RR filled the official PDF; the ICS 201, 203, 204, 206, 208, 214, 215A, 218 and 230 were
  redrawn by the app. Now all of them fill the official ICS Canada template, so printouts look like the real forms.
  - Text that does not fit a box is cut at a word with "... (cont.)" and printed in full on a continuation sheet at
    the end. More rows than the form has (resources, check-ins, log entries, meetings, hospitals) continue on extra
    copies of the form, with "Page x of y" on the ICS 214.
  - Box sizes and fonts are read from `assets/pdf/official/fields/*.json`, made by the new developer tool
    `tools/pdf_field_metadata.py`. Run it again whenever an ICS Canada PDF is replaced (see
    `includes/pdf/PDF_EXPORT_METHOD.md`).
  - ICS 218 has no fillable fields on the official PDF, so its text is drawn at the form's own positions and stamped on.
  - ICS 201 maps/sketches and ICS 202 attachments now follow the official form as extra pages (the ICS 202 used to
    switch to the app-drawn version when it had attachments).
  - The ICS 230 "meeting posting" wall sheet is not an ICS form and keeps its own layout.
  - Removed the old app-drawn renderers for the ICS 203, 204, 205, 206, 208 and 218. `forms/ics201/pdf_preview.php` now
    opens the official blank ICS 201.
  - Smoke test: builds every form's PDF, checks that each field name exists on the official template, and checks the
    ICS 218 stamping.
  - The PDFs need `pdftk` on the server (the installer adds it). Without it, these forms show an error instead of a
    PDF.
* **TSC is now SysAdmin, and it is no longer an ICS position.** ICS Canada does not list a Technical Services
  Coordinator, so the application administrator is now called **SysAdmin** everywhere (screens, help, setup, scripts).
  A SysAdmin manages the application and can open and approve every form, as before, but is not part of the incident's
  command structure: it is gone from the position list and chain of command, can't be assigned on Users & Roles, and
  first-run setup no longer assigns the administrator a position or checks them in on the ICS 211.
  - Database: migration `0004_rename_tsc_to_sysadmin.sql` renames `users.is_tsc_admin` to `users.is_sysadmin`, ends
    current TSC position assignments, renames the `TSC` role to `SYSADMIN` and switches it off (kept for history),
    removes TSC from the position catalogue, and changes unread TSC notifications to go to SysAdmins.
    `schema.sql` and `seed.sql` match.
  - **Form security change:** the 20 `TSC` rows in Form Permissions are removed (ICS 201-230 and EXTERNAL_CHECKIN).
    SysAdmins don't need them; they always have full access. No other role changes.
  - The first-run setup page is now `setup/setup_sysadmin.php`. People signed in during the update keep their access.
* **ICS 214 is now one activity log per person per operational period.** Before, everyone shared one ICS 214 that
  showed every person's sign-ins and actions. Now:
  - Each person's log starts when their login is created (Users & Roles), or the first time they open it, and shows
    only their own sign-ins/sign-outs, account changes, form actions, and the meetings marked *Completed* on the ICS 230.
  - Entries are added, never edited or deleted. Each shows who added it. To correct one, add another entry.
  - **Who can open someone else's log:** the Incident Commander / Unified Command, SysAdmins and the person's
    supervisor can read and add entries; the Documentation Unit Leader can only read. All of them can send the owner an
    "update your log" request (notification + audit entry). Everyone else sees only their own.
  - New page **ICS 214 - Activity Logs** (`forms/ics214/logs.php`) lists the logs you may open for the selected period.
  - Only the owner can sign their log. Signing locks it; while waiting for approval it can be returned. **Once approved
    it stays locked**; only a SysAdmin can reopen it.
  - The PDF prints one person's log. Sign-off and approval notifications open the right person's log.
  - The dashboard's form-status table shows only your own ICS 214, and the end-of-period reminder for an unsigned
    ICS 214 goes only to its owner (not to IC/PSC/SysAdmin for every person).
  - **Form security change:** new permission row `ICS214` / `ALL_AUTHENTICATED` (view, create, edit, sign; no
    permission management), so every signed-in user can open and sign their own log. The existing ICS214 rows for IC, PSC,
    OSC and GENERAL_STAFF are unchanged.
  - Database: migration `0003_ics214_per_person.sql` adds `form_214_activity_log.added_by_user_id` and `added_by_name`,
    ties existing logs to the person who prepared them, and adds the permission row. `schema.sql` and `seed.sql` match.
    The old shared log becomes the log of whoever created it.
* **Meetings: "held" means marked Completed.** The ICS 214 used to show "Meeting held" for any meeting whose time had
  passed, even cancelled ones. Now only meetings marked *Completed* on the ICS 230 count. An hour after a meeting's
  start, if it is still Scheduled or Tentative, the chair, the IC/UC and the Planning Section Chief get a dashboard
  reminder to mark it; the reminder disappears once the status changes. Cancelled meetings no longer show as upcoming
  on the dashboard.
* **ECC display: meetings start at the last meeting held.** The Meetings tab scrolls to the most recent Completed
  meeting (labelled *Last meeting held*) so the upcoming meetings are on screen; older meetings are dimmed.
* **Audit Log viewer** (`audit/index.php`), for SysAdmins and IC/UC holders: filter by person, category
  (sign-ins, accounts, forms, settings/other), date range and text; show 25/50/100/250/All rows with paging; plain
  sentences instead of raw action codes. No export. It is in the left menu for IC/UC, and Settings > Audit Log now
  opens it (the old `admin/audit_log.php` redirects there).
* **Fixed: the Resources Unit and Finance/Admin pages could only be opened by SysAdmins.** Their access comes
  from form codes `RESOURCES_UNIT` and `FINANCE_ADMIN`, which were missing from the `forms` table, and no role had
  permission (the Form Permissions page could not save them either). Migration `0002_resources_and_finance_unit_access.sql`
  (applied by `update.sh`) and `seed.sql` add both codes and these default permissions (view, create, edit, submit; no
  permission management):
  - Resources Unit: `RESL` (Resources Unit Leader) and `RESOURCE_UNIT` (Resources Unit staff)
  - Finance/Admin queue: `FSC` (Finance/Admin Section Chief) and `FINANCE_ADMIN` (Finance/Admin staff)

  Other roles can be added in Settings > Form Permissions. The self-check now confirms both pages have a role with access.
* **Fixed: new installs showed a broken sidebar logo.** `seed.sql` set `branding_logo_file` to an uploaded file that only
  existed on the machine it came from. The row is removed. The app now also ignores a saved logo whose file is missing
  and shows the default icon instead. The self-check reports when that happens.
* **ICS Canada branding removed from the web pages.** The ICS Canada logo is no longer shown in the sidebar, and the
  *Display ICS Canada logo* option is gone from Organization & Branding. The browser-tab icon, which was the ICS Canada
  logo, is now a Command Bridge icon (`assets/img/command-bridge-icon.svg`). That icon is also the sidebar logo until an
  organization logo is uploaded; it replaces a blank placeholder image. Messages beside PDF buttons now say
  "Blank PDF template not loaded yet." The form instruction pages keep a short note, "Source: ICS Canada Forms Catalogue."
  (now on all nine). The ICS Canada form templates and the PDFs made from them are unchanged.
  The old setting `branding_show_ics_logo` is simply no longer read; no database change.

## 0.9.0-beta.4

* **In-app help.** Every page has a **? Help** button that opens help for that page in a side panel, so you can read
  it while filling in the form (or open it in a new tab). **Help topics** in the sidebar lists everything. Help is
  written as Markdown files in `app/help/topics/`, one per page, and shown through the app only (signed-in users;
  the sign-in page's help is public). The **Email Delivery** help is complete: which method to choose; step-by-step
  Microsoft Graph (including limiting the app to one mailbox), SMTP OAuth2 for Microsoft 365, Google Workspace/Gmail
  app passwords, hosting providers and local relays; and what each error message means. Every other page has a
  short summary for now. The self-check fails if a page has no help file. See "In-app help" in docs/DEVELOPMENT.md.
* **Email Delivery page:** hints under the fields, and a "How to set up" link for the chosen method.
  - **Settings are now checked before saving**, so a rejected form no longer half-saves (previously an invalid sender
    address was saved anyway). What you typed is kept when a check fails (never secrets).
  - **"No authentication" to a relay on another server** now needs a tick box confirming that the relay only accepts
    mail from this server, and the page shows a warning while such a setup is saved. Relays on this server
    (`localhost`) are unchanged. Sending is not affected, so existing setups keep working.
  - **A password or OAuth2 sign-in is refused with encryption "None"** unless the relay is on this server.
* **Sign-out after 1 hour without activity** (was about 24 minutes, PHP's default, and not reliably enforced). The app
  now enforces the limit itself. It comes from `session.gc_maxlifetime`, which the installer sets to 3600, and
  `update.sh` adds to existing servers (an existing value is kept). The dashboard's automatic notification refresh
  does not count as activity. When the hour is up, an open dashboard goes to the sign-in page instead of staying on
  screen. The sign-in page says why. The self-check shows the current limit. See "Sign-out after inactivity" in
  docs/INSTALL.md.
* **Friendlier message when the sign-in timed out.** Submitting a form after the session expired showed only
  "Invalid CSRF token". It now shows *Your session has timed out* (and that what was entered was not saved), with a
  **Sign in again** button, and goes to the sign-in page by itself after 10 seconds. If the sign-in page itself had
  expired, it simply reloads with a message. A page left over from before signing in again (for example another tab)
  shows *This page is out of date* with a link to reload it. JSON requests get `session_expired` / `page_expired`.
  The self-check (`--base-url`) tests the timed-out case.

No database changes. Update with `update.sh`; it also adds the 1-hour sign-out setting to PHP (an existing value is kept).
The Check-In Gateway is unchanged (0.9.0-beta.2).

## 0.9.0-beta.3

**Check-In Gateway setup** (needs Check-In Gateway 0.9.0-beta.2 for the connection test)
* `install.sh` now asks whether to connect a Check-In Gateway: its address, and whether to generate the shared key here or
  paste the one the gateway installer showed. At the end it tests the connection and asks whether to switch the sync timer
  on (default Yes only if the test passed). The final screen says exactly what to do on the gateway, including the key ID
  and secret when they were generated here (shown on the terminal only, never in the log).
* New `tools/setup_checkin.sh`: the same steps for servers that are already installed. `--test` runs the connection test
  and offers to switch sync on; `--disable` switches it off; with no option it shows a menu (test / change / off).
  Switching on queues the current locations, expected resources and CIR/SAM users (the one-time backfill), sets
  `EXTERNAL_CHECKIN_ENABLED=1` and enables the timer. If any step fails, sync is left fully off.
* New `tools/rotate_checkin_secret.sh`: replaces the shared secret. It pauses sync, creates a new key ID and secret, tells you
  to run `tools/rotate_secret.sh` on the gateway, then tests and resumes. It can also undo, or stop and finish later.
* New `bin/external_checkin_ping.php`: read-only connection test. It checks the settings, trusted HTTPS, the gateway's
  database, the clock difference and the signed key, and explains each failure in plain words. The self-check
  (`bin/smoke_test.php`) runs it when sync is configured, and also checks that the public check-in link is set.
* Both installers make sure network time is on (installing `systemd-timesyncd` if no time service exists), since signed
  requests more than 5 minutes off are refused. Shared check in `deploy/lib/timesync.sh`.
* `docs/EXTERNAL_CHECKIN.md` rewritten as one step-by-step guide, including rotation and what to do with stuck items.

**Fixes**
* **A fresh install did not open the new-incident setup after the first login.** After signing in (password or
  Microsoft), users were sent straight to the dashboard, skipping the check that sends an administrator with no incident
  yet to *Set up incident*. Sign-in now goes through the start page, which picks the right destination.
* **Location QR codes / public links were never shown.** Admin > Locations read `EXTERNAL_CHECKIN_PUBLIC_ENTRY_BASE_URL`
  from the process environment instead of `/etc/commandbridge/external_checkin_sync.env`. It now reads the settings file
  (an Apache `SetEnv` still works). The fallback link format and the example file now use the gateway's real address
  format, `https://checkin.example.ca/?location=<token>`.
* **Self-signed installs could lock browsers out.** The app sent `Strict-Transport-Security` on every HTTPS page, so a
  browser that once trusted the site refused it after a certificate change, with no way to proceed. The header is now
  sent only by the Apache site, and only for trusted certificates: Let's Encrypt, and now also your-own-certificate
  installs (mode 3). To turn it back on for an existing install with a trusted certificate, see "Self-signed certificates" in docs/INSTALL.md.
* Installer and updater mark `tools/*.sh` executable; the repository now stores `install.sh` and `update.sh` as executable.

No database changes. Update with `update.sh`.

## 0.9.0-beta.2

* **Fixed: ICS 204 could not be approved.** The Planning Section Chief approval step existed in the code but had no button,
  and it was only processed for users with edit permission, which the standard permissions do not give Planning. A submitted
  ICS 204 now shows an **Approve ICS 204 (Planning Section Chief)** button to the PSC (and TSC/Admin). Approval no longer
  requires edit rights on the form (view access is enough), only works on a *submitted* form, records the approver and time,
  and is written to the audit log. The approver's name and approval time now show on the approved form.
* The Resources Unit Leader (RESL) now has ICS 204 view/edit/submit permission by default (new installs via `seed.sql`; existing installs via migration `0001_ics204_resl_permission.sql`, applied by `update.sh`), matching the on-screen help text.
* Update with `update.sh`; the Check-In Gateway is unchanged (still 0.9.0-beta.1).

## 0.9.0-beta.1 - first public beta

First public release. Earlier builds (1.0.2.x) were private; their history is folded into this baseline.

**Packaging**
* New installer (`install.sh`): installs all packages (including `pdftk-java`), asks for install folder, server name,
  TLS mode (Let's Encrypt / self-signed / own certificate / none), time zone and first administrator, writes the Apache
  site, PHP settings and database, then runs the self-check. Default location `/var/www/commandbridge`.
* Friendlier whiptail (ncurses) dialogs in `install.sh` and `update.sh`, shared code in `deploy/lib/ui.sh`; plain prompts remain for unattended runs.
* New updater (`update.sh`) with automatic database backup and numbered migrations (`database/migrations/`).
* Single `database/schema.sql` + `database/seed.sql` (reference data only: no users, incidents or forms).
* Database settings now live in `/etc/commandbridge/db.env`; names default to `commandbridge`.
* Time zone is configurable (`APP_TIMEZONE`) instead of fixed to America/Edmonton.
* Licensed AGPL-3.0; footer shows version and licence.

**Features carried from 1.0.2.x**
* Automatic approver selection through the chain of command and sign-off workflow.
* ICS 209 resource summary, ICS 209-WF fillable official template.
* Check-in sync status page with retry; login throttle and lockouts; smoke test (`bin/smoke_test.php`).

**Upgrading from a private 1.0.2.x build:** see docs/UPDATING.md (`APP_TIMEZONE` must be added to `db.env`).
