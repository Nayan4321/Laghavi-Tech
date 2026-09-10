<?php
// Copy this file to config.php (same folder) and fill in real values.
// config.php is gitignored — never commit real API keys.

return [
    // From "Create the backend app and generate a new API key" (Configuration > Apps).
    // Pick the API host that matches your Zenoti data center/region:
    //   US:   https://api.zenoti.com
    //   UAE:  https://api.zenoti.ae
    //   EU:   https://api.zenoti.eu (check with your Zenoti rep if unsure)
    'zenoti_api_url' => 'https://api.zenoti.com',
    'zenoti_api_key' => 'paste_the_generated_api_key_here',

    // Optional: limit guest search to one center. Leave blank ('') to search
    // across the whole org (needs "Search Guest Across Centers" enabled in Zenoti).
    'zenoti_center_id' => '',

    // Optional: once you know the real URL pattern for an open guest profile in
    // your Zenoti web app, put a template here using {guest_id} and {center_id}.
    // Example: 'https://yourbusiness.zenoti.com/Home/Guest/{guest_id}'
    // Leave blank to use this app's own guest.php page instead (always works).
    'zenoti_guest_url_template' => '',

    // Make up any random long string. You'll add it as ?token=... on the
    // webhook URL you give CallGear, so random internet traffic can't trigger
    // lookups.
    'webhook_token' => 'change_me_to_a_random_string',

    // CallGear's Interactive Call Processing waits for us to reply with a
    // "returned_code" telling it which branch of the call scenario to
    // continue on. In CallGear's scenario editor, set this same number's
    // linked operation to whatever the call should normally do next (so this
    // webhook never changes how calls are actually routed — it just taps in
    // to trigger the screen-pop). Ask whoever configures the CallGear
    // scenario which number to use here; 1 is CallGear's default example.
    'callgear_returned_code' => 1,
];
