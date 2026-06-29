const state = {
  currentImageDataUrl: "",
  currentRecognitionImageDataUrl: "",
  currentImageMeta: null,
  recognitionTask: null,
  recognitionRunId: 0,
  confirmInputEdited: {
    batch: false,
    productionDate: false,
    expiryDate: false,
  },
  submitWarningAcknowledged: false,
  lastRecognition: null,
  photos: [],
};

const aiImageOptions = {
  maxSide: 1600,
  jpegQuality: 0.82,
  skipBelowBytes: 800 * 1024,
};

const els = {
  runtimeStatus: document.querySelector("#runtimeStatus"),
  batchInput: document.querySelector("#batchInput"),
  productionDateInput: document.querySelector("#productionDateInput"),
  expiryDateInput: document.querySelector("#expiryDateInput"),
  recognizeButton: document.querySelector("#recognizeButton"),
  addPhotoButton: document.querySelector("#addPhotoButton"),
  fileInput: document.querySelector("#fileInput"),
  uploadGrid: document.querySelector("#uploadGrid"),
  photoCount: document.querySelector("#photoCount"),
  submitButton: document.querySelector("#submitButton"),
  dialog: document.querySelector("#confirmDialog"),
  previewImage: document.querySelector("#previewImage"),
  recognitionState: document.querySelector("#recognitionState"),
  elapsedText: document.querySelector("#elapsedText"),
  confirmBatchInput: document.querySelector("#confirmBatchInput"),
  confirmProductionDateInput: document.querySelector("#confirmProductionDateInput"),
  confirmExpiryDateInput: document.querySelector("#confirmExpiryDateInput"),
  reasonText: document.querySelector("#reasonText"),
  rawText: document.querySelector("#rawText"),
  retakeButton: document.querySelector("#retakeButton"),
  confirmButton: document.querySelector("#confirmButton"),
  toast: document.querySelector("#toast"),
};

function showToast(message) {
  els.toast.textContent = message;
  els.toast.classList.add("show");
  window.clearTimeout(showToast.timer);
  showToast.timer = window.setTimeout(() => els.toast.classList.remove("show"), 2600);
}

function setStatus(label, tone = "") {
  els.recognitionState.textContent = label;
  els.recognitionState.className = `status-chip ${tone}`.trim();
}

function resetConfirmEdited() {
  state.confirmInputEdited = {
    batch: false,
    productionDate: false,
    expiryDate: false,
  };
}

function fileToDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(file);
  });
}

function estimateDataUrlBytes(dataUrl) {
  const base64 = dataUrl.split(",")[1] || "";
  return Math.ceil((base64.length * 3) / 4);
}

function formatSize(bytes) {
  if (!Number.isFinite(bytes) || bytes <= 0) return "0KB";
  return bytes >= 1024 * 1024
    ? `${(bytes / 1024 / 1024).toFixed(1)}MB`
    : `${Math.max(1, Math.round(bytes / 1024))}KB`;
}

function formatSeconds(ms) {
  return `${(ms / 1000).toFixed(1)}秒`;
}

function loadImage(dataUrl) {
  return new Promise((resolve, reject) => {
    const image = new Image();
    image.onload = () => resolve(image);
    image.onerror = () => reject(new Error("图片读取失败，请重新拍照或上传。"));
    image.src = dataUrl;
  });
}

function canvasToBlob(canvas, type, quality) {
  return new Promise((resolve, reject) => {
    canvas.toBlob(
      (blob) => {
        if (blob) resolve(blob);
        else reject(new Error("图片压缩失败，请重新拍照或上传。"));
      },
      type,
      quality
    );
  });
}

