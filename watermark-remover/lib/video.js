// Video watermark removal with ffmpeg. Running the AI model on every frame is
// far too slow for multi-minute videos on shared hosting, so instead:
//
// - Faded / semi-transparent watermarks: the watermark sits still while the
//   camera moves, so frames sampled from across the whole video reveal it.
//   From them we estimate, per pixel, how opaque the watermark is (alpha) and
//   its colour, then undo the blend on every frame:
//     frame = (1 - a) * real + a * W   =>   real = (frame - a * W) / (1 - a)
//   This restores the real picture under the mark instead of smudging it.
// - Solid logos (or ones that don't fit that model): filled with a smooth
//   blend of the surrounding pixels.

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { spawn } = require('child_process');
const sharp = require('sharp');
const ffmpegPath = require('ffmpeg-static');

const JOBS_DIR = process.env.JOBS_DIR || path.join(__dirname, '..', 'jobs');
const THREADS = String(process.env.FFMPEG_THREADS || 2);
const JOB_TTL_MS = Number(process.env.JOB_TTL_HOURS || 2) * 3600 * 1000;
const OPAQUE = 0.7;
const MIN_ALPHA = 0.08;
const PREVIEW_SECONDS = 5;

const jobs = new Map();

function newJob() {
  const id = crypto.randomUUID();
  const dir = path.join(JOBS_DIR, id);
  fs.mkdirSync(dir, { recursive: true });
  const job = { id, dir, created: Date.now(), state: 'uploaded', progress: 0, error: null, output: null };
  jobs.set(id, job);
  return job;
}

function getJob(id) {
  return jobs.get(id);
}

function runFfmpeg(args, { onStdout, onProgress, totalSeconds } = {}) {
  return new Promise((resolve, reject) => {
    const proc = spawn(ffmpegPath, ['-hide_banner', '-nostdin', ...args]);
    let stderr = '';
    let buf = '';
    proc.stderr.on('data', (d) => { stderr = (stderr + d).slice(-8000); });
    proc.stdout.on('data', (d) => {
      if (onStdout) return onStdout(d);
      if (!onProgress) return;
      buf += d;
      const lines = buf.split('\n');
      buf = lines.pop();
      for (const line of lines) {
        const m = line.match(/^out_time_(?:us|ms)=(\d+)/);
        if (m && totalSeconds) onProgress(Math.min(1, Number(m[1]) / 1e6 / totalSeconds));
      }
    });
    proc.on('error', reject);
    proc.on('close', (code) => {
      if (code === 0) resolve(stderr);
      else reject(Object.assign(new Error('ffmpeg failed: ' + stderr.split('\n').filter(Boolean).slice(-3).join(' | ')), { stderr }));
    });
  });
}

async function probe(job) {
  // `ffmpeg -i` with no output exits non-zero but still prints the stream info.
  const info = await runFfmpeg(['-i', job.input]).catch((e) => e.stderr || '');
  const d = info.match(/Duration: (\d+):(\d+):([\d.]+)/);
  if (!d || !/Video:/.test(info)) throw new Error('This file does not look like a video.');
  job.duration = Number(d[1]) * 3600 + Number(d[2]) * 60 + Number(d[3]);
  job.frame = path.join(job.dir, 'frame.jpg');
  await runFfmpeg(['-ss', String(Math.min(1, job.duration / 2)), '-i', job.input, '-frames:v', '1', '-q:v', '2', '-y', job.frame]);
  // Use the decoded frame's size: it already accounts for phone rotation.
  const meta = await sharp(job.frame).metadata();
  job.width = meta.width;
  job.height = meta.height;
  job.state = 'ready';
}

const even = (n) => n - (n % 2);

