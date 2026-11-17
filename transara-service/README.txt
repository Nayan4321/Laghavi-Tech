Transara service – setup on transara.laghavi.site (Hostinger)
================================================================

What it is
----------
The part of Transara Pro that runs outside your customers' sites: uptime checks from outside,
alerts (email, webhooks, Slack, Discord, Teams, Telegram, SMS and WhatsApp through Twilio),
escalation, hosted status pages with subscribers, and monthly uptime reports.

No customer accounts here. Customers buy Transara Pro on laghavi.site and activate the license on
their site. Transara Pro then connects to this service with that license key; the service asks
laghavi.site whether the key is active for that exact site and gives the site its own secret token.
The license is checked again every day; when it ends, checks for that site stop.

1. Create the subdomain
   hPanel > Domains > Subdomains: create "transara" for laghavi.site. Note its folder,
   for example public_html/transara. Turn on SSL for it (hPanel > Security > SSL).

2. Create a database
   hPanel > Databases > Management: new database and user. Keep name, user and password.

3. Upload the files
   Upload everything in this folder (index.php, cron.php, worker.php, install.php, .htaccess,
   config.sample.php, app/, assets/) into the subdomain's folder.

4. config.php
   Copy config.sample.php to config.php and fill in:
   - db_name, db_user, db_pass (db_host stays localhost)
   - app_secret: a long random string (never change it later)
   - admin_user and admin_hash (the comment shows how to make the hash)
   - mail_from: a mailbox you created on laghavi.site (alerts@laghavi.site)
   - optional: telegram_token, Twilio keys, sms_per_month
   Leave dev_allow_private = false.

5. Create the tables
   hPanel > Advanced > SSH Access, then:  php install.php   (in the subdomain folder)
   No SSH? Add install.php once as a cron job (step 6 command with install.php), wait a minute,
   then delete that cron job.

6. Cron job (every minute)
   hPanel > Advanced > Cron Jobs > Custom: every minute, command:
   /usr/bin/php /home/YOUR_USER/domains/laghavi.site/public_html/transara/cron.php
   (use the real path of the subdomain folder; hPanel shows it in File Manager).

7. The product on laghavi.site
   In Laghavi License Manager, the Transara Pro product's plugin slug must be: transara-pro
   (the same as license_slug in config.php).

8. Check it
   https://transara.laghavi.site/api/v1/ping   should answer {"ok":true,...}
   https://transara.laghavi.site/admin          your dashboard (shows when checks last ran)

More regions (optional, later)
------------------------------
Rent a small server in another region, upload the same files, and give it a config.php with
base_url, main_url (https://transara.laghavi.site), worker_name and worker_key. On the main server
add the same name and key under "workers" in config.php. Run "php worker.php" there every minute.
With regions_needed = 2 a monitor is only "down" when two regions agree.

Customer status pages on their own domain
-----------------------------------------
A customer points a CNAME (status.theirsite.com) to transara.laghavi.site and enters the domain in
Transara Pro. To serve it over HTTPS you add that domain to the subdomain's hosting (hPanel >
Domains > add as parked/alias) so Hostinger issues its certificate.
