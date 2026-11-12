# 桌面状态屏 MCU ↔ 后端 TCP/HTTP 接口约定与接口指南 (MCU_API)

> **版本**：v2.1  
> **适用终端**：ESP32 / ESP32-C3 / 4G CAT1 (EC600 / Air780) 等单片机设备  
> **传输协议**：Raw TCP 明文 Socket（默认端口 8888）/ HTTP REST API  

---

## 1. 核心协议规范

### 1.1 指令格式（行协议）
* 每条指令单行发送，以 `\n` 或 `\r\n` 结尾。
* 指令大小写不敏感（推荐大写），字段之间以空格分隔。
* 错误约定：服务端若以 `ERR 4xx/5xx <描述>` 开头表示出错，设备按错误码处理，不解析正文；正常响应以标准 JSON 单行输出。

### 1.2 鉴权与安全
* 除联调指令（`MOCK_*`）外，所有业务指令必须携带用户的 `API_KEY`（Desk Key）。
* 密钥格式：`pn_desk_<32位十六进制>`。

---

## 2. 状态屏基础指令 (STATUS / ROAST / WEATHER)

| 指令 | 参数格式 | 服务端行为 | 响应示例 / 说明 |
| :--- | :--- | :--- | :--- |
| **STATUS** | `STATUS <KEY> [PAGE] [BOOK_ID]` | 获取今日登录、日记完成状态与当前时间戳（顺带上报当前阅读进度） | `{"ts":1794457494,"d":"2026-11-12","open":1,"diary":1,"todo":0,"pg_saved":1,"pg_pages":1054}` |
| **ROAST** | `ROAST <KEY>` | 调用管家大模型并结合历史记忆，流式向 Socket 吐出不超过 30 字的吐槽文本 | 0.1s/字打字机流式输出，`\n` 结尾；服务端预留 60s 超时 |
| **WEATHER** | `WEATHER <KEY> [city\|adcode]` | 获取天气与温湿度（带 30 分钟缓存） | `{"code":200,"city":"北京","weather":"晴","temp":18,"humidity":45}` |

---

## 3. 小说书架管理 (BOOKS / PICK / PROGRESS)

### 3.1 拉取书单 (BOOKS)
* **指令**：`BOOKS <KEY>`
* **回包**：
  ```json
  {
    "total": 3,
    "current": "7128048920910892067",
    "books": [
      {
        "i": 1,
        "id": "7128048920910892067",
        "title": "原神：开局变成纳西妲",
        "author": "梦里寻九",
        "page": 82,
        "pages": 1054,
        "pts": 1794457494
      }
    ]
  }
  ```
  * `page`：当前已读页码（无进度给 1，不给 0）。
  * `pages`：全书总页数（永远 > 0）。
  * `pts`：云端最后更新时间（Unix 秒），供设备比对本地离线进度。

### 3.2 选定当前书籍 (PICK)
* **指令**：`PICK <KEY> <序号1-10 或 BOOK_ID>`
* **回包**：`{"ok":1,"book_id":"7128048920910892067","title":"原神：开局变成纳西妲"}`

### 3.3 进度上报 (PROGRESS)
* **指令**：`PROGRESS <KEY> <PAGE> <BOOK_ID> [AT]`
* **回包**：
  ```json
  {"ok":1,"book_id":"7128048920910892067","title":"原神：开局变成纳西妲","page":82,"pages":1054,"ts":1794457494,"saved":1}
  ```
  * `saved`：`1` = 采纳；`0` = 比云端旧被忽略。
  * 若该书在网页已被删除：返回 `{"ok":0,"error":"该书已从书架移出，进度已清除","code":404,"reason":"BOOK_DELETED"}`。

---

## 4. 小说正文读取 (NOVEL / CHAPTER)

### 4.1 全局页码阅读 (NOVEL)
* **指令**：`NOVEL <KEY> <PAGE> [序号1-10 或 BOOK_ID]`
* **回包**：
  ```json
  {
    "title": "原神：开局变成纳西妲",
    "chapter_title": "第12章 ...",
    "chapter": 12,
    "page": 82,
    "pages": 1054,
    "chap_page": 1,
    "chap_pages": 8,
    "text": "正文内容..."
  }
  ```
  * `text`：单次切片严格不超过 850 字节 UTF-8，为单片机 1024 字节静态缓冲预留足够 JSON 余量。

### 4.2 按章读取 (CHAPTER)
* **指令**：`CHAPTER <KEY> <N> [PAGE] [序号1-10 或 BOOK_ID]`

---

## 5. 时间同步与离线冲突判定

