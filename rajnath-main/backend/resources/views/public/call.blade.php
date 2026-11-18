<x-layouts.base :title="'Consultation call'" robots="noindex,nofollow" class="bg-ivory-50">
    <main class="container-page py-8" x-data="callRoom" data-token-url="{{ route('call.token', $b->ref, false) }}" data-event-url="{{ route('call.event', $b->ref, false) }}" data-audio-only="{{ $audioOnly ? '1' : '0' }}">
        <a href="{{ $isStaff ? route('admin.dashboard') : route('account.bookings') }}" class="text-sm">← Back</a>
        <h1 class="mt-3 text-2xl">{{ $title ?? 'Consultation' }}</h1>
        <p class="text-ink-600">{{ $when }} · {{ $audioOnly ? 'Audio call' : 'Video call' }}</p>

        <div class="relative mx-auto mt-6 w-full overflow-hidden rounded-2xl bg-ink-900 text-white sm:mx-0" :class="stageClass">
            <div x-ref="remote" data-remote class="absolute inset-0 grid place-items-center" role="region" aria-label="Other participant"></div>
            <p class="absolute inset-0 grid place-items-center p-6 text-center" x-show="waiting">Connected. Waiting for the other person to join…</p>
            <p class="absolute inset-0 grid place-items-center p-6 text-center" x-show="remoteCamOff" x-text="remoteCamOffText"></p>
            <p class="absolute inset-0 grid place-items-center p-6 text-center" x-show="idle">Press “Join call” when you are ready. You can join from 15 minutes before the start.</p>
            <p class="absolute inset-0 grid place-items-center p-6 text-center" x-show="connecting" role="status">Connecting…</p>
            <div class="absolute inset-x-0 top-3 flex justify-center" x-show="reconnecting" role="status" aria-live="polite">
                <span class="rounded-full bg-gold-700 px-4 py-1.5 text-sm font-semibold" x-text="reconnectText"></span>
            </div>
            <div class="absolute left-3 top-3" x-show="weak"><span class="rounded-full bg-ink-800/80 px-3 py-1 text-xs">Weak connection</span></div>
            <div x-ref="local" class="absolute bottom-3 right-3 h-28 w-40 overflow-hidden rounded-xl bg-ink-800 sm:h-36 sm:w-52" x-show="camOn" role="region" aria-label="Your camera"></div>
        </div>

        <p class="field-error mt-3" role="alert" x-show="error" x-text="error"></p>
        <p class="mt-3 rounded-xl border border-gold-200 bg-gold-50 p-3 text-sm text-ink-900" role="status" x-show="notice" x-text="notice"></p>
        <div class="mt-4 flex flex-wrap gap-2">
            <button type="button" class="btn-primary" x-show="idle" @click="join" x-text="joinLabel">Join call</button>
            <button type="button" class="btn-primary" x-show="needsAudio" @click="startAudio">Turn on sound</button>
            <button type="button" class="btn-outline" x-show="micRetry" @click="startMic">Try microphone again</button>
            <button type="button" class="btn-outline" x-show="inCall" @click="toggleMic" x-text="micLabel"></button>
            <button type="button" class="btn-outline" x-show="camControl" @click="toggleCam" x-text="camLabel"></button>
            <button type="button" class="btn-danger" x-show="inCall" @click="leave">Leave</button>
        </div>
        <p class="mt-4 max-w-2xl text-xs text-ink-600">The call is private between you and your astrologer and is not recorded. Keep this page open during the call; if the internet drops, it reconnects by itself. Open the call in only one tab or device at a time. Use headphones for the best sound. If the call does not connect, please call the office: {{ config('brand')['contact.phone'] }}.</p>
    </main>
</x-layouts.base>
