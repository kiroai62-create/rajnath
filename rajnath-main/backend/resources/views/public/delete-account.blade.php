<x-layouts.public :title="__('Delete your account')" :description="__('How to delete your Astro Rajnath Jha account and data, from the app or on this page.')">
    <div class="container-page max-w-3xl py-12">
        <p class="eyebrow">{{ __('Account and privacy') }}</p>
        <h1 class="mt-2 text-3xl sm:text-4xl">{{ __('Delete your account') }}</h1>
        <p class="mt-3 text-ink-600">{{ __('You can delete your Astro Rajnath Jha account (website and Android app) at any time. It takes effect at once and cannot be undone.') }}</p>

        @if (session('status'))<div class="card mt-6 border-gold-300 bg-gold-50 p-4 text-sm" role="status">{{ session('status') }}</div>@endif
        @if (session('blocked'))
            <div class="card mt-6 border-danger-200 bg-danger-50 p-4 text-sm" role="alert">
                <p class="font-semibold">{{ __('Your account cannot be deleted just yet') }}</p>
                <p class="mt-1">{{ session('blocked') }}</p>
                <p class="mt-2">{{ __('We have recorded your request. Our team will contact you to finish it, usually within 2 working days.') }}</p>
            </div>
        @endif

        <div class="mt-8 grid gap-6 lg:grid-cols-2">
            <section class="card p-5 sm:p-6" aria-labelledby="app-h">
                <h2 id="app-h" class="text-lg">{{ __('In the app') }}</h2>
                <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm">
                    <li>{{ __('Open the Astro Rajnath Jha app and sign in.') }}</li>
                    <li>{{ __('Go to Account › Privacy and your data.') }}</li>
                    <li>{{ __('Tap "Delete my account", enter the code we send you, and confirm.') }}</li>
                </ol>
                @if ($signedIn)
                    <p class="mt-4 text-sm">{{ __('You are signed in on this website:') }} <a href="{{ route('account.privacy') }}">{{ __('delete from your account page') }}</a>.</p>
                @endif
            </section>

            <section class="card p-5 sm:p-6" aria-labelledby="web-h">
                <h2 id="web-h" class="text-lg">{{ __('On this page (no app needed)') }}</h2>
                @if ($step === 'start')
                    <form method="POST" action="{{ route('account-deletion.start') }}" class="mt-3 space-y-4" novalidate>
                        @csrf
                        <x-field name="identifier" :label="__('Email or mobile number of your account')" autocomplete="username" required />
                        <button class="btn-outline w-full">{{ __('Send me a code') }}</button>
                    </form>
                @else
                    <p class="mt-2 text-sm text-ink-600">{{ __('We sent a 6-digit code to :to. It is valid for 10 minutes.', ['to' => $masked]) }}</p>
                    <form method="POST" action="{{ route('account-deletion.confirm') }}" class="mt-3 space-y-4" novalidate>
                        @csrf
                        <x-field name="code" :label="__('6-digit code')" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required autofocus />
                        <x-field name="confirm" :label="__('Type DELETE to confirm')" autocomplete="off" required />
                        <button class="btn-danger w-full">{{ __('Delete my account permanently') }}</button>
                    </form>
                    <p class="mt-3"><a href="{{ route('account-deletion.show', ['restart' => 1]) }}" class="text-sm">{{ __('Use a different email or number') }}</a></p>
                @endif
            </section>
        </div>

        <section class="card mt-6 p-5 sm:p-6" aria-labelledby="what-h">
            <h2 id="what-h" class="text-lg">{{ __('What is deleted and what is kept') }}</h2>
            <div class="mt-3 grid gap-4 text-sm sm:grid-cols-2">
                <div>
                    <p class="font-semibold">{{ __('Deleted at once') }}</p>
                    <ul class="mt-1 list-disc space-y-1 pl-5">
                        <li>{{ __('Your name, email and mobile number') }}</li>
                        <li>{{ __('Birth profiles, charts and report text') }}</li>
                        <li>{{ __('Palm photos, puja sankalp details and delivery addresses') }}</li>
                        <li>{{ __('Reviews, support messages, assistant chats, notifications and app devices') }}</li>
                    </ul>
                </div>
                <div>
                    <p class="font-semibold">{{ __('Kept, without your name or contact') }}</p>
                    <ul class="mt-1 list-disc space-y-1 pl-5">
                        <li>{{ __('Order amounts, dates and payment references, which tax and accounting law requires us to keep') }}</li>
                    </ul>
                    <p class="mt-2 text-ink-600">{{ __('Copies in our encrypted backups are overwritten within 14 days.') }}</p>
                </div>
            </div>
            <p class="mt-4 text-sm text-ink-600">{{ __('Before deleting, finish or cancel any unpaid order or upcoming consultation, and use or ask for a refund of any wallet balance. Questions?') }} <a href="{{ route('contact') }}">{{ __('Contact us') }}</a>. {{ __('See also our') }} <a href="{{ route('privacy') }}">{{ __('Privacy policy') }}</a>.</p>
        </section>
    </div>
</x-layouts.public>
