# KAIZEN "Get In Touch" form: Elementor restyle

This makes the Elementor Pro multi-step form (page 9948, form widget `e67e0ee`)
look like `kaizen-pm-lead-qualification-form.html`: a white card, a segmented
progress bar, clickable option tiles, two-column rows, and Back/Continue buttons.

## 1. Fix the form settings in Elementor first (required)

CSS cannot fix these. They are why the form currently shows everything on one
page, with a single "1" indicator and small text.

1. **Add the repair script. This is what fixes the steps.** Some radio options end in
   `<small>` instead of `</small>`. The browser then nests the rest of the form inside
   step 1, so Elementor finds only one step. The option text has to stay as it is, so
   [`kaizen-form-repair.html`](kaizen-form-repair.html) repairs the page in the browser instead.
   Add an **HTML** widget **directly below the Form widget** (same container) and paste the
   file's contents into it. Submitted values are not changed.
   If a caching/optimisation plugin "delays JavaScript" (WP Rocket, LiteSpeed, etc.), exclude
   this script from it, because it must run before Elementor starts.

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

**Option A: the widget's Custom CSS box (recommended).** Select the Form widget,
go to **Advanced → Custom CSS**, and paste all of
[`kaizen-form-elementor-widget.css`](kaizen-form-elementor-widget.css). It uses Elementor's `selector`
keyword, so it applies only to this widget and keeps working if the widget is duplicated.

The Custom CSS box can't load Google Fonts (`@import` doesn't work there). To load them,
use the Form widget's **Style** tab:
- **Label → Typography → Family:** `Montserrat`
- **Field → HTML Field Typography → Family** (or any Typography control on the page): `Marcellus`

Elementor then loads both fonts, and the CSS uses them.

**Option B: the whole site.** Paste all of [`kaizen-form.css`](kaizen-form.css) into
**Appearance → Customize → Additional CSS**. Keep its `@import` line first; that line loads the fonts. This version
targets the widget id `e67e0ee`. If the widget is rebuilt, find the new id in the form's
hidden `form_id` input and replace every `e67e0ee`.

Use one option, not both. Clear any cache plugin afterwards.

Both versions target field ids such as `field_70176f8` for the two-column pairs and the
"optional" hints. If you rename a field's ID in Elementor, update it in the CSS too.

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
