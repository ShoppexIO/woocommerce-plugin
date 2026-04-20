/**
 * E2E — WooCommerce Blocks checkout payment method registration.
 *
 * Verifies that the React-based Blocks checkout:
 *   - Loads the shoppex-pay blocks-checkout.js asset
 *   - Registers the payment method via wc.wcBlocksRegistry
 *   - Renders the Shoppex radio with correct label + description
 *   - Shows the logo icon from the plugin assets
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';

const WP_URL = 'http://localhost:8080';

let failures = 0;
const check = (name, cond, detail = '') => {
	if (cond) {
		console.log(`  [PASS] ${name}`);
	} else {
		console.log(`  [FAIL] ${name}${detail ? ': ' + detail : ''}`);
		failures++;
	}
};

const docker = (cmd) =>
	execSync(
		`docker compose -f docs/docker-compose.test.yml exec -T wp-cli ${cmd}`,
		{ encoding: 'utf8' }
	).trim();

// Pre-req: cart must have something so Blocks checkout renders its payment section
docker(`wp eval '
$product_id = wc_get_products(array("limit"=>1,"return"=>"ids"))[0];
update_option("_shoppex_e2e_product_id", $product_id);
'`);

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1400, height: 900 } });
const page = await context.newPage();

// Capture JS console errors — Blocks checkout is sensitive to bad payment method registration
const consoleErrors = [];
page.on('pageerror', (err) => consoleErrors.push(err.message));
page.on('console', (msg) => {
	if (msg.type() === 'error') consoleErrors.push(msg.text());
});

console.log('=== Step 1: Seed cart ===');
await page.goto(`${WP_URL}/?p=10`, { waitUntil: 'domcontentloaded' });
const btn = page.locator('button[name="add-to-cart"], button.single_add_to_cart_button').first();
if (await btn.count() > 0) await btn.click();
await page.waitForTimeout(2000);

console.log('\n=== Step 2: Open Blocks checkout ===');
await page.goto(`${WP_URL}/?page_id=7`, { waitUntil: 'domcontentloaded' });
// Blocks JS needs time to hydrate
await page.waitForSelector('.wc-block-checkout', { timeout: 20000 });
await page.waitForTimeout(3000);
check('Blocks checkout container rendered', await page.locator('.wc-block-checkout').count() > 0);

console.log('\n=== Step 3: shoppex-pay blocks-checkout.js was requested ===');
const jsUrls = [];
// Replay — track only new requests from now on
page.on('request', (req) => {
	if (req.url().includes('shoppex-pay')) jsUrls.push(req.url());
});
// Reload to capture
await page.reload({ waitUntil: 'domcontentloaded' });
await page.waitForSelector('.wc-block-checkout', { timeout: 20000 });
await page.waitForTimeout(3000);

check(
	'Plugin JS asset loaded',
	jsUrls.some((u) => u.includes('shoppex-pay/assets/js/blocks-checkout.js')),
	`urls: ${JSON.stringify(jsUrls)}`
);

console.log('\n=== Step 4: Payment method registered via wc.wcBlocksRegistry ===');
const registered = await page.evaluate(() => {
	const registry = window.wc && window.wc.wcBlocksRegistry;
	if (!registry) return { hasRegistry: false };
	const methods = (registry.__experimentalDeRegisterPaymentMethod ? [] : null);
	// Probe for the shoppex method presence — the Blocks API doesn't expose a clean list,
	// but we can check for the rendered radio instead.
	return {
		hasRegistry: true,
	};
});
check('wc.wcBlocksRegistry available', registered.hasRegistry);

console.log('\n=== Step 5: Shoppex method renders with label and description ===');
const shoppexRadio = page.locator('input.wc-block-components-radio-control__input[id*="shoppex"], input[value="shoppex"]').first();
await shoppexRadio.waitFor({ state: 'attached', timeout: 15000 });
check('Shoppex radio input present in Blocks UI', await shoppexRadio.count() > 0);

// Look for visible title text
const titleText = await page.locator('.wc-block-components-radio-control__label', { hasText: /Shoppex/i }).count();
check('Shoppex label visible in radio list', titleText > 0);

// Click the label (radio itself is visually hidden in Blocks UI)
await page.locator('label[for="radio-control-wc-payment-method-options-shoppex"]').first().click();
await page.waitForTimeout(500);
const descVisible = await page.locator('text=Pay via Shoppex').count();
check('Description text shown after selecting Shoppex', descVisible > 0);
const isChecked = await shoppexRadio.evaluate((el) => el.checked);
check('Radio is selected after click', isChecked);

console.log('\n=== Step 6: No JS errors from our plugin ===');
const ourErrors = consoleErrors.filter((e) =>
	/shoppex/i.test(e)
);
if (ourErrors.length > 0) {
	console.log('  Errors logged:', ourErrors);
}
check('No plugin-related JS console errors', ourErrors.length === 0);

console.log('\n=== Step 7: Icon config exposed via wcSettings ===');
const iconInSettings = await page.evaluate(() => {
	const settings = window.wc?.wcSettings?.getSetting?.('shoppex_data', {});
	return settings?.icon || null;
});
check('Icon URL exposed in wcSettings data', !!iconInSettings && iconInSettings.includes('shoppex-pay'), `icon=${iconInSettings}`);

await browser.close();

if (failures > 0) {
	console.log(`\nFAILED: ${failures} assertion(s) failed.`);
	process.exit(1);
}
console.log('\nALL TESTS PASSED');
