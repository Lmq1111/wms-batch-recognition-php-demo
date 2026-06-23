# WMS Batch Recognition API PHP

极简 PHP 版批次号识别接口服务。它只做一件事：接收图片，调用阿里云百炼 DashScope 视觉模型识别 WMS “厂商批号”字段可填写的候选值，并返回候选结果、审计字段和耗时字段。

本服务不包含前端页面，不接 WMS 生产入库，不自动提交结果。所有识别结果都必须由业务人员人工确认。

## 产品边界

- 第一阶段只识别批次号/厂商批号候选。
- 不识别生产日期、有效期、型号、规格、货号、数量、价格。
- 触发词同级：`LOT`、`Lot No`、`Lot Number`、`Batch`、`S/N`、`Serial No`、`Retrace Code`、`批号`、`生产批号`、`批次号`、`批次代码`、`序列号`、`产品序列号`、`序号`、`出厂编号`。
- 识别不到返回空值，不猜。
- 结果必须人工确认。
- 本服务不写入 WMS，不自动入库。
- 服务端不压缩、不留存图片文件，只记录收到的图片大小、MIME、宽高、前端压缩参数、识别结果、耗时和人工反馈日志。

## 运行要求

目标服务器：CentOS 7.4 64 位。

建议运行环境：

- PHP 7.2 或更高，推荐 PHP 7.4。
- PHP 扩展：`curl`、`json`。
- Web Server：Nginx + PHP-FPM，或 Apache + PHP。

CentOS 7.4 自带 PHP 版本通常偏旧，建议使用 Remi 源安装 PHP 7.4。

## CentOS 7.4 部署方式

### 1. 安装 PHP 7.4 与扩展

```bash
sudo yum install -y epel-release yum-utils
sudo rpm -Uvh https://rpms.remirepo.net/enterprise/remi-release-7.rpm
sudo yum-config-manager --enable remi-php74
sudo yum install -y php php-cli php-fpm php-json php-curl php-mbstring

php -v
php -m | egrep 'curl|json'
```

### 2. 拉取代码

```bash
sudo mkdir -p /opt/wms-batch-recognition-api-php
sudo chown -R $USER:$USER /opt/wms-batch-recognition-api-php

git clone git@github.com:Lmq1111/wms-batch-recognition-api-php.git /opt/wms-batch-recognition-api-php
cd /opt/wms-batch-recognition-api-php
```

### 3. 配置环境变量

```bash
cp .env.example .env
vi .env
```

`.env` 示例：

```bash
DASHSCOPE_API_KEY=你的百炼APIKey
WMS_API_TOKEN=给WMS或小程序后端的共享Token
AI_MODEL=qwen3.6-flash
AI_API_ENDPOINT=https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions
AI_MAX_TOKENS=900
AI_TIMEOUT_MS=3000
CORS_ALLOW_ORIGIN=*
MAX_JSON_BYTES=18874368
RECOGNITION_LOG_PATH=logs/recognition-events.jsonl
```

不要把真实 API Key 提交到 Git。

### 4. 设置日志目录权限

```bash
mkdir -p logs
sudo chown -R nginx:nginx logs
sudo chmod -R 775 logs
```

如果 PHP-FPM 使用 `apache` 用户运行，把上面的 `nginx:nginx` 改成 `apache:apache`。

### 5. Nginx + PHP-FPM 配置

确认 PHP-FPM 已启动：

```bash
sudo systemctl enable php-fpm
sudo systemctl start php-fpm
sudo systemctl status php-fpm
```

新增 Nginx 配置，例如 `/etc/nginx/conf.d/wms-batch-recognition-api-php.conf`：

```nginx
server {
    listen 80;
    server_name your-domain.example.com;

    root /opt/wms-batch-recognition-api-php/public;
    index index.php;

    client_max_body_size 20m;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param SCRIPT_NAME $fastcgi_script_name;
        fastcgi_pass 127.0.0.1:9000;
    }
}
```

检查并重载：

```bash
sudo nginx -t
sudo systemctl reload nginx
```

微信小程序正式联调通常需要 HTTPS 域名，并在小程序后台配置 request 合法域名。建议由 IT 用 Nginx/网关统一加 HTTPS、鉴权和访问日志。

