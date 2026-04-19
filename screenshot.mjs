import { execSync } from 'child_process';
import { mkdirSync, existsSync } from 'fs';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const screenshotDir = join(__dirname, 'temporary screenshots');
if (!existsSync(screenshotDir)) mkdirSync(screenshotDir, { recursive: true });

const url   = process.argv[2] || 'http://localhost:3000';
const label = process.argv[3] || '';

// Count existing screenshots
const files = existsSync(screenshotDir)
  ? (await import('fs')).readdirSync(screenshotDir).filter(f => f.endsWith('.png'))
  : [];
const n = files.length + 1;
const filename = label ? `screenshot-${n}-${label}.png` : `screenshot-${n}.png`;
const outPath  = join(screenshotDir, filename);

try {
  // Try puppeteer first
  const script = `
const puppeteer = require('puppeteer');
(async () => {
  const browser = await puppeteer.launch({ args: ['--no-sandbox','--disable-setuid-sandbox'] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 2 });
  await page.goto('${url}', { waitUntil: 'networkidle2', timeout: 30000 });
  await page.screenshot({ path: '${outPath}', fullPage: true });
  await browser.close();
  console.log('Saved: ${outPath}');
})();
`;
  const tmpScript = join(__dirname, '_tmp_screenshot.cjs');
  const { writeFileSync, unlinkSync } = await import('fs');
  writeFileSync(tmpScript, script);
  execSync(`node ${tmpScript}`, { stdio: 'inherit' });
  unlinkSync(tmpScript);
} catch (err) {
  // Fallback: system screenshot via Chrome/Chromium CLI
  try {
    execSync(
      `/Applications/Google\\ Chrome.app/Contents/MacOS/Google\\ Chrome --headless --disable-gpu --screenshot="${outPath}" --window-size=1440,900 "${url}"`,
      { stdio: 'inherit' }
    );
    console.log('Saved:', outPath);
  } catch {
    console.error('Screenshot failed. Install puppeteer: npm install puppeteer');
    console.error('Or open manually:', url);
  }
}
