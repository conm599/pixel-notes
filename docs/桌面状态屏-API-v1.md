# 桌面状态屏开放接口 (API v1) 完整技术规范与参数文档

> **版本**：v1.0.0  
> **更新日期**：2026-11-08  
> **作者/维护方**：Pixel Notes 后端团队  
> **服务基址**：`https://bianqian.naxid.top/api/open.php`  
> **时区基准**：`Asia/Shanghai`（北京时间，UTC+8）  

---

## 1. 概述与设计哲学

本接口专为**硬件桌面状态屏**（如基于 ESP32-C3 单片机搭配 1.3 寸 240×240 IPS 屏幕的桌面摆件）及第三方桌面伴侣应用设计，提供便签系统今日状态的只读查询与拟人化 AI 吐槽能力。

### 核心设计原则
1. **客户端主动拉取（Pull-Only）**：MCU 客户端按计划定期向后端拉取数据，后端从不主动推送，无需维持昂贵的长连接 WebSocket，极大降低 ESP32 功耗与内存消耗。
2. **轻量紧凑与硬件友好**：针对 MCU 可用堆内存仅约 300KB 的硬件限制，接口数据字段名极致精简，JSON 尺寸控制在 100 字节以内，解析缓冲区仅需 256 字节。
3. **输出绝对安全可控**：字符集严格限定为常用 **GB2312** 范围（MCU 常用点阵字库），严格限制 30 汉字以内，全面剥离 Emoji、换行符及中英文引号，杜绝屏幕方块乱码与排版溢出。
4. **即插即用 Mock 联调**：提供与生产格式完全一致的 `/mock/status` 与 `/mock/roast`，硬件端使用测试密钥即可立即开始调屏，无需等待业务数据。

---

## 2. 身份鉴权与密钥管理

所有开放接口（含 Mock）均实行统一的密钥鉴权，只读权限，不暴露便签与日记的具体文本内容。

### 2.1 密钥申请与生命周期
- **获取途径**：登录便签系统（`https://bianqian.naxid.top`），点击右上角「设置」菜单（手机端为左侧抽屉）→「桌面状态屏」；
- **格式**：`pn_desk_<32位十六进制字符串>`（例如 `pn_desk_973f824dcd77d5ae7d387ab6f31c24c9`）；
- **操作支持**：
  - **一键申请 / 重新生成**：生成新 Key 会使旧 Key 立即失效；
  - **删除 Key**：完全注销第三方访问权限；
  - **管家性格自定义**：用户可在前端设定个性化人设（如“嘴硬心软、爱吐槽的管家”、“元气毒舌傲娇女友”等）。

### 2.2 传参方式（3 种支持，按推荐顺序）

| 方式 | 格式示例 | 适用场景 |
|---|---|---|
| **请求头（首选推荐）** | `X-API-Key: pn_desk_xxxx` | 标准 HTTPS 请求，安全且无 URL 泄露风险 |
| **标准 Bearer 头** | `Authorization: Bearer pn_desk_xxxx` | 兼容标准 API 客户端与网关代理 |
| **URL 查询参数** | `?key=pn_desk_xxxx` 或 `?api_key=pn_desk_xxxx` | 极简单片机 HTTP 客户端备用 |

> [!NOTE]
> **保持登录态的自动活跃刷新**：  
> 用户日常在浏览器保持登录便签时，只要当天打开过便签网页（`index.php`）或执行过便签操作，系统会自动通过 Session 标记刷新今日登录时间，**无需每天退出重登**即可让桌面屏感知到 `open: 1`。

---

## 3. 接口详细规范

### 3.1 接口一：获取今日状态（GET /status）

MCU 建议以 **60 秒** 为间隔轮询请求，用来刷新屏幕上的图标、打卡指示灯与时钟。

- **请求方式**：`GET`
- **请求 URL**：`https://bianqian.naxid.top/api/open.php?endpoint=status`
- **请求头**：`X-API-Key: <用户的Key>`

#### 响应说明
- **HTTP 状态码**：`200 OK`
- **Content-Type**：`application/json; charset=utf-8`

#### 响应字段表

