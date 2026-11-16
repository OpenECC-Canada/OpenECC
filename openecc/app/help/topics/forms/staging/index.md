# Staging

The staging board shows every resource the Resources Unit has received or checked in, where it is, and whether it's
**Available**, **Assigned**, **Out of service** or **Released**. It's kept by the **Staging Area Manager**, with
Operations and the Resources Unit. Who can change it is set in [Form Permissions](help:admin/form_permissions) under
**STAGING**.

## How resources get here

A resource appears once the [Resources Unit](help:forms/resources/index) records its receipt. Its facility starts as
the receiving location chosen on the [ICS 213RR](help:forms/ics213rr_v2/index), and it starts as **Available**.

## Using the board

- **Facility:** pick a staging area (or **All facilities**) at the top. It opens on the first open staging area. The
  counts show how many are available, assigned and out of service.
- **Update a resource:** set its status, then press **Update**.
  - **Assigned:** say what to, for example "Division A" or "ICS 204 Hazmat Group".
  - **Out of service:** add a note, for example "flat tire, waiting for repair".
  - **Released:** when it's demobilized. Print its [ICS 221](help:forms/resources/index) from the Resources Unit page.
    Released resources are hidden unless you tick **Show released**.
- **Moving it:** pick another facility in the same row.

Every change is kept with the time and who made it, and written to the audit log.

## Dashboard

The **Staging** panel shows available, assigned and out-of-service counts per facility. It's on by default for the
Staging Area Manager and the Operations Section Chief.

## Facilities

Staging areas are facilities on the [Facilities](help:forms/facilities/index) page, with the type **Staging Area**.
