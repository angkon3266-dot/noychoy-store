{{-- Sticky buy bar — always on mobile, on scroll for desktop. Expects `productPage` scope. --}}
@if(theme('sticky_buy_bar') && ($product->isAvailable() || $product->isPreorder()))
    @php $preorder = $product->isPreorder(); @endphp
    <div x-data="{ scrolled: false }" @scroll.window="scrolled = window.scrollY > 700">
        <div class="fixed bottom-14 md:bottom-0 inset-x-0 z-40 bg-white border-t border-ink-100 shadow-[0_-4px_12px_rgba(0,0,0,0.06)] transition-transform"
             :class="(scrolled || window.innerWidth < 768) ? 'translate-y-0' : 'translate-y-full md:translate-y-full'">
            <div class="mx-auto max-w-7xl px-4 py-3 flex items-center gap-3">
                <div class="shrink-0 hidden sm:block">
                    <div class="font-medium text-sm truncate max-w-[14rem]">{{ $product->name }}</div>
                </div>
                <div class="shrink-0">
                    <div class="text-xs text-ink-700/50">Total</div>
                    <div class="font-semibold text-gold-700" x-text="'৳'+Number(price*qty).toLocaleString()"></div>
                </div>
                <form action="{{ route('cart.add', $product) }}" method="POST" class="hidden sm:block" @submit.prevent="fireAddToCart($event.target); $store.cart.add($event.target)">
                    @csrf
                    <input type="hidden" name="variant_id" :value="variant==='none' ? '' : variant">
                    <input type="hidden" name="qty" :value="qty">
                    <button type="submit" class="btn-outline whitespace-nowrap" :disabled="!canBuy">Add to cart</button>
                </form>
                <form action="{{ route('cart.buynow', $product) }}" method="POST" class="flex-1">
                    @csrf
                    <input type="hidden" name="variant_id" :value="variant==='none' ? '' : variant">
                    <input type="hidden" name="qty" :value="qty">
                    <button type="submit" class="w-full {{ $preorder ? 'inline-flex items-center justify-center rounded-md bg-violet-600 px-4 py-2.5 font-medium text-white hover:bg-violet-700 transition' : 'btn-primary' }}" :disabled="!canBuy">{{ $preorder ? 'Book now' : 'Buy now' }}</button>
                </form>
            </div>
        </div>
    </div>
    <div class="md:hidden h-32"></div>
@endif
