<?php
define('ITS_ME_JUSTTOVERIFY', true);
require_once 'database.php';

header("Content-type: text/css; charset: UTF-8");

// --- Fetch main color ---
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'main_color' LIMIT 1");
$stmt->execute();
$mainColor = $stmt->fetchColumn();
$hasColor = !empty($mainColor);

// --- Background image ---
$bgRelativePath = 'images/background_image.png';
$bgCssPath = "../../api/" . $bgRelativePath;

$hasImage = file_exists($bgRelativePath);
?>

:root {

<?php if ($hasImage): ?>
  --backgroundImage: url('<?= $bgCssPath ?>');
<?php endif; ?>

<?php if ($hasColor): ?>
  --mainColor: <?= $mainColor ?>;

  --sidebarText: #ffffff;

  --sidebar-hover: color-mix(in srgb, var(--mainColor), white 12%);
  --sidebar-border: color-mix(in srgb, var(--mainColor), white 18%);
  --sidebar-accent: color-mix(in srgb, var(--mainColor), white 40%);
  --sidebarText-muted: color-mix(in srgb, var(--sidebarText), transparent 30%);
<?php endif; ?>

<?php if (!$hasColor && !$hasImage): ?>
  /* Nothing set, no image no main color */
<?php endif; ?>

}