// Separate marked areas (e.g. a logo in the corner and a mark in the middle)
// are processed as separate small crops instead of one huge one.
function findRegions(mask, width, height) {
  const seen = new Uint8Array(width * height);
  const boxes = [];
  const stack = [];
  for (let start = 0; start < mask.length; start++) {
    if (!mask[start] || seen[start]) continue;
    let x0 = width, y0 = height, x1 = 0, y1 = 0, count = 0;
    seen[start] = 1;
    stack.push(start);
    while (stack.length) {
      const i = stack.pop();
      const x = i % width;
      const y = (i - x) / width;
      count++;
      if (x < x0) x0 = x;
      if (x > x1) x1 = x;
      if (y < y0) y0 = y;
      if (y > y1) y1 = y;
      for (const j of [i - 1, i + 1, i - width, i + width]) {
        if (j < 0 || j >= mask.length || seen[j] || !mask[j]) continue;
        if ((j === i - 1 && x === 0) || (j === i + 1 && x === width - 1)) continue;
        seen[j] = 1;
        stack.push(j);
      }
    }
    if (count >= 20) boxes.push(padBox({ x0, y0, x1, y1 }, width, height));
  }
  // Merge boxes whose padded areas overlap.
  for (let merged = true; merged;) {
    merged = false;
    for (let i = 0; i < boxes.length && !merged; i++) {
      for (let j = i + 1; j < boxes.length && !merged; j++) {
        const a = boxes[i];
        const b = boxes[j];
        if (a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h) {
          const x = Math.min(a.x, b.x);
          const y = Math.min(a.y, b.y);
          boxes[i] = { x, y, w: Math.max(a.x + a.w, b.x + b.w) - x, h: Math.max(a.y + a.h, b.y + b.h) - y };
          boxes.splice(j, 1);
          merged = true;
        }
      }
    }
  }
  return boxes;
}

function padBox(b, width, height) {
  const pad = Math.max(24, Math.min(64, Math.round(Math.min(b.x1 - b.x0, b.y1 - b.y0) * 0.5)));
  const x = even(Math.max(0, b.x0 - pad));
  const y = even(Math.max(0, b.y0 - pad));
  const w = even(Math.min(width, b.x1 + pad + 1) - x);
  const h = even(Math.min(height, b.y1 + pad + 1) - y);
  return { x, y, w, h };
}

// Per-pixel mean and standard deviation over frames spread across the video.
async function frameStats(job, box) {
  const frameSize = box.w * box.h * 3;
  const sample = async (pre, vf) => {
    // Integer sums of up to 240 frames fit exactly in 32 bits.
    const sum = new Uint32Array(frameSize);
    const sumSq = new Uint32Array(frameSize);
    let pending = Buffer.alloc(0);
    let count = 0;
    await runFfmpeg([...pre, '-i', job.input, '-vf', `${vf}crop=${box.w}:${box.h}:${box.x}:${box.y},format=rgb24`,
      '-an', '-fps_mode', 'passthrough', '-frames:v', '240', '-f', 'rawvideo', 'pipe:1'], {
      onStdout: (chunk) => {
        pending = pending.length ? Buffer.concat([pending, chunk]) : chunk;
        while (pending.length >= frameSize) {
          for (let i = 0; i < frameSize; i++) {
            const v = pending[i];
            sum[i] += v;
            sumSq[i] += v * v;
          }
          pending = pending.subarray(frameSize);
          count++;
        }
      },
    });
    return { sum, sumSq, count };
  };
  // Up to 240 frames spread evenly over the whole video.
  const r = await sample([], `fps=${Math.max(0.2, 235 / Math.max(job.duration, 1))},`);
  if (r.count < 10) throw new Error('Video is too short to analyse. Use "Solid logo" mode.');
  const mean = new Float32Array(frameSize);
  const std = new Float32Array(frameSize);
  for (let i = 0; i < frameSize; i++) {
    mean[i] = r.sum[i] / r.count;
    std[i] = Math.sqrt(Math.max(0, r.sumSq[i] / r.count - mean[i] * mean[i]));
  }
  return { mean, std };
}

// Connected groups of non-zero pixels, as lists of pixel indexes.
function blobs(map, w, h) {
  const seen = new Uint8Array(w * h);
  const out = [];
  for (let start = 0; start < map.length; start++) {
    if (!map[start] || seen[start]) continue;
    const blob = [start];
    seen[start] = 1;
    for (let k = 0; k < blob.length; k++) {
      const i = blob[k];
      const x = i % w;
      const next = [x > 0 ? i - 1 : -1, x < w - 1 ? i + 1 : -1, i - w, i + w];
      for (const j of next) {
        if (j < 0 || j >= map.length || seen[j] || !map[j]) continue;
        seen[j] = 1;
        blob.push(j);
      }
    }
    out.push(blob);
  }
  return out;
}

