@if ($paginator->hasPages())
    <nav class="d-flex flex-wrap justify-content-between align-items-center gap-2" aria-label="Pagination">
        <p class="small text-muted mb-0">
            แสดง <strong>{{ $paginator->firstItem() }}</strong>–<strong>{{ $paginator->lastItem() }}</strong>
            จากทั้งหมด <strong>{{ number_format($paginator->total()) }}</strong> รายการ
        </p>

        <ul class="pagination pagination-sm mb-0">
            @if ($paginator->onFirstPage())
                <li class="page-item disabled" aria-disabled="true"><span class="page-link"><i class="mdi mdi-chevron-left"></i></span></li>
            @else
                <li class="page-item"><a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="ก่อนหน้า"><i class="mdi mdi-chevron-left"></i></a></li>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li class="page-item disabled" aria-disabled="true"><span class="page-link">{{ $element }}</span></li>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <li class="page-item active" aria-current="page"><span class="page-link">{{ $page }}</span></li>
                        @else
                            <li class="page-item"><a class="page-link" href="{{ $url }}">{{ $page }}</a></li>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <li class="page-item"><a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="ถัดไป"><i class="mdi mdi-chevron-right"></i></a></li>
            @else
                <li class="page-item disabled" aria-disabled="true"><span class="page-link"><i class="mdi mdi-chevron-right"></i></span></li>
            @endif
        </ul>
    </nav>
@elseif ($paginator->total() > 0)
    <p class="small text-muted mb-0">ทั้งหมด <strong>{{ number_format($paginator->total()) }}</strong> รายการ</p>
@endif
