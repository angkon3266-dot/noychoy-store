{{-- Shared by all product templates: only showcase and classic used to have a
     breadcrumb, so switching template silently cost the navigation (and left
     the BreadcrumbList JSON-LD describing a trail the page didn't show). --}}
<nav aria-label="Breadcrumb" class="text-sm text-ink-700/60 mb-6">
    <a href="{{ route('home') }}" class="hover:text-gold-700">Home</a> /
    @if($product->category)<a href="{{ route('category.show', $product->category) }}" class="hover:text-gold-700">{{ $product->category->name }}</a> /@endif
    <span class="text-ink-800">{{ $product->name }}</span>
</nav>
