# Watermark Remover (photos + videos)

Remove watermarks and logos from property **photos** and **videos** you
own. Everything runs on your own Hostinger Business hosting:
**no paid API, no per-file cost.**

> Only use this on photos/videos you own or have permission to edit. The
> tool asks the user to confirm this before every removal.

Live at: **https://photo.femmehaven.net**

## How it works

### Photos — smart detection + LaMa AI
- The browser sends the photo + a black/white mask of what you painted.
- **Smart mode (default)** finds the watermark itself inside the painted
  area, so objects behind or between its letters are kept:
  - Watermark strokes are thin, evenly see-through, and one colour (white,
    grey or black). The server compares every painted pixel with the
    picture around it and keeps only pixels that fit that pattern; coloured
    objects, furniture edges, a white window frame etc. don't fit and are
    left untouched.
  - For see-through watermarks it works out the exact opacity and colour,
    then undoes them: `picture = (pixel − opacity × colour) / (1 − opacity)`.
    The object under the watermark comes back instead of being replaced.
  - Only pixels that can't be recovered (solid parts, or picture blown out to
    pure white under the mark) go to the LaMa AI fill — just those thin
    strokes, not the whole painted area.
  - **Show detection** paints what was found on the photo before removing:
    green = restored, orange = AI fill. Raise **Detection sensitivity** if a
    faint watermark is only partly found.
  - If no watermark shape is found it falls back to AI fill.
- **AI fill mode** (the old behaviour): LaMa replaces the whole painted area.
  Use it for solid logos/stickers where there is nothing behind to keep.
- LaMa runs on the server CPU on just the area around what it fills, pasted
  back into the full-size photo. Photos are processed in memory and never
  saved on the server.
- On first start the server downloads the AI model (~200 MB) into
  `models/`. The page shows the download progress.
- Limits: a solid white watermark looks the same as a white object, so in
  that case white objects inside the painted area may be filled too — paint
  tightly. Very faint marks on near-white areas may leave a faint trace.

### Videos — ffmpeg
Running AI on every frame is far too slow for 2–5 minute videos on
shared hosting (thousands of frames × ~30 s each), so videos use a
different method:

1. You upload the video and paint over the watermark(s) on one frame.
   The watermark must stay in the same place for the whole video.
2. The server samples ~240 frames from across the whole video. Because
   the camera moves but the watermark doesn't, it can work out per pixel
   **how see-through the watermark is and its colour**.
3. **Faded / see-through watermarks** (e.g. light text across the middle):
   the blend is mathematically undone on every frame —
   `real = (frame − opacity × colour) / (1 − opacity)` — which brings back
   the actual picture underneath instead of smudging it.
4. **Solid logos** (e.g. a coloured box in the corner): the area is
   filled with a smooth blend of the surrounding pixels (soft patch).
5. The result is re-encoded as MP4 (H.264 + AAC audio kept).

- **Preview 5 seconds** first to check the result quickly, then
  **Process full video**.
- Expect roughly 1–2× the video's length to process (a 3-minute video ≈
  3–6 minutes), depending on how busy the shared server is.
- Uploads and results are stored in `jobs/` and deleted after 2 hours.
- Choose **Solid logo only** if Auto leaves a faint ghost of a logo.
- Works best when the camera moves (walkthroughs). On a tripod shot
  (e.g. an agent talking to camera) parts of the background never change,
  so those parts of the watermark get the soft fill instead of a full
  recovery — usually fine on plain walls.

## Deploy / update on Hostinger (Business account)

### Make the upload zip

Use `watermark-remover.zip` (sent in the chat), **or** zip the *contents*
of this `watermark-remover/` folder so `package.json` is at the top level.
Include the hidden `.npmrc` file. Do **not** include `node_modules/`,
`models/` or `jobs/`.

### First deploy

1. hPanel (Business account) → **Websites → Add Website → Node.js Web App**
   → upload the zip.
2. Build settings: Framework **Express**, Node **22.x**, root `./`,
   entry `server.js`, install `npm install`, start `npm start`.
3. Environment variables:
   | Name | Value |
   |---|---|
   | `ACCESS_PASSWORD` | a password (letters and numbers) — any username works at the login prompt |
   | `ORT_THREADS` | `2` |
   | `FFMPEG_THREADS` | `2` |
4. Deploy, then connect the domain `photo.femmehaven.net`.

### Updating to a new version

Open the existing app in hPanel → upload the new zip / **Redeploy**. Keep
the same environment variables. The AI model may re-download once after a
redeploy (the page shows progress).

## If something goes wrong

| Symptom | Likely cause / fix |
|---|---|
| Build fails while installing `onnxruntime-node` | Make sure the `.npmrc` file is in the zip (it stops an unneeded GPU download). |
| Build fails while installing `ffmpeg-static` | It downloads the ffmpeg program from GitHub during install. Redeploy once; if it keeps failing, send me the build log. |
| "AI model error: Model download failed" | Download `lama_fp32.onnx` from https://huggingface.co/Carve/LaMa-ONNX and upload it into a `models/` folder in the app via File Manager. |
| Video upload fails at a certain size | Hostinger may limit upload size. Try a smaller/compressed video, or tell me the size that fails. |
| Video stuck at the same % for a long time / app restarts | Shared hosting CPU or memory limit. Try a shorter clip; tell me the video length and resolution. |
| Faint outline of the watermark left in the video | Brush a little wider around the watermark and run again. |
| Photo: blurry/odd fill on big watermarks | Remove large ones in a few smaller strokes using **Remove more from this result**. |

## Run locally

```bash
cd watermark-remover
npm install
npm start          # http://localhost:3000
```
