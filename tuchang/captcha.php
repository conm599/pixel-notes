<?php
// 图片验证码：答案只存 session，页面不暴露任何算式
ini_set('display_errors', '0');
ini_set('log_errors', '1');
$isHttps = !empty($_SERVER['HTTPS']) || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
// 强制 HTTPS（防降级）
if (!$isHttps) {
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
    header('Location: https://' . $host . $uri, true, 301);
    exit;
}
session_set_cookie_params(array(
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => true
));
session_start();

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

// 去除易混淆字符 O0I1L
$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
$code = '';
for ($i = 0; $i < 4; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
$_SESSION['captcha_code'] = $code;
$_SESSION['captcha_expire'] = time() + 300; // 5 分钟过期

// 小画布 + 放大（内置字体绘制，避免依赖 TTF 字体文件）
$w = 60; $h = 16;
$img = imagecreatetruecolor($w, $h);
$bg = imagecolorallocate($img, 26, 31, 47);
imagefilledrectangle($img, 0, 0, $w, $h, $bg);

// 干扰噪点（少量，不影响阅读）
for ($i = 0; $i < 12; $i++) {
    $c = imagecolorallocate($img, rand(60, 140), rand(60, 140), rand(70, 150));
    imagesetpixel($img, rand(0, $w - 1), rand(0, $h - 1), $c);
}
// 干扰线（1 条浅色细线）
$c = imagecolorallocate($img, rand(60, 110), rand(60, 110), rand(70, 120));
imageline($img, rand(0, $w / 2), rand(1, $h - 1), rand($w / 2, $w), rand(1, $h - 1), $c);
// 字符逐个绘制（随机偏移、随机亮色）
$x = 4;
for ($i = 0; $i < 4; $i++) {
    $c = imagecolorallocate($img, rand(210, 255), rand(200, 255), rand(170, 240));
    imagestring($img, 5, $x, rand(0, 3), $code[$i], $c);
    $x += 13;
}

// 放大 2 倍（清晰可读，仍有基本 OCR 难度）
$bigW = $w * 2; $bigH = $h * 2;
$big = imagecreatetruecolor($bigW, $bigH);
imagecopyresized($big, $img, 0, 0, 0, 0, $bigW, $bigH, $w, $h);

// 放大后再加少量噪点
for ($i = 0; $i < 30; $i++) {
    $c = imagecolorallocate($big, rand(100, 200), rand(100, 200), rand(110, 210));
    imagesetpixel($big, rand(0, $bigW - 1), rand(0, $bigH - 1), $c);
}

imagepng($big);
imagedestroy($img);
imagedestroy($big);
