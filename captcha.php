<?php
/**
 * 图片验证码（人机验证，注册发码前校验）
 * 学图床方案：4 位去混淆字符（去掉 O0I1L）、噪点+干扰线、session 存答案、
 * 5 分钟过期、一次性使用（校验后立即作废），答案不暴露给页面。
 */
require_once __DIR__ . '/config/database.php';

sendSecurityHeaders();
header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

startSecureSession();

$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
$code = '';
for ($i = 0; $i < 4; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
$_SESSION['captcha_code'] = $code;
$_SESSION['captcha_expire'] = time() + 300;

// 像素风深色画布（小画布绘制再放大 2 倍，保留锯齿感的同时提高 OCR 难度）
$w = 60; $h = 16;
$img = imagecreatetruecolor($w, $h);
$bg = imagecolorallocate($img, 20, 26, 40);
imagefilledrectangle($img, 0, 0, $w, $h, $bg);

// 干扰噪点
for ($i = 0; $i < 14; $i++) {
    $c = imagecolorallocate($img, rand(60, 140), rand(60, 140), rand(70, 150));
    imagesetpixel($img, rand(0, $w - 1), rand(0, $h - 1), $c);
}

// 零散小字符诱饵（用 imagestring 最小的 1/2 号字体，压低对比度，专干扰 OCR：
// 真码是 5 号大字体+高亮色，诱饵是 1-2 号小字体+暗色，人眼可轻松分辨，机器难以分割）
$decoyChars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
for ($i = 0; $i < 26; $i++) {
    $ch = $decoyChars[random_int(0, strlen($decoyChars) - 1)];
    $c = imagecolorallocate($img, rand(45, 95), rand(45, 95), rand(50, 100));
    imagestring($img, random_int(1, 2), rand(0, $w - 5), rand(0, $h - 7), $ch, $c);
}

// 干扰线（1 条）
$c = imagecolorallocate($img, rand(60, 110), rand(60, 110), rand(70, 120));
imageline($img, rand(0, $w / 2), rand(1, $h - 1), rand($w / 2, $w), rand(1, $h - 1), $c);
// 字符逐个绘制（随机偏移 + 亮色）
$x = 4;
for ($i = 0; $i < 4; $i++) {
    $c = imagecolorallocate($img, rand(210, 255), rand(200, 255), rand(170, 240));
    imagestring($img, 5, $x, rand(0, 3), $code[$i], $c);
    $x += 13;
}

$bigW = $w * 2; $bigH = $h * 2;
$big = imagecreatetruecolor($bigW, $bigH);
imagecopyresized($big, $img, 0, 0, 0, 0, $bigW, $bigH, $w, $h);
// 放大后再加噪点
for ($i = 0; $i < 30; $i++) {
    $c = imagecolorallocate($big, rand(100, 200), rand(100, 200), rand(110, 210));
    imagesetpixel($big, rand(0, $bigW - 1), rand(0, $bigH - 1), $c);
}

imagepng($big);
imagedestroy($img);
imagedestroy($big);
