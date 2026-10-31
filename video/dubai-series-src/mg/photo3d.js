// "3D photo" map: a reference render turned into a depth-displaced mesh (depth from a
// monocular depth model), flown through with a perspective camera so the city keeps its
// real look while buildings shift against the ground. Labels, highlights and routes are
// placed in the picture's own pixel coordinates and projected through the same camera.
(function () {
  const T = THREE;
  const clamp = (x, a = 0, b = 1) => Math.max(a, Math.min(b, x));
  const ease = t => t * t * (3 - 2 * t);

  window.PhotoMap = function (glCanvas, canvas2d, P) {
    const W = glCanvas.width, H = glCanvas.height, IW = P.w, IH = P.h, DZ = P.dz || 260;
    const renderer = new T.WebGLRenderer({ canvas: glCanvas, antialias: true, preserveDrawingBuffer: true });
    renderer.setPixelRatio(1); renderer.setSize(W, H, false); renderer.outputEncoding = T.sRGBEncoding;
    renderer.setClearColor(new T.Color(P.bg || '#0b1512'));
    const scene = new T.Scene();
    const camera = new T.PerspectiveCamera(30, W / H, 10, 40000);

    // depth samples (8-bit grey image, near = bright)
    const dc = document.createElement('canvas'); dc.width = P.depthImg.width; dc.height = P.depthImg.height;
    const dx = dc.getContext('2d'); dx.drawImage(P.depthImg, 0, 0);
    const dd = dx.getImageData(0, 0, dc.width, dc.height).data;
    const depthAt = (x, y) => {                                       // bilinear, x/y in image px
      const fx = clamp(x / IW) * (dc.width - 1), fy = clamp(y / IH) * (dc.height - 1);
      const x0 = Math.floor(fx), y0 = Math.floor(fy), x1 = Math.min(x0 + 1, dc.width - 1), y1 = Math.min(y0 + 1, dc.height - 1), ax = fx - x0, ay = fy - y0;
      const g = (xx, yy) => dd[(yy * dc.width + xx) * 4] / 255;
      return (g(x0, y0) * (1 - ax) + g(x1, y0) * ax) * (1 - ay) + (g(x0, y1) * (1 - ax) + g(x1, y1) * ax) * ay;
    };
    const P3 = (x, y) => new T.Vector3(x - IW / 2, IH / 2 - y, depthAt(x, y) * DZ);

    // displaced mesh
    const SX = P.segX || 480, SY = Math.round(SX * IH / IW);
    const geo = new T.PlaneGeometry(IW, IH, SX, SY), pos = geo.attributes.position;
    for (let i = 0; i < pos.count; i++) { const x = pos.getX(i) + IW / 2, y = IH / 2 - pos.getY(i); pos.setZ(i, depthAt(x, y) * DZ); }
    geo.computeVertexNormals();
    const tex = new T.Texture(P.img); tex.needsUpdate = true; tex.encoding = T.sRGBEncoding; tex.anisotropy = 8;
    tex.minFilter = T.LinearMipmapLinearFilter;
    scene.add(new T.Mesh(geo, new T.MeshBasicMaterial({ map: tex })));
    { const g = new T.BoxGeometry(IW + 24, IH + 24, 40); const base = new T.Mesh(g, new T.MeshBasicMaterial({ color: '#1d2421' })); base.position.z = -21; scene.add(base);
      const e = new T.Mesh(new T.PlaneGeometry(IW + 26, IH + 26), new T.MeshBasicMaterial({ color: '#c9a46a' })); e.position.z = -.5; scene.add(e); }

    const ctx = canvas2d.getContext('2d');
    let C = null;
    function proj(x, y, lift = 0) { const v = P3(x, y); v.z += lift; v.project(C); return [(v.x + 1) / 2 * W, (1 - v.y) / 2 * H, v.z]; }

    function pill(L, a, t) {
      const s = proj(L.x, L.y); const lift = L.lift ?? 60;
      ctx.globalAlpha = a;
      ctx.strokeStyle = 'rgba(230,200,145,.85)'; ctx.lineWidth = 1.5; ctx.beginPath(); ctx.moveTo(s[0], s[1]); ctx.lineTo(s[0], s[1] - lift * a); ctx.stroke();
      ctx.fillStyle = '#e6c891'; ctx.beginPath(); ctx.arc(s[0], s[1], 5, 0, 6.283); ctx.fill();
      if (L.pulse) { const ph = ((t - L.at) % 1.4) / 1.4; ctx.strokeStyle = `rgba(230,200,145,${1 - ph})`; ctx.lineWidth = 2; ctx.beginPath(); ctx.arc(s[0], s[1], 6 + ph * 40, 0, 6.283); ctx.stroke(); }
      ctx.font = L.big ? 'italic 600 40px Corm' : '600 21px Jost'; const txt = L.big ? L.text : L.text.toUpperCase();
      if (!L.big) ctx.letterSpacing = '4px';
      const w = ctx.measureText(txt).width + 36, hg = L.big ? 58 : 44, x = s[0] - w / 2, y = s[1] - lift * a - hg;
      ctx.fillStyle = L.gold ? 'rgba(201,164,106,.95)' : 'rgba(12,22,19,.82)'; ctx.strokeStyle = 'rgba(230,200,145,.7)';
      ctx.beginPath(); ctx.roundRect(x, y, w, hg, hg / 2); ctx.fill(); if (!L.gold) ctx.stroke();
      ctx.fillStyle = L.gold ? '#14100a' : '#efe8d8'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillText(txt, s[0] + (L.big ? 0 : 2), y + hg / 2 + 1);
      ctx.letterSpacing = '0px'; ctx.globalAlpha = 1;
    }

    return function render(t, cam, opt = {}) {
      // camera: looks at image point (x, y) so `zoom` image-px fit across the frame, orbited by yaw / pitch
      // keep the frame inside the picture (no empty edges): limit zoom, then clamp the target
      if (!cam.free) {
        const zoom = Math.min(cam.zoom, IH * camera.aspect * .9), hv = zoom / camera.aspect / 2;
        cam = { ...cam, zoom, x: clamp(cam.x, zoom / 2 + 20, IW - zoom / 2 - 20), y: clamp(cam.y, hv * .82, IH - hv - 10) };
      }
      const tgt = P3(cam.x, cam.y), fov = camera.fov * Math.PI / 180;
      const dist = (cam.zoom / 2) / (Math.tan(fov / 2) * camera.aspect);
      const yaw = (cam.yaw || 0) * Math.PI / 180, pit = (cam.pitch || 0) * Math.PI / 180;
      const dir = new T.Vector3(Math.sin(yaw) * Math.cos(pit), -Math.sin(pit), Math.cos(yaw) * Math.cos(pit));
      camera.position.copy(tgt).addScaledVector(dir, dist); const sp = (cam.spin || 0) * Math.PI / 180; camera.up.set(Math.sin(sp), Math.cos(sp), 0); camera.lookAt(tgt);
      camera.rotateZ((cam.roll || 0) * Math.PI / 180); camera.updateMatrixWorld(); camera.updateProjectionMatrix(); C = camera;
      renderer.render(scene, camera);

      ctx.clearRect(0, 0, W, H);
      for (const h of opt.highlights || []) {                         // gold glow over a region of the picture
        const a = clamp((t - h.at) / .6) * (h.until ? 1 - clamp((t - h.until) / .5) : 1); if (a <= 0) continue;
        const pts = h.poly.map(p => proj(p[0], p[1], 2));
        ctx.save(); ctx.beginPath(); pts.forEach((p, i) => i ? ctx.lineTo(p[0], p[1]) : ctx.moveTo(p[0], p[1])); ctx.closePath();
        ctx.globalAlpha = a; ctx.fillStyle = 'rgba(230,190,110,.22)'; ctx.fill();
        ctx.shadowColor = 'rgba(240,200,120,.9)'; ctx.shadowBlur = 18; ctx.strokeStyle = '#f0cd8a'; ctx.lineWidth = 3;
        ctx.setLineDash([14, 8]); ctx.lineDashOffset = -t * 40; ctx.stroke(); ctx.restore();
      }
      for (const r of opt.routes || []) {
        const p = ease(clamp((t - r.at) / (r.dur || 1.5))); if (p <= 0) continue;
        const pts = r.pts.map(q => proj(q[0], q[1], 3)); const n = Math.max(2, Math.ceil(pts.length * p));
        ctx.lineCap = 'round'; ctx.strokeStyle = 'rgba(230,200,145,.35)'; ctx.lineWidth = 12; ctx.beginPath(); pts.slice(0, n).forEach((q, i) => i ? ctx.lineTo(q[0], q[1]) : ctx.moveTo(q[0], q[1])); ctx.stroke();
        ctx.strokeStyle = '#f3dca7'; ctx.lineWidth = 5; ctx.setLineDash([16, 12]); ctx.lineDashOffset = -t * 60; ctx.stroke(); ctx.setLineDash([]);
        if (r.plane && p < 1) { const e = pts[n - 1], b = pts[Math.max(n - 3, 0)], ang = Math.atan2(e[1] - b[1], e[0] - b[0]);
          ctx.save(); ctx.translate(e[0], e[1]); ctx.rotate(ang); ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.moveTo(18, 0); ctx.lineTo(-10, -4); ctx.lineTo(-4, -16); ctx.lineTo(-8, -16); ctx.lineTo(-16, -3); ctx.lineTo(-22, -3); ctx.lineTo(-24, -9); ctx.lineTo(-27, -9); ctx.lineTo(-25, 0); ctx.lineTo(-27, 9); ctx.lineTo(-24, 9); ctx.lineTo(-22, 3); ctx.lineTo(-16, 3); ctx.lineTo(-8, 16); ctx.lineTo(-4, 16); ctx.lineTo(-10, 4); ctx.closePath(); ctx.fill(); ctx.restore(); }
      }
      for (const L of opt.leaders || []) {
        const a = clamp((t - L.at) / .7) * (L.until ? 1 - clamp((t - L.until) / .4) : 1); if (a <= 0) continue;
        const s1 = proj(L.x, L.y), top = L.top ?? 300;
        ctx.globalAlpha = a; ctx.strokeStyle = 'rgba(255,255,255,.85)'; ctx.lineWidth = 2; ctx.beginPath(); ctx.moveTo(s1[0], s1[1]); ctx.lineTo(s1[0], s1[1] + (top + 70 - s1[1]) * a); ctx.stroke();
        ctx.fillStyle = '#fff'; ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic'; ctx.shadowColor = 'rgba(0,0,0,.45)'; ctx.shadowBlur = 18;
        ctx.font = '500 92px Corm'; ctx.fillText(L.text, s1[0], top); ctx.font = '500 22px Jost'; ctx.letterSpacing = '9px'; ctx.fillText(L.sub || '', s1[0] + 4, top + 44); ctx.letterSpacing = '0px';
        ctx.shadowBlur = 0; ctx.globalAlpha = 1;
      }
      for (const L of opt.labels || []) { const a = clamp((t - L.at) / .5) * (L.until ? 1 - clamp((t - L.until) / .4) : 1); if (a > 0) pill(L, a, t); }
    };
  };
})();
