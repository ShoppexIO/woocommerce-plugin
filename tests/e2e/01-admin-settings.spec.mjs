/**
 * E2E — WP admin settings for the Shoppex gateway.
 *
 * Verifies:
 *   1. Gateway appears in WooCommerce → Settings → Payments
 *   2. Settings page renders all configured fields
 *   3. Plugin admin listing shows the activation correctly
 */
import { chromium } from 'playwright';

const WP_URL = 'http://localhost:8080';
const ADMIN_USER = 'admin';
const ADMIN_PASS = 'admin';

let failures = 0;
const check = (name, cond, detail = '') => {
	if (cond) {
		console.log(`  [PASS] ${name}`);
	} else {
		console.log(`  [FAIL] ${name}${detail ? ': ' + detail : ''}`);
		failures++;
	}
};

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1400, height: 900 } });
const page = await context.newPage();

console.log('=== Test 1: Admin login ===');
await page.goto(`${WP_URL}/wp-login.php`);
await page.fill('#user_login', ADMIN_USER);
await page.fill('#user_pass', ADMIN_PASS);
await page.click('#wp-submit');
await page.waitForLoadState('networkidle');
check('Login succeeded', page.url().includes('/wp-admin'));

console.log('\n=== Test 2: Plugin is listed as active ===');
await page.goto(`${WP_URL}/wp-admin/plugins.php`);
const pluginRow = page.locator('tr[data-plugin="shoppex-pay/shoppex-pay.php"]');
check('Shoppex Pay row present', await pluginRow.count() > 0);
const activeClass = await pluginRow.getAttribute('class');
check('Plugin marked active', activeClass && activeClass.includes('active'), `class="${activeClass}"`);

console.log('\n=== Test 3: Gateway visible in WC Payments ===');
await page.goto(`${WP_URL}/wp-admin/admin.php?page=wc-settings&tab=checkout`);
const shoppexRow = page.locator('tr[data-gateway_id="shoppex"]');
check('Shoppex gateway row in payments list', await shoppexRow.count() > 0);
const enabledToggle = shoppexRow.locator('.woocommerce-input-toggle');
check(
	'Gateway toggle is present',
	await enabledToggle.count() > 0,
);
const isEnabled = await enabledToggle.getAttribute('class');
check('Gateway is enabled', isEnabled && isEnabled.includes('woocommerce-input-toggle--enabled'), `class="${isEnabled}"`);

console.log('\n=== Test 4: Settings page renders all fields ===');
await page.goto(`${WP_URL}/wp-admin/admin.php?page=wc-settings&tab=checkout&section=shoppex`);
const expectedFields = [
	'woocommerce_shoppex_enabled',
	'woocommerce_shoppex_title',
	'woocommerce_shoppex_description',
	'woocommerce_shoppex_api_key',
	'woocommerce_shoppex_webhook_secret',
	'woocommerce_shoppex_white_label',
	'woocommerce_shoppex_order_prefix',
	'woocommerce_shoppex_debug',
];
for (const id of expectedFields) {
	const exists = await page.locator(`#${id}`).count();
	check(`Field #${id} present`, exists > 0);
}

console.log('\n=== Test 5: Webhook URL displayed correctly on settings page ===');
const settingsText = await page.textContent('body');
check(
	'Webhook URL hint shown',
	settingsText.includes('?wc-api=shoppex_webhook'),
);

console.log('\n=== Test 6: API key field is password type (security) ===');
const apiKeyType = await page.locator('#woocommerce_shoppex_api_key').getAttribute('type');
check('API key uses password input', apiKeyType === 'password', `type=${apiKeyType}`);
const webhookSecretType = await page.locator('#woocommerce_shoppex_webhook_secret').getAttribute('type');
check('Webhook secret uses password input', webhookSecretType === 'password', `type=${webhookSecretType}`);

await browser.close();

if (failures > 0) {
	console.log(`\nFAILED: ${failures} assertion(s) failed.`);
	process.exit(1);
}
console.log('\nALL TESTS PASSED');