1. **时钟校准**：设备以 `STATUS` 回包中的 `ts`（Unix 秒）作为基准：
   $$\text{设备时间} = \text{ts} + \frac{\text{millis}() - \text{接收时刻 millis}()}{1000}$$
2. **打开书籍时的裁决**：
   * 本地 `ts` > 云端 `pts` $\rightarrow$ 信本地，进入阅读并立即发起补报。
   * 本地 `ts` $\le$ 云端 `pts` $\rightarrow$ 信云端，覆盖本地进度。
   * 云端无 `pts` $\rightarrow$ 一律信云端。

---

## 6. 限流与超时保护

1. **吐槽限流**：每分钟最多 2 次，超频返回 `ERR 429 TOO MANY REQUESTS (MAX 2 PER MINUTE)`，设备端退避冷却建议设为 31 秒。
2. **大模型思考耗时 (TTFT)**：带思考链（CoT）推理模型或高峰排队首字耗时可能达 20~30 秒。服务端已放宽至 60 秒，**设备端读取超时必须设置为 60 秒以上**（如 `client.setTimeout(60000)`），且轮询循环中加入 `delay(10)` 喂狗，切勿设置 5s/10s 过早超时！

---

## 7. 单片机预取与高频阅读性能优化指南 (按收益排序)

针对单片机（特别是 4G CAT1 模组 / ESP32）在翻页与三页预取（3×1KB 静态缓冲）时的网络瓶颈，服务端已完成以下三项架构升级：

### 收益第 1 名：章节级切片常驻内存 LRU 缓存（已上线）
* **原理**：服务端内置 30 章节 LRU 内存缓存池。上游拉取一整章后按 ~300 字/页在内存精准切片并持久保持。
* **实测收益**：
  * **冷页请求**（首次拉取上游）：~870ms
  * **热页请求**（命中 LRU 缓存）：**~110ms（含跨洋网络往返；VPS 本机处理延迟 <1ms！）**
  * **加速比**：**7.6 倍以上**。
* **设备端收益**：**设备端一行代码不用改**，连续翻页或预取时，同一章后续切片瞬间秒回！

---

### 收益第 2 名：Keep-Alive 连接保活（单连接跑多条命令，已上线）
* **旧痛点**：旧版服务端处理完单条命令即主动 `close()`。4G CAT1 模组（MIPOPEN）每次建连握手耗时 1~2 秒，三页预取需要连续建连 3 次，光握手就浪费 4~6 秒。
* **新架构**：
  * **读完一行回一行，不主动断开连接**；
  * 支持设备在**同一个 TCP 连接**内连续流水线发送指令（如 `NOVEL 82` $\rightarrow$ `NOVEL 83` $\rightarrow$ `NOVEL 84`）；
  * **空闲 30 秒超时**：连接若连续 30 秒无任何新数据传输，服务端才安全关闭释放。
* **单片机端接入代码（推荐）**：
  ```cpp
  // 保持 client 打开，连续拉取阅读页与预取页：
  if (!client.connected()) {
      client.connect(SERVER_IP, SERVER_PORT, 5000);
  }
  // 1. 发送当前页
  client.printf("NOVEL %s 82\n", API_KEY);
  String line1 = client.readStringUntil('\n'); // 立即拿到 82 页

  // 2. 无需 reconnect，直接发送预取页！
  client.printf("NOVEL %s 83\n", API_KEY);
  String line2 = client.readStringUntil('\n'); // 命中缓存瞬回！

  // 3. 继续预取下一页
  client.printf("NOVEL %s 84\n", API_KEY);
  String line3 = client.readStringUntil('\n'); // 命中缓存瞬回！
  ```

---

### 收益第 3 名：批量取页指令 (NOVELS，已上线)
* **指令格式**：
  ```text
  NOVELS <KEY> <START_PAGE> [COUNT] [序号1-10 或 BOOK_ID]
  ```
  * `START_PAGE`：起始全局页码（如 `82`）
  * `COUNT`：连续拉取页数（默认为 `3`，限制 `1 ~ 5`）
  * `BOOK_ID`：可选（省略则默认为当前书架选中书）
* **服务端行为**：
  单次 TCP 请求，服务端内部走 LRU 缓存，**连续向 Socket 吐出 `COUNT` 行标准小说 JSON（每页一行，`\n` 结尾）**。
* **实测耗时**：一次请求批量收齐 3 页切片仅耗时 **201ms**！
* **单片机端解析示例**：
  ```cpp
  client.printf("NOVELS %s 82 3\n", API_KEY);
  for (int i = 0; i < 3; i++) {
      String jsonLine = client.readStringUntil('\n');
      // 解析单页并推入 3x1KB 静态预取队列...
  }
  ```
