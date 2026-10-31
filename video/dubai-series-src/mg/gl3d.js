// Realistic WebGL (three.js) layer for the map scenes. Same worlds, cameras and rise timing as the
// Canvas2D map; the 2D canvas now only draws labels, leaders, routes and clouds on top.
// World coords (x, y, z-up) map to three as (x, z, -y).
(function () {
  const T = THREE;
  T.ColorManagement.legacyMode = false;
  const clamp = (x, a = 0, b = 1) => Math.max(a, Math.min(b, x));
  const easeOutBack = t => { const c = 1.15; return 1 + (c + 1) * Math.pow(t - 1, 3) + c * Math.pow(t - 1, 2); };
  function rng(seed) { let s = seed >>> 0 || 1; return () => { s = (s * 1664525 + 1013904223) >>> 0; return s / 4294967296; }; }
  const W3 = (x, y, z = 0) => new T.Vector3(x, z, -y);

  // ------------------------------------------------------------------ facade material
  // Real-looking facades: windows laid out in each building's own frame, reflective glass panes,
  // matte spandrels and mullions, per-pane variation (blinds / lights), dark gravel roofs, base AO.
  function facade(o) {
    const m = new T.MeshStandardMaterial({ roughness: o.rough ?? .7, metalness: 0, color: 0xffffff });
    m.onBeforeCompile = sh => {
      Object.assign(sh.uniforms, { uFloor: { value: o.floor }, uWin: { value: o.win }, uGlass: { value: o.glass }, uCyl: { value: o.cyl ? 1 : 0 }, uLow: { value: o.low ? 1 : 0 } });
      sh.vertexShader = 'varying vec3 vWP; varying vec3 vLoc; varying vec3 vON; varying float vSeed; varying float vR;\n' +
        sh.vertexShader.replace('#include <worldpos_vertex>', `#include <worldpos_vertex>
        #ifdef USE_INSTANCING
          vec3 sc = vec3(length(instanceMatrix[0].xyz), length(instanceMatrix[1].xyz), length(instanceMatrix[2].xyz));
          vWP = (modelMatrix * instanceMatrix * vec4(transformed, 1.0)).xyz;
          vec2 cc = floor(instanceMatrix[3].xz * 0.25);
          vSeed = fract(sin(dot(cc, vec2(12.9898, 78.233))) * 43758.5453);
        #else
          vec3 sc = vec3(30.0, 1.0, 30.0); vWP = (modelMatrix * vec4(transformed, 1.0)).xyz; vSeed = 0.37;
        #endif
          vLoc = transformed * sc; vON = objectNormal; vR = sc.x * 0.5;`);
      sh.fragmentShader = `uniform float uFloor; uniform float uWin; uniform float uGlass; uniform float uCyl; uniform float uLow;
        varying vec3 vWP; varying vec3 vLoc; varying vec3 vON; varying float vSeed; varying float vR;
        float h21(vec2 p){ return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453); }
        float gWin = 0.0; float gRoof = 0.0;\n` +
        sh.fragmentShader.replace('#include <color_fragment>', `#include <color_fragment>
          float side = 1.0 - step(0.5, abs(vON.y));
          gRoof = 1.0 - side;
          float u = uCyl > 0.5 ? atan(vLoc.z, vLoc.x) * vR : (abs(vON.x) > 0.5 ? vLoc.z : vLoc.x);
          float fh = uFloor * mix(0.92, 1.12, vSeed);
          float ww = uWin * mix(0.75, 1.5, fract(vSeed * 7.31));
          float fy = vWP.y / fh, fx = u / ww;
          float fl = fract(fy), cl = fract(fx);
          vec2 cell = vec2(floor(fx), floor(fy));
          float band = step(0.5, fract(vSeed * 3.17)) * (1.0 - uLow);                 // ribbon glazing vs punched windows
          float fins = step(0.55, fract(vSeed * 9.13)) * (1.0 - uLow) * max(uCyl, step(0.8, fract(vSeed * 4.7)));
          float sp = mix(0.30, 0.10, band);
          float win = smoothstep(sp, sp + 0.03, fl) * (1.0 - smoothstep(0.95, 0.98, fl));
          win *= mix(smoothstep(0.10, 0.14, cl) * (1.0 - smoothstep(0.86, 0.90, cl)), 1.0 - 0.85 * (1.0 - smoothstep(0.0, 0.05, cl)) , band);
          float fw = fwidth(fy) + fwidth(fx);
          win = mix(win, mix(0.55, 0.8, band), smoothstep(0.25, 0.7, fw));
          float vf = smoothstep(0.18, 0.24, cl) * (1.0 - smoothstep(0.74, 0.80, cl)) * (1.0 - 0.6 * (1.0 - smoothstep(0.0, 0.06, fl)));
          vf = mix(vf, 0.6, smoothstep(0.25, 0.7, fw));
          win = mix(win, vf, fins);
          win *= side; gWin = win;
          float pane = h21(cell + vSeed * 91.0);
          float lum = dot(diffuseColor.rgb, vec3(0.3, 0.59, 0.11));
          vec3 tintA = lum > 0.45 ? vec3(0.06, 0.10, 0.16) : diffuseColor.rgb * 0.55 + vec3(0.004, 0.008, 0.014);
          tintA = mix(tintA, vec3(0.035, 0.06, 0.12), uLow * 0.6);
          vec3 glassC = mix(tintA, tintA * 1.8 + 0.015, pane * mix(0.3, 0.7, 1.0 - band)) * mix(1.0, 0.75, step(0.88, pane));
          glassC = mix(glassC, vec3(0.62, 0.55, 0.42), step(0.97, pane) * uLow);
          diffuseColor.rgb = mix(diffuseColor.rgb, glassC, win * uGlass);
          diffuseColor.rgb *= mix(0.62, 1.0, smoothstep(0.0, 9.0, vWP.y));
          vec3 roofC = mix(vec3(0.50, 0.50, 0.48), vec3(0.62, 0.61, 0.58), h21(floor(vWP.xz * 0.5)));
          float parapet = 0.0;
          diffuseColor.rgb = mix(diffuseColor.rgb, roofC * mix(1.0, 1.25, uLow), gRoof);`)
        .replace('#include <roughnessmap_fragment>', `#include <roughnessmap_fragment>
          roughnessFactor = mix(roughnessFactor, 0.14, gWin * uGlass); roughnessFactor = mix(roughnessFactor, 0.95, gRoof);`)
        .replace('#include <metalnessmap_fragment>', `#include <metalnessmap_fragment>
          metalnessFactor = mix(metalnessFactor, 0.55, gWin * uGlass);`);
    };
    m.envMapIntensity = .75; m.customProgramCacheKey = () => JSON.stringify(o);
    return m;
  }

  // ------------------------------------------------------------------ ground painting helpers
  function makeCanvas(N) { const c = document.createElement('canvas'); c.width = c.height = N; return c; }
  function painter(ctx, N, b) {                 // b = [x0, y0, x1, y1] world rect
    const sx = N / (b[2] - b[0]), sy = N / (b[3] - b[1]);
    const P = (x, y) => [(x - b[0]) * sx, (b[3] - y) * sy];
    return {
      s: sx, P,
      path(pts, close = true) { ctx.beginPath(); pts.forEach((p, i) => { const q = P(p[0], p[1]); i ? ctx.lineTo(q[0], q[1]) : ctx.moveTo(q[0], q[1]); }); if (close) ctx.closePath(); },
      noise(alpha, size, seed, cols) {                       // speckle texture over the whole canvas
        const r = rng(seed), n = Math.round(N * N / (size * size) * .9);
        for (let i = 0; i < n; i++) { ctx.fillStyle = cols[Math.floor(r() * cols.length)]; ctx.globalAlpha = alpha * r(); ctx.fillRect(r() * N, r() * N, size * (.5 + r()), size * (.5 + r())); }
        ctx.globalAlpha = 1;
      },
    };
  }
  function rooftops(ctx, pt, test, seed, step, cols, angle = 0.66) {   // dense low-rise city baked into the ground
    const r = rng(seed); const ca = Math.cos(angle), sa = Math.sin(angle), N = ctx.canvas.width;
    for (let gx = -6000; gx < 6000; gx += step) for (let gy = -6000; gy < 6000; gy += step) {
      const x = gx * ca - gy * sa, y = gx * sa + gy * ca; if (!test([x, y])) continue;
      const q = pt.P(x, y), s = pt.s; if (q[0] < -20 || q[1] < -20 || q[0] > N + 20 || q[1] > N + 20) continue;
      const mx = ((gx % 110) + 110) % 110, my = ((gy % 110) + 110) % 110; if (mx < 12 || my < 12) continue;   // street grid
      if (r() < .1) continue;
      const parts = 1 + Math.floor(r() * 3);
      ctx.save(); ctx.translate(q[0], q[1]); ctx.rotate(-angle + (r() < .15 ? (r() - .5) * .6 : 0));
      for (let k = 0; k < parts; k++) {
        const w = step * (.25 + r() * .5), d = step * (.25 + r() * .5), ox = (r() - .5) * step * .45, oy = (r() - .5) * step * .45;
        ctx.fillStyle = 'rgba(45,32,20,.22)'; ctx.fillRect((ox - w / 2) * s + .9, (oy - d / 2) * s + .9, w * s, d * s);
        ctx.fillStyle = cols[Math.floor(r() * cols.length)]; ctx.fillRect((ox - w / 2) * s, (oy - d / 2) * s, w * s, d * s);
      }
      if (r() < .25) { ctx.fillStyle = ['#5d7a43', '#4c6a39', '#6f8b4d'][Math.floor(r() * 3)]; ctx.beginPath(); ctx.arc((r() - .5) * step * s, (r() - .5) * step * s, (1 + r() * 2) * s, 0, 6.28); ctx.fill(); }
      ctx.restore();
    }
  }
  function streetGrid(ctx, pt, test, angle = 0.66, dark = false) {
    const ca = Math.cos(angle), sa = Math.sin(angle), s = pt.s;
    ctx.lineCap = 'butt';
    for (const pass of dark ? [['#6e6253', 8], ['#3f3f3d', 5.5]] : [['#a9a398', 11], ['#5d6063', 7.5]]) {
      ctx.strokeStyle = pass[0]; ctx.lineWidth = pass[1] * s;
      for (let k = -6050; k < 6000; k += 110) for (const dir of [0, 1]) {
        let prev = null; ctx.beginPath();
        for (let u = -6000; u <= 6000; u += 40) {
          const gx = dir ? u : k + 5, gy = dir ? k + 5 : u; const x = gx * ca - gy * sa, y = gx * sa + gy * ca;
          const q = pt.P(x, y); const ok = test([x, y]);
          if (ok && prev) ctx.lineTo(q[0], q[1]); else if (ok) ctx.moveTo(q[0], q[1]); prev = ok;
        }
        ctx.stroke();
      }
    }
  }
  function road(ctx, pt, pts, w, asphalt = '#55595c') {
    const s = pt.s; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
    pt.path(pts, false); ctx.strokeStyle = '#7a7166'; ctx.lineWidth = (w + 3) * s; ctx.stroke();
    pt.path(pts, false); ctx.strokeStyle = asphalt; ctx.lineWidth = w * s; ctx.stroke();
    if (w * s > 10) { pt.path(pts, false); ctx.setLineDash([3 * s, 3 * s]); ctx.strokeStyle = 'rgba(240,240,235,.55)'; ctx.lineWidth = Math.max(1, .25 * s); ctx.stroke(); ctx.setLineDash([]); }
  }
  function water(ctx, pt, poly, deep, shallow, edgeBlur) {
    pt.path(poly); ctx.save(); ctx.clip();
    const g = ctx.createLinearGradient(0, 0, ctx.canvas.width, ctx.canvas.height); g.addColorStop(0, deep); g.addColorStop(1, shallow);
    ctx.fillStyle = g; ctx.fillRect(0, 0, ctx.canvas.width, ctx.canvas.height);
    const r = rng(9); ctx.globalAlpha = .07;
    for (let i = 0; i < 2500; i++) { ctx.fillStyle = r() < .5 ? '#ffffff' : '#0b2a3a'; ctx.fillRect(r() * ctx.canvas.width, r() * ctx.canvas.height, 2 + r() * 14, 1 + r() * 2); }
    ctx.globalAlpha = 1; ctx.restore();
    if (edgeBlur) { pt.path(poly); ctx.strokeStyle = edgeBlur; ctx.lineWidth = 2; ctx.stroke(); }
  }

  // ------------------------------------------------------------------ main
  window.MapGL = function (glCanvas, world, kind) {
    const W = glCanvas.width, H = glCanvas.height;
    const renderer = new T.WebGLRenderer({ canvas: glCanvas, antialias: true, preserveDrawingBuffer: true, alpha: false });
    renderer.setPixelRatio(1); renderer.setSize(W, H, false);
    renderer.outputEncoding = T.sRGBEncoding; renderer.toneMapping = T.ACESFilmicToneMapping; renderer.toneMappingExposure = kind === 'di' ? .92 : .78;
    renderer.shadowMap.enabled = true; renderer.shadowMap.type = T.PCFSoftShadowMap;
    const scene = new T.Scene();
    const SKY = new T.Color(kind === 'di' ? '#c9dbe6' : '#9d8f74');
    renderer.setClearColor(SKY);
    scene.fog = new T.Fog(SKY, 1000, 6000);
    scene.add(new T.HemisphereLight(0xdfeefe, 0x8a7656, 0.36));
    const sun = new T.DirectionalLight(0xffe6c2, 3.1); sun.castShadow = true;
    sun.shadow.mapSize.set(3072, 3072); sun.shadow.bias = -0.0004; sun.shadow.normalBias = 0.6;
    scene.add(sun); scene.add(sun.target);
    const SUN = kind === 'bb' ? new T.Vector3(-0.7, 0.55, -0.35).normalize() : new T.Vector3(-0.55, 0.78, -0.30).normalize();
    const camera = new T.PerspectiveCamera(40, 1, 2, 30000);
    {                                                         // sky environment for glass reflections
      const es = new T.Scene(); const sg = new T.SphereGeometry(100, 32, 16);
      const sm = new T.ShaderMaterial({ side: T.BackSide, vertexShader: 'varying vec3 vP; void main(){ vP = position; gl_Position = projectionMatrix * modelViewMatrix * vec4(position,1.0); }',
        fragmentShader: 'varying vec3 vP; void main(){ float h = normalize(vP).y; vec3 sky = mix(vec3(0.86,0.9,0.92), vec3(0.42,0.62,0.85), smoothstep(0.0,0.6,h)); vec3 gnd = mix(vec3(0.7,0.6,0.46), vec3(0.55,0.47,0.36), smoothstep(0.0,-0.5,h)); vec3 c = h > 0.0 ? sky : gnd; float sunv = pow(max(dot(normalize(vP), normalize(vec3(-0.55,0.78,-0.3))),0.0), 300.0); gl_FragColor = vec4(c + sunv*4.0, 1.0); }' });
      es.add(new T.Mesh(sg, sm)); const pm = new T.PMREMGenerator(renderer); scene.environment = pm.fromScene(es, 0.02).texture;
    }

    // ---------------- ground (outer + detailed inner), painted per world
    const B = world.ground.bounds; const bx0 = B[0][0], by0 = B[0][1], bx1 = B[2][0], by1 = B[2][1];
    const inner = kind === 'bb' ? [-1150, -1150, 1150, 1150] : [-1600, -1600, 1600, 1600];
    function groundPlane(rect, N, y, paint) {
      const c = makeCanvas(N), ctx = c.getContext('2d'), pt = painter(ctx, N, rect); paint(ctx, pt, N);
      const tex = new T.CanvasTexture(c); tex.encoding = T.sRGBEncoding; tex.anisotropy = 8;
      const geo = new T.PlaneGeometry(rect[2] - rect[0], rect[3] - rect[1]); geo.rotateX(-Math.PI / 2);
      const mesh = new T.Mesh(geo, new T.MeshStandardMaterial({ map: tex, roughness: .93, metalness: 0, envMapIntensity: .35 }));
      mesh.position.set((rect[0] + rect[2]) / 2, y, -(rect[1] + rect[3]) / 2); mesh.receiveShadow = true; scene.add(mesh);
      return { c, rect };
    }
    let paintWorld;
    if (kind === 'bb') {
      paintWorld = (ctx, pt, N) => {
        ctx.fillStyle = '#9c7b52'; ctx.fillRect(0, 0, N, N);
        pt.noise(.55, N / 300, 3, ['#8a6a44', '#b08d62', '#7d6142', '#a8875c', '#6f5a40', '#c09d70']);
        const sea = world.water[0].poly;
        streetGrid(ctx, pt, p => !inPolyW(p, sea), 0.66, true);
        rooftops(ctx, pt, p => !inPolyW(p, sea), 21, 12, ['#c2a77e', '#b39570', '#d2bd98', '#a48a66', '#cbb088', '#ddd0b5', '#968062', '#e6dcc6']);
        for (const w of world.water) water(ctx, pt, w.poly, '#0f3d52', '#1d5f78', 'rgba(150,135,105,.9)');
        for (const pk of world.islands) { pt.path(pk.poly); ctx.fillStyle = pk.col[1] > pk.col[0] ? '#5c6b38' : '#c8b088'; ctx.fill(); }   // parks / Palm Jumeirah
        road(ctx, pt, world.roads[0].pts, 16, '#3c3d3d'); road(ctx, pt, world.roads[1].pts, 12, '#3c3d3d'); road(ctx, pt, world.roads[2].pts, 7, '#4a4a48'); road(ctx, pt, world.roads[3].pts, 5, '#4a4a48');
        // downtown plaza paving around the lake and Burj
        pt.path(world.lake || []); ctx.lineWidth = 18 * pt.s; ctx.strokeStyle = '#cfc6b4'; ctx.stroke(); if (world.lake) water(ctx, pt, world.lake, '#1a6f95', '#2a8ab0');
      };
    } else {
      paintWorld = (ctx, pt, N) => {
        ctx.fillStyle = '#b3a58e'; ctx.fillRect(0, 0, N, N);
        pt.noise(.4, N / 300, 5, ['#a49680', '#c4b8a2', '#958872', '#bdb19b']);
        const sea = world.water[0].poly;
        streetGrid(ctx, pt, p => !inPolyW(p, sea) && Math.hypot((p[0] - world.apt[0]) * .8, p[1] - world.apt[1]) > 360);
        rooftops(ctx, pt, p => !inPolyW(p, sea) && Math.hypot((p[0] - world.apt[0]) * .8, p[1] - world.apt[1]) > 360, 33, 20, ['#c9c0b0', '#d6cebf', '#b8ad9a', '#e1dacd', '#a99d88']);
        pt.path(sea); ctx.save(); ctx.clip();                                         // sea: deep blue, turquoise shallows near land
        ctx.fillStyle = '#123f5e'; ctx.fillRect(0, 0, N, N);
        { const rr = rng(31); ctx.filter = `blur(${Math.round(N / 120)}px)`;
          for (let i = 0; i < 160; i++) { ctx.fillStyle = rr() < .6 ? 'rgba(8,38,70,.55)' : 'rgba(34,96,128,.45)'; ctx.beginPath();
            ctx.ellipse(rr() * N, rr() * N, N * (.01 + rr() * .05), N * (.008 + rr() * .03), rr() * 3, 0, 6.28); ctx.fill(); }
          ctx.filter = 'none'; }
        ctx.filter = `blur(${Math.round(N / 260)}px)`;
        for (const I of world.islands) { pt.path(I.poly); ctx.lineWidth = 34 * pt.s; ctx.strokeStyle = 'rgba(52,160,170,.65)'; ctx.stroke(); ctx.fillStyle = 'rgba(52,160,170,.65)'; ctx.fill(); }
        pt.path(world.coast, false); ctx.lineWidth = 60 * pt.s; ctx.strokeStyle = 'rgba(56,170,180,.7)'; ctx.stroke();
        ctx.filter = 'none';
        const r = rng(4); ctx.globalAlpha = .08;
        for (let i = 0; i < 4000; i++) { ctx.fillStyle = r() < .5 ? '#ffffff' : '#062033'; ctx.fillRect(r() * N, r() * N, 2 + r() * 12, 1 + r() * 2); }
        ctx.globalAlpha = 1; ctx.restore();
        pt.path(world.coast, false); ctx.lineWidth = 10 * pt.s; ctx.strokeStyle = '#e9dcbc'; ctx.stroke();      // mainland beach
        if (world.planImg) {                                                          // Deira from the Nakheel render, clipped to land
          const PR = world.planRect, V = window.NKSEG.valid, cx = V.reduce((a, q) => a + q[0], 0) / 4, cy = V.reduce((a, q) => a + q[1], 0) / 4;
          const vs = V.map(p => [cx + (p[0] - cx) * .97, cy + (p[1] - cy) * .97]);
          ctx.save(); pt.path(vs); ctx.clip(); ctx.beginPath(); sea.forEach((p, i) => { const q = pt.P(p[0], p[1]); i ? ctx.lineTo(q[0], q[1]) : ctx.moveTo(q[0], q[1]); }); ctx.closePath(); ctx.rect(0, 0, N, N); ctx.clip('evenodd');
          const a0 = pt.P(PR[0], PR[3]), a1 = pt.P(PR[2], PR[1]); ctx.drawImage(world.planImg, a0[0], a0[1], a1[0] - a0[0], a1[1] - a0[1]); ctx.restore();
        }
        { const rr = rng(77);                                                          // boats with wakes
          for (let i = 0; i < 70; i++) { const x = -1500 + rr() * 3000, y = -350 + rr() * 1800; if (!inPolyW([x, y], sea) || world.islands.some(I => inPolyW([x, y], I.poly))) continue;
            const P = pt.P(x, y), a2 = rr() * 6.28, L = (4 + rr() * 5) * pt.s; ctx.save(); ctx.translate(P[0], P[1]); ctx.rotate(a2);
            const g = ctx.createLinearGradient(0, 0, -L * 6, 0); g.addColorStop(0, 'rgba(255,255,255,.55)'); g.addColorStop(1, 'rgba(255,255,255,0)');
            ctx.fillStyle = g; ctx.beginPath(); ctx.moveTo(0, 0); ctx.lineTo(-L * 6, -L * 1.2); ctx.lineTo(-L * 6, L * 1.2); ctx.closePath(); ctx.fill();
            ctx.fillStyle = '#ffffff'; ctx.fillRect(-L * .5, -L * .18, L, L * .36); ctx.restore(); } }
        water(ctx, pt, world.water[1].poly, '#1e5f74', '#2a7488');
        pt.path([[world.apt[0] - 330, world.apt[1] - 200], [world.apt[0] + 330, world.apt[1] - 200], [world.apt[0] + 330, world.apt[1] + 200], [world.apt[0] - 330, world.apt[1] + 200]]);
        ctx.fillStyle = '#9d9a90'; ctx.fill();
        for (const rd of world.roads) road(ctx, pt, rd.pts, rd.w * 3);
      };
    }
    groundPlane([bx0, by0, bx1, by1], 4096, 0, paintWorld);
    groundPlane(inner, 4096, 0.05, paintWorld);
    if (kind === 'bb') groundPlane([-650, -650, 650, 650], 4096, 0.1, paintWorld);

    // ---------------- water: glossy, rippled surface over the painted sea bed (sun glints + sky reflection)
    const nrm = (() => {
      const N = 512, c = makeCanvas(N), x = c.getContext('2d'), img = x.createImageData(N, N), r = rng(12);
      const hgt = new Float32Array(N * N);
      for (let k = 0; k < 60; k++) { const fx = (1 + Math.floor(r() * 8)) * 2 * Math.PI / N, fy = (1 + Math.floor(r() * 8)) * 2 * Math.PI / N, ph = r() * 6.28, am = 1 / (1 + k * .2);
        for (let j = 0; j < N; j++) for (let i = 0; i < N; i++) hgt[j * N + i] += am * Math.sin(i * fx * (r() < .5 ? 1 : 1) + j * fy + ph); }
      for (let j = 0; j < N; j++) for (let i = 0; i < N; i++) {
        const dx = hgt[j * N + (i + 1) % N] - hgt[j * N + (i - 1 + N) % N], dy = hgt[((j + 1) % N) * N + i] - hgt[((j - 1 + N) % N) * N + i];
        const o = (j * N + i) * 4; img.data[o] = 128 - dx * 40; img.data[o + 1] = 128 - dy * 40; img.data[o + 2] = 255; img.data[o + 3] = 255; }
      x.putImageData(img, 0, 0); const t = new T.CanvasTexture(c); t.wrapS = t.wrapT = T.RepeatWrapping; t.repeat.set(60, 60); return t;
    })();
    const waterMat = new T.MeshStandardMaterial({ color: kind === 'di' ? '#0f4a63' : '#0c4552', roughness: .1, metalness: .3, transparent: true, opacity: kind === 'di' ? .42 : .6,
      normalMap: nrm, normalScale: new T.Vector2(.35, .35), envMapIntensity: 1.3, depthWrite: false });
    for (const w of world.water) {
      const shape = new T.Shape(w.poly.map(p => new T.Vector2(p[0], p[1])));
      const g = new T.ShapeGeometry(shape); g.rotateX(-Math.PI / 2);
      const uv = g.attributes.uv; const pos = g.attributes.position;
      for (let i = 0; i < uv.count; i++) uv.setXY(i, pos.getX(i) / 4000, -pos.getZ(i) / 4000);
      const mesh = new T.Mesh(g, waterMat); mesh.position.y = .25; mesh.receiveShadow = true; scene.add(mesh);
    }

    // ---------------- islands (extruded land with painted tops)
    const islandObjs = [];
    if (kind === 'di') {
      const sideMat = new T.MeshStandardMaterial({ color: '#e8dbbb', roughness: .95 });
      world.islands.forEach((I, k) => {
        const N = 2048, c = makeCanvas(N), ctx = c.getContext('2d');
        const xs = I.poly.map(p => p[0]), ys = I.poly.map(p => p[1]);
        const rect = [Math.min(...xs) - 5, Math.min(...ys) - 5, Math.max(...xs) + 5, Math.max(...ys) + 5];
        const pt = painter(ctx, N, rect), s = pt.s, r = rng(50 + k);
        // island top = the Nakheel render, rectified to plan view
        const PR = world.planRect, PS = world.planScale, img = world.planImg;
        ctx.save(); ctx.drawImage(img, (rect[0] - PR[0]) * PS, (PR[3] - rect[3]) * PS, (rect[2] - rect[0]) * PS, (rect[3] - rect[1]) * PS, 0, 0, N, N);
        ctx.globalCompositeOperation = 'destination-in'; pt.path(I.poly); ctx.fillStyle = '#000'; ctx.fill(); ctx.globalCompositeOperation = 'destination-over';
        ctx.fillStyle = '#e9dcb8'; ctx.fillRect(0, 0, N, N); ctx.globalCompositeOperation = 'source-over';
        ctx.save();
        ctx.restore();
        const tex = new T.CanvasTexture(c); tex.encoding = T.sRGBEncoding;
        tex.anisotropy = 8; tex.repeat.set(1 / (rect[2] - rect[0]), 1 / (rect[3] - rect[1])); tex.offset.set(-rect[0] / (rect[2] - rect[0]), -rect[1] / (rect[3] - rect[1]));
        const shape = new T.Shape(I.poly.map(p => new T.Vector2(p[0], p[1])));
        const geo = new T.ExtrudeGeometry(shape, { depth: 1, bevelEnabled: false, curveSegments: 2 }); geo.rotateX(-Math.PI / 2);
        const mesh = new T.Mesh(geo, [new T.MeshStandardMaterial({ map: tex, roughness: .95, envMapIntensity: .3 }), sideMat]);
        mesh.receiveShadow = true; mesh.castShadow = true; scene.add(mesh); islandObjs.push({ I, mesh });
      });
    }

    if (world.spits) {
      const sm = new T.MeshStandardMaterial({ color: '#efe4c6', roughness: .95 });
      for (const sp of world.spits) { const g = new T.ExtrudeGeometry(new T.Shape(sp.map(p => new T.Vector2(p[0], p[1]))), { depth: 1.2, bevelEnabled: false }); g.rotateX(-Math.PI / 2);
        const m = new T.Mesh(g, sm); m.receiveShadow = true; scene.add(m); islandObjs.push({ I: { at: world.islands[0].at, h: 1.2, dur: 1.2 }, mesh: m, spit: true }); }
    }
    // ---------------- buildings: each tower is assembled from parts (podium, shaft, setbacks, crown,
    // rooftop plant), drawn as instanced boxes / cylinders / octagons with the facade shader.
    const GEO = {
      box: (() => { const g = new T.BoxGeometry(1, 1, 1); g.translate(0, .5, 0); return g; })(),
      cyl: (() => { const g = new T.CylinderGeometry(.5, .5, 1, 28, 1); g.translate(0, .5, 0); return g; })(),
      taper: (() => { const g = new T.CylinderGeometry(.3, .5, 1, 28, 1); g.translate(0, .5, 0); return g; })(),
      cone: (() => { const g = new T.CylinderGeometry(0, .5, 1, 16, 1); g.translate(0, .5, 0); return g; })(),
      oct: (() => { const g = new T.CylinderGeometry(.5, .5, 1, 8, 1); g.rotateY(Math.PI / 8); g.translate(0, .5, 0); return g; })(),
    };
    const MAT = {
      tbox: facade({ floor: 3.6, win: 1.9, glass: 1, rough: .55 }),
      tcyl: facade({ floor: 3.6, win: 1.9, glass: 1, rough: .55, cyl: 1 }),
      ttap: facade({ floor: 3.6, win: 1.6, glass: 1, rough: .5, cyl: 1 }),
      spire: new T.MeshStandardMaterial({ color: '#cfd6dc', roughness: .25, metalness: .85 }),
      toct: facade({ floor: 3.6, win: 1.9, glass: 1, rough: .55, cyl: 1 }),
      low: facade({ floor: 3.2, win: 2.6, glass: .9, rough: .85, low: 1 }),
      plant: new T.MeshStandardMaterial({ color: '#8e8f8c', roughness: .8, metalness: .2 }),
    };
    const parts = { tbox: [], tcyl: [], toct: [], ttap: [], spire: [], low: [], plant: [] }, burj = [];
    const blds = [];
    const r = rng(77);
    // tower palette: white, dark-blue glass, grey, black, silver (linear RGB)
    const PAL = kind === 'bb' ? [[[.80, .80, .78], .3], [[.50, .52, .54], .32], [[.30, .31, .32], .22], [[.06, .09, .14], .1], [[.03, .03, .035], .06]]
      : [[[.86, .86, .84], .26], [[.02, .05, .13], .2], [[.24, .25, .26], .16], [[.012, .014, .018], .11], [[.52, .55, .58], .21], [[.55, .40, .18], .06]];
    const pick = () => { let x = r(); for (const [c, w] of PAL) { if ((x -= w) < 0) return c; } return PAL[0][0]; };
    const WALLS = [[.93, .91, .86], [.86, .84, .80], [.78, .80, .82]];
    for (const b of world.buildings) {
      if (b.group === 'burj') { burj.push(b); continue; }
      const p = b.poly; if (p.length !== 4) continue;
      const cx = (p[0][0] + p[2][0]) / 2, cy = (p[0][1] + p[2][1]) / 2;
      const w = Math.hypot(p[1][0] - p[0][0], p[1][1] - p[0][1]), d = Math.hypot(p[2][0] - p[1][0], p[2][1] - p[1][1]);
      const ang = Math.atan2(p[1][1] - p[0][1], p[1][0] - p[0][0]);
      const tall = b.h > 14 || (b.floors > 0 && b.h > 10) || b.res;
      const B = { b, cx, cy, ang, idx: [] }; blds.push(B);
      const add = (k, o) => { o.B = B; B.idx.push([k, parts[k].length]); parts[k].push(o); };
      if (b.group === 'apt' && b.h < 1) { add('plant', { lx: 0, ly: 0, w, d, y0: 0, hf: 1, col: new T.Color('#3f4245'), abs: b.h }); continue; }
      if (!tall) {
        const wc = b.mall ? [.93, .92, .9] : b.group === 'isl' ? [.97, .96, .93] : (b.group === 'deira' || b.group === 'fabric' || b.group === 'far') ? [.86 + r() * .08, .80 + r() * .07, .70 + r() * .08] : WALLS[Math.floor(r() * WALLS.length)];
        const col = new T.Color().setRGB(...wc);
        add('low', { lx: 0, ly: 0, w, d, y0: 0, hf: 1, col });
        if (b.group === 'isl' && r() < .6) add('low', { lx: w * .18, ly: -d * .12, w: w * .55, d: d * .6, y0: 1, hf: .45, col });   // villa upper storey
        if (b.h > 4 && r() < .5) add('plant', { lx: (r() - .5) * w * .4, ly: (r() - .5) * d * .4, w: Math.min(3, w * .2), d: Math.min(3, d * .2), y0: 1, hf: 0, abs: 1.2 });
        continue;
      }
      const RES = [[.92, .89, .82], [.96, .95, .92], [.86, .83, .77], [.80, .80, .78], [.90, .86, .76]];
      if (b.res) {                                                       // mid-rise residential block
        const col = new T.Color().setRGB(...RES[Math.floor(r() * RES.length)]);
        add('low', { lx: 0, ly: 0, w, d, y0: 0, hf: 1, col, res: 1 });
        add('plant', { lx: (r() - .5) * w * .4, ly: (r() - .5) * d * .4, w: w * .25, d: d * .3, y0: 1, hf: 0, abs: 2.2 });
        if (r() < .4) add('low', { lx: 0, ly: 0, w: w * .6, d: d * .55, y0: 1, hf: 0, abs: 3.2, col });
        continue;
      }
      const H = b.h, col = new T.Color().setRGB(...pick());
      const kind = r(); const shaft = kind < .16 ? 'tcyl' : kind < .3 ? 'toct' : 'tbox';
      const R = Math.min(w, d);
      let y = 0;
      if (H > 40 && r() < .6) { const ph = Math.min(12, H * .14); add('tbox', { lx: 0, ly: 0, w: w * 1.45, d: d * 1.35, y0: 0, hf: ph / H, col: col.clone().multiplyScalar(.95) }); }
      const steps = H > 60 ? 1 + Math.floor(r() * 3) : 1;
      let sw = 1, sd = 1;
      for (let k = 0; k < steps; k++) {
        const frac = k === steps - 1 ? 1 - y : (1 - y) * (.45 + r() * .3);
        add(shaft, shaft === 'tbox' ? { lx: 0, ly: 0, w: w * sw, d: d * sd, y0: y, hf: frac, col } : { lx: 0, ly: 0, w: R * sw, d: R * sw, y0: y, hf: frac, col });
        y += frac; sw *= .8; sd *= .84;
      }
      const tw = (shaft === 'tbox' ? w : R) * sw / .8, td = (shaft === 'tbox' ? d : R) * sd / .84;
      const crown = r();
      const slender = H > 45 && Math.min(w, d) < H / 3.2;
      if (slender && crown < .3) add('ttap', { lx: 0, ly: 0, w: Math.min(tw, td), d: Math.min(tw, td), y0: 1, hf: .14, col });          // tapered top
      if (slender && crown >= .3 && crown < .55) add('spire', { lx: 0, ly: 0, w: Math.min(tw, td) * .7, d: Math.min(tw, td) * .7, y0: 1, hf: .22 }); // spire
      else if (crown < .35) add(shaft, { lx: 0, ly: 0, w: tw * .55, d: td * .55, y0: 1, hf: 0, abs: 4 + r() * 6, col });                   // crown block
      else if (crown < .5) add('plant', { lx: 0, ly: 0, w: 1, d: 1, y0: 1, hf: 0, abs: 12 + r() * 18 });                            // mast
      for (let k = 0; k < 1 + Math.floor(r() * 3); k++)
        add('plant', { lx: (r() - .5) * tw * .5, ly: (r() - .5) * td * .5, w: tw * (.15 + r() * .2), d: td * (.15 + r() * .2), y0: 1, hf: 0, abs: 2 + r() * 2.5 });
    }
    const inst = {};
    for (const k in parts) {
      const arr = parts[k]; if (!arr.length) continue;
      const geo = k === 'tcyl' ? GEO.cyl : k === 'toct' ? GEO.oct : k === 'ttap' ? GEO.taper : k === 'spire' ? GEO.cone : GEO.box;
      const m = new T.InstancedMesh(geo, MAT[k], arr.length);
      m.castShadow = true; m.receiveShadow = true; m.frustumCulled = false;
      arr.forEach((o, i) => m.setColorAt(i, o.col || new T.Color('#9a9b98'))); scene.add(m); inst[k] = { m, arr };
    }
    // Burj Khalifa: three-lobed tiers with setbacks + spire
    const burjMat = facade({ floor: 4, win: 1.4, glass: 1, rough: .4, cyl: 1 });
    const burjMeshes = [];
    burj.forEach((b, k) => {
      const rad = Math.hypot(b.poly[0][0] - b.c[0], b.poly[0][1] - b.c[1]);
      const last = k === burj.length - 1;
      const grp = new T.Group(); scene.add(grp);
      if (last) {
        const g = new T.CylinderGeometry(.12, rad, 1, 12); g.translate(0, .5, 0);
        const mesh = new T.Mesh(g, new T.MeshStandardMaterial({ color: '#c8d0d6', metalness: .8, roughness: .25 })); mesh.castShadow = true; grp.add(mesh);
      } else {
        for (let l = 0; l < 3; l++) {
          const a = l / 3 * Math.PI * 2 + .3, lr = rad * (.62 - .1 * ((l + k) % 3) / 2);
          const g = new T.CylinderGeometry(lr, lr, 1, 24); g.translate(Math.cos(a) * rad * .42, .5 * (1 - .12 * ((l + k) % 3)), Math.sin(a) * rad * .42); g.scale(1, 1 - .12 * ((l + k) % 3), 1);
          const mesh = new T.Mesh(g, burjMat); mesh.castShadow = true; mesh.receiveShadow = true; grp.add(mesh);
        }
        const gc = new T.CylinderGeometry(rad * .45, rad * .45, 1, 24); gc.translate(0, .5, 0);
        const core = new T.Mesh(gc, burjMat); core.castShadow = true; grp.add(core);
      }
      burjMeshes.push({ b, mesh: grp });
    });

    // ---------------- trees on the islands (instanced)
    let trees = null;
    if (kind === 'di') {
      const occ = world.buildings.filter(b => b.group === 'isl').map(b => b.c), occR = b => 9;
      const all = []; world.islands.forEach((I, k) => (I.trees || []).forEach(t => { if (!occ.some(c => Math.hypot(c[0] - t[0], c[1] - t[1]) < 9)) all.push({ t, k }); }));
      const crownG = new T.IcosahedronGeometry(1, 1); crownG.scale(1, .42, 1);
      const trunkG = new T.CylinderGeometry(.08, .12, 1, 5); trunkG.translate(0, .5, 0);
      const cm = new T.InstancedMesh(crownG, new T.MeshStandardMaterial({ roughness: .95, flatShading: true }), all.length);
      const tm = new T.InstancedMesh(trunkG, new T.MeshStandardMaterial({ color: '#7a6248', roughness: 1 }), all.length);
      all.forEach((o, i) => cm.setColorAt(i, new T.Color(o.t[4]).multiplyScalar(.62)));
      for (const m of [cm, tm]) { m.castShadow = true; m.frustumCulled = false; scene.add(m); }
      trees = { cm, tm, all };
    }

    const M = new T.Matrix4(), Q = new T.Quaternion(), S = new T.Vector3(), Pv = new T.Vector3(), UP = new T.Vector3(0, 1, 0);
    const GOLD = new T.Color().setRGB(.95, .74, .4);
    function prog(b, t) { const at = b.at + (b.delay || 0); if (t < at - .9) return -1; return clamp((t - at) / (b.dur || .9)); }

    return function render(t, c, opt) {
      // camera identical to the 2D projection (pitch/yaw/dist around target, focal length f, principal point cy)
      const yaw = c.yaw * Math.PI / 180, pit = c.pitch * Math.PI / 180;
      const dirH = [Math.sin(yaw), Math.cos(yaw)];
      const C = [c.x - dirH[0] * c.dist * Math.cos(pit), c.y - dirH[1] * c.dist * Math.cos(pit), c.dist * Math.sin(pit)];
      const fullH = 2 * (c.cy || .5) * H, f = c.f || 1100;
      camera.fov = 2 * Math.atan(fullH / 2 / f) * 180 / Math.PI; camera.aspect = W / fullH;
      camera.setViewOffset(W, fullH, 0, 0, W, H);
      camera.position.copy(W3(C[0], C[1], C[2])); camera.up.set(0, 1, 0); camera.lookAt(W3(c.x, c.y, 0)); camera.updateProjectionMatrix();
      scene.fog.near = c.dist * 1.3; scene.fog.far = c.dist * 8;
      const tgt = W3(c.x, c.y, 0), span = clamp(c.dist * 1.1, 500, 3200);
      sun.position.copy(tgt).addScaledVector(SUN, 4000); sun.target.position.copy(tgt);
      Object.assign(sun.shadow.camera, { left: -span, right: span, top: span, bottom: -span, near: 100, far: 9000 }); sun.shadow.camera.updateProjectionMatrix();

      const rise = new Map();
      for (const B of blds) { const p = prog(B.b, t); rise.set(B, p < 0 ? -1 : p === 0 ? 0 : easeOutBack(p)); B.hl = opt.hl ? opt.hl(B.b, t) : false; }
      for (const k in inst) {
        const { m, arr } = inst[k];
        arr.forEach((o, i) => {
          const B = o.B, e = rise.get(B), H = B.b.h, z0 = B.b.z0 || 0;
          const ca = Math.cos(B.ang), sa = Math.sin(B.ang);
          let y0, h, vis = e >= 0;
          if (e === 0) { y0 = 0; h = o.y0 === 0 && !o.abs ? .3 : 0; vis = h > 0; }
          else { y0 = o.y0 * H * e; h = o.abs ? o.abs * Math.min(1, e) : Math.max(.3, o.hf * H * e); if (o.abs && e < .95) vis = false; }
          Q.setFromAxisAngle(UP, B.ang);
          S.set(vis ? o.w : .0001, vis ? h : .0001, vis ? o.d : .0001);
          Pv.copy(W3(B.cx + o.lx * ca - o.ly * sa, B.cy + o.lx * sa + o.ly * ca, z0 + y0));
          M.compose(Pv, Q, S); m.setMatrixAt(i, M);
          if (opt.hl && o.col) m.setColorAt(i, B.hl ? GOLD : o.col);
        });
        m.instanceMatrix.needsUpdate = true; if (m.instanceColor) m.instanceColor.needsUpdate = true;
      }
      for (const { b, mesh } of burjMeshes) {
        const p = prog(b, t); mesh.visible = p > 0;
        mesh.position.copy(W3(b.c[0], b.c[1], b.z0 || 0)); mesh.scale.set(1, Math.max(.01, b.h * easeOutBack(Math.max(p, 0))), 1);
        const hl = opt.hl && opt.hl(b, t); mesh.traverse(o => { if (o.material && o.material !== burjMat) return; if (o.material) o.material.color.set(hl ? '#f2c477' : '#e9eef2'); });
      }
      for (const { I, mesh } of islandObjs) {
        const p = clamp((t - I.at) / (I.dur || 1.2)); mesh.visible = p > 0; mesh.scale.set(1, Math.max(.01, I.h * (1 - Math.pow(1 - p, 3))), 1);
      }
      if (trees) {
        trees.all.forEach((o, i) => {
          const I = world.islands[o.k]; const p = clamp((t - I.at - .6 - o.t[3] * 1.4) / .6);
          const R = o.t[2] * .75 * p + .0001, h = (2.5 + o.t[2] * 1.6) * p + .0001, z = I.h;
          Q.identity(); Pv.copy(W3(o.t[0], o.t[1], z)); S.set(R * .9, h, R * .9); M.compose(Pv, Q, S); trees.tm.setMatrixAt(i, M);
          Q.setFromAxisAngle(UP, o.t[3] * 6.28); Pv.copy(W3(o.t[0], o.t[1], z + h)); S.set(R, R, R); M.compose(Pv, Q, S); trees.cm.setMatrixAt(i, M);
        });
        trees.cm.instanceMatrix.needsUpdate = true; trees.tm.instanceMatrix.needsUpdate = true;
      }
      nrm.offset.set(t * .004, t * .0025);
      renderer.render(scene, camera);
    };
  };
  function inPolyW(pt, poly) {
    let c = false; for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
      const [xi, yi] = poly[i], [xj, yj] = poly[j];
      if (((yi > pt[1]) !== (yj > pt[1])) && (pt[0] < (xj - xi) * (pt[1] - yi) / (yj - yi) + xi)) c = !c;
    } return c;
  }

  // wrap the 2D renderer: GL draws the world, 2D draws the annotation layer on top
  const Map2D = window.Map3D;
  window.Map3D = function (canvas2d, world) {
    world.gl = true;
    const gl = MapGL(document.getElementById('map3d'), world, world.kind);
    const r2 = Map2D(canvas2d, world);
    return function (t, cam, opt = {}) { gl(t, cam, opt); r2(t, cam, opt); };
  };
})();