async function prepareImageForAi(imageDataUrl) {
  const started = performance.now();
  const originalBytes = estimateDataUrlBytes(imageDataUrl);
  const image = await loadImage(imageDataUrl);
  const originalWidth = image.naturalWidth || image.width;
  const originalHeight = image.naturalHeight || image.height;
  const originalMaxSide = Math.max(originalWidth, originalHeight);
  const needsResize = originalMaxSide > aiImageOptions.maxSide;
  const needsCompression = originalBytes > aiImageOptions.skipBelowBytes || needsResize;

  if (!needsCompression) {
    return {
      dataUrl: imageDataUrl,
      compressed: false,
      originalBytes,
      processedBytes: originalBytes,
      originalWidth,
      originalHeight,
      width: originalWidth,
      height: originalHeight,
      compressMs: performance.now() - started,
    };
  }

  const scale = Math.min(1, aiImageOptions.maxSide / originalMaxSide);
  const width = Math.max(1, Math.round(originalWidth * scale));
  const height = Math.max(1, Math.round(originalHeight * scale));
  const canvas = document.createElement("canvas");
  canvas.width = width;
  canvas.height = height;
  const context = canvas.getContext("2d", { alpha: false });
  context.fillStyle = "#ffffff";
  context.fillRect(0, 0, width, height);
  context.drawImage(image, 0, 0, width, height);

  const blob = await canvasToBlob(canvas, "image/jpeg", aiImageOptions.jpegQuality);
  const compressedDataUrl = await fileToDataUrl(blob);
  const processedBytes = estimateDataUrlBytes(compressedDataUrl);
  const useCompressed = needsResize || processedBytes < originalBytes;

  return {
    dataUrl: useCompressed ? compressedDataUrl : imageDataUrl,
    compressed: useCompressed,
    originalBytes,
    processedBytes: useCompressed ? processedBytes : originalBytes,
    originalWidth,
    originalHeight,
    width: useCompressed ? width : originalWidth,
    height: useCompressed ? height : originalHeight,
    compressMs: performance.now() - started,
  };
}

function formatImageTiming(aiElapsedMs, meta) {
  const parts = [`AI ${formatSeconds(aiElapsedMs)}`];
  if (meta) {
    const action = meta.compressed ? "压缩" : "检查";
    parts.push(`${action} ${formatSeconds(meta.compressMs)}`);
    if (meta.compressed) {
      parts.push(`${formatSize(meta.originalBytes)}→${formatSize(meta.processedBytes)}`);
    }
  }
  return parts.join(" · ");
}

function formatImageProcessing(meta) {
  if (!meta) return "";
  const action = meta.compressed ? "压缩" : "检查";
  const parts = [`${action} ${formatSeconds(meta.compressMs)}`];
  if (meta.compressed) {
    parts.push(`${formatSize(meta.originalBytes)}→${formatSize(meta.processedBytes)}`);
  }
  return parts.join(" · ");
}

function startRecognitionTask(imageDataUrl) {
  state.recognitionRunId += 1;
  resetConfirmEdited();
  state.submitWarningAcknowledged = false;
  state.recognitionTask = {
    id: state.recognitionRunId,
    imageDataUrl,
    recognitionImageDataUrl: "",
    imageMeta: null,
    recognition: null,
    status: "preparing",
    errorMessage: "",
    confirmed: false,
  };
  state.currentImageDataUrl = imageDataUrl;
  state.currentRecognitionImageDataUrl = "";
  state.currentImageMeta = null;
  state.lastRecognition = null;
  els.confirmBatchInput.value = "";
  els.confirmProductionDateInput.value = "";
  els.confirmExpiryDateInput.value = "";
  renderConfirmDialog();
  recognizeCurrentImage(state.recognitionRunId);
}

function normalizeRecognitionResponse(payload) {
  const data = payload?.data || {};
  const meta = payload?.meta || {};
  const audit = payload?.audit || {};
  return {
    batch_number: data.batch_number || "",
    production_date: data.production_date || "",
    expiry_date: data.expiry_date || "",
    status: data.status || "error",
    confidence: data.confidence || "unknown",
    trigger: data.trigger || "",
    candidates: Array.isArray(data.candidates) ? data.candidates : [],
    reason: audit.ai_reason || "",
    raw_visible_text: audit.ai_raw_visible_text || "",
    elapsed_ms: meta.elapsed_ms || 0,
    total_elapsed_ms: meta.total_elapsed_ms || 0,
  };
}

function applyRecognitionFields(recognition) {
  if (!recognition) return;
  if (!state.confirmInputEdited.batch && recognition.batch_number) {
    els.confirmBatchInput.value = recognition.batch_number;
  }
  if (!state.confirmInputEdited.productionDate && recognition.production_date) {
    els.confirmProductionDateInput.value = recognition.production_date;
  }
  if (!state.confirmInputEdited.expiryDate && recognition.expiry_date) {
    els.confirmExpiryDateInput.value = recognition.expiry_date;
  }
}

