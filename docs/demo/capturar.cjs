// Captura el tablero para la Diapositiva 5. Requiere Playwright y el tablero en :3100.
const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch();
  const base = 'http://127.0.0.1:3100';
  // Acceso
  let c = await b.newContext({ viewport: { width: 1280, height: 800 }, locale: 'es-PE' });
  let pg = await c.newPage(); await pg.goto(base + '/acceso'); await pg.waitForTimeout(800);
  await pg.screenshot({ path: 'docs/demo/capturas/01-acceso.png' }); await c.close();
  // Tablero escritorio
  c = await b.newContext({ viewport: { width: 1280, height: 900 }, locale: 'es-PE', timezoneId: 'America/Lima' });
  await c.addCookies([{ name: 'sippt_sesion', value: 'operador', url: base }]);
  pg = await c.newPage(); await pg.goto(base + '/'); await pg.waitForTimeout(2500);
  await pg.screenshot({ path: 'docs/demo/capturas/02-tablero-escritorio.png', fullPage: true }); await c.close();
  // Tablero móvil 375 px
  c = await b.newContext({ viewport: { width: 375, height: 812 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, locale: 'es-PE', timezoneId: 'America/Lima' });
  await c.addCookies([{ name: 'sippt_sesion', value: 'operador', url: base }]);
  pg = await c.newPage(); await pg.goto(base + '/'); await pg.waitForTimeout(2500);
  await pg.screenshot({ path: 'docs/demo/capturas/03-tablero-movil.png' });
  await pg.screenshot({ path: 'docs/demo/capturas/04-tablero-movil-completo.png', fullPage: true });
  // Una vista por rol: mismo semáforo, distinto acento y contenido.
  for (const rol of ['operador', 'tecnico', 'veterinario']) {
    for (const [sufijo, vista] of [['movil', { width: 375, height: 812, deviceScaleFactor: 2, isMobile: true }], ['escritorio', { width: 1280, height: 900 }]]) {
      c = await b.newContext({ viewport: { width: vista.width, height: vista.height }, deviceScaleFactor: vista.deviceScaleFactor ?? 1, isMobile: !!vista.isMobile, locale: 'es-PE', timezoneId: 'America/Lima' });
      await c.addCookies([{ name: 'sippt_sesion', value: rol, url: base }]);
      pg = await c.newPage(); await pg.goto(base + '/'); await pg.waitForTimeout(2000);
      await pg.screenshot({ path: `docs/demo/capturas/rol-${rol}-${sufijo}.png`, fullPage: sufijo === 'escritorio' });
      await c.close();
    }
  }
  await b.close();
})();