| 字段名 | 类型 | 含义 | 说明与取值 |
|---|---|---|---|
| `ts` | 整数 (int) | 当前 Unix 时间戳（秒） | 服务器当前绝对时间，MCU 可直接用于校对本地 RTC |
| `d` | 字符串 (string) | 今日日期 | `YYYY-MM-DD` 格式，严格按照 `Asia/Shanghai` 时区计算 |
| `open` | 整数 (0 或 1) | 今日便签打开状态 | `1` 表示今天已登录或已打开便签，`0` 表示今天尚未打开过 |
| `diary` | 整数 (0 或 1) | 今日便签/日记书写状态 | `1` 表示今天已创建或修改过便签，`0` 表示今天尚未动笔 |
| `todo` | 整数 (int) | 未完成待办数量 | 契约保留字段，当前版本固定返回 `0` |

#### 响应示例
```json
{"ts":1794135571,"d":"2026-11-08","open":1,"diary":1,"todo":0}
```

---

### 3.2 接口二：AI 拟人吐槽（GET /roast）

MCU 达到设定的提醒时间（或用户轻触屏幕物理按键）时请求一次，后端调用大模型实时生成一句吐槽，并按 **0.1 秒/字** 的速率伪流式逐字回传，在屏幕上呈现打字机动效。

- **请求方式**：`GET`
- **请求 URL**：`https://bianqian.naxid.top/api/open.php?endpoint=roast`
- **请求头**：`X-API-Key: <用户的Key>`

#### 响应特性与头信息
- **HTTP 状态码**：`200 OK`
- **Content-Type**：`text/plain; charset=utf-8`
- **X-Accel-Buffering**：`no`（禁用 Nginx 与反代中间层缓冲，确保字符即时到达客户端）
- **Cache-Control**：`no-cache, no-store, must-revalidate`
- **传输模式**：分块逐字传输（Chunked Stream），连接正常关闭即代表句子发送完毕。

#### 后端输出铁律保证
1. **字数硬截断**：整句总字数绝对不超 **30 个汉字**（超出时优先在最后一个标点处自然截断）。
2. **字符集纯净**：全量过滤 Emoji 表情、换行符（`\r\n`）、制表符、英文双引号、单引号与反斜杠，字符清洗后全部转换为 **GB2312** 编码兼容汉字。
3. **频率限制（Rate Limit）**：**每分钟最多允许请求 1 次**。若短时间内重复触发，接口返回 `429 Too Many Requests`，并携带 `Retry-After: <秒数>` 响应头。
4. **多轮记忆与智能压缩**：
   - 对话上下文存储于后端数据库，支持多轮拟人化记忆继承；
   - 累积上下文达到 **200K 字符** 时触发无感压缩，**完整保留最近 30 条对话记录** 并生成前期互动摘要，最大上下文容量为 256K。

#### 响应示例
```text
嘴上说懒得管你，日记都写了，便签却空着，补一条呗。
```

---

### 3.3 接口三与四：联调假接口（Mock 模式）

专为单片机团队在前期无真实账号、离线或网络调优时设计。

#### GET /mock/status
- **URL**：`https://bianqian.naxid.top/api/open.php?endpoint=mock/status`
- **鉴权**：可传入任意非空调试 Key（例如 `X-API-Key: mcu_debug_key`）；若不传或传入 `invalid` 会返回 `401` 以便单片机测试错误捕获分支。
- **返回**：固定返回规范结构体：
  ```json
  {"ts":1794128220,"d":"2026-11-08","open":1,"diary":0,"todo":3}
  ```

#### GET /mock/roast
- **URL**：`https://bianqian.naxid.top/api/open.php?endpoint=mock/roast`
- **返回**：不消耗大模型 Token，按 0.1s 间隔流式吐出固定文案：
  ```text
  今天日记又没写，还好意思看屏幕？
  ```

---

## 4. HTTP 状态码与客户端处理规范

硬件端单片机只需根据 HTTP 状态码执行对应动作，无需额外解析错误正文：

| HTTP 状态码 | 含义 | MCU 单片机处理策略 |
|---|---|---|
| **200** | 成功 | 解析 JSON 或按流绘制文字上屏 |
| **401** | 未授权 / Key 错误 | 屏幕显示“密钥错误”图标或提示，**禁止重试**（避免浪费流量） |
| **429** | 请求过于频繁 | 读取 `Retry-After` 头秒数（未读取则默认等待 60s），期间维持上一次显示内容 |
| **503** | 大模型或服务不可用 | 屏幕降级显示单片机 Flash 本地固化的兜底文案（如“今天先放过你，快去写便签！”） |
| **504 / 超时** | 网络响应超时 | 显示离线或维持旧内容，按退避策略重试 |

