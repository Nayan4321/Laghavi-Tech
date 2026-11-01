// node blender/tile_ground.js <ep>  ->  splits ground_z*.png larger than 4096 px into tiles (the laptop GPU
// downsizes bigger textures) and writes tiles.json: { "ground_z17.png": [{ file, rect: [x0, y0, x1, y1] (metres) }] }
const fs = require('fs'), path = require('path'), { execFileSync } = require('child_process');
const dir = path.join(__dirname, 'real', 'ep' + (process.argv[2] || '5r'));
const FF = path.join(__dirname, '..', 'mg', 'node_modules', 'ffmpeg-static', 'ffmpeg.exe');
const M = JSON.parse(fs.readFileSync(path.join(dir, 'img.json'))), out = {};
for (const r of M.rings) {
  const name = `ground_z${r.z}.png`, [W, H] = r.px; if (W <= 4096 && H <= 4096) continue;
  const nx = Math.ceil(W / 4096), ny = Math.ceil(H / 4096), tw = Math.ceil(W / nx), th = Math.ceil(H / ny), list = [];
  const mx = (r.ne_m[0] - r.sw_m[0]) / W, my = (r.ne_m[1] - r.sw_m[1]) / H;
  for (let j = 0; j < ny; j++) for (let i = 0; i < nx; i++) {
    const x = i * tw, y = j * th, w = Math.min(tw, W - x), h = Math.min(th, H - y), file = `ground_z${r.z}_${i}_${j}.png`;
    execFileSync(FF, ['-v', 'error', '-y', '-i', path.join(dir, name), '-vf', `crop=${w}:${h}:${x}:${y}`, path.join(dir, file)]);
    list.push({ file, rect: [r.sw_m[0] + x * mx, r.ne_m[1] - (y + h) * my, r.sw_m[0] + (x + w) * mx, r.ne_m[1] - y * my] });
  }
  out[name] = list; console.log(name, nx + 'x' + ny, 'tiles');
}
fs.writeFileSync(path.join(dir, 'tiles.json'), JSON.stringify(out));
