
const puppeteer = require('puppeteer');
(async () => {
  const browser = await puppeteer.launch({ args: ['--no-sandbox','--disable-setuid-sandbox'] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
  await page.goto('http://localhost:3001', { waitUntil: 'networkidle2', timeout: 30000 });
  await page.evaluate(() => {
    const sections = document.querySelectorAll('section');
    for(const s of sections) {
      if(s.textContent.includes('Essential Shield')) {
        s.scrollIntoView();
        break;
      }
    }
  });
  await new Promise(r => setTimeout(r, 800));
  await page.screenshot({ path: 'temporary screenshots/solutions-check.png' });
  await browser.close();
  console.log('done');
})();
