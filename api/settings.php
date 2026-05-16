<?php
// The exact path where your live HTML expects the image
$liveFile = 'api/images/image1.png';

// Create a completely unique temporary file name
// Example output: 'api/images/temp_64b5a29f12345.png'
$uniqueTempName = 'api/images/temp_' . uniqid() . '.png';

// 1. Move the uploaded file to the UNIQUE temp location safely
if (move_uploaded_file($_FILES['new_logo']['tmp_name'], $uniqueTempName)) {
    
    // 2. The Atomic Swap: Instantly replace the live file
    if (rename($uniqueTempName, $liveFile)) {
        echo json_encode(["status" => "success", "message" => "Image changed successfully!"]);
    } else {
        // Cleanup if the swap fails
        unlink($uniqueTempName);
        echo json_encode(["status" => "error", "message" => "Could not swap files."]);
    }

} else {
    echo json_encode(["status" => "error", "message" => "Upload failed."]);
}
?>