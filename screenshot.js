const puppeteer = require('puppeteer');
const path = require('path');
const fs = require('fs');

const htmlPath = 'file:///' + path.resolve(__dirname, 'index-v2.html').replace(/\\/g, '/');
const outDir = path.join(__dirname, 'screenshots');
if (!fs.existsSync(outDir)) fs.mkdirSync(outDir);

const sections = [
  { id: 'hero',          name: '01-hero' },
  { id: 'directions',    name: '02-directions' },
  { id: 'master',        name: '03-master' },
  { id: 'projects',      name: '04-projects' },
  { id: 'workshop',      name: '05-workshop' },
  { id: 'trust',         name: '06-trust' },
  { id: 'collaboration', name: '07-collaboration' },
  { id: 'contacts',      name: '08-contacts' },
];

(async () => {
  const browser = await puppeteer.launch({ headless: 'new', args: ['--no-sandbox'] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1.5 });
  await page.goto(htmlPath, { waitUntil: 'networkidle2', timeout: 15000 });
  await new Promise(r => setTimeout(r, 1500)); // ждём шрифты и анимации

  // Полная страница
  await page.screenshot({ path: path.join(outDir, '00-fullpage.png'), fullPage: true });
  console.log('00-fullpage.png');

  for (const s of sections) {
    const el = await page.$('#' + s.id);
    if (!el) { console.log('not found: ' + s.id); continue; }
    await el.scrollIntoView();
    await new Promise(r => setTimeout(r, 400));
    await el.screenshot({ path: path.join(outDir, s.name + '.png') });
    console.log(s.name + '.png');
  }

  await browser.close();
  console.log('DONE');
})();
