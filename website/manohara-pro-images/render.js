// node render.js DIR : renders every DIR/*.html to a 1200x1200 PNG next to it.
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
(async () => {
  const dir = process.argv[2]; const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1200, height: 1200 }, deviceScaleFactor: 1 });
  for (const f of fs.readdirSync(dir).filter(f => f.endsWith('.html')).sort()) {
    await p.goto('file://' + path.join(dir, f)); await p.waitForTimeout(300);
    await p.screenshot({ path: path.join(dir, f.replace('.html', '.png')) });
  }
  await b.close();
})();