function renderConfirmDialog() {
  const task = state.recognitionTask;
  if (!task || task.confirmed) return;

  state.currentImageDataUrl = task.imageDataUrl;
  state.currentRecognitionImageDataUrl = task.recognitionImageDataUrl;
  state.currentImageMeta = task.imageMeta;
  state.lastRecognition = task.recognition;
  els.previewImage.src = task.imageDataUrl;
  els.elapsedText.textContent = task.recognition?.elapsed_ms
    ? formatImageTiming(task.recognition.elapsed_ms, task.imageMeta)
    : task.imageMeta
      ? formatImageProcessing(task.imageMeta)
      : "";
  els.rawText.textContent = task.recognition?.raw_visible_text
    ? `可见文字：\n${task.recognition.raw_visible_text}`
    : "";

  applyRecognitionFields(task.recognition);

  if (task.status === "preparing") {
    setStatus("准备中", "warn");
    els.reasonText.textContent = "图片已载入，正在准备识别图。";
  } else if (task.status === "recognizing") {
    setStatus("识别中", "warn");
    els.reasonText.textContent = "AI 正在识别批次号、生产日期和失效日期，你也可以先人工填写。";
  } else if (task.status === "recognized") {
    setStatus("已识别，待确认", "ok");
    els.reasonText.textContent =
      task.recognition?.reason || "已识别到候选字段，请人工确认后回填。";
  } else if (task.status === "multiple_candidates") {
    setStatus("多候选，需确认", "warn");
    els.reasonText.textContent = [
      task.recognition?.reason || "识别到多个批次候选，请人工确认。",
      task.recognition?.candidates?.length ? `候选：${task.recognition.candidates.join("、")}` : "",
    ]
      .filter(Boolean)
      .join(" ");
  } else if (task.status === "not_found") {
    setStatus("未识别到批号", "warn");
    els.reasonText.textContent =
      task.recognition?.reason || "未找到明确批次号；生产日期/失效日期如有明确识别会保留，否则可人工填写或留空。";
  } else {
    setStatus("识别失败", "danger");
    els.reasonText.textContent = task.errorMessage || "AI 返回异常，请人工填写或重新拍照。";
  }

  if (!els.dialog.open) els.dialog.showModal();
}

async function recognizeCurrentImage(runId) {
  const started = performance.now();
  try {
    const task = state.recognitionTask;
    if (!task || task.id !== runId) return;

    task.status = "preparing";
    renderConfirmDialog();
    const imageMeta = await prepareImageForAi(task.imageDataUrl);
    if (runId !== state.recognitionRunId) return;

    task.recognitionImageDataUrl = imageMeta.dataUrl;
    task.imageMeta = imageMeta;
    task.status = "recognizing";
    state.currentRecognitionImageDataUrl = imageMeta.dataUrl;
    state.currentImageMeta = imageMeta;
    renderConfirmDialog();

    const response = await fetch("/api/wms/batch-recognize", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        imageDataUrl: task.recognitionImageDataUrl,
        source: "php-demo",
        image_meta: {
          compressed: imageMeta.compressed,
          original_image_size_kb: Number((imageMeta.originalBytes / 1024).toFixed(1)),
          recognition_image_size_kb: Number((imageMeta.processedBytes / 1024).toFixed(1)),
          width: imageMeta.width,
          height: imageMeta.height,
          max_side: aiImageOptions.maxSide,
          quality: aiImageOptions.jpegQuality,
        },
      }),
    });
    const payload = await response.json();
    if (!response.ok) throw new Error(payload.error || "识别失败");
    if (runId !== state.recognitionRunId) return;

    const recognition = normalizeRecognitionResponse(payload);
    task.recognition = recognition;
    task.status = recognition.status;
    state.lastRecognition = recognition;
    renderConfirmDialog();
  } catch (error) {
    if (runId !== state.recognitionRunId) return;
    const task = state.recognitionTask;
    if (!task || task.id !== runId) return;
    const elapsed = performance.now() - started;
    task.status = "error";
    task.errorMessage = `${error.message} · ${formatSeconds(elapsed)}`;
    renderConfirmDialog();
  }
}

function addPhoto(imageDataUrl) {
  if (state.photos.length >= 10) {
    showToast("最多上传 10 张照片。");
    return;
  }
  state.photos.push(imageDataUrl);
  renderPhotos();
}

