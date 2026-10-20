# Cornwall Legal Centre CIC: new pages (Elementor HTML widget)

Three new pages, ready to paste into Elementor. Each file contains everything it needs
(styles plus content), so no plugin or custom CSS is needed.

| File | Suggested page title | Suggested slug |
|---|---|---|
| `accessible-justice-project.html` | The Accessible Justice Project | `accessible-justice-project` |
| `privacy-notice.html` | Privacy Notice | `privacy-notice` |
| `terms-and-conditions.html` | Terms and Conditions | `terms-and-conditions` |

Changes to existing pages (phone number, Areas of Expertise, Fees and Funding,
News and Media) are being done manually and are not included here. There are no contact forms
either: add a Gravity Form wherever you want one.

## How to add each page

1. **WP Admin → Pages → Add New**, enter the title, then click **Edit with Elementor**.
2. Open page settings (cog icon, bottom left) and set **Page Layout → Elementor Full Width**.
   In the Kadence settings for the page, hide the page title (the design has its own heading).
3. Add a **Container** and set **Content Width: Full Width**, **Padding: 0**, **Gap: 0**.
4. Drag in an **HTML** widget, open the `.html` file in Notepad, VS Code or similar,
   **copy everything**, and paste it into the widget.
5. Click **Publish**.

The text inherits the site's fonts automatically, and the colours match the homepage
(charcoal, gold and cream).

## Links to check

- The buttons link to **`/contact-us/`**. If the Contact page uses a different address, search
  the file for `/contact-us/` and replace it.
- Add the new pages to the menu: **Appearance → Menus** (or **Customize → Header → Navigation**).
- Point the footer **Privacy Policy** link at `/privacy-notice/`. Also consider adding a
  `/terms-and-conditions/` link next to it.
- Optional: in **Settings → Privacy**, choose *Privacy Notice* as the privacy policy page.

## Responsive behaviour

The pages were tested at 1366px (desktop), 820px (tablet) and 390px (mobile), with no
sideways scrolling. Cards stack to a single column, buttons go full width on phones, and the
contents list on the legal pages collapses to one column.

## Notes

- The Privacy Notice and Terms text is copied word for word from the client document. Only
  the headings and layout were added. The ICO link uses its current address (`https://ico.org.uk/`).
- **Security:** the client document contains the WordPress admin password in plain text.
  Because the site was recently hacked, change that password and avoid sharing it in documents.
