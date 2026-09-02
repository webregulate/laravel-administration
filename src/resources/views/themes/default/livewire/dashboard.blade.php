<div>
    {{-- Title --}}
    @themeComponent('heading', [
        'title' => 'Dashboard',
        'icon' => 'fa-solid fa-tachometer-alt',
    ])

    {{-- Notifications widget --}}
    <div>
        @livewire('wrla.notifications-widget', [
            'userIds' => config('wr-laravel-administration.dashboard.notifications.user_groups'),
        ])
    </div>
</div>
