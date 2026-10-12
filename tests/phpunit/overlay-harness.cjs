// Runs assets/js/overlay.js against a minimal page and prints what the buyer sees.
// Usage: node overlay-harness.cjs <overlay.js> <missing|without-invoice-mode|with-invoice-mode>
const fs = require('fs');
const vm = require('vm');

const [script, embed] = process.argv.slice(2);

function element(hidden) {
	return { hidden, addEventListener() {} };
}
const redirecting = element(true);
const payButton = element(false);
const elements = { '[data-shoppex-pay-open]': payButton, '[data-shoppex-pay-redirecting]': redirecting };
const root = {
	querySelector: (selector) => elements[selector] || null,
};
const document = {
	getElementById: (id) => (id === 'shoppex-pay-overlay' ? root : null),
	addEventListener() {},
};
const opened = [];
const replaced = [];
const window = {
	shoppexPayOverlay: {
		invoiceId: '3f0e8a52-6c1d-4b7e-9a35-2d4c8f1b6e70',
		checkoutBaseUrl: 'https://checkout.shoppex.io',
		paymentUrl: 'https://checkout.shoppex.io/invoice/3f0e8a52-6c1d-4b7e-9a35-2d4c8f1b6e70',
		returnUrl: 'https://store.test/checkout/order-received/1234/',
	},
	// Only replace(): an assign() would leave the order payment page in the history.
	location: { replace: (url) => replaced.push(url) },
};
if (embed !== 'missing') {
	// The embed SDK before invoice mode: open() exists but needs items or groupId.
	window.Shoppex = { init() {}, open: (options) => opened.push(options) };
	if (embed === 'with-invoice-mode') {
		window.Shoppex.supports = { invoiceId: true };
	}
}

vm.runInNewContext(fs.readFileSync(script, 'utf8'), { window, document });
process.stdout.write(
	JSON.stringify({ redirecting_shown: !redirecting.hidden, pay_button_shown: !payButton.hidden, opened, replaced }),
);
