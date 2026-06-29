# 日志字段说明

本 PHP 服务会把识别事件、识别失败事件和人工反馈事件写入 JSONL 文件。

默认日志文件：

```text
logs/recognition-events.jsonl
```

可通过环境变量修改：

```text
RECOGNITION_LOG_PATH=/path/to/recognition-events.jsonl
```

日志目录和文件会在首次写入时自动创建。服务进程需要对目标目录有写权限。

默认日志保留最近 180 天事件。可通过环境变量调整：

```text
LOG_RETENTION_DAYS=180
```

服务每次写入日志前，会根据每行事件的 `created_at` 清理超过保留天数的旧行；无法解析 `created_at` 的异常行会保留，避免误删。设为 `0` 可关闭自动清理。

## 事件类型

| `event_type` | 含义 | 何时写入 |
|---|---|---|
| `recognition` | 识别事件 | 模型调用成功，并生成识别结果后写入 |
| `recognition_error` | 识别失败事件 | 图片已解析，但模型调用、返回或解析过程失败时写入 |
| `feedback` | 人工反馈事件 | 调用 `/api/wms/batch-feedback` 写入人工确认结果时写入 |

如果 AI 模型调用超过 `AI_TIMEOUT_MS`，服务会中止请求并记录为 `status=not_found` 的 `recognition` 事件，`ai_reason` 中会包含超时说明。

## 常用字段

| 字段 | 含义 |
|---|---|
| `request_id` | 调用方传入或服务端生成的请求 ID |
| `status` | `recognized`、`multiple_candidates`、`not_found` 或 `error` |
| `batch_number` | AI 最终给出的批次号候选，未识别到时为空字符串 |
| `production_date` | AI 识别到的生产日期，格式 `YYYY-MM-DD`；只到年月时补为当月 1 号，未识别到为空字符串 |
| `expiry_date` | AI 识别到的失效日期，格式 `YYYY-MM-DD`；只到年月时补为当月 1 号，未识别到为空字符串 |
| `candidates` | 候选批次号数组 |
| `confidence` | 模型置信度：`high`、`medium`、`low`、`unknown` |
| `trigger` | 命中的触发词 |
| `ai_raw_visible_text` | 模型看到的与批次识别相关的短文本 |
| `ai_reason` | 模型判断原因或后端兜底说明 |
| `elapsed_ms` | PHP 服务调用百炼模型的耗时 |
| `total_elapsed_ms` | 接口从进入识别处理到生成响应前的总耗时 |
| `ai_timeout_ms` | 模型调用超时阈值；超过后按 `status=not_found` 记录 |
| `image_info` | 收到的图片大小、MIME、宽高，以及调用方上报的压缩参数 |

## `image_info`

| 字段 | 含义 |
|---|---|
| `received_image_size_kb` | 服务端实际收到的图片大小，单位 KB |
| `received_image_bytes` | 服务端实际收到的图片字节数 |
| `received_mime_type` | 服务端收到的图片 MIME 类型 |
| `received_width` / `received_height` | 服务端读取到的图片宽高 |
| `client_compressed` | 调用方是否已压缩图片 |
| `client_original_image_size_kb` | 调用方上报的原图大小 |
| `client_recognition_image_size_kb` | 调用方上报的识别图大小 |
| `client_image_width` / `client_image_height` | 调用方上报的识别图宽高 |
| `client_image_max_side` | 调用方压缩时设置的最长边 |
| `client_image_quality` | 调用方压缩时设置的 JPEG 质量 |

## 反馈事件

`feedback` 事件用于把人工确认结果和识别结果按 `request_id` 关联起来。

| 字段 | 含义 |
|---|---|
| `ai_batch_number` | AI 返回的批次号 |
| `confirmed_batch_number` | 人工确认后的批次号，可为空 |
| `ai_production_date` | AI 返回的生产日期，可为空 |
| `confirmed_production_date` | 人工确认后的生产日期，可为空 |
| `ai_expiry_date` | AI 返回的失效日期，可为空 |
| `confirmed_expiry_date` | 人工确认后的失效日期，可为空 |
| `is_modified` | 人工确认值是否不同于 AI 返回值 |
| `operator` | 操作人，可选 |
| `note` | 备注，可选 |
