// Usage: node render.js <ep> <outdir> [t1,t2,...]  -> PNG frames with transparency (window + hook stay clear)
const { chromium } = require('playwright');
(async () => {
  const [ep, out, list] = process.argv.slice(2);
  const fs = require('fs'); fs.mkdirSync(out, { recursive: true });
  const b = await chromium.launch({ args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--allow-file-access-from-files', '--disable-web-security'] }); const p = await b.newPage({ viewport: { width: 1080, height: 1920 } });
  const errs = []; p.on('pageerror', e => errs.push(e.message)); p.on('console', m => { if (m.type() === 'error') errs.push(m.text()); });
  await p.goto(`file://${__dirname}/mg/index.html?ep=${ep}`, { timeout: 600000 });
  await p.waitForFunction('window.READY === true', null, { timeout: 600000 });
  const total = await p.evaluate('window.TOTAL');
  const times = list ? list.split(',').map(Number) : Array.from({ length: Math.round(total * 30) }, (_, i) => i / 30);
  let i = 0;
  for (const t of times) {
    await p.evaluate(t => window.renderAt(t), t);
    const name = list ? `t${t.toFixed(2)}` : String(i).padStart(5, '0');
    await p.screenshot({ path: `${out}/${name}.png`, omitBackground: true });
    if (++i % 150 === 0) console.log('frames', i, '/', times.length);
  }
  console.log('total', total, 'frames', times.length, 'errors', JSON.stringify(errs.slice(0, 5)));
  await b.close();
})();