// Best two-group split of values in 0..1 (Otsu). Returns each group's median.
function otsu(values) {
  if (values.length < 50) return null;
  const bins = 100;
  const hist = new Float64Array(bins);
  for (const v of values) hist[Math.min(bins - 1, Math.floor(v * bins))]++;
  let total = 0;
  for (let b = 0; b < bins; b++) total += b * hist[b];
  let best = -1, cut = 0, wB = 0, sB = 0;
  for (let b = 0; b < bins - 1; b++) {
    wB += hist[b];
    sB += b * hist[b];
    const wF = values.length - wB;
    if (!wB || !wF) continue;
    const between = wB * wF * (sB / wB - (total - sB) / wF) ** 2;
    if (between > best) { best = between; cut = (b + 1) / bins; }
  }
  const lo = values.filter((v) => v < cut).sort((a, b) => a - b);
  const hi = values.filter((v) => v >= cut).sort((a, b) => a - b);
  // Watermark strokes are usually a fair share of the brushed area.
  if (hi.length < values.length * 0.03 || !lo.length) return null;
  return { low: lo[lo.length >> 1], high: hi[hi.length >> 1] };
}

function boxBlur(src, w, h, r) {
  const tmp = new Float32Array(src.length);
  const out = new Float32Array(src.length);
  for (let y = 0; y < h; y++) {
    let acc = 0;
    const row = y * w;
    for (let x = -r; x <= r; x++) acc += src[row + Math.min(w - 1, Math.max(0, x))];
    for (let x = 0; x < w; x++) {
      tmp[row + x] = acc;
      acc += src[row + Math.min(w - 1, x + r + 1)] - src[row + Math.max(0, x - r)];
    }
  }
  for (let x = 0; x < w; x++) {
    let acc = 0;
    for (let y = -r; y <= r; y++) acc += tmp[Math.min(h - 1, Math.max(0, y)) * w + x];
    for (let y = 0; y < h; y++) {
      out[y * w + x] = acc;
      acc += tmp[Math.min(h - 1, y + r + 1) * w + x] - tmp[Math.max(0, y - r) * w + x];
    }
  }
  return out;
}

// What a per-pixel statistic would look like without the watermark, by
// spreading in the values from just outside the marked area.
function fromOutside(values, mask, w, h, r) {
  const n = w * h;
  const weight = new Float32Array(n);
  let v = new Float32Array(n);
  for (let i = 0; i < n; i++) {
    weight[i] = mask[i] ? 0 : 1;
    v[i] = mask[i] ? 0 : values[i];
  }
  let wb = weight;
  for (let k = 0; k < 3; k++) {
    wb = boxBlur(wb, w, h, r);
    v = boxBlur(v, w, h, r);
  }
  const out = new Float32Array(n);
  for (let i = 0; i < n; i++) out[i] = wb[i] > 1e-6 ? v[i] / wb[i] : values[i];
  return out;
}

const channel = (arr, c) => {
  const out = new Float32Array(arr.length / 3);
  for (let i = 0; i < out.length; i++) out[i] = arr[i * 3 + c];
  return out;
};

