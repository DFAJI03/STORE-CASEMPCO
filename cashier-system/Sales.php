<?php
// sales.php - the cashier screen: search items, build a cart, take payment.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

// Load every active product once. The browser searches this list, so the
// screen stays fast. The server still re-checks everything when saving.
$products = $pdo->query(
    'SELECT id, barcode, name, selling_price AS price, stock_qty AS stock
     FROM products
     WHERE is_active = 1
     ORDER BY name'
)->fetchAll();

// Customers, for choosing who takes items on utang.
$customers = $pdo->query('SELECT id, name FROM customers ORDER BY name')->fetchAll();

$pageTitle = 'Sales';
require __DIR__ . '/includes/header.php';
?>

<h1>Sales</h1>

<div id="notice"></div>

<div class="pos-grid">

    <!-- LEFT: find items -->
    <section class="card">
        <h2>Find item</h2>
        <input id="search" autocomplete="off" autofocus
               placeholder="Type a name or scan a barcode, then press Enter">
        <div id="results" class="results"></div>
    </section>

    <!-- RIGHT: cart and payment -->
    <section class="card">
        <h2>Cart</h2>

        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="num">Price</th>
                    <th>Qty</th>
                    <th class="num">Total</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="cart-body"></tbody>
        </table>

        <div class="totals">
            <div><span>Subtotal</span><span id="subtotal">0.00</span></div>
            <div>
                <span>Discount</span>
                <input id="discount" type="number" min="0" step="0.01" value="0">
            </div>
            <div class="totals__grand"><span>Total</span><span id="total">0.00</span></div>
        </div>

        <div class="form-grid">
            <div>
                <label for="method">Payment</label>
                <select id="method">
                    <option value="cash">Cash</option>
                    <option value="card">Card</option>
                    <option value="utang">Utang (pay later)</option>
                </select>
            </div>
            <div>
                <label for="amount-paid" id="amount-label">Amount received</label>
                <input id="amount-paid" type="number" min="0" step="0.01">
            </div>
        </div>

        <!-- Only shown when the payment is Utang -->
        <div id="utang-box" class="form-grid" style="display:none; margin-top:12px;">
            <div>
                <label for="customer-id">Customer</label>
                <select id="customer-id">
                    <option value="">-- Choose customer --</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="new-customer">Or type a new customer</label>
                <input id="new-customer" autocomplete="off" placeholder="e.g. Aling Nena">
            </div>
        </div>

        <div class="totals">
            <div><span id="change-label">Change</span><span id="change">0.00</span></div>
        </div>

        <button type="button" class="btn" id="checkout">Complete sale</button>
        <button type="button" class="btn btn--gray" id="clear">Clear cart</button>
    </section>
</div>

