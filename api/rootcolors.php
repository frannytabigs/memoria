<?php
define('ITS_ME_JUSTTOVERIFY', true);
require_once 'database.php';
// 1. Tell the browser this is a CSS file
header("Content-type: text/css; charset: UTF-8");

// 2. Fetch from your database (simulated here)
// Example: SELECT primary_color FROM settings LIMIT 1;
$r = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'main_color' LIMIT 1");
$r->execute();

$result = $r->fetch(PDO::FETCH_ASSOC);
// Fallback color if not set in the database

$mainColor = '#0f172a'; // Default to a dark color (Dark Knight)
if ($result && !empty($result['setting_value'])) {
    $mainColor = $result['setting_value'];
}
// 3. Output the CSS variables
?>
:root {
  --mainColor: <?php echo $mainColor; ?>; 

  /* ---> THE COMPANION VARIABLE <--- */
  /* If mainColor is dark, make this #ffffff. If mainColor is light, make this #0f172a */
  --sidebarText: #ffffff;

  --backgroundImage: url("../images/MandaueBackground.jpg");

  /* --- SIDEBAR DYNAMIC COLORS --- */
  --sidebar-hover: color-mix(in srgb, var(--mainColor), white 12%);
  --sidebar-border: color-mix(in srgb, var(--mainColor), white 18%);
  --sidebar-accent: color-mix(in srgb, var(--mainColor), white 40%);

  /* Dynamically muted text based on your chosen text color */
  --sidebarText-muted: color-mix(in srgb, var(--sidebarText), transparent 30%);
}