// Decide per pixel how to treat each marked area of one region.
//
// A see-through watermark lets the moving picture show through, just dimmer:
// the pixel's variation over time drops to (1 - alpha) of its surroundings'.
// A solid logo barely varies at all. So the variation ratio gives alpha
// without needing to know the watermark colour; the colour is then found
// from the average frame.
async function analyseRegion(job, box, mask, mode) {
  const n = box.w * box.h;
  const alpha = new Float32Array(n);
  const fill = new Uint8Array(n);
  let color = [255, 255, 255];

  if (mode === 'fill') {
    fill.set(mask);
  } else {
    const { mean, std } = await frameStats(job, box);
    let maskW = 0;
    let maskH = 0;
    {
      let x0 = box.w, x1 = 0, y0 = box.h, y1 = 0;
      for (let i = 0; i < n; i++) {
        if (!mask[i]) continue;
        const x = i % box.w;
        const y = (i - x) / box.w;
        x0 = Math.min(x0, x); x1 = Math.max(x1, x); y0 = Math.min(y0, y); y1 = Math.max(y1, y);
      }
      maskW = x1 - x0 + 1;
      maskH = y1 - y0 + 1;
    }
    const r = Math.ceil(Math.max(maskW, maskH) / 3) + 4;

    const s = new Float32Array(n);
    for (let i = 0; i < n; i++) s[i] = (std[i * 3] + std[i * 3 + 1] + std[i * 3 + 2]) / 3;
    const sLocal = boxBlur(s, box.w, box.h, 1).map((v) => v / 9);
    const sOut = fromOutside(s, mask, box.w, box.h, r);
    const meanOut = [0, 1, 2].map((c) => fromOutside(channel(mean, c), mask, box.w, box.h, r));

    const raw = new Float32Array(n);
    const candidates = [];
    for (let i = 0; i < n; i++) {
      if (!mask[i]) continue;
      // Too little movement around this spot to measure: fill it instead.
      if (sOut[i] < 4) { fill[i] = 1; continue; }
      raw[i] = Math.min(1, Math.max(0, 1 - sLocal[i] / sOut[i]));
      if (raw[i] > OPAQUE) fill[i] = 1;
      else candidates.push(raw[i]);
    }

    // The per-pixel measurement is noisy, but a watermark is normally one
    // even opacity. Split the marked pixels into "watermark" and "not" and use
    // the watermark group's typical opacity, scaled down only at soft edges.
    const split = otsu(candidates);
    let a0 = 0;
    if (split && split.high - split.low > MIN_ALPHA) {
      a0 = Math.min(OPAQUE, split.high - split.low);
      for (let i = 0; i < n; i++) {
        if (!mask[i] || fill[i]) continue;
        let cover = (raw[i] - split.low) / (split.high - split.low);
        if (cover >= 0.8) cover = 1;
        else if (cover <= 0.3) cover = 0;
        alpha[i] = a0 * cover;
      }
      // Partly-covered pixels are only real at the soft edges of the
      // watermark's strokes; elsewhere they are measurement noise.
      const core = new Float32Array(n);
      for (let i = 0; i < n; i++) core[i] = alpha[i] >= a0 ? 1 : 0;
      // Tiny isolated specks are noise too; real strokes are much bigger.
      const minSize = Math.max(30, Math.round(Math.min(maskW, maskH) * 0.3) ** 2 / 10);
      for (const blob of blobs(core, box.w, box.h)) {
        if (blob.length < minSize) for (const i of blob) { core[i] = 0; alpha[i] = 0; }
      }
      const nearCore = boxBlur(core, box.w, box.h, 2);
      for (let i = 0; i < n; i++) {
        if (alpha[i] && alpha[i] < a0 && nearCore[i] < 1) alpha[i] = 0;
      }
    }

    // Stray "solid" specks inside a faded watermark are just noisy readings.
    if (a0) {
      for (const blob of blobs(fill, box.w, box.h)) {
        if (blob.length < 30) for (const i of blob) { fill[i] = 0; alpha[i] = a0; }
      }
    }

    // Swallow the soft edge of solid logos into the fill.
    if (fill.some((v) => v)) {
      const near = boxBlur(Float32Array.from(fill), box.w, box.h, 3);
      for (let i = 0; i < n; i++) {
        if (mask[i] && near[i] > 0) { fill[i] = 1; alpha[i] = 0; }
      }
    }

    // One colour for the whole watermark (they are nearly always one colour),
    // as the median of what each clearly-marked pixel implies.
    const samples = [[], [], []];
    for (let i = 0; i < n; i++) {
      if (!a0 || alpha[i] < a0) continue;
      for (let c = 0; c < 3; c++) {
        samples[c].push((mean[i * 3 + c] - (1 - a0) * meanOut[c][i]) / a0);
      }
    }
    if (samples[0].length >= 20) {
      color = samples.map((arr) => {
        arr.sort((p, q) => p - q);
        return Math.min(255, Math.max(0, arr[arr.length >> 1]));
      });
      // Double-check each pixel: a real watermark pixel also shifts the
      // average colour towards the watermark colour. Spots that only looked
      // "dimmer" because the camera moved less there are left alone.
      const wAvg = (color[0] + color[1] + color[2]) / 3;
      const shift = new Float32Array(n);
      for (let i = 0; i < n; i++) {
        shift[i] = (mean[i * 3] + mean[i * 3 + 1] + mean[i * 3 + 2] - meanOut[0][i] - meanOut[1][i] - meanOut[2][i]) / 3;
      }
      const shiftLocal = boxBlur(shift, box.w, box.h, 1);
      for (let i = 0; i < n; i++) {
        if (!alpha[i]) continue;
        const bg = (meanOut[0][i] + meanOut[1][i] + meanOut[2][i]) / 3;
        if (Math.abs(wAvg - bg) < 8) continue;
        const expected = alpha[i] * (wAvg - bg);
        if (shiftLocal[i] / 9 / expected < 0.4) alpha[i] = 0;
      }
    } else {
      alpha.fill(0);
    }
    if (!alpha.some((a) => a > 0)) {
      // No see-through mark found: fill the solid part if there clearly is
      // one, otherwise everything that was brushed.
      const solid = fill.reduce((t, v) => t + v, 0);
      const brushed = mask.reduce((t, v) => t + v, 0);
      if (solid < brushed * 0.05) fill.set(mask);
    }
  }

  const region = { box, fade: alpha.some((a) => a > 0), fill: fill.some((v) => v) };
  const raw = (buf, channels) => sharp(Buffer.from(buf), { raw: { width: box.w, height: box.h, channels } });
  const tag = `${box.x}_${box.y}`;

  if (region.fade) {
    // amount[c] = alpha * colour (what the watermark adds), strength = 1 - alpha.
    const amount = new Uint8Array(n * 3);
    const strength = new Uint8Array(n);
    for (let i = 0; i < n; i++) {
      strength[i] = Math.round((1 - alpha[i]) * 255);
      for (let c = 0; c < 3; c++) amount[i * 3 + c] = Math.round(alpha[i] * color[c]);
    }
    region.amountPath = path.join(job.dir, `amount_${tag}.png`);
    region.strengthPath = path.join(job.dir, `strength_${tag}.png`);
    await raw(amount, 3).png().toFile(region.amountPath);
    await raw(strength, 1).png().toFile(region.strengthPath);
  }
  if (region.fill) {
    const solid = new Uint8Array(n);
    for (let i = 0; i < n; i++) solid[i] = fill[i] ? 255 : 0;
    const grown = await raw(solid, 1).blur(1.5).threshold(1).extractChannel(0).raw().toBuffer();
    // keep = 255 where the pixel can be used to fill from, 0 over the logo.
    const keep = new Uint8Array(n);
    for (let i = 0; i < n; i++) keep[i] = grown[i] ? 0 : 255;
    region.keepPath = path.join(job.dir, `keep_${tag}.png`);
    region.blendPath = path.join(job.dir, `blendmask_${tag}.png`);
    await raw(keep, 1).png().toFile(region.keepPath);
    await sharp(grown, { raw: { width: box.w, height: box.h, channels: 1 } }).blur(1).png().toFile(region.blendPath);
    let mw = 0;
    let mh = 0;
    for (let y = 0; y < box.h; y++) {
      let run = 0;
      for (let x = 0; x < box.w; x++) { run = grown[y * box.w + x] ? run + 1 : 0; mw = Math.max(mw, run); }
    }
    for (let x = 0; x < box.w; x++) {
      let run = 0;
      for (let y = 0; y < box.h; y++) { run = grown[y * box.w + x] ? run + 1 : 0; mh = Math.max(mh, run); }
    }
    // Blur radius big enough to reach from the edge to the middle of the logo.
    region.radius = Math.max(4, Math.min(Math.ceil(Math.min(mw, mh) / 2) + 6, Math.floor(Math.min(box.w, box.h) / 2) - 1));
  }
  return { region, alphaPixels: alpha.reduce((t, a) => t + (a > 0 ? 1 : 0), 0), fillPixels: fill.reduce((t, v) => t + v, 0), color };
}