<script>
    // The product list from the database (safe to embed: special characters are escaped).
    const PRODUCTS = <?= json_encode($products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    let cart = [];   // each line: { id, name, price, stock, qty }

    const el = (id) => document.getElementById(id);

    // Format a number as money: 1234.5 -> 1,234.50
    function money(n) {
        return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // Make text safe to put inside HTML.
    function esc(text) {
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

    function notice(message, type) {
        el('notice').innerHTML = '<div class="alert alert--' + type + '">' + esc(message) + '</div>';
    }

    // ---------- Finding items ----------
    function findMatches(term) {
        term = term.trim().toLowerCase();
        const list = term === ''
            ? PRODUCTS
            : PRODUCTS.filter(function (p) {
                return p.name.toLowerCase().includes(term) || p.barcode.toLowerCase().includes(term);
            });
        return list.slice(0, 12);
    }

    function showResults(term) {
        const list = findMatches(term);
        el('results').innerHTML = list.length
            ? list.map(function (p) {
                return '<button type="button" class="result" data-add="' + p.id + '"' + (p.stock <= 0 ? ' disabled' : '') + '>' +
                       '<span class="result__name">' + esc(p.name) + '</span>' +
                       '<span class="result__meta">' + money(p.price) + ' &middot; ' +
                       (p.stock > 0 ? p.stock + ' in stock' : 'Out of stock') + '</span></button>';
            }).join('')
            : '<p class="hint">No items found.</p>';
    }

    // ---------- Cart ----------
    function addToCart(id) {
        const product = PRODUCTS.find(function (p) { return p.id == id; });
        if (!product) return;

        const line = cart.find(function (l) { return l.id == id; });
        const alreadyInCart = line ? line.qty : 0;

        if (alreadyInCart + 1 > product.stock) {
            notice('Not enough stock for ' + product.name + ' (only ' + product.stock + ' left).', 'error');
            return;
        }

        if (line) {
            line.qty++;
        } else {
            cart.push({ id: product.id, name: product.name, price: Number(product.price), stock: Number(product.stock), qty: 1 });
        }
        el('notice').innerHTML = '';
        renderCart();
    }

    function renderCart() {
        el('cart-body').innerHTML = cart.length
            ? cart.map(function (l) {
                return '<tr>' +
                    '<td>' + esc(l.name) + '</td>' +
                    '<td class="num">' + money(l.price) + '</td>' +
                    '<td><input class="qty-input" type="number" min="1" max="' + l.stock + '" value="' + l.qty + '" data-qty="' + l.id + '"></td>' +
                    '<td class="num">' + money(l.price * l.qty) + '</td>' +
                    '<td><button type="button" class="btn btn--small btn--danger" data-remove="' + l.id + '">X</button></td>' +
                    '</tr>';
            }).join('')
            : '<tr><td colspan="5">The cart is empty.</td></tr>';
        updateTotals();
    }

    function getTotals() {
        const subtotal = cart.reduce(function (sum, l) { return sum + l.price * l.qty; }, 0);
        let discount = parseFloat(el('discount').value) || 0;
        discount = Math.min(Math.max(discount, 0), subtotal);
        const total = subtotal - discount;
        const method = el('method').value;
        // Cash and utang use the typed amount. Card pays the exact total.
        const paid = method === 'card' ? total : (parseFloat(el('amount-paid').value) || 0);
        const change = method === 'cash' ? Math.max(paid - total, 0) : 0;
        const utang = method === 'utang' ? Math.max(total - paid, 0) : 0;
        return { subtotal: subtotal, discount: discount, total: total, paid: paid, change: change, utang: utang };
    }

    function updateTotals() {
        const t = getTotals();
        el('subtotal').textContent = money(t.subtotal);
        el('total').textContent = money(t.total);
        // For utang, the last line shows what the customer still owes.
        const isUtang = el('method').value === 'utang';
        el('change-label').textContent = isUtang ? 'Utang (still owed)' : 'Change';
        el('change').textContent = money(isUtang ? t.utang : t.change);
    }

    // ---------- Saving the sale ----------
    async function checkout() {
        if (!cart.length) {
            notice('The cart is empty.', 'error');
            return;
        }

        const t = getTotals();
        const method = el('method').value;

        if (method === 'cash' && t.paid < t.total) {
            notice('The amount received is less than the total.', 'error');
            return;
        }

        const customerId = el('customer-id').value;
        const newCustomer = el('new-customer').value.trim();
        if (method === 'utang') {
            if (!customerId && newCustomer === '') {
                notice('Choose a customer, or type a new customer name, for utang.', 'error');
                return;
            }
            if (t.paid >= t.total) {
                notice('The customer paid the full amount. Use Cash instead of Utang.', 'error');
                return;
            }
        }

        el('checkout').disabled = true;
        try {
            const response = await fetch('api/save_sale.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    items: cart.map(function (l) { return { product_id: l.id, qty: l.qty }; }),
                    discount: t.discount,
                    payment_method: method,
                    amount_paid: t.paid,
                    customer_id: customerId,
                    new_customer: newCustomer
                })
            });
            const data = await response.json();

            if (!data.success) {
                notice(data.message || 'Could not save the sale.', 'error');
                return;
            }

            if (method === 'utang') {
                notice('Sale ' + data.invoice_no + ' saved on utang for ' + data.customer.name +
                       '. Total ' + money(data.total) + ', still owed ' + money(data.utang) + '.', 'ok');
                // A newly typed customer joins the list so they can be picked next time.
                const known = Array.from(el('customer-id').options).some(function (o) { return o.value == data.customer.id; });
                if (!known) {
                    el('customer-id').add(new Option(data.customer.name, data.customer.id));
                }
            } else {
                notice('Sale ' + data.invoice_no + ' saved. Total ' + money(data.total) + '. Change ' + money(data.change) + '.', 'ok');
            }

            // Keep the on-screen stock numbers in step with the database.
            cart.forEach(function (l) {
                const p = PRODUCTS.find(function (x) { return x.id == l.id; });
                p.stock -= l.qty;
            });

            cart = [];
            el('discount').value = 0;
            el('amount-paid').value = '';
            el('customer-id').value = '';
            el('new-customer').value = '';
            renderCart();
            showResults('');
            el('search').value = '';
            el('search').focus();
        } catch (error) {
            notice('Could not reach the server. Is Apache running?', 'error');
        } finally {
            el('checkout').disabled = false;
        }
    }

    // ---------- Wiring the buttons ----------
    el('search').addEventListener('input', function () { showResults(el('search').value); });

    // Enter adds an exact barcode match, or the only item that matches.
    el('search').addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') return;
        event.preventDefault();

        const term = el('search').value.trim().toLowerCase();
        if (term === '') return;

        const exact = PRODUCTS.find(function (p) { return p.barcode.toLowerCase() === term; });
        const list = findMatches(term);
        const pick = exact || (list.length === 1 ? list[0] : null);

        if (pick) {
            addToCart(pick.id);
            el('search').value = '';
            showResults('');
        } else {
            notice(list.length ? 'Several items match. Click the one you want.' : 'No item found.', 'error');
        }
    });

    el('results').addEventListener('click', function (event) {
        const button = event.target.closest('[data-add]');
        if (button) addToCart(button.dataset.add);
    });

    el('cart-body').addEventListener('click', function (event) {
        const button = event.target.closest('[data-remove]');
        if (!button) return;
        cart = cart.filter(function (l) { return l.id != button.dataset.remove; });
        renderCart();
    });

    // Changing a quantity box (when you leave it or press Enter).
    el('cart-body').addEventListener('change', function (event) {
        const input = event.target.closest('[data-qty]');
        if (!input) return;

        const line = cart.find(function (l) { return l.id == input.dataset.qty; });
        let qty = parseInt(input.value, 10);

        if (!qty || qty < 1) {
            cart = cart.filter(function (l) { return l !== line; });
        } else if (qty > line.stock) {
            notice('Only ' + line.stock + ' of ' + line.name + ' in stock.', 'error');
            line.qty = line.stock;
        } else {
            line.qty = qty;
        }
        renderCart();
    });

    el('discount').addEventListener('input', updateTotals);
    el('amount-paid').addEventListener('input', updateTotals);

    el('method').addEventListener('change', function () {
        const method = el('method').value;
        // Card pays the exact total, so the "amount received" box is not needed.
        el('amount-paid').disabled = method === 'card';
        // Utang needs a customer, and the amount box becomes an optional "paid now".
        el('utang-box').style.display = method === 'utang' ? '' : 'none';
        el('amount-label').textContent = method === 'utang' ? 'Paid now (optional)' : 'Amount received';
        updateTotals();
    });

    el('checkout').addEventListener('click', checkout);
    el('clear').addEventListener('click', function () {
        cart = [];
        renderCart();
    });

    // Start the screen.
    showResults('');
    renderCart();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
