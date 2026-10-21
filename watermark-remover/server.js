require('dotenv').config();
const path = require('path');
const crypto = require('crypto');
const express = require('express');
const multer = require('multer');
const lama = require('./lib/lama');
const video = require('./lib/video');

const PORT = process.env.PORT || 3000;
const ACCESS_PASSWORD = process.env.ACCESS_PASSWORD || '';
const MAX_MB = Number(process.env.MAX_UPLOAD_MB || 20);
const MAX_VIDEO_MB = Number(process.env.MAX_VIDEO_MB || 1024);

const app = express();
app.disable('x-powered-by');

// Optional password so strangers can't use the server's CPU. Any username works.
if (ACCESS_PASSWORD) {
  const expected = Buffer.from(ACCESS_PASSWORD);
  app.use((req, res, next) => {
    const header = req.headers.authorization || '';
    const decoded = Buffer.from(header.replace(/^Basic /, ''), 'base64').toString();
    const given = Buffer.from(decoded.slice(decoded.indexOf(':') + 1));
    if (given.length === expected.length && crypto.timingSafeEqual(given, expected)) return next();
    res.set('WWW-Authenticate', 'Basic realm="Photo tool"').status(401).send('Password required');
  });
}

app.use(express.static(path.join(__dirname, 'public')));

const upload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: MAX_MB * 1024 * 1024, files: 2 },
  fileFilter: (req, file, cb) => cb(null, /^image\/(jpeg|png|webp)$/.test(file.mimetype)),
});

app.get('/api/status', (req, res) => {
  const { status, downloaded, total, error } = lama.state;
  res.json({ status, downloaded, total, error });
});

app.post('/api/remove', upload.fields([{ name: 'image' }, { name: 'mask' }]), async (req, res) => {
  if (req.body.rights !== 'yes') {
    return res.status(400).json({ error: 'Please confirm you own these photos or have permission to edit them.' });
  }
  const image = req.files?.image?.[0];
  const mask = req.files?.mask?.[0];
  if (!image || !mask) return res.status(400).json({ error: 'Upload a JPG, PNG or WebP photo and mark the watermark.' });

  try {
    const result = await lama.inpaint(image.buffer, mask.buffer);
    res.set('Content-Type', result.type);
    res.set('X-Processing-Ms', String(result.ms));
    res.send(result.buffer);
  } catch (err) {
    console.error('[remove]', err);
    res.status(500).json({ error: err.message });
  }
});

const videoUpload = multer({
  storage: multer.diskStorage({
    destination: (req, file, cb) => {
      req.job = video.newJob();
      cb(null, req.job.dir);
    },
    filename: (req, file, cb) => cb(null, 'input' + (path.extname(file.originalname).toLowerCase().replace(/[^.a-z0-9]/g, '') || '.mp4')),
  }),
  limits: { fileSize: MAX_VIDEO_MB * 1024 * 1024, files: 1 },
  // Some browsers send no/generic type for .mov/.mkv, so also trust the extension.
  fileFilter: (req, file, cb) =>
    cb(null, /^video\//.test(file.mimetype) || /\.(mp4|mov|m4v|mkv|webm|avi|3gp)$/i.test(file.originalname)),
});

app.post('/api/video', videoUpload.single('video'), async (req, res) => {
  if (!req.file) return res.status(400).json({ error: 'Upload a video file (MP4, MOV, …).' });
  const job = req.job;
  job.input = req.file.path;
  try {
    await video.probe(job);
    res.json({ id: job.id, width: job.width, height: job.height, duration: job.duration });
  } catch (err) {
    console.error('[video upload]', err);
    res.status(400).json({ error: err.message });
  }
});

const withJob = (req, res, next) => {
  req.job = video.getJob(req.params.id);
  if (!req.job) return res.status(404).json({ error: 'Video not found or expired. Please upload it again.' });
  next();
};

app.get('/api/video/:id/frame.jpg', withJob, (req, res) => res.sendFile(req.job.frame));

app.post('/api/video/:id/process', withJob, upload.single('mask'), (req, res) => {
  if (req.body.rights !== 'yes') {
    return res.status(400).json({ error: 'Please confirm you own this video or have permission to edit it.' });
  }
  if (!req.file) return res.status(400).json({ error: 'Mark the watermark first.' });
  if (['queued', 'analysing', 'processing'].includes(req.job.state)) {
    return res.status(409).json({ error: 'This video is already being processed.' });
  }
  video.start(req.job, {
    maskBuffer: req.file.buffer,
    mode: req.body.mode === 'fill' ? 'fill' : 'auto',
    preview: req.body.preview === 'yes',
  });
  res.json({ ok: true });
});

app.get('/api/video/:id/status', withJob, (req, res) => {
  const { state, progress, error, preview, stats } = req.job;
  res.json({ state, progress, error, preview, stats });
});

app.get('/api/video/:id/result.mp4', withJob, (req, res) => {
  if (req.job.state !== 'done') return res.status(404).json({ error: 'Not ready yet.' });
  if (req.query.download) res.attachment(req.job.preview ? 'preview.mp4' : 'clean-video.mp4');
  res.sendFile(req.job.output);
});

app.use((err, req, res, next) => {
  const tooBig = err.code === 'LIMIT_FILE_SIZE';
  const limit = req.path.startsWith('/api/video') ? `${MAX_VIDEO_MB} MB` : `${MAX_MB} MB`;
  res.status(tooBig ? 413 : 500).json({ error: tooBig ? `File is larger than ${limit}.` : err.message });
});

const server = app.listen(PORT, () => {
  console.log(`Watermark remover listening on port ${PORT}`);
  // Start downloading/loading the model straight away so the first photo isn't slow.
  lama.getSession().catch((err) => console.error('[model]', err.message));
});
// Big videos on slow connections take longer than Node's 5-minute default.
server.requestTimeout = 60 * 60 * 1000;
