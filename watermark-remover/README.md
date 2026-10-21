# Photo Watermark Remover (test version)

Upload a property photo, paint over the watermark or logo, and the
**LaMa** AI model fills the area in with matching background — floor,
wall, sky, etc. Everything runs on your own Hostinger Business hosting:
**no paid API, no per-photo cost.**

> Only use this on photos you own or have permission to edit. The tool
> asks the user to confirm this before every removal.

Live at: **https://photo.femmehaven.net** (once deployed — steps below)

## How it works

- Node.js + Express app (Hostinger Business "Node.js Web App").
- The browser sends the photo + a black/white mask of what you painted.
- The server crops just the area around the mask, runs LaMa on it at
  512×512, and pastes the result back into the full-size photo.
- Photos are processed in memory and never saved on the server.
- On first start the server downloads the AI model (~200 MB) into
  `models/`. The page shows the download progress; after that it's ready.

## Deploy on Hostinger (Business account)

### 1. Make the upload zip

Download `watermark-remover.zip` (sent in the chat), **or** make it
yourself: zip the *contents* of this `watermark-remover/` folder so that
`package.json` is at the top level of the zip. Do **not** include
`node_modules/` or `models/`.

### 2. Create the Node.js app

1. Log in to hPanel with the **Business Web Hosting** account (the one
   that has `femmehaven.net`).
2. Go to **Websites → Add Website → Node.js Apps**.
3. Choose **Upload your website files** and upload `watermark-remover.zip`.
4. When asked for the domain, choose/enter **`photo.femmehaven.net`**.
   If it doesn't let you type a subdomain, first create it under
   **Domains → Subdomains** for `femmehaven.net`, then come back.
5. Build settings — Hostinger usually detects these, check they match:
   | Setting | Value |
   |---|---|
   | Framework | Express |
   | Node.js version | **22.x** |
   | Entry file | `server.js` |
   | Build / install command | `npm install` |
   | Start command | `npm start` |
6. **Environment variables** (strongly recommended):
   | Name | Value |
   |---|---|
   | `ACCESS_PASSWORD` | a password of your choice — without it anyone who finds the URL can use your server |
   | `ORT_THREADS` | `2` |
7. Click **Deploy** and wait for the build to finish.

### 3. First run

1. Open https://photo.femmehaven.net (enter the password if you set
   one — any username works).
2. The top of the page shows *"Downloading AI model… X%"*. Wait until
   it says **AI model ready** (usually 1–3 minutes, one time only).
3. Upload a photo, paint over the watermark with a bit of margin, tick
   the confirmation box, click **Remove watermark**.
4. The result shows how many seconds it took. **Tell me this number** —
   it decides whether this free setup is fast enough.

### Updating later

Re-upload a new zip the same way (or redeploy). The model is
re-downloaded if Hostinger wipes the app folder on redeploy.

## If something goes wrong

| Symptom | Likely cause / fix |
|---|---|
| Build fails while installing `onnxruntime-node` | Make sure the `.npmrc` file is in the zip (it stops an unneeded GPU download). Some zip tools hide dot-files. |
| Stuck on "AI model error: Model download failed" | The server couldn't reach Hugging Face. Download `lama_fp32.onnx` from https://huggingface.co/Carve/LaMa-ONNX yourself and upload it into a `models/` folder in the app via File Manager. |
| "Failed (503)" / app restarts while processing | Hit the hosting memory limit. Try a smaller photo; tell me and I'll lower memory use. |
| Very slow (over ~60 s) | Shared CPU is busy/limited. We can switch processing to a paid per-image API or a VPS without changing the page. |
| Blurry/odd fill on big watermarks across the middle | LaMa works best on small–medium marks. Remove large ones in a few smaller strokes using **Remove more from this result**. |

## Run locally

```bash
cd watermark-remover
npm install
npm start          # http://localhost:3000
```
