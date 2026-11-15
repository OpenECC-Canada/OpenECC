# ICS 213 - General Message

The ICS 213 is for any message that needs to be written down: a message taken by radio or phone that can't be passed on in person, or a message from someone on the incident to be sent on by the Communications Centre. Examples are a resource order, a change of incident name or a coordination issue. On paper it is a three-part form: the sender fills in the top, the receiver writes a reply, and the reply copy goes back to the sender.

- **Usually prepared by:** anyone on the incident, including dispatchers.
- **Who gets it:** the person it's addressed to, or the Communications Centre to send it. A copy goes to the Documentation Unit.

General steps for signing and printing are in [Working with ICS forms](help:forms/working_with_forms).

## Messages list

The first page lists the messages for the operational period with their date, reference, To, From, subject and status:

- **Awaiting approval**: signed, waiting for the approver before it is emailed. Nothing has been sent yet.
- **Returned**: the approver sent it back for changes.
- **Sent**: delivered to the dashboard and any email recipients. Waiting for a reply.
- **Replied**: the reply has been entered.
- **Cancelled**: stopped before it was sent.

Press **Create New Message** to write one, or **Open** to read one and add the reply.

## Writing a message

| Block | What to enter |
|---|---|
| 1. Incident Name | Filled in for you. |
| Reference | A code such as CB-213-7K2QX is given to every message. It goes in the email subject, so a reply that comes back by email can be matched to this message. |
| 2. To | Pick the **position** it's for from the list of positions filled in the incident. The name and position fill in for you, and everyone holding that position gets a dashboard notification. For someone outside the incident organization, leave the position blank and type the name. Use at least a first initial and last name. Under Unified Command, include the agency. |
| 3. From | Your name and position. Filled in from your account. |
| 4. Subject | A short subject. |
| 5. Date/Time | When the message was written. Defaults to now. |
| 6. Message | The message. Keep it short and clear. **If you need a reply, say how and by when**, for example "Please reply by 15:30 to ecc-comms@example.ca or call the ECC at 780-555-0100. Include the reference CB-213-7K2QX." If no reply is needed, say so: "For your information only. No reply needed." |
| 7. Approved By / Signature | Optional for a message that only goes to the dashboard. For an emailed message it is filled in for you with the approver's name and position. |

## Signing and sending

There is no separate Save. The button at the bottom changes with what you've chosen:

- **Sign and Send ICS 213**: the message only goes to the dashboard of the "To" position. It is signed with your name and the time, sent straight away and locked.
- **Sign and Request Approval**: the message is also being emailed. An email can't be recalled, so it goes first to your **approver**: your supervisor, or the Incident Commander if you don't have one. You can pick someone else in the **Approver** list. **Nothing is sent until it is approved.**
- **The Incident Commander's own messages** don't need approval. The IC's name goes in Block 7 and the message is sent straight away.

The approver gets a notice on their dashboard. They see the message and who it will be emailed to, and can:

- **Approve and Send**: Block 7 is filled in with their name, position and the time, and the message goes to the dashboard and the email recipients.
- **Return for changes**, with a reason. You get a notice, change the message and sign it again.
- **Cancel** it. Nothing is sent.

The approver can't change the message. While it waits, you can **Change approver** or **Cancel** it.

**Once sent, the message can't be changed.** Only the reply can be added. If something was wrong, send a new message that corrects it.

## Sending it by email

When you write the message you can also email it, as well as the dashboard notice. An emailed message needs approval first (see above):

- **Also email the people holding the "To" position**: uses the email on their user account. An administrator can make this happen every time in [Email Delivery](help:admin/smtp).
- **Contacts from this period's ICS 205A**: tick anyone listed on the [ICS 205A](help:forms/ics205a/index) with an email address.
- **Other email addresses**: type any others, separated by commas or new lines.

Each person gets an email with:

- the incident and the reference code in the subject
- the full message as plain text
- the official ICS 213 attached as a PDF

**Reply** in their email program goes to **your own** email address.

On the message you'll find an **Email** list of everyone it was sent to, when and by whom, and the result:

- **Accepted by mail server**: the email was handed to the mail server. This does **not** prove it reached the person's inbox.
- **Failed**: the mail server refused it. The reason is shown.
- **Waiting for approval**: added with Send to more people, not approved yet.

**Resend** tries one again. **Send to more people** emails it to anyone else later; this needs your approver's OK too, unless you are the IC.

**Bounces:** if an address is wrong or a mailbox is full, the "undeliverable" notice comes back later to Command Bridge's own sending mailbox (the one set up in [Email Delivery](help:admin/smtp)), not to this page. Ask whoever looks after that mailbox to watch for bounces during an incident.

## Replying

Once the message has been sent, open it and fill in **8. Reply**, **Reply By**, **ICS Position** and **Reply Date/Time**, then press **Save reply**. The message then shows as Replied, and the sender gets a dashboard notification.

**A reply that came back by email:** find the message by typing its reference code from the email's subject into **Find a message** on the list. Paste the reply into 8. Reply, set **Reply received** to "By email", enter the email address it came from, and press **Save reply**.

## Tips

- When you pick a position in **2. To**, the message shows up in that person's **My action items** on the dashboard once it is sent. Opening the message clears the notice. An approval request stays until the approver acts on it.
- The ICS 213 isn't part of the operational period sign-off: messages don't appear in the forms that still need signing.
- Command Bridge doesn't read anyone's mailbox. Replies sent by email arrive in the sender's inbox and are typed in by hand, as described above.
- To order resources, use the [ICS 213RR](help:forms/ics213rr_v2/index) instead. It follows the request through approval, ordering and delivery.
