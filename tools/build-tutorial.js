#!/usr/bin/env node
/**
 * 构建教程数据文件：docs/便签使用教程-*.md → bianqian/js/tutorial-data.js
 * 用法：node tools/build-tutorial.js
 * 内容零硬编码：教程改完 md 后重跑本脚本 + bump 引用版本号即可。
 */
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const src = path.join(root, 'docs', '便签使用教程-从入门到精通.md');
const out = path.join(root, 'bianqian', 'js', 'tutorial-data.js');

const md = fs.readFileSync(src, 'utf8');
// 以 JSON 字符串安全转义后注入全局变量
const payload = JSON.stringify(md);
const js = '/* 自动生成：由 docs/便签使用教程-从入门到精通.md 构建而来（tools/build-tutorial.js），勿手改 */\n'
  + 'window.TUTORIAL_MD = ' + payload + ';\n';
fs.writeFileSync(out, js, 'utf8');
console.log('OK ->', out, '(' + js.length + ' bytes)');