### 6. 临时本地验证方式

如果只是临时验证，也可以使用 PHP 内置服务：

```bash
cd /opt/wms-batch-recognition-api-php
php -S 0.0.0.0:5178 -t public
```

生产部署不建议长期使用 PHP 内置服务。

## 接口

### 健康检查

```text
GET /api/health
```

返回模板：

```json
{
  "ok": true,
  "hasApiKey": true,
  "provider": "dashscope",
  "providerLabel": "DashScope",
  "model": "qwen3.6-flash",
  "thinking": "disabled",
  "aiTimeoutMs": 3000,
  "logEnabled": true,
  "runtime": "php",
  "phpVersion": "7.4.33"
}
```

### 批次识别

```text
POST /api/wms/batch-recognize
Content-Type: application/json
Authorization: Bearer <WMS_API_TOKEN>
```

也可以使用：

```text
X-API-Key: <WMS_API_TOKEN>
```

请求模板，传图片 base64：

```json
{
  "request_id": "optional-client-request-id",
  "imageBase64": "base64-content-without-data-url-prefix",
  "mimeType": "image/jpeg",
  "source": "wms-miniapp-test",
  "image_meta": {
    "compressed": true,
    "original_image_size_kb": 1800,
    "recognition_image_size_kb": 320,
    "width": 1200,
    "height": 900,
    "max_side": 1600,
    "quality": 0.82
  },
  "client_meta": {
    "device": "optional",
    "operator_id": "optional"
  }
}
```

请求模板，传完整 data URL：

```json
{
  "request_id": "optional-client-request-id",
  "imageDataUrl": "data:image/jpeg;base64,...",
  "source": "wms-miniapp-test"
}
```

识别成功返回模板：

```json
{
  "ok": true,
  "request_id": "optional-client-request-id",
  "data": {
    "batch_number": "A-263100-3Z26",
    "status": "recognized",
    "confidence": "high",
    "trigger": "Serial No",
    "candidates": ["A-263100-3Z26"],
    "needs_human_confirmation": true
  },
  "audit": {
    "ai_raw_visible_text": "Serial No A-263100-3Z26",
    "ai_reason": "图片中明确存在触发词 Serial No..."
  },
  "meta": {
    "elapsed_ms": 1521,
    "total_elapsed_ms": 1540,
    "ai_timeout_ms": 3000,
    "provider": "dashscope",
    "provider_label": "DashScope",
    "model": "qwen3.6-flash",
    "thinking": "disabled",
    "image_info": {
      "received_image_size_kb": 320,
      "received_image_bytes": 327680,
      "received_mime_type": "image/jpeg",
      "received_width": 1200,
      "received_height": 900,
      "client_compressed": true,
      "client_original_image_size_kb": 1800,
      "client_recognition_image_size_kb": 320,
      "client_image_width": 1200,
      "client_image_height": 900,
      "client_image_max_side": 1600,
      "client_image_quality": 0.82
    }
  }
}
```

未识别到返回模板：

```json
{
  "ok": true,
  "request_id": "optional-client-request-id",
  "data": {
    "batch_number": "",
    "status": "not_found",
    "confidence": "unknown",
    "trigger": "",
    "candidates": [],
    "needs_human_confirmation": true
  },
  "audit": {
    "ai_raw_visible_text": "",
    "ai_reason": "未发现明确批次号触发词，按不猜测原则返回空值。"
  },
  "meta": {
    "elapsed_ms": 1400,
    "total_elapsed_ms": 1418,
    "ai_timeout_ms": 3000,
    "provider": "dashscope",
    "provider_label": "DashScope",
    "model": "qwen3.6-flash",
    "thinking": "disabled",
    "image_info": {}
  }
}
```

多候选返回模板：

