{{-- Left panel, uses alpine to allow reiszing width, resize bar fixed to right side absolutely --}}
<div
    x-data="{
        dragging: false,
        startX: 0,
        minW: 0,
        maxW: 0
    }"
    x-bind:style="'width: ' + (windowWidth < 768 ? windowWidth : leftPanelAttemptedWidth) + 'px;'"
    :class="(leftPanelOpen ? 'min-w-44 max-w-[100%] ' : 'min-w-0 max-w-0 border-none ') + (!dragging ? 'transition-all' : '')"
    id="left-panel"
    style="z-index: 6;"
    class="wrla-sidebar">

    {{-- Collapse button (Use collapse icon from fontawesome) --}}
    <button
        @click="leftPanelOpen = !leftPanelOpen; dragging = false;"
        x-bind:style="leftPanelOpen ? 'right: 0;' : 'right: -28px;'"
        x-bind:class="leftPanelOpen ? 'rounded-bl border-l' : 'rounded-br border-r';"
        class="wrla-sidebar-collapse-btn">
        <div class="relative">
            <i x-bind:class="{'fas fa-chevron-left': leftPanelOpen, 'fas fa-chevron-right': !leftPanelOpen}" class="relative -top-0.5"></i>
        </div>
    </button>

    {{-- Resize bar --}}
    <div
        :class="leftPanelOpen ? 'cursor-ew-resize' : 'hidden cursor-auto';"
        class="wrla-sidebar-resize-bar"
        @mousedown="$event.preventDefault(); if(leftPanelOpen && !dragging){ startX = $event.clientX; dragging = true; }"
        @mousemove.window="() => {
            // If window width lower than mobile, set width to window width
            if (window.innerWidth < 768) {
                leftPanelAttemptedWidth = window.innerWidth;
                return;
            }

            if (dragging) {
                leftPanelAttemptedWidth = leftPanelAttemptedWidth + $event.clientX - startX;
                startX = $event.clientX;

                // Get the min and max width of the left panel
                minW = parseInt(window.getComputedStyle(document.getElementById('left-panel')).getPropertyValue('min-width'));
                maxW = Math.floor(window.innerWidth);

                // If leftPanelWidth is lower than min width or higher than max width, set it to min or max width
                if (leftPanelAttemptedWidth < minW) leftPanelAttemptedWidth = minW;
                if (leftPanelAttemptedWidth > maxW) leftPanelAttemptedWidth = maxW;
            }
        }"
        @mouseup.window="dragging = false;"
    ></div>

    {{-- Inner content wrapper --}}
    <div class="flex flex-col justify-start items-start w-full h-full overflow-hidden">

        {{-- Logo --}}
        <div class="w-full">
            <div class="wrla-sidebar-logo">
                {{-- <img src="{{ asset(config('wr-laravel-administration.logo.light')) }}" title="Light Logo" alt="Light Logo" class="dark:hidden w-full" /> --}}
                <img src="{{ asset(config('wr-laravel-administration.logo.dark')) }}" title="Dark Logo" alt="Dark Logo" class="w-full" />
            </div>
        </div>

        {{-- Divider --}}
        <div class="wrla-sidebar-divider"></div>

        {{-- Impersonating user bar --}}
        @if($WRLAHelper::isImpersonatingUser())
            <div class="wrla-sidebar-impersonate-bar">
                <a href="{{ route('wrla.impersonate.switch-back') }}" class="text-primary-500">
                    <i class="fa fa-key mr-1"></i>
                    Switch back
                </a>
                to {{ $WRLAHelper::getImpersonatingOriginalUser()->name }}
            </div>
        @endif

        {{-- Profile avatar and logged in info --}}
        <div
            :class="leftPanelOpen ? 'flex' : 'hidden';"
            class="wrla-sidebar-profile">
            <div class="w-full min-w-14 max-w-16">
                @themeComponent('forced-aspect-image', [
                    'src' => $user->wrlaUserData?->getProfileAvatar(),
                    'class' => 'rounded-full !border-slate-600',
                    'aspect' => '1/1',
                ])
            </div>
            <div class="flex flex-col text-sm">
                <div class="flex flex-col pb-1">
                    <span class="text-sm">{{ $user->wrlaUserData?->getFullName() }}</span>
                    <span class="text-xs font-semibold text-slate-400">{{ $user->wrlaUserData?->getRole() }}</span>
                </div>
                <span class="flex justify-start items-center gap-2 text-xs">
                    <i class="fa fa-circle text-primary-500" style="font-size: 8px;"></i>
                    <div class="flex gap-2">
                        <span class="text-slate-300">Online</span>
                        <span class="text-slate-400">|</span>
                        <a href="{{ route('wrla.logout') }}" class="text-primary-500">Logout</a>
                    </div>
                </span>
            </div>
        </div>

        {{-- Overflow Y scroll area --}}
        <div class="wrla-sidebar-scroll">

            {{-- Navigation --}}
            <div class="wrla-nav-container">
                @include('wr-laravel-administration::themes.default.layouts.partials.partial-navigation')
            </div>

        </div>
    </div>
</div>
