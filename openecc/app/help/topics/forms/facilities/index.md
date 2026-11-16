# Facilities

Every facility for the incident in one list: the ECC, ICP, reception centres, staging areas, camps, warming centres,
medical aid stations and so on. It's usually kept by the **Facilities Unit Leader**, with the Logistics Section Chief.
Everyone in the incident can read it. Who can change it is set in [Form Permissions](help:admin/form_permissions)
under **FACILITIES**.

This page replaces **Check-in Locations**: your check-in locations are already here, as facilities that are check-in
points.

## Adding a facility

Press **Add a facility** and fill in what you know. Only the name is required.

| Field | What to enter |
|---|---|
| Name | For example "Wainwright Rec Centre". |
| Type | ECC, Reception Centre, Staging Area and so on. Your SysAdmin can change the list in [Lookups](help:admin/lookups) under **Facility Types**. |
| Address | Street address. |
| Check-in point | Tick if people and resources check in here. See below. |
| Latitude / Longitude | Optional. Adds a **Map** link. |
| Capacity | How many people and vehicles it can hold. |
| Hours | For example "24 hours" or "07:00-22:00". |
| Contact name / phone | Who runs it on site. |
| Services | Power, water, washrooms, kitchen, internet and so on. |
| Accessibility | For example "ramp at the north door". |
| Notes | Anything else. |

A new facility starts as **Planned**. Set it to **Open** when it's ready, and **Closed** when it closes. The open and
close times are recorded. **Archived** hides it from lists.

## Check-in points

A facility marked as a **check-in point**:

- can be picked as the **expected check-in / receiving location** on an [ICS 213RR](help:forms/ics213rr_v2/index)
- can be assigned to Check-In Recorders for Companion Check-In
- appears on the **Check-In Gateway** while it's Open, with its public link and QR code (under **Public link and QR**)

Other facilities stay on this server only. **Contact names, phone numbers and notes are never sent to the Check-In
Gateway**, even for check-in points. The gateway only gets the name, type, address and status.

## Where facilities show up

- **Location boxes:** the ICS 204 reporting location, ICS 215 reporting location and ICS 230 meeting location suggest
  open facilities as you type. You can still type anything.
- **Dashboard:** the **Open facilities** panel, on by default for the Facilities Unit Leader and the LSC.
- **Staging:** the [Staging board](help:forms/staging/index) groups resources by facility.

Every change is written to the audit log.
