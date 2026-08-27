<?php
/**
 * 朗读工坊 - 独立 TTS 页面（与主站共享登录会话）
 */
require_once __DIR__ . '/config/database.php';
sendSecurityHeaders();
header('Cache-Control: no-store, must-revalidate');
startSecureSession();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>朗读工坊 - Pixel Notes</title>
    <link rel="stylesheet" href="css/pixel.css?v=32">
</head>
<body>
    <!-- 顶部导航 -->
    <nav class="navbar">
        <a href="index.php" class="navbar-brand">
            <span class="icon">🔊</span> 朗读工坊
        </a>
        <div class="navbar-user">
            <span>👤 <?= htmlspecialchars($_SESSION['username']) ?></span>
            <a href="index.php" class="btn btn-outline btn-xs">← 返回便签</a>
        </div>
    </nav>

    <div class="main-container">
        <div class="toolbar">
            <span class="toolbar-title">🎙 文字转语音</span>
            <div class="toolbar-actions">
                <select id="notePicker" class="tts-select" title="从便签导入文本">
                    <option value="">📄 从便签导入...</option>
                </select>
            </div>
        </div>

        <!-- 文本输入 -->
        <div class="tts-panel">
            <div class="form-group">
                <label class="form-label" for="ttsText">朗读文本 <span id="charCount" class="tts-charcount">0 / 5000</span></label>
                <textarea id="ttsText" class="form-input tts-textarea" placeholder="输入任意想听的内容，或从右侧导入便签..." maxlength="5000"></textarea>
            </div>

            <!-- 参数区 -->
            <div class="tts-controls">
                <div class="tts-ctrl">
                    <label class="form-label" for="ttsVoice">🗣 音色</label>
                    <select id="ttsVoice" class="tts-select">
                        <option value="fable" selected>晓伊 · 女 · 活泼甜润</option>
                        <option value="alloy">晓晓 · 女 · 温柔亲切</option>
                        <option value="nova">晓涵 · 女 · 清亮甜美</option>
                        <option value="shimmer">晓梦 · 女 · 轻柔温润</option>
                        <option value="echo">云希 · 男 · 阳光少年</option>
                        <option value="onyx">云扬 · 男 · 沉稳大气</option>
                    </select>
                </div>

                <div class="tts-ctrl">
                    <label class="form-label">⏩ 语速 <span id="speedVal" class="tts-ctrl-val">1.0x</span></label>
                    <input type="range" id="ttsSpeed" class="tts-slider" min="0.5" max="2" step="0.1" value="1">
                </div>
            </div>

            <div class="tts-actions">
                <button id="btnSpeak" class="btn btn-primary" type="button">▶ 生成语音</button>
                <button id="btnClear" class="btn btn-outline" type="button">清空</button>
            </div>
        </div>

        <!-- 结果区 -->
        <div id="ttsResult" class="tts-panel tts-result" style="display:none;">
            <div class="tts-result-head">
                <span class="tts-result-title">🎵 生成的语音</span>
                <span class="tts-result-btns">
                    <a id="btnDownload" class="btn btn-outline btn-xs" download="pixel-tts.mp3" style="display:none;">⬇ 下载 MP3</a>
                    <button id="btnSrt" class="btn btn-outline btn-xs" type="button" title="逐字时间轴字幕文件">💬 字幕 SRT</button>
                    <button id="btnVideo" class="btn btn-outline btn-xs" type="button" title="带卡拉OK字幕画面（实时录制，时长≈音频）">🎬 生成视频</button>
                    <a id="btnVideoDl" class="btn btn-outline btn-xs" style="display:none;">⬇ 下载视频</a>
                </span>
            </div>
            <audio id="ttsAudio" class="tts-audio" controls preload="metadata"></audio>
            <div id="recStatus" class="tts-rec-status" style="display:none;">🎬 录制中…</div>
            <div class="tts-sub-title">💬 逐字字幕 <span class="tts-sub-note">（估算时间轴 · 点击文字可跳转）</span></div>
            <div id="ttsSubtitles" class="tts-subtitles md-body"></div>
        </div>

        <div id="ttsLoading" class="tts-loading" style="display:none;">
            <span class="tts-loading-icon">🎧</span> 正在合成语音，请稍候...
        </div>

        <div id="ttsError" class="error-msg" style="display:none;"></div>
    </div>

    <!-- Toast -->
    <div id="toast" class="toast" style="display:none;"></div>

    <script src="js/tts.js?v=11"></script>
</body>
</html>