async function analyse(job, maskBuffer, mode) {
  const { width, height } = job;
  const grow = Math.max(2, Math.round(Math.max(width, height) / 500));
  const full = await sharp(maskBuffer)
    .resize(width, height, { fit: 'fill' })
    .extractChannel(0)
    .threshold(128)
    .blur(grow)
    .threshold(8)
    .raw()
    .toBuffer();
  const boxes = findRegions(full, width, height);
  if (!boxes.length) throw new Error('Nothing was marked. Brush over the watermark first.');

  const regions = [];
  const stats = { regions: boxes.length, fadePixels: 0, fillPixels: 0 };
  for (const box of boxes) {
    const mask = new Uint8Array(box.w * box.h);
    for (let y = 0; y < box.h; y++) {
      for (let x = 0; x < box.w; x++) mask[y * box.w + x] = full[(box.y + y) * width + box.x + x] > 127 ? 1 : 0;
    }
    const r = await analyseRegion(job, box, mask, mode);
    regions.push(r.region);
    stats.fadePixels += r.alphaPixels;
    stats.fillPixels += r.fillPixels;
  }
  job.regions = regions;
  return stats;
}

async function render(job, preview) {
  const start = preview ? Math.max(0, Math.min(job.duration * 0.3, job.duration - PREVIEW_SECONDS)) : 0;
  const total = preview ? Math.min(PREVIEW_SECONDS, job.duration) : job.duration;
  const out = path.join(job.dir, preview ? 'preview.mp4' : 'result.mp4');

  const inputs = [...(preview ? ['-ss', String(start), '-t', String(total)] : []), '-i', job.input];
  const still = (file) => {
    inputs.push('-loop', '1', '-i', file);
    return `[${inputs.filter((a) => a === '-i').length - 1}:v]`;
  };
  const graph = [];
  let last = '[0:v]';
  job.regions.forEach((rg, k) => {
    const { x, y, w, h } = rg.box;
    graph.push(`${last}split[base${k}][in${k}]`, `[in${k}]crop=${w}:${h}:${x}:${y},format=gbrp[c${k}]`);
    let cur = `[c${k}]`;
    if (rg.fade) {
      // real = (frame - alpha*colour) / (1 - alpha)
      graph.push(
        `${still(rg.amountPath)}format=gbrp[am${k}]`,
        `${still(rg.strengthPath)}format=gbrp[st${k}]`,
        `${cur}[am${k}]blend=all_mode=subtract:shortest=1[sub${k}]`,
        `[sub${k}][st${k}]blend=all_mode=divide:shortest=1[f${k}]`,
      );
      cur = `[f${k}]`;
    }
    if (rg.fill) {
      // Fill = blur of the surrounding pixels only (logo pixels weighted 0),
      // divided by the blur of the weights, blended in over the logo.
      const bb = `boxblur=luma_radius=${rg.radius}:luma_power=2:chroma_radius=${rg.radius}:chroma_power=2`;
      graph.push(
        `${still(rg.keepPath)}format=gbrp,split[kp${k}][kq${k}]`,
        `${still(rg.blendPath)}format=gbrp[bm${k}]`,
        `${cur}split[fa${k}][fb${k}]`,
        `[fa${k}][kp${k}]blend=all_mode=multiply:shortest=1,${bb}[num${k}]`,
        `[kq${k}]${bb}[den${k}]`,
        `[num${k}][den${k}]blend=all_mode=divide:shortest=1[fl${k}]`,
        `[fb${k}][fl${k}][bm${k}]maskedmerge[g${k}]`,
      );
      cur = `[g${k}]`;
    }
    graph.push(`[base${k}]${cur}overlay=${x}:${y}:shortest=1[o${k}]`);
    last = `[o${k}]`;
  });
  graph.push(`${last}format=yuv420p[vout]`);

  await runFfmpeg([
    '-y', ...inputs,
    '-filter_complex', graph.join(';'),
    '-map', '[vout]', '-map', '0:a?',
    '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20',
    '-c:a', 'aac', '-b:a', '160k',
    '-threads', THREADS, '-movflags', '+faststart',
    '-progress', 'pipe:1', '-nostats', out,
  ], { totalSeconds: total, onProgress: (p) => { job.progress = p; } });
  return out;
}

