<div class="header">
    @php
        $logoPath = app_logo() ? url(app_logo()) : asset('images/email/logo.svg');
    @endphp

    <img src="{{ $logoPath }}" alt="tagyourtaxi" width="180">
</div>
