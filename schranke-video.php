<?php
header("Content-Type: multipart/x-mixed-replace; boundary=--myboundary");

$ch = curl_init("http://10.134.185.8/axis-cgi/mjpg/video.cgi");
curl_setopt($ch, CURLOPT_USERPWD, "video:videosee");
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
    echo $data;
    flush();
    return strlen($data);
});
curl_exec($ch);
curl_close($ch);
?>
