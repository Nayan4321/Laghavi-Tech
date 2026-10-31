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
  function facade(o) {
    const m = new T.MeshStandardMaterial({ roughness: o.rough, metalness: o.metal || 0, color: 0xffffff });
    m.onBeforeCompile = sh => {
      Object.assign(sh.uniforms, { uFloor: { value: o.floor }, uWin: { value: o.win }, uAmt: { value: o.amt }, uTint: { value: new T.Color(o.tint) } });
      sh.vertexShader = 'varying vec3 vWP; varying vec3 vWN;\n' + sh.vertexShader.replace('#include <worldpos_vertex>', `#include <worldpos_vertex>
        #ifdef USE_INSTANCING
          vWP = (modelMatrix * instanceMatrix * vec4(transformed, 1.0)).xyz; vWN = normalize(mat3(modelMatrix) * mat3(instanceMatrix) * objectNormal);
        #else
          vWP = (modelMatrix * vec4(transformed, 1.0)).xyz; vWN = normalize(mat3(modelMatrix) * objectNormal);
        #endif`);
      sh.fragmentShader = 'uniform float uFloor; uniform float uWin; uniform float uAmt; uniform vec3 uTint; varying vec3 vWP; varying vec3 vWN; float gWin = 0.0;\n' +
        sh.fragmentShader.replace('#include <color_fragment>', `#include <color_fragment>
          float side = 1.0 - step(0.6, abs(vWN.y));
          float u = abs(vWN.x) > abs(vWN.z) ? vWP.z : vWP.x;
          float fl = fract(vWP.y / uFloor), cl = fract(u / uWin);
          float win = step(0.22, fl) * step(fl, 0.84) * step(0.12, cl) * step(cl, 0.88);
          float fw = fwidth(vWP.y / uFloor) + fwidth(u / uWin);
          win = mix(win, 0.45, smoothstep(0.2, 0.65, fw));
          diffuseColor.rgb = mix(diffuseColor.rgb, diffuseColor.rgb * uTint, win * side * uAmt); gWin = win * side;
          diffuseColor.rgb *= mix(0.58, 1.0, smoothstep(0.0, 12.0, vWP.y));
          diffuseColor.rgb *= mix(1.0, 1.08, side * smoothstep(20.0, 160.0, vWP.y));`).replace('#include <dithering_fragment>', '#include <dithering_fragment>\n gl_FragColor.rgb *= mix(1.0, ' + o.post.toFixed(2) + ', gWin);');
    };
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
      if (r() < .12) continue;
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
  function road(ctx, pt, pts, w, asphalt = '#55595c') {
    const s = pt.s; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
    pt.path(pts, false); ctx.strokeStyle = '#8d8a84'; ctx.lineWidth = (w + 3) * s; ctx.stroke();
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
    renderer.outputEncoding = T.sRGBEncoding; renderer.toneMapping = T.ACESFilmicToneMapping; renderer.toneMappingExposure = .88;
    renderer.shadowMap.enabled = true; renderer.shadowMap.type = T.PCFSoftShadowMap;
    const scene = new T.Scene();
    const SKY = new T.Color(kind === 'di' ? '#b9d3e6' : '#cfd9df');
    renderer.setClearColor(SKY);
    scene.fog = new T.Fog(SKY, 1000, 6000);
    scene.add(new T.HemisphereLight(0xdfeefe, 0x9c8462, 0.55));
    const sun = new T.DirectionalLight(0xfff1dc, 2.3); sun.castShadow = true;
    sun.shadow.mapSize.set(3072, 3072); sun.shadow.bias = -0.0004; sun.shadow.normalBias = 0.6;
    scene.add(sun); scene.add(sun.target);
    const SUN = new T.Vector3(-0.55, 0.78, -0.30).normalize();
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
        ctx.fillStyle = '#b8976b'; ctx.fillRect(0, 0, N, N);
        pt.noise(.45, N / 260, 3, ['#a5845a', '#c8ab80', '#977652', '#bf9f74', '#8f7a55']);
        const sea = world.water[0].poly;
        rooftops(ctx, pt, p => !inPolyW(p, sea), 21, 18, ['#c4a983', '#b59a74', '#d0bb98', '#a98f6c', '#cdb28a', '#d8cab0', '#9c8667']);
        for (const w of world.water) water(ctx, pt, w.poly, '#1b4f5e', '#246775', 'rgba(210,200,175,.9)');
        for (const pk of world.islands) { pt.path(pk.poly); ctx.fillStyle = pk.col[1] > pk.col[0] ? '#6f9a4e' : '#dccaa2'; ctx.fill(); }   // parks / Palm Jumeirah
        road(ctx, pt, world.roads[0].pts, 16); road(ctx, pt, world.roads[1].pts, 12); road(ctx, pt, world.roads[2].pts, 7, '#6a6c6c'); road(ctx, pt, world.roads[3].pts, 5, '#6a6c6c');
        // downtown plaza paving around the lake and Burj
        pt.path(world.lake || []); ctx.lineWidth = 18 * pt.s; ctx.strokeStyle = '#d7d2c6'; ctx.stroke(); if (world.lake) water(ctx, pt, world.lake, '#22707f', '#2b8394');
      };
    } else {
      paintWorld = (ctx, pt, N) => {
        ctx.fillStyle = '#c4a87d'; ctx.fillRect(0, 0, N, N);
        pt.noise(.35, N / 300, 5, ['#b39570', '#d2bb94', '#a78a64']);
        const sea = world.water[0].poly;
        rooftops(ctx, pt, p => !inPolyW(p, sea) && Math.hypot((p[0] - world.apt[0]) * .8, p[1] - world.apt[1]) > 360, 33, 20, ['#cdb895', '#d9c8a7', '#bfa680', '#e3d6bc', '#b39b77']);
        pt.path(sea); ctx.save(); ctx.clip();                                         // sea: deep blue, turquoise shallows near land
        ctx.fillStyle = '#123f5e'; ctx.fillRect(0, 0, N, N);
        ctx.filter = `blur(${Math.round(N / 140)}px)`;
        for (const I of world.islands) { pt.path(I.poly); ctx.lineWidth = 70 * pt.s; ctx.strokeStyle = 'rgba(64,190,196,.85)'; ctx.stroke(); ctx.fillStyle = 'rgba(64,190,196,.85)'; ctx.fill(); }
        pt.path(world.coast, false); ctx.lineWidth = 60 * pt.s; ctx.strokeStyle = 'rgba(56,170,180,.7)'; ctx.stroke();
        ctx.filter = 'none';
        const r = rng(4); ctx.globalAlpha = .08;
        for (let i = 0; i < 4000; i++) { ctx.fillStyle = r() < .5 ? '#ffffff' : '#062033'; ctx.fillRect(r() * N, r() * N, 2 + r() * 12, 1 + r() * 2); }
        ctx.globalAlpha = 1; ctx.restore();
        pt.path(world.coast, false); ctx.lineWidth = 10 * pt.s; ctx.strokeStyle = '#e9dcbc'; ctx.stroke();      // mainland beach
        water(ctx, pt, world.water[1].poly, '#1e5f74', '#2a7488');
        pt.path([[world.apt[0] - 330, world.apt[1] - 200], [world.apt[0] + 330, world.apt[1] - 200], [world.apt[0] + 330, world.apt[1] + 200], [world.apt[0] - 330, world.apt[1] + 200]]);
        ctx.fillStyle = '#9d9a90'; ctx.fill();
        for (const rd of world.roads) road(ctx, pt, rd.pts, rd.w * 3);
      };
    }
    groundPlane([bx0, by0, bx1, by1], 4096, 0, paintWorld);
    groundPlane(inner, 4096, 0.05, paintWorld);

    // ---------------- islands (extruded land with painted tops)
    const islandObjs = [];
    if (kind === 'di') {
      const sideMat = new T.MeshStandardMaterial({ color: '#e8dbbb', roughness: .95 });
      world.islands.forEach((I, k) => {
        const c = makeCanvas(1024), ctx = c.getContext('2d');
        const xs = I.poly.map(p => p[0]), ys = I.poly.map(p => p[1]);
        const rect = [Math.min(...xs) - 5, Math.min(...ys) - 5, Math.max(...xs) + 5, Math.max(...ys) + 5];
        const pt = painter(ctx, 1024, rect);
        ctx.fillStyle = '#efe3c4'; ctx.fillRect(0, 0, 1024, 1024);                         // beach sand
        pt.path(I.inner); ctx.fillStyle = '#3d6a2e'; ctx.fill(); ctx.save(); ctx.clip();
        const r = rng(50 + k);
        for (let i = 0; i < 260; i++) { ctx.fillStyle = ['#55823b', '#6b9746', '#2f5726', '#4a7735'][Math.floor(r() * 4)]; ctx.globalAlpha = .5;
          ctx.beginPath(); ctx.ellipse(r() * 1024, r() * 1024, 20 + r() * 70, 12 + r() * 40, r() * 3, 0, 6.28); ctx.fill(); }
        ctx.globalAlpha = 1;
        for (let i = 0; i < 60; i++) { ctx.strokeStyle = 'rgba(230,226,214,.7)'; ctx.lineWidth = 3; ctx.beginPath(); const a = r() * 1024, b2 = r() * 1024; ctx.moveTo(a, b2);
          ctx.bezierCurveTo(a + r() * 300 - 150, b2 + r() * 300 - 150, a + r() * 300 - 150, b2 + r() * 300 - 150, a + r() * 400 - 200, b2 + r() * 400 - 200); ctx.stroke(); }   // estate roads
        ctx.restore();
        const tex = new T.CanvasTexture(c); tex.encoding = T.sRGBEncoding;
        tex.repeat.set(1 / (rect[2] - rect[0]), 1 / (rect[3] - rect[1])); tex.offset.set(-rect[0] / (rect[2] - rect[0]), -rect[1] / (rect[3] - rect[1]));
        const shape = new T.Shape(I.poly.map(p => new T.Vector2(p[0], p[1])));
        const geo = new T.ExtrudeGeometry(shape, { depth: 1, bevelEnabled: false, curveSegments: 2 }); geo.rotateX(-Math.PI / 2);
        const mesh = new T.Mesh(geo, [new T.MeshStandardMaterial({ map: tex, roughness: .95, envMapIntensity: .3 }), sideMat]);
        mesh.receiveShadow = true; mesh.castShadow = true; scene.add(mesh); islandObjs.push({ I, mesh });
      });
    }

    // ---------------- buildings: instanced boxes (towers / low-rise) + Burj Khalifa meshes
    const boxGeo = new T.BoxGeometry(1, 1, 1); boxGeo.translate(0, .5, 0);
    const glassMat = facade({ post: .62, rough: .12, metal: .65, floor: 3.4, win: 2.6, amt: .45, tint: '#55636d' }); glassMat.envMapIntensity = 1.1;
    const concMat = facade({ post: .7, rough: .7, metal: 0, floor: 3.4, win: 2.4, amt: .75, tint: '#3c4852' }); concMat.envMapIntensity = .6;
    const lowMat = facade({ post: .85, rough: .9, floor: 3.0, win: 2.6, amt: .3, tint: '#5e5446' });
    const groups = { glass: [], conc: [], low: [] }, burj = [];
    const r = rng(77);
    for (const b of world.buildings) {
      if (b.group === 'burj') { burj.push(b); continue; }
      const p = b.poly; if (p.length !== 4) continue;
      const cx = (p[0][0] + p[2][0]) / 2, cy = (p[0][1] + p[2][1]) / 2;
      const w = Math.hypot(p[1][0] - p[0][0], p[1][1] - p[0][1]), d = Math.hypot(p[2][0] - p[1][0], p[2][1] - p[1][1]);
      const ang = Math.atan2(p[1][1] - p[0][1], p[1][0] - p[0][0]);
      const tall = b.h > 14 || b.floors > 0 && b.h > 10;
      const glass = tall && (b.col ? b.col[2] > b.col[0] + 4 : r() < .5);
      let col;
      if (b.group === 'apt' && b.h < 1) col = new T.Color('#4a4d50');
      else if (tall) col = new T.Color().setRGB(...(glass ? [.62 + r() * .1, .72 + r() * .07, .8 + r() * .06] : [.93 + r() * .05, .9 + r() * .05, .85 + r() * .05]));
      else if (b.group === 'isl') col = new T.Color().setRGB(.95, .93, .89);
      else col = new T.Color().setRGB(.74 + r() * .1, .66 + r() * .08, .54 + r() * .08);
      groups[tall ? (glass ? 'glass' : 'conc') : 'low'].push({ b, cx, cy, w, d, ang, col });
    }
    const inst = {};
    for (const k of ['glass', 'conc', 'low']) {
      const arr = groups[k]; if (!arr.length) continue;
      const m = new T.InstancedMesh(boxGeo, { glass: glassMat, conc: concMat, low: lowMat }[k], arr.length);
      m.castShadow = true; m.receiveShadow = true; m.frustumCulled = false;
      arr.forEach((o, i) => m.setColorAt(i, o.col)); scene.add(m); inst[k] = { m, arr };
    }
    const burjMeshes = burj.map((b, k) => {
      const rad = Math.hypot(b.poly[0][0] - b.c[0], b.poly[0][1] - b.c[1]);
      const top = k === burj.length - 1 ? .15 : rad * .82;
      const g = new T.CylinderGeometry(top, rad, 1, k === burj.length - 1 ? 8 : 18); g.translate(0, .5, 0);
      const mesh = new T.Mesh(g, k === burj.length - 1 ? new T.MeshStandardMaterial({ color: '#c9d2d8', metalness: .4, roughness: .3 }) : facade({ post: .7, rough: .15, metal: .7, floor: 4, win: 1.6, amt: .4, tint: '#6d7f8c' }));
      mesh.castShadow = true; mesh.receiveShadow = true; scene.add(mesh); return { b, mesh };
    });

    // ---------------- trees on the islands (instanced)
    let trees = null;
    if (kind === 'di') {
      const all = []; world.islands.forEach((I, k) => (I.trees || []).forEach(t => all.push({ t, k })));
      const g = new T.IcosahedronGeometry(1, 0); const m = new T.InstancedMesh(g, new T.MeshStandardMaterial({ roughness: 1 }), all.length);
      all.forEach((o, i) => m.setColorAt(i, new T.Color(o.t[4]).multiplyScalar(.7))); m.castShadow = true; m.frustumCulled = false; scene.add(m); trees = { m, all };
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

      for (const k in inst) {
        const { m, arr } = inst[k];
        arr.forEach((o, i) => {
          const p = prog(o.b, t); const z0 = o.b.z0 || 0;
          let h;
          if (p < 0) h = 0.0001; else if (p === 0) h = .35; else h = Math.max(.35, o.b.h * easeOutBack(p));
          Q.setFromAxisAngle(UP, o.ang); S.set(p < 0 ? .0001 : o.w, h, p < 0 ? .0001 : o.d); Pv.copy(W3(o.cx, o.cy, z0));
          M.compose(Pv, Q, S); m.setMatrixAt(i, M);
          if (opt.hl) m.setColorAt(i, opt.hl(o.b, t) ? GOLD : o.col);
        });
        m.instanceMatrix.needsUpdate = true; if (m.instanceColor) m.instanceColor.needsUpdate = true;
      }
      for (const { b, mesh } of burjMeshes) {
        const p = prog(b, t); mesh.visible = p > 0;
        mesh.position.copy(W3(b.c[0], b.c[1], b.z0 || 0)); mesh.scale.set(1, Math.max(.01, b.h * easeOutBack(Math.max(p, 0))), 1);
        const hl = opt.hl && opt.hl(b, t); mesh.material.color.set(hl ? '#f2c477' : '#ffffff');
      }
      for (const { I, mesh } of islandObjs) {
        const p = clamp((t - I.at) / (I.dur || 1.2)); mesh.visible = p > 0; mesh.scale.set(1, Math.max(.01, I.h * (1 - Math.pow(1 - p, 3))), 1);
      }
      if (trees) {
        trees.all.forEach((o, i) => {
          const I = world.islands[o.k]; const p = clamp((t - I.at - .6 - o.t[3] * 1.4) / .6);
          const s = o.t[2] * .9 * p + .0001; Pv.copy(W3(o.t[0], o.t[1], I.h + s * .8)); Q.identity(); S.set(s, s * 1.2, s); M.compose(Pv, Q, S); trees.m.setMatrixAt(i, M);
        });
        trees.m.instanceMatrix.needsUpdate = true;
      }
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
