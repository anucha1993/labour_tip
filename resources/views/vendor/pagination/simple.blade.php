@if ($paginator->hasPages())
    <ul class="pagination pagination-sm mb-0">
        @if ($paginator->onFirstPage())
            <li class="page-item disabled"><span class="page-link">ก่อนหน้า</span></li>
        @else
            <li class="page-item"><a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">ก่อนหน้า</a></li>
        @endif
        @if ($paginator->hasMorePages())
            <li class="page-item"><a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">ถัดไป</a></li>
        @else
            <li class="page-item disabled"><span class="page-link">ถัดไป</span></li>
        @endif
    </ul>
@endif
