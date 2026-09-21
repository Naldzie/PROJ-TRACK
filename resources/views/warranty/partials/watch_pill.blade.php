{{-- One warranty check as a small pill under its date (warranty/preview.blade.php).
     $check: a WarrantyMonitor check (state, label, …) or null. Nothing is shown for
     'ok' or an unset date. --}}
@if(!empty($check) && in_array($check['state'], ['expired', 'expiring', 'due', 'passed'], true))
    <span class="watch-pill {{ $check['state'] }}">{{ $check['label'] }}</span>
@endif
