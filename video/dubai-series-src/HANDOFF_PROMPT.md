Paste everything below into a Claude Code session that runs ON YOUR COMPUTER (Claude desktop app), opened in your local clone of the Laghavi-Tech repository.

---

Goal: rebuild the 3D map sections of two vertical real-estate reels (Dubai Series EP2 "Business Bay" and EP5 "Dubai Islands", for H&S Real Estate, presenter Vikas Mangwani) as photo-real Blender renders on this computer, using its GPU. The client wants the look of professional architectural renders (ref/16.webp for Business Bay, ref/nakheel_clean.png for Dubai Islands), not a cartoon/clay look.

Work on branch claude/stoic-ramanujan-as30ee (pull it first). Everything is in video/dubai-series-src/ — read its README.md first.

Keep exactly:
- Script, scene order and timing from mg/scenes.js (EP2 = 42.33 s, EP5 = 44.16 s). Only show what the presenter actually says.
- Camera moves: the cam0/cam1/keys paths in mg/scenes.js (target x,y world units, dist, pitch, yaw in degrees, ease-in-out per scene). World +x east, +y north, z up. Map area = top 1080x1240 of a 1080x1920 frame.
- Buildings rising from their plots (slight overshoot) at the times set in each episode's setup() in mg/scenes.js.
- Labels, pins, gold highlights, stats panel and DXB plane route: keep the existing HTML overlay (mg/index.html + render.js) composited over the Blender frames, or redo them in compositing.
- Presenter openings are finished: ref/ep2_opening_burj.mp4 (first 6.33 s), ref/ep5_opening_beach.mp4 (first 5.70 s). After the opening he sits in a rounded window at x56 y1268, 340x430 (see encode.sh, winmask.png). The presenter source video is not in the repo; ask me for "HS - Vikas dubai final 2 videos" (EP2 = 0.00–42.33 s, EP5 = 50.567–94.73 s).

Geometry:
- Business Bay/Downtown: businessBay() in mg/map3d.js (Dubai Canal, Burj Khalifa at world (-120,160), Dubai Mall, fountain lake, Sheikh Zayed Rd, Al Khail Rd, Palm Jumeirah). Look of ref/16.webp: warm tan ground with dense beige/white low-rise blocks, dark roads, grey/silver/white glass towers with some dark blue/black, dark teal water, low warm sun, long shadows, light haze, tilt-shift depth of field.
- Dubai Islands: nk_seg.json = real island outlines (A Central, B Shore, C Oasis, D Golf, E Elite) + ~3,200 building footprints [x,y,w,d]; nk_plan.png = the Nakheel render rectified to plan (world rect [-1100,-900,1100,900], 2 px/unit), usable as albedo/guide. Look of ref/nakheel_clean.png: deep blue sea, turquoise shallows, beaches, dense tree canopy, golf fairways on C and D, white mid-rise blocks + mall on A, marina at A's west end, villas on B/C/D/E, Deira mainland to the south-east, DXB at world (700,-760).

Approach: Blender Python (blender --background --python script.py), procedural geometry from the JSON/JS data, real materials (reflective glass, concrete, water), scattered trees/palms (Poly Haven), HDRI sky, Cycles on GPU (OptiX/CUDA), 1080x1240 @ 30 fps, denoise. Render a few test stills and show me before the full render (I'm cost-sensitive). Deliver per episode: EP2_Business_Bay_preview_with_presenter.mp4 / EP5_Dubai_Islands_preview_with_presenter.mp4 (presenter + original audio) and ..._graphics_greenscreen.mp4, saved to video/, then commit and push.