function renderPhotos() {
  for (const tile of els.uploadGrid.querySelectorAll(".photo-tile")) tile.remove();

  state.photos.forEach((src) => {
    const tile = document.createElement("div");
    tile.className = "photo-tile";
    const img = document.createElement("img");
    img.src = src;
    img.alt = "已上传照片";
    tile.append(img);
    els.uploadGrid.insertBefore(tile, els.addPhotoButton);
  });

  els.photoCount.textContent = `${state.photos.length}/10`;
  els.addPhotoButton.hidden = state.photos.length >= 10;
}

async function loadRuntime() {
  const response = await fetch("/api/health");
  const data = await response.json();
  const providerLabel = data.providerLabel || data.provider || "AI";
  els.runtimeStatus.textContent = data.hasApiKey
    ? `PHP真实识别 / ${providerLabel} ${data.model} / ${data.aiTimeoutMs}ms`
    : `缺少 API Key`;
}

els.recognizeButton.addEventListener("click", () => els.fileInput.click());
els.addPhotoButton.addEventListener("click", () => els.fileInput.click());

els.fileInput.addEventListener("change", async () => {
  const file = els.fileInput.files?.[0];
  if (!file) return;
  if (!file.type.startsWith("image/")) {
    showToast("请上传图片文件。");
    return;
  }

  const dataUrl = await fileToDataUrl(file);
  els.fileInput.value = "";
  startRecognitionTask(dataUrl);
});

els.confirmBatchInput.addEventListener("input", () => {
  if (state.recognitionTask && !state.recognitionTask.confirmed) {
    state.confirmInputEdited.batch = true;
  }
});

els.confirmProductionDateInput.addEventListener("input", () => {
  if (state.recognitionTask && !state.recognitionTask.confirmed) {
    state.confirmInputEdited.productionDate = true;
  }
});

els.confirmExpiryDateInput.addEventListener("input", () => {
  if (state.recognitionTask && !state.recognitionTask.confirmed) {
    state.confirmInputEdited.expiryDate = true;
  }
});

els.dialog.addEventListener("cancel", (event) => {
  if (state.recognitionTask && !state.recognitionTask.confirmed) {
    event.preventDefault();
    showToast("请先确认或重新拍照。");
  }
});

els.retakeButton.addEventListener("click", () => {
  state.recognitionRunId += 1;
  state.recognitionTask = null;
  resetConfirmEdited();
  state.submitWarningAcknowledged = false;
  els.dialog.close();
  els.fileInput.click();
});

els.confirmButton.addEventListener("click", () => {
  const task = state.recognitionTask;
  const confirmedBatch = els.confirmBatchInput.value.trim();
  const confirmedProductionDate = els.confirmProductionDateInput.value.trim();
  const confirmedExpiryDate = els.confirmExpiryDateInput.value.trim();

  els.batchInput.value = confirmedBatch;
  els.productionDateInput.value = confirmedProductionDate;
  els.expiryDateInput.value = confirmedExpiryDate;
  addPhoto(task?.imageDataUrl || state.currentImageDataUrl);
  if (task) task.confirmed = true;
  state.recognitionTask = null;
  state.recognitionRunId += 1;
  resetConfirmEdited();
  state.submitWarningAcknowledged = false;
  els.dialog.close();

  if (!state.lastRecognition) {
    showToast("已人工确认，照片已加入上传照片。");
  } else {
    showToast("已回填确认字段，照片已加入上传照片。");
  }
});

els.submitButton.addEventListener("click", () => {
  const batch = els.batchInput.value.trim();
  const productionDate = els.productionDateInput.value.trim();
  const expiryDate = els.expiryDateInput.value.trim();
  if (state.recognitionTask && !state.recognitionTask.confirmed && !state.submitWarningAcknowledged) {
    state.submitWarningAcknowledged = true;
    showToast("有识别结果待确认，请先在当前确认页完成确认；再次点击提交可继续模拟提交。");
    return;
  }

  const dateText = [productionDate ? `生产日期 ${productionDate}` : "", expiryDate ? `失效日期 ${expiryDate}` : ""]
    .filter(Boolean)
    .join("，");
  if (!batch) {
    showToast(dateText ? `已模拟提交：厂商批号为空，${dateText}` : "已模拟提交：厂商批号为空，等待后台记录。");
    return;
  }
  showToast(dateText ? `已模拟提交：厂商批号 ${batch}，${dateText}` : `已模拟提交：厂商批号 ${batch}`);
});

await loadRuntime();
