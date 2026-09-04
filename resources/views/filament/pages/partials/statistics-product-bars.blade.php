@php($productRows = collect($rows)->take(10))
@php($productMax = max(1, $productRows->max('quote_count') ?? 1))
@forelse($productRows as $product)
    <div class="stats-bar-row"><span title="{{ $product->description }}">{{ $product->code }}</span><div class="stats-bar"><i style="width:{{ $product->quote_count/$productMax*100 }}%;background:linear-gradient(90deg,#ff9800,#ffd166)"></i></div><b>{{ $product->quote_count }}</b></div>
@empty
    <p class="stats-muted">No quoted products in this period.</p>
@endforelse
