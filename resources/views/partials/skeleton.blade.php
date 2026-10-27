{{-- Generic page skeleton shown while the next page is loading (toggled by labour.js). --}}
<div class="page-skeleton" id="pageSkeleton" aria-hidden="true">
    <div class="d-flex justify-content-between align-items-end mb-4 gap-3">
        <div class="flex-grow-1">
            <div class="sk sk-title"></div>
            <div class="sk sk-sub"></div>
        </div>
        <div class="d-none d-md-flex gap-2"><div class="sk sk-btn"></div><div class="sk sk-btn" style="width:100px"></div></div>
    </div>

    <div class="row g-3 mb-4">
        @for ($i = 0; $i < 4; $i++)
            <div class="col-6 col-xl-3">
                <div class="card stat-card">
                    <div class="sk sk-circle" style="border-radius:12px"></div>
                    <div class="sk-body">
                        <div class="sk sk-text mx-auto" style="width:50%;height:20px"></div>
                        <div class="sk sk-text mt-2 mx-auto" style="width:70%"></div>
                    </div>
                </div>
            </div>
        @endfor
    </div>

    <div class="card">
        <div class="card-body pb-2">
            <div class="row g-3">
                <div class="col-md-5"><div class="sk sk-input"></div></div>
                <div class="col-md-3"><div class="sk sk-input"></div></div>
                <div class="col-md-2"><div class="sk sk-input"></div></div>
                <div class="col-md-2"><div class="sk sk-input"></div></div>
            </div>
        </div>
        <div class="mt-2">
            @for ($i = 0; $i < 7; $i++)
                <div class="sk-row">
                    <div class="sk sk-text" style="width:24px"></div>
                    <div><div class="sk sk-text" style="width:{{ 55 + ($i * 7) % 35 }}%"></div><div class="sk sk-text mt-2" style="width:35%;height:9px"></div></div>
                    <div class="sk sk-text" style="width:{{ 60 + ($i * 11) % 30 }}%"></div>
                    <div class="sk sk-text" style="width:70%"></div>
                    <div class="sk sk-text" style="width:64px;height:22px;border-radius:999px"></div>
                    <div class="d-flex gap-1 justify-content-end"><div class="sk" style="width:30px;height:30px"></div><div class="sk" style="width:30px;height:30px"></div></div>
                </div>
            @endfor
        </div>
    </div>
</div>
