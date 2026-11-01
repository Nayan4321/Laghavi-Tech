# Dubai Series EP2 / EP5 — source for the realistic Blender rebuild

Everything needed to rebuild the two map videos as photo-real Blender renders.

- `EP2_Business_Bay.docx`, `EP5_Dubai_Islands.docx` — the scripts.
- `mg/` — the current browser-based version (HTML/three.js). `scenes.js` holds the exact timeline:
  scene start times (seconds from the start of each presenter clip), on-screen text, label/pin
  positions and camera keyframes (`cam0`/`cam1`/`keys`: x, y = target in world units, dist, pitch,
  yaw in degrees, f = focal length in px for a 1080x1240 map area). `map3d.js` has the world
  geometry generators (`businessBay()`, `dubaiIslands()`), `gl3d.js` the realistic WebGL renderer.
- `nk_seg.json` — Dubai Islands outlines (A–E) and ~3,200 building footprints [x, y, w, d] in world
  units, traced from the Nakheel aerial (`ref/nakheel_clean.png`). `nk_plan.png` + `nk_plan.json`
  are that aerial rectified to plan view (world rect R = [-1100,-900,1100,900], 2 px/unit).
- `ref/16.webp` — the Downtown/Business Bay city-model look the client liked.
- `ref/21.jpg` — miniature diorama reference. `ref/19.webp`, `diplate3.png` — Dubai Islands beach plate.
- `ref/ep2_opening_burj.mp4`, `ref/ep5_opening_beach.mp4` — finished presenter openings (keep as is).
- `encode.sh`, `winmask.png` — how the graphics are composited with the presenter window
  (window at x 56, y 1268, 340x430 in the 1080x1920 frame).

## Blender rebuild (current deliverables)

Map shots rendered in Blender 5.2 (EEVEE) from real map data, composited under the existing HTML overlay.
EP2 uses a stylised "city model" ground; EP5 uses the satellite photo with the Nakheel-plan islands.

1. `node blender/export.js <ep>` — per-frame cameras / highlights from `mg/scenes.js` (+ EP5 island plan data).
2. `blender --background --python blender/fetch_real.py -- <ep>` and `node blender/fetch_osm2.js <ep>` — Esri imagery + OpenStreetMap.
3. `node blender/paint_ground.js 2` / `node blender/paint_ground.js 5 sat` — ground textures.
4. `blender --background --factory-startup --python blender/build_real.py -- <ep> blender/out/ep<ep> all` — map frames.
5. `BL=../blender/out/ep<ep> node render.js <ep> blender/out/ov<ep>` — overlay (labels, text, presenter card).
6. `bash encode.sh blender/out/ov2 0 42.33 6.33 EP2_Business_Bay ref/ep2_opening_burj.mp4`
   `bash encode.sh blender/out/ov5 50.567 44.16 5.70 EP5_Dubai_Islands ref/ep5_opening_beach.mp4`

Credits shown on screen: map data © OpenStreetMap contributors, imagery Esri.

### EP5 Dubai Islands: Palm Springs reel layout (current EP5 deliverable)

Full-frame satellite map with a gliding camera, presenter window top-right, word-by-word captions,
glowing island zones, white callout cards. Design + timing: `mg/reel5.js`; overlay page: `mg/reel.html`.

1. `blender --background --python blender/fetch_real.py -- 5r` (+ copy `real/ep5/osm*.json` into `real/ep5r/`)
2. `node blender/paint_ground.js 5r sat` then `node blender/tile_ground.js 5r` (GPU-sized texture tiles)
3. Island outlines: `blender/trace_islands.py` -> `real/ep5r/islands_real.json` -> `mg/islands5.js`
4. Cameras: `node -e` over `mg/reel5.js` -> `blender/data/ep5r/frames.json` (see `camAt`)
5. `blender --background --factory-startup --python blender/build_real.py -- 5r blender/out/ep5r all 16`
6. `BL=../blender/out/ep5r node reel_render.js blender/out/ov5r`
7. `bash encode_reel.sh blender/out/ov5r 50.567 44.16 5.70 EP5_Dubai_Islands ref/ep5_opening_beach.mp4 mg/reel_window_border.png`

Captions come from a faster-whisper transcript of his clip (word timestamps), corrected by hand in `reel5.js`.
