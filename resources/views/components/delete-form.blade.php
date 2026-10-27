@props(['action', 'confirm' => 'คุณต้องการลบรายการนี้ใช่หรือไม่?', 'title' => 'ยืนยันการลบ', 'label' => 'ลบ', 'icon' => 'mdi-delete', 'size' => 'btn-sm'])
<form method="POST" action="{{ $action }}" data-confirm="{{ $confirm }}" data-confirm-title="{{ $title }}" data-confirm-ok="ลบ" class="d-inline">
    @csrf
    @method('DELETE')
    <button type="submit" {{ $attributes->merge(['class' => 'btn btn-soft-danger ' . $size]) }} title="{{ $label }}">
        <i class="mdi {{ $icon }}"></i>{{ $slot->isEmpty() ? '' : $slot }}
    </button>
</form>
