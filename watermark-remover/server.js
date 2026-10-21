require('dotenv').config();
const path = require('path');
const crypto = require('crypto');
const express = require('express');
const multer = require('multer');
const lama = require('./lib/lama');

const PORT = process.env.PORT || 3000;
const ACCESS_PASSWORD = process.env.ACCESS_PASSWORD || '';
const MAX_MB = Number(process.env.MAX_UPLOAD_MB || 20);

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

app.use((err, req, res, next) => {
  const tooBig = err.code === 'LIMIT_FILE_SIZE';
  res.status(tooBig ? 413 : 500).json({ error: tooBig ? `Photo is larger than ${MAX_MB} MB.` : err.message });
});

app.listen(PORT, () => {
  console.log(`Watermark remover listening on port ${PORT}`);
  // Start downloading/loading the model straight away so the first photo isn't slow.
  lama.getSession().catch((err) => console.error('[model]', err.message));
});
