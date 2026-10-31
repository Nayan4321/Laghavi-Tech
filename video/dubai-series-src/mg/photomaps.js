// Photo-based map scenes: camera keys and overlays in the reference picture's pixel coordinates.
window.PHOTOMAPS = {
  '5off': {
    src: '../ref/nakheel_2x.jpg', depth: '../ref/nk_d8.png', w: 1600, h: 1229, dz: 140,
    scenes: (() => {
      const wide = { x: 800, y: 600, zoom: 1500, yaw: 0, pitch: 0 };
      const ISL = [['Central Island · A', 470, 880, 1], ['Shore Island · B', 1110, 610, 0], ['Oasis Island · C', 700, 330, 0], ['Golf Island · D', 230, 640, 0], ['Elite Island · E', 1430, 570, 0]];
      return {
        buyers: { alpha: .34, cam0: { ...wide, yaw: -5 }, cam1: { ...wide, yaw: 5 } },
        title:  { alpha: .6, cam0: { x: 800, y: 620, zoom: 1550, yaw: -3, pitch: 2 }, cam1: { x: 780, y: 600, zoom: 1350, yaw: 3, pitch: 3 } },
        size:   { keys: [[12.4, { x: 800, y: 620, zoom: 1580, yaw: 0, pitch: 0 }], [14.2, { x: 760, y: 600, zoom: 1250, yaw: -4, pitch: 3 }], [16.9, { x: 800, y: 560, zoom: 1150, yaw: 4, pitch: 4 }]],
          labels: ISL.map(([t, x, y, g], i) => ({ x, y, text: t, gold: !!g, at: 15.25 + i * .16, lift: 46 + (i % 2) * 26 })),
          leaders: [{ x: 650, y: 560, text: 'Dubai Islands', sub: 'BY NAKHEEL', at: 13.3, until: 15.0, top: 640 }] },
        beach:  { cam0: { x: 600, y: 520, zoom: 900, yaw: -5, pitch: 5 }, cam1: { x: 760, y: 480, zoom: 820, yaw: 5, pitch: 6 } },
        dxb:    { cam0: { x: 1000, y: 850, zoom: 1100, yaw: 4, pitch: 4 }, cam1: { x: 1150, y: 920, zoom: 1000, yaw: -3, pitch: 5 },
          routes: [{ pts: [[470, 880], [800, 880], [1000, 880], [1110, 965], [1300, 1050], [1450, 1120], [1560, 1190]], at: 19.5, dur: 1.8, plane: true }],
          labels: [{ x: 1540, y: 1180, text: 'DXB · Dubai International', at: 20.6, lift: 70, pulse: true }, { x: 1110, y: 965, text: '10 min', gold: true, at: 20.2, lift: 40 }] },
        price:  { alpha: .34, cam0: { ...wide, zoom: 1400, yaw: 5 }, cam1: { ...wide, zoom: 1350, yaw: -5 } },
        who:    { alpha: .34, cam0: { x: 650, y: 450, zoom: 1200, yaw: -5, pitch: 3 }, cam1: { x: 750, y: 480, zoom: 1150, yaw: 5, pitch: 3 } },
        why:    { alpha: .34, cam0: { x: 1000, y: 800, zoom: 1300, yaw: 5, pitch: 3 }, cam1: { x: 900, y: 750, zoom: 1250, yaw: -5, pitch: 3 } },
        rare:   { alpha: .34, cam0: { ...wide, yaw: -4 }, cam1: { ...wide, zoom: 1400, yaw: 4 } },
        cta:    { alpha: .34, cam0: { ...wide, zoom: 1400, yaw: 5 }, cam1: { ...wide, yaw: -5 } },
      };
    })(),
  },
};
window.PHOTOMAPS_OFF = {
  '2': {
    src: '../ref/16_2x.jpg', depth: '../ref/16_d8.png', w: 2000, h: 1130, dz: 240,
    scenes: (() => {
      const wide = { x: 1000, y: 560, zoom: 1850, yaw: 0, pitch: 0 };
      const OFFICES = [[325, 225], [515, 215], [520, 470], [470, 620], [345, 620], [320, 470]];
      const BURJ = [[893, 165], [918, 165], [940, 455], [868, 455]];
      const F = o => ({ free: 1, ...o });
      return {
        bb:    { alpha: .6, cam0: F({ x: 1000, y: 560, zoom: 2600, yaw: -18, pitch: 26, spin: -10 }), cam1: F({ x: 980, y: 540, zoom: 2300, yaw: 14, pitch: 22, spin: 6 }) },
        deals: { alpha: .34, cam0: F({ x: 1150, y: 600, zoom: 1500, yaw: -16, pitch: 18, spin: 8 }), cam1: F({ x: 1300, y: 650, zoom: 1250, yaw: 10, pitch: 14, spin: -6 }) },
        price: { alpha: .34, cam0: F({ x: 700, y: 400, zoom: 1300, yaw: 14, pitch: 16, spin: -8 }), cam1: F({ x: 820, y: 380, zoom: 1100, yaw: -12, pitch: 12, spin: 6 }) },
        yield: { alpha: .34, cam0: F({ x: 450, y: 700, zoom: 1400, yaw: -12, pitch: 20, spin: 10 }), cam1: F({ x: 520, y: 520, zoom: 1150, yaw: 12, pitch: 14, spin: -4 }) },
        who:   { alpha: .34, cam0: F({ x: 1500, y: 600, zoom: 1400, yaw: 16, pitch: 18, spin: -12 }), cam1: F({ x: 900, y: 450, zoom: 1300, yaw: -14, pitch: 16, spin: 8 }) },
        cta:   { alpha: .34, cam0: F({ x: 1000, y: 560, zoom: 1700, yaw: 12, pitch: 24, spin: 8 }), cam1: F({ x: 1000, y: 560, zoom: 2500, yaw: -12, pitch: 28, spin: -8 }) },
        map: {
          keys: [[19.4, F({ x: 1000, y: 565, zoom: 2700, yaw: 0, pitch: 30, spin: 0 })],      // whole map on the table
                 [20.5, F({ x: 905, y: 330, zoom: 760, yaw: -16, pitch: 14, spin: -6 })],     // swoop to Burj Khalifa / Downtown
                 [21.5, F({ x: 560, y: 380, zoom: 700, yaw: 14, pitch: 12, spin: 8 })],       // across to Business Bay on the canal
                 [23.0, F({ x: 480, y: 470, zoom: 620, yaw: 18, pitch: 16, spin: 12 })],      // orbit the canal towers
                 [24.6, F({ x: 760, y: 420, zoom: 1250, yaw: -10, pitch: 20, spin: -4 })]],   // pull back: Bay + Downtown together
          labels: [{ x: 905, y: 175, text: 'Burj Khalifa', at: 19.75, lift: 30, pulse: true }, { x: 840, y: 505, text: 'Dubai Mall', at: 20.0, until: 21.1, lift: 50 },
                   { x: 1250, y: 430, text: 'Sheikh Zayed Rd', at: 20.1, until: 21.1, lift: 40 }, { x: 430, y: 470, text: 'Business Bay', big: true, at: 21.2, lift: 110 },
                   { x: 455, y: 650, text: 'Dubai Canal', at: 21.0 }, { x: 420, y: 330, text: 'Offices next door', gold: true, at: 21.6, lift: 90, pulse: true },
                   { x: 760, y: 440, text: 'Downtown · short walk', at: 23.2, lift: 40 }],
          highlights: [{ poly: OFFICES, at: 21.55, until: 23.4 }, { poly: BURJ, at: 23.0 }],
        },
        close: {
          keys: [[33.3, F({ x: 470, y: 640, zoom: 700, yaw: 10, pitch: 18, spin: 10 })], [35.6, F({ x: 450, y: 460, zoom: 560, yaw: -8, pitch: 14, spin: -4 })],
                 [38.2, F({ x: 440, y: 340, zoom: 640, yaw: -16, pitch: 16, spin: -10 })]],
          labels: [{ x: 430, y: 560, text: 'Live on the canal', at: 33.7, lift: 60, pulse: true }, { x: 420, y: 300, text: 'Tenants work here', gold: true, at: 35.0, lift: 80 }],
          routes: [{ pts: [[430, 560], [440, 500], [440, 430], [430, 360], [420, 300]], at: 34.9, dur: 1.2 }],
          highlights: [{ poly: OFFICES, at: 34.8 }],
        },
      };
    })(),
  },
};
