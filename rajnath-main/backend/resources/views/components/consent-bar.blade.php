{{-- Cookie choice for Google Analytics and ads (Google Consent Mode). Small, at the bottom, never blocks the page. --}}
<div x-data="consentBar" x-show="open" x-cloak role="region" aria-label="{{ __('Cookie choice') }}"
     class="fixed inset-x-3 bottom-3 z-40 mx-auto max-w-2xl rounded-2xl border border-ink-200 bg-white p-4 text-sm shadow-lg print:hidden sm:flex sm:items-center sm:gap-4">
    <p class="text-ink-700">{{ __('We use Google Analytics and show ads on free tools to keep them free. Allow cookies for this, or keep only the necessary ones.') }}
        <a href="{{ route('privacy') }}">{{ __('Privacy Policy') }}</a></p>
    <div class="mt-3 flex shrink-0 gap-2 sm:mt-0">
        <button type="button" class="btn-ghost px-3" @click="necessary">{{ __('Only necessary') }}</button>
        <button type="button" class="btn-primary px-4" @click="accept">{{ __('Allow') }}</button>
    </div>
</div>
