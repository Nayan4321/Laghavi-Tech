# Blender rebuild of the EP2 / EP5 map shots

The pipeline keeps the timing, camera moves and rise rules from `mg/` exactly. It reads them from the JS files themselves, so nothing is copied by hand.

1. `node export_world.js 2 ep2.json` (or `5 ep5.json`) runs `businessBay()` / `dubaiIslands()` and the episode's `setup()` rise rules from `mg/`. It writes:
   - every building footprint with its rise time
   - water, roads and islands
   - the camera and map opacity for each frame (30 fps), computed the same way as `mg/index.html`
2. `python3 paint_ground.py ep2.json texdir` paints the ground textures: sand, the dense low-rise roof carpet, roads and parks.
3. `blender --background --python build_scene.py -- ep2.json texdir outdir all [samples]` builds the scene and renders the frames. A frame list such as `660,1050` or a range such as `600-720` also works. Without a Blender install, `pip install bpy` (Python 3.13) works too: `python build_scene.py -- ...`.
   - Cycles picks up OptiX/CUDA/HIP/Metal automatically and falls back to CPU.
   - Buildings rise with the same ease-out-back overshoot as `gl3d.js`. The gold highlights come from each scene's `hl` rule.
   - EP5: the island tops use `ref/nk_plan.png` as albedo, with 3D trees scattered on its green pixels and turquoise shallows around the islands.
4. `python3 post.py ep2.json outdir postdir` adds aerial haze (from the distance to the ground plane) and the tilt-shift blur bands.

Test stills are in `tests/`.

Render cost: with a 4-core CPU at 24 samples, one frame takes about 45 s. Each episode has about 1,100 map frames, so a full episode is about 14 h on CPU. On a recent NVIDIA GPU with OptiX it should be a few seconds per frame.
