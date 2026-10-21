// Runs the LaMa inpainting model (ONNX export, fixed 512x512 input) on the
// server CPU. Only the area around the marked watermark is sent through the
// model, then pasted back into the full-resolution photo, so big photos stay
// fast and memory use stays low on shared hosting.

const fs = require('fs');
const path = require('path');
const { Readable } = require('stream');
const { pipeline } = require('stream/promises');
const sharp = require('sharp');
const ort = require('onnxruntime-node');

const MODEL_URL =
  process.env.MODEL_URL || 'https://huggingface.co/Carve/LaMa-ONNX/resolve/main/lama_fp32.onnx';
const MODEL_PATH = process.env.MODEL_PATH || path.join(__dirname, '..', 'models', 'lama_fp32.onnx');
const SIZE = 512;
const THREADS = Number(process.env.ORT_THREADS || 2);

sharp.concurrency(1);

const state = { status: 'idle', downloaded: 0, total: 0, error: null };
let sessionPromise = null;

async function downloadModel() {
  if (fs.existsSync(MODEL_PATH)) return;
  fs.mkdirSync(path.dirname(MODEL_PATH), { recursive: true });
  state.status = 'downloading';
  const res = await fetch(MODEL_URL);
  if (!res.ok) throw new Error(`Model download failed: HTTP ${res.status}`);
  state.total = Number(res.headers.get('content-length') || 0);
  const tmp = MODEL_PATH + '.part';
  const body = Readable.fromWeb(res.body);
  body.on('data', (chunk) => { state.downloaded += chunk.length; });
  await pipeline(body, fs.createWriteStream(tmp));
  fs.renameSync(tmp, MODEL_PATH);
}

function getSession() {
  if (!sessionPromise) {
    sessionPromise = (async () => {
      await downloadModel();
      state.status = 'loading';
      const session = await ort.InferenceSession.create(MODEL_PATH, {
        intraOpNumThreads: THREADS,
        interOpNumThreads: 1,
        graphOptimizationLevel: 'all',
      });
      state.status = 'ready';
      return session;
    })().catch((err) => {
      state.status = 'error';
      state.error = err.message;
      sessionPromise = null;
      throw err;
    });
  }
  return sessionPromise;
}

// One photo at a time: shared hosting has few CPU cores and a memory cap.
let queue = Promise.resolve();
function enqueue(job) {
  const run = queue.then(job, job);
  queue = run.catch(() => {});
  return run;
}

function maskBounds(mask, width, height) {
  let x0 = width, y0 = height, x1 = -1, y1 = -1;
  for (let y = 0; y < height; y++) {
    const row = y * width;
    for (let x = 0; x < width; x++) {
      if (mask[row + x] > 127) {
        if (x < x0) x0 = x;
        if (x > x1) x1 = x;
        if (y < y0) y0 = y;
        if (y > y1) y1 = y;
      }
    }
  }
  return x1 < 0 ? null : { x0, y0, x1, y1 };
}

// Square crop around the mask with enough surrounding context for the model
// to understand the texture it is filling in.
function cropBox(b, width, height) {
  const bw = b.x1 - b.x0 + 1;
  const bh = b.y1 - b.y0 + 1;
  let side = Math.max(bw, bh) * 2;
  side = Math.max(side, Math.max(bw, bh) + 128, 256);
  side = Math.min(side, Math.max(width, height));
  const cw = Math.min(side, width);
  const ch = Math.min(side, height);
  const cx = (b.x0 + b.x1) / 2;
  const cy = (b.y0 + b.y1) / 2;
  const left = Math.round(Math.min(Math.max(cx - cw / 2, 0), width - cw));
  const top = Math.round(Math.min(Math.max(cy - ch / 2, 0), height - ch));
  return { left, top, width: cw, height: ch };
}

