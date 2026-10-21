<button
    id="ondc-add-to-cart"
    data-item-id="{{ $product->id }}"
    data-bagisto-id="{{ $product->bagisto_product_id }}"
    class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium"
>
    Add to Cart
</button>
<div id="ondc-cart-status" class="mt-3 text-sm hidden"></div>

<script>
document.getElementById('ondc-add-to-cart').addEventListener('click', async function () {
    const btn       = this;
    const status    = document.getElementById('ondc-cart-status');
    const ondcId    = btn.dataset.itemId;
    const bagistoId = btn.dataset.bagistoId;

    btn.disabled     = true;
    btn.textContent  = 'Getting quote…';
    status.className = 'mt-3 text-sm';
    status.textContent = '';
    status.classList.remove('hidden');

    try {
        // Step 1 — call /select → polls on_select → stores quote
        const selectResp = await fetch('{{ route("ondc.buyer.select") }}', {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ ondc_item_id: ondcId, quantity: 1 }),
        });

        const selectData = await selectResp.json();
        console.log('[ONDC Select] response:', selectData);

        if (!selectData.success) {
            throw new Error(selectData.error ?? 'Select failed');
        }

        btn.textContent = 'Adding to cart…';

        // Step 2 — add to Bagisto cart using bagisto_product_id
        const cartResp = await fetch('/api/checkout/cart', {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({
                product_id: bagistoId,
                quantity:   1,
            }),
        });

        const cartData = await cartResp.json();
        console.log('[Bagisto Cart] response:', cartData);

        if (cartData.data || cartData.message) {
            status.textContent = '✅ Added to cart! Quote: ₹' + (selectData.quote?.price?.value ?? '—');
            status.classList.add('text-green-600');
            btn.textContent = 'Added ✓';
        } else {
            throw new Error(cartData.message ?? 'Cart add failed');
        }

    } catch (err) {
        console.error('[ONDC] Error:', err);
        status.textContent = '❌ ' + err.message;
        status.classList.add('text-red-600');
        btn.disabled    = false;
        btn.textContent = 'Add to Cart';
    }
});
</script>