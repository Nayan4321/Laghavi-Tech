=== Rinnovo Session Membership Pro ===
Contributors: nayan4321
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Classes, coaching and lessons for Rinnovo Subscriptions & Licenses: book with session credits or unlimited passes, recurring slots, waitlists, cut-offs, freeze, attendance, family accounts, waivers, video links, calendars and reminders.

== Description ==

For yoga and fitness studios, tutoring, coaching and therapy sessions billed weekly, monthly or yearly. Requires the free **Rinnovo Subscriptions & Licenses** plugin and WooCommerce.

= Booking without paying each time =

* A built-in class schedule: weekly times, extra dates, duration, instructor, place or online. `[session_schedule]` shows it with "Book" buttons.
* WooCommerce Bookings, Amelia and Bookly (in their WooCommerce mode): tick "Book with a credit" on the bookable product and members pay nothing; a session credit is used.

= Recurring slots =

* Members reserve "every Tuesday at 17:00"; the coming sessions are booked automatically while their membership is active and has sessions left.

= Online sessions and calendars =

* A Zoom meeting is created for each online session (Server-to-Server OAuth app).
* Google Meet, Microsoft Teams or any other link per class.
* The link is only shown to booked members.
* Every booking email has an .ics file and an "Add to Google Calendar" link.
* Each member, and each instructor, gets a personal calendar feed that Google Calendar, Outlook and Apple Calendar subscribe to.

= Capacity and waitlists =

* Places per class (1 for private sessions). When a class is full, members join the waitlist.
* When someone cancels, the first person waiting is booked and told by email and SMS (or just told, if you prefer).

= Session credits =

* Each plan or variation gives N sessions per billing period ("4 sessions a month", "8 classes a month") or unlimited classes.
* Credits refill on renewal. Unused ones expire, roll over, or roll over up to a limit.
* Top-up products add extra sessions ("Buy extra sessions" button).

= Cancelling and rescheduling =

* Members cancel or move a session to another date of the same class from their account, up to the cut-off (for example 24 hours).
* Late cancellations and no-shows use up the credit (both optional).

= Freeze =

* Members freeze their membership for some weeks (holiday, injury): the renewal date moves, nothing is charged, and their sessions wait.
* Bookings inside the freeze are cancelled with their credits back.

= Attendance and progress =

* Instructors mark "Here" or "No-show" and add progress notes, from WooCommerce > Sessions or the `[session_roster]` page.
* Members see how many classes they attended.

= Family accounts =

* One parent pays: add children or family members (as many as the plan allows) and book for them with the shared sessions.

= Waivers and intake forms =

* The liability waiver at checkout: the member accepts it and types their full name, and it's stored with date and IP. Members who bought before sign it in their account.
* Intake questionnaires (health, goals, date of birth…) before the first booking.

= Reminders =

* Email and SMS (Twilio) 24 hours and 1 hour before each session, next to Rinnovo's renewal reminders. Members can turn SMS off.

= Recurring billing =

* Weekly, monthly or yearly through Rinnovo Subscriptions & Licenses: Stripe charges automatically, and any other WooCommerce payment method gets "Pay now" invoices.

== Installation ==

1. Install and activate WooCommerce and the free Rinnovo Subscriptions & Licenses plugin.
2. Upload and activate Rinnovo Session Membership Pro, and enter your key in WooCommerce > Session Membership Pro license.
3. Tick "Session membership" on your subscription products (General tab), create classes under Classes, and put `[session_schedule]` on a page.

== External services ==

* **License server (laghavi.site):** sends your license key, site address, plugin name and version to activate, check and update. [Terms](https://laghavi.site/terms-and-conditions/)
* **Zoom (only if you enter its keys):** the class name, start time and length are sent to create a meeting. [Terms](https://explore.zoom.us/en/terms/)
* **Twilio (only if you enter its keys):** the member's phone number and the reminder text are sent to deliver SMS. [Terms](https://www.twilio.com/en-us/legal/tos)

== Changelog ==

= 1.0.0 =
* First release.
