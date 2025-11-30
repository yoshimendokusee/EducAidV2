<?php
// About Page content helper - isolates about page editable blocks in separate tables
// Functions: about_block($key,$defaultHtml), about_block_style($key)
// Sanitizer: about_sanitize_html($html)

// Ensure DB connection exists
if (!isset($connection)) { @include_once __DIR__ . '/../../config/database.php'; }

if (!function_exists('about_sanitize_html')) {
  function about_sanitize_html($html){
    $html=preg_replace('#<script[^>]*>.*?</script>#is','',$html);
    $html=preg_replace('/on[a-zA-Z]+\s*=\s*"[^"]*"/i','',$html);
    $html=preg_replace("/on[a-zA-Z]+\s*=\s*'[^']*'/i",'', $html);
    $html=preg_replace('/javascript:/i','',$html);
    return $html;
  }
}

// Fetch unified contact info from municipalities table (centralized source)
$ABOUT_CONTACT_INFO = [
    'contact_phone' => '(046) 886-4454',
    'contact_email' => 'educaid@generaltrias.gov.ph',
    'contact_address' => 'City Government of General Trias, Cavite',
    'office_hours' => 'Monday - Friday, 8:00 AM - 5:00 PM'
];

if (isset($connection)) {
    // Check if contact columns exist in municipalities table
    $checkQuery = @pg_query($connection, "SELECT column_name FROM information_schema.columns WHERE table_name = 'municipalities' AND column_name = 'contact_phone' LIMIT 1");
    if ($checkQuery && pg_num_rows($checkQuery) > 0) {
        $contactQuery = @pg_query_params($connection, 
            "SELECT contact_phone, contact_email, contact_address, office_hours FROM municipalities WHERE municipality_id = $1 LIMIT 1", 
            [1]
        );
        if ($contactQuery && ($contactRow = pg_fetch_assoc($contactQuery))) {
            if (!empty($contactRow['contact_phone'])) $ABOUT_CONTACT_INFO['contact_phone'] = $contactRow['contact_phone'];
            if (!empty($contactRow['contact_email'])) $ABOUT_CONTACT_INFO['contact_email'] = $contactRow['contact_email'];
            if (!empty($contactRow['contact_address'])) $ABOUT_CONTACT_INFO['contact_address'] = $contactRow['contact_address'];
            if (!empty($contactRow['office_hours'])) $ABOUT_CONTACT_INFO['office_hours'] = $contactRow['office_hours'];
        }
    }
}

if (!function_exists('about_get_contact')) {
    function about_get_contact($key) {
        global $ABOUT_CONTACT_INFO;
        return htmlspecialchars($ABOUT_CONTACT_INFO[$key] ?? '');
    }
}

// ALWAYS load blocks data (even if functions already exist) so fresh data is available
$ABOUT_SAVED_BLOCKS = [];
if (isset($connection)) {
  // Query may fail if table not yet created; that's fine (empty defaults used)
  $res = @pg_query($connection, "SELECT block_key, html, text_color, bg_color FROM about_content_blocks WHERE municipality_id=1");
  if ($res) { while($r=pg_fetch_assoc($res)) { $ABOUT_SAVED_BLOCKS[$r['block_key']] = $r; } pg_free_result($res); }
}

// Only define functions once
if (!function_exists('about_block')) {
  function about_block($key,$defaultHtml){
    global $ABOUT_SAVED_BLOCKS; if(isset($ABOUT_SAVED_BLOCKS[$key])){ $h=about_sanitize_html($ABOUT_SAVED_BLOCKS[$key]['html']); if($h!=='') return $h; } return $defaultHtml;
  }
}
if (!function_exists('about_block_style')) {
  function about_block_style($key){
    global $ABOUT_SAVED_BLOCKS; if(!isset($ABOUT_SAVED_BLOCKS[$key])) return ''; $r=$ABOUT_SAVED_BLOCKS[$key]; $s=[]; if(!empty($r['text_color'])) $s[]='color:'.$r['text_color']; if(!empty($r['bg_color'])) $s[]='background-color:'.$r['bg_color']; return $s? ' style="'.implode(';',$s).'"':'';
  }
}