// One video at a time: encoding uses all the CPU shared hosting gives us.
let queue = Promise.resolve();

function start(job, { maskBuffer, mode, preview }) {
  job.state = 'queued';
  job.progress = 0;
  job.error = null;
  job.output = null;
  const run = async () => {
    try {
      if (!job.regions || job.planKey !== mode + ':' + hash(maskBuffer)) {
        job.state = 'analysing';
        job.stats = await analyse(job, maskBuffer, mode);
        job.planKey = mode + ':' + hash(maskBuffer);
      }
      job.state = 'processing';
      job.output = await render(job, preview);
      job.preview = preview;
      job.state = 'done';
      job.progress = 1;
    } catch (err) {
      console.error('[video]', err);
      job.state = 'error';
      job.error = err.message;
    }
  };
  queue = queue.then(run);
}

function hash(buf) {
  return crypto.createHash('sha1').update(buf).digest('hex');
}

function cleanup() {
  for (const [id, job] of jobs) {
    if (Date.now() - job.created > JOB_TTL_MS && !['queued', 'analysing', 'processing'].includes(job.state)) {
      fs.rmSync(job.dir, { recursive: true, force: true });
      jobs.delete(id);
    }
  }
}
// Leftovers from before a restart are not in `jobs`; clear them on boot.
fs.mkdirSync(JOBS_DIR, { recursive: true });
for (const name of fs.readdirSync(JOBS_DIR)) {
  if (/^[0-9a-f-]{36}$/.test(name)) fs.rmSync(path.join(JOBS_DIR, name), { recursive: true, force: true });
}
setInterval(cleanup, 10 * 60 * 1000).unref();

module.exports = { newJob, getJob, probe, start, JOBS_DIR };