```json
{
  "ok": true,
  "request_id": "optional-client-request-id",
  "data": {
    "batch_number": "M603004e",
    "status": "multiple_candidates",
    "confidence": "medium",
    "trigger": "Batch、LOT",
    "candidates": ["M603004e", "24031501"],
    "needs_human_confirmation": true
  },
  "audit": {
    "ai_raw_visible_text": "Batch M603004e LOT 24031501",
    "ai_reason": "存在多个同级触发词候选，需人工选择。"
  },
  "meta": {
    "elapsed_ms": 1600,
    "total_elapsed_ms": 1622,
    "ai_timeout_ms": 3000,
    "provider": "dashscope",
    "provider_label": "DashScope",
    "model": "qwen3.6-flash",
    "thinking": "disabled",
    "image_info": {}
  }
}
```

错误返回模板：

```json
{
  "ok": false,
  "error": "WMS 接口鉴权失败。"
}
```

`status` 取值：

| status | 含义 | 前端处理建议 |
|---|---|---|
| `recognized` | 识别到唯一候选 | 展示候选，必须人工确认后回填 |
| `multiple_candidates` | 存在多个候选 | 展示候选列表或让人工输入确认 |
| `not_found` | 未识别到明确批次号 | 允许人工填写或重新拍照，不能自动猜测 |
| `error` | 识别流程异常 | 提示失败，可重新拍照或人工填写 |

如果 AI 模型调用超过 `AI_TIMEOUT_MS`，接口会中止模型请求，并按 `status=not_found` 返回空批号，不作为接口错误处理。

### 人工确认反馈

```text
POST /api/wms/batch-feedback
Content-Type: application/json
Authorization: Bearer <WMS_API_TOKEN>
```

请求模板：

```json
{
  "request_id": "optional-client-request-id",
  "ai_batch_number": "A-263100-3Z26",
  "confirmed_batch_number": "A-263100-3Z26",
  "is_modified": false,
  "operator": "optional",
  "note": "optional"
}
```

人工确认值可以为空，表示本次最终没有填写批次号：

```json
{
  "request_id": "optional-client-request-id",
  "ai_batch_number": "",
  "confirmed_batch_number": "",
  "is_modified": false,
  "operator": "optional",
  "note": "未识别到，人工确认为空"
}
```

返回模板：

```json
{
  "ok": true,
  "request_id": "optional-client-request-id"
}
```

## 日志

识别事件、识别失败事件和反馈事件都会追加写入：

```text
logs/recognition-events.jsonl
```

日志文件会在首次写入时自动创建。字段说明见 [docs/log-fields.md](docs/log-fields.md)。

耗时字段口径：

- `elapsed_ms`：PHP 服务调用百炼模型的耗时，包含接口服务到模型服务的网络传输和模型返回时间。
- `total_elapsed_ms`：本接口从进入识别处理到生成响应前的总耗时，可与 `elapsed_ms` 对比估算服务端本地解析、图片信息读取、规则处理和日志前准备的开销。
- `ai_timeout_ms`：模型调用超时阈值。默认 3000ms；超过后接口中止 AI 请求，按 `status=not_found` 返回空批号，继续进入人工确认/填写。

## curl 测试

### 健康检查

```bash
curl http://your-domain.example.com/api/health
```

### 批次识别

```bash
IMAGE="/path/to/sample.jpg"
BASE64=$(base64 -w 0 "$IMAGE")

cat > /tmp/wms-batch-test.json <<EOF
{
  "request_id": "test-curl",
  "imageBase64": "$BASE64",
  "mimeType": "image/jpeg",
  "source": "curl-test",
  "image_meta": {
    "compressed": false
  }
}
EOF

curl -X POST http://your-domain.example.com/api/wms/batch-recognize \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer test-token" \
  --data-binary @/tmp/wms-batch-test.json
```

macOS 的 `base64` 可用：

```bash
BASE64=$(base64 -i "$IMAGE" | tr -d '\n')
```

### 反馈接口

```bash
curl -X POST http://your-domain.example.com/api/wms/batch-feedback \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer test-token" \
  -d '{
    "request_id": "test-curl",
    "ai_batch_number": "A-263100-3Z26",
    "confirmed_batch_number": "A-263100-3Z26",
    "is_modified": false,
    "operator": "tester"
  }'
```

## 目录结构

```text
.
├── public/
│   ├── .htaccess
│   └── index.php
├── docs/
│   └── log-fields.md
├── .env.example
├── .gitignore
└── README.md
```
