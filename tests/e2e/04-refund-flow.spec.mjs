/**
 * E2E — Refund flow.
 *
 * Exercises the plugin's process_refund() path through wc_create_refund(), which
 * is what WC calls when the admin hits the "Refund" button.
 */
import { execSync } from 'node:child_process';
import { writeFileSync as writeFile } from 'node:fs';

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

const runPhp = (filename) => {
	return docker(`wp eval-file /var/www/html/wp-content/plugins/shoppex-pay/../../../../tmp-e2e/${filename}`);
};

// Write PHP snippets to a dir docker can read
const phpDir = '/Users/florianm/Desktop/Projects/shoppex-woocommerce/tmp-e2e';
execSync(`mkdir -p ${phpDir}`);

// We'll mount tmp-e2e into the container. Easier: use wp eval-file with a file
// accessible in the container. The docker-compose already mounts shoppex-pay;
// we'll write PHP files into shoppex-pay/tests-tmp/ (ephemeral, gitignored).
const tmpDir = '/Users/florianm/Desktop/Projects/shoppex-woocommerce/shoppex-pay/tests-tmp';
execSync(`mkdir -p ${tmpDir}`);

const writePhp = (filename, code) => {
	writeFile(`${tmpDir}/${filename}`, `<?php\n${code}\n`);
};

const evalFile = (filename) =>
	docker(`wp eval-file /var/www/html/wp-content/plugins/shoppex-pay/tests-tmp/${filename}`);

console.log('=== Step 0: Reset orders + logs ===');
writePhp('clear.php', `
foreach (wc_get_orders(array("limit"=>-1,"return"=>"ids")) as $id) {
	wc_get_order($id)->delete(true);
}
echo "cleared";
`);
evalFile('clear.php');
execSync(`docker compose -f docs/docker-compose.test.yml exec -T wordpress sh -c 'rm -f /var/www/html/wp-content/uploads/wc-logs/shoppex*'`, { encoding: 'utf8' });
console.log('  cleared.');

console.log('\n=== Step 1: Create paid order with non-existent Shoppex uniqid ===');
writePhp('create-order-with-uniqid.php', `
$order = wc_create_order();
$order->set_payment_method("shoppex");
$order->set_currency("USD");
$order->set_status("processing");
$product = wc_get_product(wc_get_products(array("limit"=>1,"return"=>"ids"))[0]);
$order->add_product($product, 1);
$order->calculate_totals();
$order->update_meta_data("_shoppex_uniqid", "ord_no_such_session_12345");
$order->save();
echo $order->get_id();
`);
const orderId = parseInt(evalFile('create-order-with-uniqid.php'), 10);
check(`Paid order created (id=${orderId})`, orderId > 0);

console.log('\n=== Step 2: Trigger refund — plugin should call the API and error gracefully ===');
writePhp('refund-order.php', `
$order_id = ${orderId};
$result = wc_create_refund(array(
	"order_id" => $order_id,
	"amount" => 5.00,
	"reason" => "E2E test partial refund",
	"refund_payment" => true,
));
if (is_wp_error($result)) {
	echo "WP_ERROR:" . $result->get_error_code() . ":" . $result->get_error_message();
} else {
	echo "REFUND_ID:" . $result->get_id();
}
`);
const refundResult = evalFile('refund-order.php');
console.log(`  Refund call outcome: ${refundResult.substring(0, 300)}`);
check(
	'Plugin attempted refund and returned an error (unknown uniqid)',
	refundResult.includes('WP_ERROR:'),
	refundResult
);

console.log('\n=== Step 3: Verify plugin actually hit /dev/v1/orders/<uniqid>/refund ===');
writePhp('read-log.php', `
$log_dir = WP_CONTENT_DIR . "/uploads/wc-logs/";
foreach (glob($log_dir . "shoppex*") as $f) { echo file_get_contents($f); }
`);
const logSnippet = evalFile('read-log.php');
check(
	'WC log shows POST /dev/v1/orders/ord_no_such_session_12345/refund',
	logSnippet.includes('POST /dev/v1/orders/ord_no_such_session_12345/refund'),
	'log excerpt: ' + logSnippet.substring(0, 500)
);
check(
	'Plugin sent amount=5 in refund body',
	logSnippet.includes('"amount":"5"') || logSnippet.includes('"amount":"5.00"'),
);
check(
	'Plugin sent reason in refund body',
	logSnippet.includes('E2E test partial refund'),
);

console.log('\n=== Step 4: Refund without _shoppex_uniqid → specific error code ===');
writePhp('create-order-no-uniqid.php', `
$order = wc_create_order();
$order->set_payment_method("shoppex");
$order->set_currency("USD");
$order->set_status("processing");
$product = wc_get_product(wc_get_products(array("limit"=>1,"return"=>"ids"))[0]);
$order->add_product($product, 1);
$order->calculate_totals();
$order->save();
echo $order->get_id();
`);
const noMetaId = parseInt(evalFile('create-order-no-uniqid.php'), 10);

writePhp('refund-no-meta.php', `
$r = wc_create_refund(array("order_id" => ${noMetaId}, "amount" => 5.00, "refund_payment" => true));
if (is_wp_error($r)) echo "WP_ERROR:" . $r->get_error_code() . ":" . $r->get_error_message();
`);
const refundNoMeta = evalFile('refund-no-meta.php');
check(
	'Refund on order without _shoppex_uniqid → "No Shoppex order reference" message',
	refundNoMeta.includes('No Shoppex order reference'),
	refundNoMeta
);

console.log('\n=== Step 5: Failed-refund flow leaves an order note ===');
writePhp('read-notes.php', `
$notes = wc_get_order_notes(array("order_id" => ${orderId}));
foreach ($notes as $n) echo $n->content . "|---|";
`);
const notes = evalFile('read-notes.php');
check(
	`Order ${orderId} has at least one order note`,
	notes.length > 0,
	notes.substring(0, 300)
);

// Cleanup tmp files
execSync(`rm -rf ${tmpDir}`);

if (failures > 0) {
	console.log(`\nFAILED: ${failures} assertion(s) failed.`);
	process.exit(1);
}
console.log('\nALL TESTS PASSED');
