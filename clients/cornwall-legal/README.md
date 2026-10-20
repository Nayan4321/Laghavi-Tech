# Cornwall Legal Centre CIC: pages (Elementor HTML widget)

Every file contains its own styles, so no plugin or custom CSS is needed. Fonts come from the
site automatically, and the colours match the homepage (charcoal, gold and cream).
All pages are responsive (checked at desktop, tablet and 390px mobile).

## Single-widget pages (one HTML widget each)

| File | Page title | Suggested slug |
|---|---|---|
| `accessible-justice-project.html` | The Accessible Justice Project | `accessible-justice-project` |
| `privacy-notice.html` | Privacy Notice | `privacy-notice` |
| `terms-and-conditions.html` | Terms and Conditions | `terms-and-conditions` |
| `fees-and-funding.html` | Fees and Funding (replaces *Our Services*) | `fees-and-funding` |

## Redesigned pages with a Gravity Form slot (three widgets each)

| Folder | Page | Form |
|---|---|---|
| `contact-us/` | Contact Us | required |
| `about-us/` | About Us | optional |
| `meet-our-founder/` | Meet Our Founder (blog post) | optional |

Each folder holds three files, added to the page **top to bottom in this order**:

1. `1-html-widget.html`: an **HTML** widget
2. `2-shortcode-widget.txt`: a **Shortcode** widget containing your Gravity Form
3. `3-html-widget.html`: an **HTML** widget

Why the split: Elementor's HTML widget does **not** run shortcodes, so the form goes in its
own Shortcode widget between the two HTML parts.

**Styling the form:** select the container that holds the Shortcode widget, go to
**Advanced → CSS Classes**, and type `clc-gf`. That gives the dark form card on cream, matching
your current contact form. Use `clc-gf clc-gf--light` for a light card on white instead. Set that
container's padding to 0. Change `id="1"` in the shortcode to your form's ID
(**Forms** menu, ID column).

On About Us and Meet Our Founder, skip the Shortcode widget if you don't want a form there.

## Images and video to link (search the file for `PASTE-`)

| Page | What to replace | Where to get it |
|---|---|---|
| About Us, part 1 | `PASTE-IMAGE-URL-HERE` | Media Library → click image → **Copy URL to clipboard** |
| Meet Our Founder, part 1 | `PASTE-FOUNDER-PHOTO-URL-HERE` | Same as above |
| Meet Our Founder, part 1 | `PASTE-YOUTUBE-ID-HERE` | The ID from the YouTube link: `youtube.com/watch?v=`**`AbC123`** |
| Meet Our Founder, part 3 | `YOURPAGENAME` (twice) | Your Facebook page address, the part after `facebook.com/` |
| Contact Us, part 1 | `PASTE-FACEBOOK-URL`, `PASTE-INSTAGRAM-URL`, `PASTE-LINKEDIN-URL` | Your full social profile links |

If an image URL is left as the placeholder, that image column hides itself and the text spans
the full width, so the page never shows a broken image.

The Facebook block on Meet Our Founder is optional. It shows your latest Facebook posts
automatically. Delete that `<section>` if you don't want it.

## Page setup (OceanWP + Elementor)

1. Open the page or post and click **Edit with Elementor**.
2. Page settings (cog icon, bottom left): set **Page Layout → Elementor Full Width**.
3. Below the editor in WP Admin, in **OceanWP Settings**: set **Title → Display Page Title: Disable**
   (the design has its own banner; this also removes the incorrect "Home → Contact" breadcrumb),
   **Main → Content Layout: 100% Full Width**, and turn off the sidebar.
   For the **Meet Our Founder post**, also hide the featured image and post meta.
4. Add a **Container** with **Content Width: Full Width**, **Padding: 0** and **Gap: 0**, then add
   the widget(s) inside it.
5. Open each file in Notepad or VS Code, **copy everything**, and paste it into the widget.
6. Click **Publish** / **Update**.

## Replacing "Our Services" with Fees and Funding

1. Open **Our Services** with Elementor, delete its sections, and add `fees-and-funding.html`.
2. In **Pages → Quick Edit**, rename the page to **Fees and Funding** and change the slug to `fees-and-funding`.
3. Rename the menu item in **Appearance → Menus**, and update its link if the slug changed.
4. Add a 301 redirect from `/our-services/` to `/fees-and-funding/` (the free *Redirection*
   plugin, or Rank Math / Yoast), or keep the old slug.

## Links to check

- Buttons link to **`/contact-us/`**, `/fees-and-funding/`, `/privacy-notice/` and
  `/terms-and-conditions/`. If any page uses a different address, find and replace it in the files.
- Point the footer **Privacy Policy** link at `/privacy-notice/`.
- The footer Quicklinks still say "Fees & Fundings", which should be "Fees and Funding".

## Notes

- The Privacy Notice, Terms, About Us and founder text are the client's own wording. Only
  headings and layout were added.
- **Security:** the client's update document contains the WordPress admin password in plain text.
  Because the site was recently hacked, change that password and avoid sharing it in documents.
