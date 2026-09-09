<?php
function compressImage($source, $destination, $quality) {
    if (!file_exists($source)) {
        return false;
    }
    $info = getimagesize($source);
    if ($info === false) {
        return false;
    }
    $image = null;
    if ($info['mime'] == 'image/jpeg') {
        $image = imagecreatefromjpeg($source);
        if ($image) {
            imagejpeg($image, $destination, $quality);
        }
    } elseif ($info['mime'] == 'image/png') {
        $image = imagecreatefrompng($source);
        if ($image) {
            imagepng($image, $destination, round(9 * $quality / 100));
        }
    }
    if (isset($image)) {
        imagedestroy($image);
    }
    return true;
}