### 超时与退避建议
- **连接超时**：5 秒
- **`/status` 等待超时**：10 秒
- **`/roast` 首字节等待超时**：20 秒（大模型实时推理需 2~4 秒）
- **`/roast` 字符间隙超时**：5 秒
- **重试退避间隔**：失败后按 60s -> 120s -> 240s -> 最大 600s 递增重试。

---

## 5. ESP32 (Arduino C++) 接入示例代码

```cpp
#include <WiFi.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <ArduinoJson.h>

const char* API_KEY = "pn_desk_973f824dcd77d5ae7d387ab6f31c24c9";
const char* STATUS_URL = "https://bianqian.naxid.top/api/open.php?endpoint=status";
const char* ROAST_URL  = "https://bianqian.naxid.top/api/open.php?endpoint=roast";

// 1. 获取状态数据
void fetchDeskStatus() {
    WiFiClientSecure client;
    client.setInsecure(); // 简化证书校验，亦可加载根证书
    
    HTTPClient https;
    if (https.begin(client, STATUS_URL)) {
        https.addHeader("X-API-Key", API_KEY);
        https.setTimeout(10000);
        
        int httpCode = https.GET();
        if (httpCode == HTTP_CODE_OK) {
            String payload = https.getString();
            
            // 使用 ArduinoJson 6 解析精简结构体
            StaticJsonDocument<256> doc;
            DeserializationError error = deserializeJson(doc, payload);
            if (!error) {
                long ts = doc["ts"];
                const char* date = doc["d"];
                int open = doc["open"];
                int diary = doc["diary"];
                
                Serial.printf("今日状态: 日期=%s, 打开=%d, 写日记=%d\n", date, open, diary);
                // TODO: 绘制到 TFT / IPS 屏幕
            }
        } else if (httpCode == 401) {
            Serial.println("API Key 无效，请检查设置！");
        }
        https.end();
    }
}

// 2. 逐字流式读取吐槽
void fetchDeskRoast() {
    WiFiClientSecure client;
    client.setInsecure();
    
    HTTPClient https;
    if (https.begin(client, ROAST_URL)) {
        https.addHeader("X-API-Key", API_KEY);
        https.setTimeout(25000); // AI 推理预留较长超时
        
        int httpCode = https.GET();
        if (httpCode == HTTP_CODE_OK) {
            WiFiClient* stream = https.getStreamPtr();
            Serial.print("吐槽内容: ");
            
            // 逐字/逐块接收并渲染
            while (https.connected() && (stream->available() || stream->connected())) {
                while (stream->available()) {
                    char c = stream->read();
                    Serial.print(c);
                    // TODO: 将字符喂入 UTF-8/GB2312 屏幕字库渲染缓冲区
                }
                delay(10);
            }
            Serial.println();
        } else if (httpCode == 429) {
            Serial.println("触发限流保护，请稍后重试");
        }
        https.end();
    }
}
```

## 6. 明文 TCP Socket 直连接入规范（非 HTTP/S，MCU 首选）

专为单片机彻底去除 HTTP 协议头解析、避免 TLS/HTTPS 握手内存消耗（省下 ~40KB RAM，握手提速 3 倍）设计。

### 6.1 服务端连接信息
- **服务器 IP**：`154.219.110.21`（亦可直连域名 `bianqian.naxid.top`）
- **TCP 端口**：`8888`
- **传输协议**：Raw TCP Stream（明文传输，无任何 HTTP 请求头与响应头封装）
- **自启动与自愈机制**：后端已集成进程守护机制，随 PHP 主服务与 Web 请求自动拉起并在崩溃后自愈。

### 6.2 指令与响应规范（单行交互）

客户端通过 TCP 连接服务器后，发送单行命令（以 `\n` 结尾）：

