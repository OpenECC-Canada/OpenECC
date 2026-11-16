# Form permissions

Choose which positions can view, create, edit, submit and manage permissions for each ICS form. Pick a form, then
tick the rights for each role.

## Rights

| Right | What it allows |
|---|---|
| View | Open the form and read it. |
| Create | Start the form for an operational period. |
| Edit | Change it while it is a draft. |
| Submit | Sign it and send it for approval. |
| Manage permissions | Change this form's permissions. |

A person gets every right that any of their positions has. A SysAdmin can open every form.

## Two special roles

- **INCIDENT_POSITION**: anyone holding any position in the active incident. By default they can read the Incident
  Action Plan forms (201, 202, 203, 204, 205, 206, 207, 208, 215A, 230) and write an ICS 213.
- **ALL_AUTHENTICATED**: every signed-in user, even without a position. By default only the ICS 214 (each person
  keeps their own).

## How this shapes the menu

The forms in the side menu are grouped for each person:

- **Your forms**: forms one of their positions can create or edit.
- **Action plan**: Incident Action Plan forms they can read.
- **More forms**: every other form they can open, folded closed. Its number shows how many.

A form they can't open isn't listed. So changing a permission here also moves the form between the groups. Each
person's browser remembers which groups they opened or closed.

Some forms also check the position when signing (for example, only the IC or the Communications Unit Leader signs
the ICS 205). Those checks are not changed here.
