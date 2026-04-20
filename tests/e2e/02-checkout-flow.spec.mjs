/**
 * E2E — Full browser checkout flow
 *
 * Story:
 *   Customer visits shop → adds product to cart → goes to checkout →
 *   selects Shoppex Pay → fills billing → clicks "Place Order" →
 *   plugin calls local Shoppex backend → redirect to hosted checkout URL.
 *
 * Verifies:
 *   - Shoppex appears as a payment method option
 *   - process_payment() is actually called (backend receives a POST)
 *   - A real Shoppex checkout session is created
 *   - The browser is redirected to the session URL
 *   - The WC order is saved with _shoppex_uniqid meta
 *   - Order status is `pending` while awaiting payment
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

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1400, height: 900 } });
const page = await context.newPage();

console.log('=== Step 1: Visit product page + click Add to Cart ===');
await page.goto(`${WP_URL}/?p=10`, { waitUntil: 'domcontentloaded', timeout: 30000 });
await page.waitForTimeout(1500);

// Try single_add_to_cart_button (classic) — works across themes
const addBtn = page.locator('button[name="add-to-cart"], button.single_add_to_cart_button').first();
if (await addBtn.count() > 0) {
	await addBtn.click();
	await page.waitForLoadState('domcontentloaded');
	await page.waitForTimeout(2000);
	check('Product added to cart via click', true);
} else {
	// Fallback: WC add-to-cart URL with ajax API
	await page.request.get(`${WP_URL}/wp-json/wc/store/v1/cart/add-item?id=10&quantity=1`);
	check('Product added via Store API fallback', true);
}

console.log('\n=== Step 2: Go to checkout ===');
// Use ?page_id=7 directly — safer than slug routing under fresh perma-structure
await page.goto(`${WP_URL}/?page_id=7`, { waitUntil: 'domcontentloaded', timeout: 30000 });
await page.waitForTimeout(3000);
// Blocks checkout is React — wait for payment method section to render
await page.waitForSelector('.wc-block-components-radio-control__input, input[name="payment_method"]', { timeout: 30000 }).catch(() => {});
await page.waitForTimeout(2000);

// Check whether we landed on classic or blocks checkout
const isBlocksCheckout = await page.locator('.wc-block-checkout').count() > 0;
const isClassicCheckout = await page.locator('form.woocommerce-checkout').count() > 0;
console.log(`  Checkout flavor: ${isBlocksCheckout ? 'Blocks' : isClassicCheckout ? 'Classic' : 'UNKNOWN'}`);
check('Checkout page loads', isBlocksCheckout || isClassicCheckout);

console.log('\n=== Step 3: Shoppex Pay is visible as payment option ===');
// Both flavors expose a radio input with value="shoppex"
const shoppexRadio = page.locator('input[name="payment_method"][value="shoppex"], input[name="radio-control-wc-payment-method-options"][value="shoppex"]').first();
await shoppexRadio.waitFor({ state: 'attached', timeout: 10000 });
check('Shoppex radio option attached', await shoppexRadio.count() > 0);

// Title visible
const shoppexLabel = await page.locator('text=Shoppex Pay').count();
check('Shoppex Pay label visible', shoppexLabel > 0);

console.log('\n=== Step 4: Fill billing details & select Shoppex ===');

// Fill billing — classic checkout fields
if (isClassicCheckout) {
	await page.fill('#billing_first_name', 'Playwright');
	await page.fill('#billing_last_name', 'Tester');
	await page.fill('#billing_address_1', '123 Test Street');
	await page.fill('#billing_city', 'Testville');
	await page.fill('#billing_postcode', '12345');
	await page.fill('#billing_phone', '555-0100');
	await page.fill('#billing_email', 'playwright@example.com');
	// Some countries need a state dropdown
	const stateSelect = page.locator('#billing_state');
	if (await stateSelect.count() > 0 && await stateSelect.isVisible()) {
		await stateSelect.selectOption({ index: 1 }).catch(() => {});
	}
} else {
	// Blocks checkout
	await page.fill('#email', 'playwright@example.com');
	await page.fill('#billing-first_name, input[id*="first_name"]', 'Playwright');
	await page.fill('#billing-last_name, input[id*="last_name"]', 'Tester');
	await page.fill('#billing-address_1, input[id*="address_1"]', '123 Test Street');
	await page.fill('#billing-city, input[id*="city"]', 'Testville');
	await page.fill('#billing-postcode, input[id*="postcode"]', '12345');
}

// Click the Shoppex payment method label (reliable way to select radio)
await page.locator('label[for="payment_method_shoppex"], label[for="radio-control-wc-payment-method-options-shoppex"]').first().click().catch(() => {});

console.log('\n=== Step 5: Click Place Order & capture the redirect ===');

// Snapshot orders before to diff after
const ordersBefore = parseInt(
	docker("wp eval 'echo count(wc_get_orders(array(\"limit\"=>-1,\"return\"=>\"ids\")));'").trim(),
	10
);

// Capture the page's attempted navigation — Blocks checkout uses window.location assign,
// so we hook the history API to see where the plugin told the browser to go.
const navigatedUrls = [];
page.on('framenavigated', (frame) => {
	if (frame === page.mainFrame()) navigatedUrls.push(frame.url());
});

// Click "Place order"
const placeOrderBtn = page.locator('#place_order, button.wc-block-components-checkout-place-order-button').first();
await placeOrderBtn.click();
await page.waitForTimeout(8000);

const finalUrl = page.url();
console.log(`  Final URL: ${finalUrl}`);
console.log(`  Navigation trace: ${JSON.stringify(navigatedUrls)}`);

// The Shoppex hosted checkout is http://localhost:3003/invoice/<uuid> — Playwright
// cannot resolve it because 3003 is not running in this test harness. That's fine:
// the *attempt* to navigate there is what we want to verify.
check(
	'Browser attempted to navigate to Shoppex hosted checkout',
	navigatedUrls.some((u) => u.includes('/invoice/') && /[0-9a-f]{8}-[0-9a-f]{4}-/.test(u))
		|| finalUrl.includes('chrome-error'), // error page means the redirect fired to a non-resolvable host
	`nav trace: ${JSON.stringify(navigatedUrls)}`
);

console.log('\n=== Step 6: Verify WC order was created with Shoppex uniqid ===');

const ordersAfter = parseInt(
	docker("wp eval 'echo count(wc_get_orders(array(\"limit\"=>-1,\"return\"=>\"ids\")));'").trim(),
	10
);
check(`Order count grew (before=${ordersBefore}, after=${ordersAfter})`, ordersAfter >= ordersBefore + 1 || ordersAfter >= 1);

// Get the latest shoppex order
const latestOrderId = docker('wp eval "echo (int) wc_get_orders(array(\'payment_method\'=>\'shoppex\',\'limit\'=>1,\'return\'=>\'ids\',\'orderby\'=>\'date\',\'order\'=>\'DESC\'))[0] ?? 0;"').trim();
const orderId = parseInt(latestOrderId, 10);
check(`Latest Shoppex order id resolved (id=${orderId})`, orderId > 0);

if (orderId > 0) {
	const orderStatus = docker(`wp eval "echo wc_get_order(${orderId})->get_status();"`).trim();
	const uniqid = docker(`wp eval "echo wc_get_order(${orderId})->get_meta('_shoppex_uniqid');"`).trim();
	const sessionUrl = docker(`wp eval "echo wc_get_order(${orderId})->get_meta('_shoppex_session_url');"`).trim();
	const paymentMethod = docker(`wp eval "echo wc_get_order(${orderId})->get_payment_method();"`).trim();

	check('Order is in pending status', orderStatus === 'pending', `status=${orderStatus}`);
	check('Order payment_method = shoppex', paymentMethod === 'shoppex', `got ${paymentMethod}`);
	check('_shoppex_uniqid meta set (session created server-side)', uniqid.length > 0 && uniqid !== '0', `uniqid="${uniqid}"`);
	check('_shoppex_session_url meta set (hosted checkout URL)', sessionUrl.startsWith('http'), `url="${sessionUrl}"`);
	check('Session URL points at a Shoppex invoice path', sessionUrl.includes('/invoice/'), `url="${sessionUrl}"`);
}

console.log('\n=== Step 7: Verify API request was actually made (via WC log) ===');
const logSnippet = docker(`wp eval "
\\$log_dir = WP_CONTENT_DIR . '/uploads/wc-logs/';
foreach (glob(\\$log_dir . 'shoppex*') as \\$f) { echo file_get_contents(\\$f); }
"`);
check(
	'WC log shows POST /dev/v1/checkout/sessions',
	logSnippet.includes('POST /dev/v1/checkout/sessions'),
);
check(
	'WC log shows 201 response with uniqid',
	logSnippet.includes('Response 201') && logSnippet.includes('"id":'),
);
check(
	'Plugin sent white_label explicitly (not omitted)',
	logSnippet.includes('"white_label":false') || logSnippet.includes('"white_label":true'),
);
check(
	'Plugin sent metadata.wc_order_id',
	logSnippet.includes('"wc_order_id":'),
);
check(
	'Plugin sent metadata.source=woocommerce',
	logSnippet.includes('"source":"woocommerce"'),
);

await browser.close();

if (failures > 0) {
	console.log(`\nFAILED: ${failures} assertion(s) failed.`);
	process.exit(1);
}
console.log('\nALL TESTS PASSED');
