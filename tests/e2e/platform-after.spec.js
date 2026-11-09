import { test } from '@playwright/test'
import { loginPlatform } from './helpers.js'
const DIR = 'tests/e2e/__screens__/platform-after'
async function settle(page){ await page.waitForLoadState('networkidle').catch(()=>{}); await page.waitForTimeout(2500); await page.evaluate(()=>window.scrollTo(0,document.body.scrollHeight)); await page.waitForTimeout(800); await page.evaluate(()=>window.scrollTo(0,0)); await page.waitForTimeout(1200) }
async function shoot(page,w,h,path){ await page.setViewportSize({width:w,height:h}); await page.goto('/platform/dashboard/overview'); await settle(page); await page.screenshot({path}) }
test('platform APRES admin', async ({ page }) => {
  const errs=[]; page.on('pageerror',e=>errs.push(e.message)); page.on('response',r=>{if(r.status()===403)errs.push('403 '+r.url())})
  await loginPlatform(page)
  await shoot(page,1440,2400,`${DIR}/01-admin-desktop-light.png`)
  await page.emulateMedia({colorScheme:'dark'}); await shoot(page,1440,2400,`${DIR}/02-admin-desktop-dark.png`); await page.emulateMedia({colorScheme:'light'})
  await shoot(page,834,2600,`${DIR}/03-admin-tablet.png`)
  await shoot(page,390,3200,`${DIR}/04-admin-mobile.png`)
  console.log('admin 403s:', errs.filter(e=>e.includes('403')).length)
})
