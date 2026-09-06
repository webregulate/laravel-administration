@foreach($WRLAHelper::getNavigationItems() as $navigationItem)
    {{-- If $navigationItem is null or fails checkCondition then continue --}}
    @continue($navigationItem == null || !$navigationItem->checkShowCondition())
    @php $enabled = $navigationItem->checkEnabledCondition(); @endphp

    {{-- If navigation item does not have children --}}
    @if(!$navigationItem->hasChildren())
        @if($navigationItem->route == 'wrla::html')
            {!! $navigationItem->render() !!}
        @else
            <div class="relative w-full overflow-hidden">
                <a
                    @if($enabled === true) href="{{ $navigationItem->getUrl() }}" @else title="{{ $enabled }}" @endif
                    @if($navigationItem->openInNewTab === true) target="_blank" @endif
                    {{ $navigationItem->getAttributes() }}
                    class="wrla-nav-item @if($WRLAHelper::isNavItemCurrentRoute($navigationItem)) wrla-nav-item-active @endif @if($enabled === true) wrla-nav-item-enabled @else wrla-nav-item-disabled @endif">
                    <div class="wrla-nav-item-icon">
                        <i class="{{ $navigationItem->icon }} text-lg mr-1 @if($navigationItem->isActive()) text-primary-500 @endif"></i>
                    </div>
                    <span class="wrla-nav-item-label" style="top: -1px;">{{ $navigationItem->render() }}</span>
                </a>
            </div>
        @endif
    {{-- If navigation item has children --}}
    @else
        <div x-data="{
            thisActive: {{ $navigationItem->isActive() ? 'true' : 'false' }},
            dropdownOpen: $persist(false).using(sessionStorage).as('nav_' + {{ $navigationItem->index }} + '_open'),
            childIsActive: {{ $navigationItem->isChildActive() ? 'true' : 'false' }}
        }"
            @navigation_item_clicked.window="if($event.detail.except !== {{ $navigationItem->index }}) dropdownOpen = false"
            @click="$dispatch('navigation_item_clicked', { except: {{ $navigationItem->index }} })"
            class="relative w-full overflow-hidden">
            <div class="wrla-nav-group-header">

                {{-- If navigation item has a route --}}
                @if(!empty($navigationItem->route))
                    <a href="{{ $navigationItem->getUrl() }}"
                        @if($navigationItem->openInNewTab === true) target="_blank" @endif
                        {{ $navigationItem->getAttributes() }}
                        :class="{ 'wrla-nav-group-active': !dropdownOpen && thisActive, 'wrla-nav-group-child-active': childIsActive, 'wrla-nav-group-border-closed': !dropdownOpen && (thisActive || childIsActive), 'wrla-nav-group-border-open': dropdownOpen && (thisActive || childIsActive) }"
                        class="wrla-nav-group-trigger">
                        <div class="wrla-nav-item-icon">
                            <i class="{{ $navigationItem->icon }} text-lg mr-1" :class="{ 'text-primary-500': thisActive || childIsActive }"></i>
                        </div>
                        <span class="wrla-nav-item-label" style="top: -1px;">{{ $navigationItem->render() }}</span>
                    </a>
                {{-- If navigation item does not have a route --}}
                @else
                    <span
                        @click="dropdownOpen = !dropdownOpen;"
                        :class="{ 'wrla-nav-group-active': !dropdownOpen && thisActive, 'wrla-nav-group-child-active': childIsActive, 'wrla-nav-group-border-closed': !dropdownOpen && (thisActive || childIsActive), 'wrla-nav-group-border-open': dropdownOpen && (thisActive || childIsActive) }"
                        class="wrla-nav-group-trigger cursor-pointer">
                        <div class="wrla-nav-item-icon">
                            <i class="{{ $navigationItem->icon }} text-lg mr-1" :class="{ 'text-primary-500': thisActive || childIsActive }"></i>
                        </div>
                        <span class="wrla-nav-item-label" style="top: -1px;">{{ $navigationItem->render() }}</span>
                    </span>
                @endif

                {{-- Dropdown arrow --}}
                <div
                    style="z-index: 6;"
                    @click="dropdownOpen = !dropdownOpen;"
                    :class="{ '!text-primary-500': dropdownOpen, 'wrla-nav-group-border-closed': !dropdownOpen && (thisActive || childIsActive), 'wrla-nav-group-border-open': dropdownOpen && (thisActive || childIsActive) }"
                    class="wrla-nav-group-arrow">
                    <i :class="{'fas fa-chevron-right': !dropdownOpen, 'fas fa-chevron-down': dropdownOpen}" class="text-xs mt-1"></i>
                </div>
            </div>

            {{-- Dropdown child list --}}
            <div x-show="dropdownOpen"
                x-transition
                x-transition:enter.duration.100ms
                x-transition:leave.duration.40ms
                class="wrla-nav-group-children" style="border-bottom-color: {{ config('wr-laravel-administration.colors.slate.600') }};">
                @foreach($navigationItem->children as $child)
                    {{-- If $child is null or fails checkCondition then continue --}}
                    @continue($child == null || !$child->checkShowCondition())
                    @php $enabled = $child->checkEnabledCondition(); @endphp
                    <a
                        @if($enabled === true) href="{{ $child->getUrl() }}" @else title="{{ $enabled }}" @endif
                        {{ $child->getAttributes() }}
                        class="wrla-nav-child-item @if($child->isActive()) wrla-nav-child-item-active @endif @if($enabled === true) wrla-nav-child-item-enabled @else wrla-nav-child-item-disabled @endif">
                        <div class="wrla-nav-item-icon">
                            <i class="{{ $child->icon }} text-lg mr-1 @if($child->isActive()) text-primary-500 @endif"></i>
                        </div>
                        <span class="wrla-nav-item-label" style="top: -1px;">{{ $child->render() }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

@endforeach