| 指令 | 客户端发送格式（以 `\n` 结尾） | 服务端应答内容与行为 | 网络延迟 |
|---|---|---|---|
| **获取状态** | `STATUS <API_KEY>\n` | 直接回传状态 JSON 字符串 `{"ts":...,"d":...,"open":1,"diary":1,"todo":0}\n`，发送完毕自动关闭 Socket | **~0.1s** |
| **获取吐槽** | `ROAST <API_KEY>\n` | 以 0.1s/字 逐字流式向 Socket 写入纯文本，传输完毕追加 `\n` 并自动关闭 Socket | ~4-6s |
| **Mock 状态** | `MOCK_STATUS\n` | 直接回传固定 Mock 状态 JSON（免 Key） | <0.1s |
| **Mock 吐槽** | `MOCK_ROAST\n` | 逐字流式吐出固定 Mock 吐槽文本（免 Key） | ~1.6s |
| **获取天气** | `WEATHER [city\|adcode]\n` | 同端口明文 TCP 代理 uapis 天气数据（10分钟缓存，紧凑 JSON 回传），完毕自动断开 | **<0.05s** (缓存命中) |

> 异常应答：若鉴权失败直接返回 `ERR 401 UNAUTHORIZED\n`，若触发限流返回 `ERR 429 TOO MANY REQUESTS\n`。

### 6.3 ESP32 Arduino 极简 TCP 代码示例（仅需基础 WiFiClient）

```cpp
#include <WiFi.h>
#include <ArduinoJson.h>

const char* SERVER_IP = "154.219.110.21";
const uint16_t SERVER_PORT = 8888;
const char* API_KEY = "pn_desk_973f824dcd77d5ae7d387ab6f31c24c9";

// 1. 获取状态（普通 WiFiClient，无需 WiFiClientSecure，仅需几字节缓冲区，耗时仅 100ms）
void fetchStatusTcp() {
    WiFiClient client;
    if (client.connect(SERVER_IP, SERVER_PORT, 5000)) {
        client.printf("STATUS %s\n", API_KEY);
        String line = client.readStringUntil('\n');
        client.stop();

        StaticJsonDocument<256> doc;
        if (!deserializeJson(doc, line)) {
            long ts   = doc["ts"];
            int open  = doc["open"];
            int diary = doc["diary"];
            Serial.printf("TCP 状态获取成功: open=%d, diary=%d\n", open, diary);
            // TODO: 绘制到桌面屏幕
        }
    } else {
        Serial.println("TCP 连接失败");
    }
}

// 2. 流式获取吐槽（逐字接收，直接送入屏幕刷新打字机效果）
void fetchRoastTcp() {
    WiFiClient client;
    if (client.connect(SERVER_IP, SERVER_PORT, 5000)) {
        client.printf("ROAST %s\n", API_KEY);
        Serial.print("吐槽接收: ");
        
        while (client.connected() || client.available()) {
            while (client.available()) {
                char c = client.read();
                Serial.print(c);
                // TODO: 喂入字库绘制到屏幕
            }
            delay(10);
        }
        Serial.println();
        client.stop();
    }
}
```


### 6.4 ESP32 获取天气极简代码示例（免 HTTP/TLS 协议栈）

```cpp
// 3. 免 HTTP 代理获取天气（单行 TCP，耗时 <50ms，完全消除 TLS/HTTP 握手开销）
void fetchWeatherTcp(const char* city = "北京") {
    WiFiClient client;
    if (client.connect(SERVER_IP, SERVER_PORT, 5000)) {
        if (city && strlen(city) > 0) {
            client.printf("WEATHER %s\n", city);
        } else {
            client.print("WEATHER\n"); // 默认按服务器公网 IP 自动定位
        }
        String line = client.readStringUntil('\n');
        client.stop();

        StaticJsonDocument<512> doc;
        DeserializationError err = deserializeJson(doc, line);
        if (!err) {
            const char* cityName = doc["city"];
            const char* weather = doc["weather"];
            int temp = doc["temperature"];
            int humidity = doc["humidity"];
            const char* wind = doc["wind_direction"];
            Serial.printf("[%s] 天气: %s, 气温: %d℃, 湿度: %d%%, 风向: %s\n", cityName, weather, temp, humidity, wind);
            // TODO: 送入墨水屏/TFT 屏幕天气组件刷新
        } else {
            Serial.println("解析天气 JSON 失败");
        }
    } else {
        Serial.println("连接天气 TCP 失败");
    }
}
```

### 6.5 天气返回 JSON 契约字段参考

```json
{
  "province": "北京市",
  "city": "北京",
  "adcode": "110000",
  "weather": "晴",
  "weather_icon": "100",
  "temperature": 5,
  "wind_direction": "西南风",
  "wind_power": "2级",
  "humidity": 56,
  "report_time": "10 分钟前发布"
}
```
