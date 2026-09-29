# KAIZEN "Get In Touch" form: Elementor restyle

This makes the Elementor Pro multi-step form (page 9948, form widget `e67e0ee`)
look like `kaizen-pm-lead-qualification-form.html`: a white card, a segmented
progress bar, clickable option tiles, two-column rows, and Back/Continue buttons.

## 1. Fix the form settings in Elementor first (required)

CSS cannot fix these. They are why the form currently shows everything on one
page, with a single "1" indicator and small text.

1. **Broken `<small>` tags in the radio options.** In **Which best describes you?**
   and **When do you need a manager appointed?**, the options end in `<small>`
   when they should end in `</small>`. Each unclosed tag wraps the rest of the
   form. That breaks the steps and makes the text smaller and smaller. Rewrite each
   option as `label|value`, so the submitted value is also clean:

   ```
   Developer or master developer<br><small>Handing over a project, or appointing a manager for a new build</small>|Developer or master developer
   Family office<br><small>Managing real estate assets on behalf of a family or private group</small>|Family office
   Portfolio owner or investor<br><small>Multiple units or buildings held for income</small>|Portfolio owner or investor
   Commercial / retail asset owner<br><small>Office, retail, mixed-use or industrial asset</small>|Commercial / retail asset owner
   Owners committee or association<br><small>Acting for owners in an existing building or community</small>|Owners committee or association
   ```
   ```
   Immediately<br><small>Handover, expiry or a problem to solve now</small>|Immediately
   Within 1 – 3 months
   Within 3 – 6 months
   More than 6 months away
   Just researching options
   ```

2. **Step buttons are swapped.** On the 2nd, 3rd and 4th **Step** fields, set
   *Previous Button* = `Back` and *Next Button* = `Continue`.
   Right now they are reversed.

3. **Add a "Select…" placeholder to every dropdown.** Make this the first line of
   each Select field's options: `Select…|`. It has an empty value, so the required
   dropdowns (Asset type, Condition) make people choose instead of silently
   sending the first option.

4. **Delete the blank options.** One is the empty last option in *How is the asset
   managed today?*. The other is the empty last line in *Is there a tender or RFP
   process?*.

5. **Steps indicator.** In Form → *Steps Settings* → *Type*, pick anything
   except *None* or *Progress Bar* (e.g. *Number*). The CSS turns the
   indicators into the thin 4-segment bar.

Optional, to match the HTML's behaviour:
- Make *How is the asset managed today?* a **Radio** field. It is a single choice in the HTML.
- Replace the consent checkbox field with two **Acceptance** fields. Mark the
  privacy one *Required* and leave the marketing one optional. As things stand, Elementor can't
  require that one specific box is ticked. The CSS already styles Acceptance fields.

## 2. Add the CSS

Paste all of [`kaizen-form.css`](kaizen-form.css) into
**Appearance → Customize → Additional CSS**, then publish. Keep the `@import` line at the very top; it loads
the Marcellus and Montserrat fonts. Clear any cache plugin afterwards.

(If you'd rather use Elementor → Custom Code, wrap it in `<style>…</style>` and
change the `@import` into
`<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Marcellus&family=Montserrat:wght@400;500;600;700&display=swap">`
placed above the style tag.)

Everything is scoped to `.elementor-element-e67e0ee`, so nothing else on the
site is affected. If the widget is ever duplicated or rebuilt, its id changes.
Find the new id in the form's hidden `form_id` input and replace every `e67e0ee`.
The two-column pairs and the "optional" hints target field ids such as
`field_70176f8`. If you rename a field's ID in Elementor, update it in the CSS too.

## What the CSS does

| HTML design | How it's done on the Elementor form |
|---|---|
| 4-segment red progress bar | Step indicators restyled into bars (active and completed are red) |
| Serif step titles + grey hint | HTML fields' `<h4>`/`<p>`, and the first question label, use Marcellus |
| Option tiles with red checked state | Each radio/checkbox label becomes a bordered tile; `input:checked + label` turns it red/tinted |
| Services and "managed today" in 2 columns | Grid on those two fields |
| Paired fields side by side | Units/Buildings, Emirate/Community, RFP/Prompted, First/Last name, Company/Job title (stacked on phones) |
| "optional" / "Select all that apply" hints | Added after those labels with CSS; the red `*` is hidden |
| Back link + red Continue, divider above | Elementor step buttons restyled; submit arrow icon hidden |
| Trust line under Send | Added under the submit row with CSS |