async function inpaint(imageBuffer, maskBuffer) {
  const session = await getSession();
  return enqueue(async () => {
    const started = Date.now();

    const base = sharp(imageBuffer, { failOn: 'none' }).rotate();
    const meta = await sharp(imageBuffer).metadata();
    const { data: rgb, info } = await base.removeAlpha().raw().toBuffer({ resolveWithObject: true });
    const { width, height } = info;

    // Grow the painted mask a few pixels so watermark edges/shadows are covered.
    const grow = Math.max(3, Math.round(Math.max(width, height) / 400));
    const mask = await sharp(maskBuffer)
      .resize(width, height, { fit: 'fill' })
      .extractChannel(0)
      .threshold(128)
      .blur(grow)
      .threshold(8)
      .raw()
      .toBuffer();

    const bounds = maskBounds(mask, width, height);
    if (!bounds) throw new Error('Nothing was marked. Brush over the watermark first.');
    const box = cropBox(bounds, width, height);

    const rawOpts = (channels) => ({ raw: { width, height, channels } });
    const cropRgb = await sharp(rgb, rawOpts(3))
      .extract(box)
      .resize(SIZE, SIZE, { fit: 'fill', kernel: 'lanczos3' })
      .raw()
      .toBuffer();
    const cropMask = await sharp(mask, rawOpts(1))
      .extract(box)
      .resize(SIZE, SIZE, { fit: 'fill' })
      .extractChannel(0)
      .raw()
      .toBuffer();

    const plane = SIZE * SIZE;
    const imgTensor = new Float32Array(3 * plane);
    const maskTensor = new Float32Array(plane);
    for (let i = 0; i < plane; i++) {
      const m = cropMask[i] > 0 ? 1 : 0;
      maskTensor[i] = m;
      for (let c = 0; c < 3; c++) {
        imgTensor[c * plane + i] = m ? 0 : cropRgb[i * 3 + c] / 255;
      }
    }

    const [imgName, maskName] = session.inputNames.some((n) => /mask/i.test(n))
      ? [session.inputNames.find((n) => !/mask/i.test(n)), session.inputNames.find((n) => /mask/i.test(n))]
      : session.inputNames;
    const results = await session.run({
      [imgName]: new ort.Tensor('float32', imgTensor, [1, 3, SIZE, SIZE]),
      [maskName]: new ort.Tensor('float32', maskTensor, [1, 1, SIZE, SIZE]),
    });
    const out = results[session.outputNames[0]].data;

    // Some exports return 0..1, others 0..255.
    let max = 0;
    for (let i = 0; i < out.length; i += 97) if (out[i] > max) max = out[i];
    const scale = max <= 1.5 ? 255 : 1;
    const outRgb = Buffer.alloc(3 * plane);
    for (let i = 0; i < plane; i++) {
      for (let c = 0; c < 3; c++) {
        const v = out[c * plane + i] * scale;
        outRgb[i * 3 + c] = v < 0 ? 0 : v > 255 ? 255 : Math.round(v);
      }
    }

    const filled = await sharp(outRgb, { raw: { width: SIZE, height: SIZE, channels: 3 } })
      .resize(box.width, box.height, { fit: 'fill', kernel: 'lanczos3' })
      .raw()
      .toBuffer();
    // Soft edge so the filled patch blends into the untouched pixels.
    const alpha = await sharp(mask, rawOpts(1))
      .extract(box)
      .blur(Math.max(1, grow / 2))
      .extractChannel(0)
      .raw()
      .toBuffer();

    for (let y = 0; y < box.height; y++) {
      for (let x = 0; x < box.width; x++) {
        const a = alpha[y * box.width + x] / 255;
        if (a === 0) continue;
        const src = (y * box.width + x) * 3;
        const dst = ((box.top + y) * width + box.left + x) * 3;
        for (let c = 0; c < 3; c++) {
          rgb[dst + c] = Math.round(rgb[dst + c] * (1 - a) + filled[src + c] * a);
        }
      }
    }

    const png = meta.format === 'png';
    const result = sharp(rgb, rawOpts(3));
    const output = png
      ? await result.png({ compressionLevel: 9 }).toBuffer()
      : await result.jpeg({ quality: 92, mozjpeg: true }).toBuffer();

    return { buffer: output, type: png ? 'image/png' : 'image/jpeg', ms: Date.now() - started };
  });
}

module.exports = { inpaint, getSession, state };
