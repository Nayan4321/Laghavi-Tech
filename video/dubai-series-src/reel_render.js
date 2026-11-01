// node reel_render.js <outdir> [t1,t2,...]   (BL=<Blender frames folder relative to mg/>, BORDER=1 for the window frame)
const { chromium } = require('./mg/node_modules/playwright-core'), path = require('path'), fs = require('fs');
(async () => {
  const [out, list] = process.argv.slice(2); fs.mkdirSync(out, { recursive: true });
  const b = await chromium.launch({ channel: 'msedge', args: ['--allow-file-access-from-files', '--disable-web-security'] });
  const p = await b.newPage({ viewport: { width: 1080, height: 1920 } }); const errs = []; p.on('pageerror', e => errs.push(e.message));
  const q = (process.env.BL ? `bl=${encodeURIComponent(process.env.BL)}` : '') + (process.env.BORDER ? '&border=1' : '');
  await p.goto('file:///' + path.join(__dirname, 'mg', 'reel.html').split(path.sep).join('/') + '?' + q);
  await p.waitForFunction('window.READY === true', null, { timeout: 120000 });
  const total = await p.evaluate('window.TOTAL');
  const times = list ? list.split(',').map(Number) : Array.from({ length: Math.round(total * 30) }, (_, i) => i / 30);
  let i = 0;
  for (const t of times) {
    await p.evaluate(t => window.renderAt(t), t);
    await p.screenshot({ path: `${out}/${list ? 't' + t.toFixed(2) : String(i).padStart(5, '0')}.png`, omitBackground: true });
    if (++i % 150 === 0) console.log('frames', i, '/', times.length);
  }
  console.log('frames', times.length, 'errors', JSON.stringify(errs.slice(0, 5))); await b.close();
})();
