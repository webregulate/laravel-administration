<div>
    <div class="relative pb-6">
        {{-- Title --}}
        @themeComponent('heading', [
            'title' => 'File Manager',
            'icon' => 'fa-solid fa-folder',
        ])

        {{-- Refresh button --}}
        @themeComponent('forms.button', [
            'type' => 'button',
            'size' => 'small',
            'text' => 'Refresh',
            'icon' => 'fas fa-sync-alt',
            'attributes' => Arr::toAttributeBag([
                'class' => 'absolute top-0 right-0 mt-2',
                'wire:click' => 'refresh',
            ])
        ])
    </div>

    {{-- Loop through any errors and display --}}
    @if($errors->any())
        <div class="mb-4">
            @foreach($errors->all() as $error)
                @themeComponent('alert', ['type' => 'error', 'message' => $error])
            @endforeach
        </div>
    @endif

    <div class="flex justify-between items-center gap-3 mb-4">
        <div class="flex-1">
            <p class="w-full text-lg px-2 font-normal text-slate-600 dark:!text-slate-400 border-b border-slate-400">
                {{-- If multiple file systems available, display "filesystem" here to show all --}}
                @if(count($fileSystemNames) > 1)
                    <span class="text-slate-700 font-semibold cursor-pointer hover:underline" wire:click="switchFileSystem('')">file systems</span>
                    @if(!empty($currentFileSystemName))
                        /
                    @endif
                @endif

                {{-- Current filesystem --}}
                <span class="text-sky-600 font-bold cursor-pointer hover:underline" wire:click="switchDirectory('')">{{ $currentFileSystemName }}</span>
    
                {{-- Directory paths --}}
                @php $filePathParts = []; @endphp
                @foreach(explode('/', ltrim($fullFilePath, '/')) as $filePathPart)
                    @php $filePathParts[] = $filePathPart; @endphp
                    /
                    @if(!$loop->last)
                        <span type="button" wire:click="switchDirectory('{{ implode('.', $filePathParts) }}')" class="cursor-pointer hover:underline">{{ $filePathPart }}</span>
                    @else
                        <span class="text-slate-700 dark:!text-slate-400">{{ $filePathPart }}</span>
                    @endif
                @endforeach
            </p>
        </div>
        <div class="flex items-center gap-2 whitespace-nowrap">
            {{-- Loading spinner --}}
            <div wire:loading.flex class="justify-end items-center gap-2 text-base" style="line-height: 0px;">
                <i class="fas fa-spinner animate-spin inline-block"></i>
                <span>Loading...</span>
            </div>

            <button
                x-on:click="if(newDirectoryName = window.prompt('Name of new directory', 'directory_name')) {
                    $wire.dispatchSelf('createDirectory', {
                        'newDirectoryName': newDirectoryName,
                    });
                }"
                class="flex justify-center items-center gap-1 w-fit px-2 text-[14px] !h-[22.6px] font-semibold border bg-primary-600 dark:bg-primary-800 text-white dark:text-slate-200 hover:brightness-110 border-teal-500 dark:border-teal-600 shadow-slate-400 dark:shadow-slate-700 rounded-md shadow-sm whitespace-nowrap cursor-pointer"
            >
                <i class="fas fa-folder-plus text-xs mr-1"></i>
                Create directory
            </button>

            <label
                for="fileUpload"
                x-on:click="$wire.set('uploadFilePath', '{{ trim(str_replace('.', '/', $viewingDirectory), '/') }}')"
                class="flex justify-center items-center gap-1 w-fit px-2 text-[14px] !h-[22.6px] font-semibold border bg-primary-600 dark:bg-primary-800 text-white dark:text-slate-200 hover:brightness-110 border-teal-500 dark:border-teal-600 shadow-slate-400 dark:shadow-slate-700 rounded-md shadow-sm whitespace-nowrap cursor-pointer"
            >
                <i class="fas fa-upload text-xs mr-1"></i>
                Upload file
            </label>
            <input
                id="fileUpload"
                type="file"
                wire:model.live="uploadFile"
                accept="*/*"
                class="hidden" />
        </div>
    </div>
    
    <div class="relative flex items-start gap-4">
        
        {{-- Items display --}}
        <div class="w-full flex flex-col" style="@if($viewingItemType !== null && $highlightedItem !== null) width: 62%; @endif">

            {{-- File systems --}}
            @if(empty($currentFileSystemName))
                
                @foreach($fileSystemNames as $fileSystemName)
                    <div
                        class="{{ $highlightedItem !== null && $highlightedItem == $fileSystemName ? '!bg-slate-50 dark:!bg-slate-800 !border-primary-500 !border-2 !font-bold' : '' }} w-full flex justify-between items-center gap-2 px-3 h-12 text-lg bg-gray-100 dark:bg-slate-800 first:rounded-t-md last:rounded-b-md hover:bg-white dark:hover:!bg-slate-700 text-slate-700 dark:text-white even:!bg-opacity-60 dark:even:!bg-opacity-60 whitespace-nowrap truncate">
                        
                        <div
                            wire:click="switchFileSystem('{{ $fileSystemName }}')"
                            class="w-full h-full flex items-center gap-2 cursor-pointer">
                            <div class="text-center">
                                <i class="fas fa-folder text-sky-600 mr-1.5"></i>
                            </div>
                            <div>{{ $fileSystemName }}</div>
                        </div>
                    </div>
                @endforeach

            {{-- Directories / Files list --}}
            @else

                {{-- Directories --}}
                @foreach(array_merge((!empty($viewingDirectory) ? ['..'] : []), $currentDirectories) as $directory)
                    <div
                        class="{{ $highlightedItem !== null && $highlightedItem == $directory ? '!bg-slate-50 dark:!bg-slate-800 !border-primary-500 !border-2 !font-bold' : '' }} w-full flex justify-between items-center gap-2 px-3 h-12 text-lg bg-gray-100 dark:!bg-slate-800 first:rounded-t-md last:rounded-b-md hover:bg-white dark:hover:!bg-slate-700 text-slate-700 dark:text-white even:!bg-opacity-60 dark:even:!bg-opacity-60 whitespace-nowrap truncate">
                        
                        <div
                            @if($directory == '..')
                                wire:click="switchDirectory('..')"
                            @else
                                wire:click="switchDirectory('{{ (empty($viewingDirectory) ? '' : $viewingDirectory.'.').$directory }}')"
                                title="{{ ltrim((empty($viewingDirectory) ? '' : str_replace('.', '/', $viewingDirectory).'/').$directory, '/') }}"
                            @endif
                            class="w-full h-full flex items-center gap-2 cursor-pointer">
                            <div class="text-center">
                                <i class="fas fa-folder text-amber-400 mr-1.5"></i>
                            </div>
                            <div>{{ $directory }}</div>
                        </div>
        
                        <div class="flex justify-end items-center gap-2">
                            {{-- If is valid directory --}}
                            @if($directory != '..')
                                {{-- Delete button --}}
                                @themeComponent('forms.button', [
                                    'type' => 'button',
                                    'size' => 'small',
                                    'color' => 'danger',
                                    'text' => 'Delete',
                                    'icon' => 'fas fa-trash text-xs leading-0',
                                    'attributes' => Arr::toAttributeBag([
                                        'title' => 'Delete directory and all contents',
                                        'class' => '!py-0 !leading-0 !h-[22.6px]',
                                        // 'wire:click' => "deleteFile('$viewingDirectory', '".(is_array($directoryOrFile) ? $key : $directoryOrFile)."')",
                                        // Rather than wire:click, use x-on:click to first confirm deletion, and then call the method
                                        'x-on:click' => "if(confirm('Are you sure you want to delete this directory and all of its contents?')) {
                                            \$wire.dispatchSelf('deleteDirectory', {
                                                'directoryPath': '$viewingDirectory',
                                                'name': '$directory'
                                            });
                                        }"
                                    ])
                                ])
                            @endif
                        </div>
                    </div>
                @endforeach

                {{-- Files --}}
                @foreach($currentFiles as $file)
                    <div
                        class="{{ $highlightedItem !== null && $highlightedItem == $file ? '!bg-slate-50 dark:!bg-slate-800 !border-primary-500 !border-2 !font-bold' : '' }} w-full relative flex justify-between items-center gap-2 px-3 h-12 text-lg bg-gray-100 dark:bg-slate-800 first:rounded-t-md last:rounded-b-md hover:bg-white dark:hover:!bg-slate-700 text-slate-700 dark:text-white even:!bg-opacity-60 dark:even:!bg-opacity-60 whitespace-nowrap truncate">
                        
                        <div
                            wire:click="viewFile('{{ $viewingDirectory }}', '{{ $file }}')"
                            title="{{ ltrim(str_replace('.', '/', $viewingDirectory).'/'.$file, '/') }}"
                            class="w-full h-full flex items-center gap-2 cursor-pointer">
                            <div class="text-center">
                                <i class="fas fa-file text-primary-500 mr-1.5"></i>
                            </div>
                            <div>{{ $file }}</div>
                        </div>
        
                        <div class="sticky right-0 bg-inherit flex justify-end items-center gap-2 {{ $loop->even ? 'even:!bg-opacity-60' : '' }}">
                            <label
                                for="fileReplace"
                                title="Replace file"
                                x-on:click="$wire.set('replaceFilePath', '{{ trim(trim(str_replace('.', '/', $viewingDirectory), '/').'/'.$file, '/') }}')"
                                class="flex justify-center items-center gap-1 w-fit px-2 text-[14px] !h-[22.6px] font-semibold border bg-primary-600 dark:bg-primary-800 text-white dark:text-slate-200 hover:brightness-110 border-teal-500 dark:border-teal-600 shadow-slate-400 dark:shadow-slate-700 rounded-md shadow-sm whitespace-nowrap cursor-pointer"
                            >
                                <i class="fas fa-upload text-xs mr-1"></i>
                                Replace
                            </label>
                            <input
                                id="fileReplace"
                                type="file"
                                wire:model.live="replaceFile"
                                accept="*/*"
                                class="hidden" />

                            {{-- Delete button --}}
                            @themeComponent('forms.button', [
                                'type' => 'button',
                                'size' => 'small',
                                'color' => 'danger',
                                'text' => 'Delete',
                                'icon' => 'fas fa-trash text-xs leading-0',
                                'attributes' => Arr::toAttributeBag([
                                    'title' => 'Delete file',
                                    'class' => '!py-0 !leading-0 !h-[22.6px]',
                                    'x-on:click' => "if(confirm('Are you sure you want to delete this file?')) {
                                        \$wire.dispatchSelf('deleteFile', {
                                            'directoryPath': '$viewingDirectory',
                                            'name': '$file'
                                        });
                                    }"
                                ])
                            ])
                        </div>
                    </div>
                @endforeach

            @endif

        </div>

        {{-- If file system set --}}
        @if($currentFileSystemName !== null)
    
            @if($viewingItemType !== null && $highlightedItem !== null)
                <div class="sticky top-12" style="width: 38%;">
                    {{-- Preview text --}}
                    <p class="mb-3 px-2 text-sm text-slate-500 border-b border-slate-400">
                        <i class="fas fa-eye mr-1 text-slate-400 text-xs"></i>
                        Preview {{ $viewingItemType }}
                    </p>

                    {{-- Text --}}
                    @if($viewingItemType == 'text' || $viewingItemType == 'error')
                        @themeComponent('forms.textarea', [
                            'label' => null,
                            'options' => [],
                            'attributes' => Arr::toAttributeBag([
                                'readonly' => true,
                                'wire:model' => 'viewingItemContent',
                                'name' => 'log_content',
                                'class' => '!bg-slate-100 dark:!bg-slate-800 h-64',
                            ])
                        ])
                    {{-- Image --}}
                    @elseif($viewingItemType == 'image')
                        <div class="w-full flex justify-center items-center">
                            <img src="{{ $viewingItemContent }}" alt="{{ $highlightedItem }}" class="w-full max-w-full">
                        </div>
                    {{-- Video --}}
                    @elseif($viewingItemType == 'video')
                        <div class="w-full flex justify-center items-center">
                            <video controls class="max-w-full max-h-64">
                                <source src="{{ $viewingItemContent }}" type="video/mp4">
                                Your browser does not support the video tag.
                            </video>
                        </div>
                    @else
                        <div class="w-full flex justify-center items-center mt-3">
                            <i class="fas fa-info-circle text-slate-400 mr-1"></i>
                            {{ $viewingItemType }} preview not available.
                        </div>
                    @endif

                    @if(!empty($viewingItemData))
                        <div class="w-full pt-5 text-sm text-slate-600 dark:!text-slate-400">
                            <i class="fas fa-info-circle text-slate-400 mr-1"></i>
                            {!! $viewingItemData !!}
                        </div>
                    @endif

                    {{-- Public URL (clickable) and absolute path (greyed text), shown for any file type --}}
                    @if(!empty($viewingItemPublicUrl))
                        <div class="w-full pt-5 text-sm text-slate-600 dark:!text-slate-400">
                            <p>
                                <i class="fas fa-link text-slate-400 mr-1"></i>
                                Public URL:
                            </p>
                            <a href="{{ $viewingItemPublicUrl }}" target="_blank" class="block w-full text-sm text-sky-600 hover:underline" style="overflow-wrap: break-word;">
                                {{ $viewingItemPublicUrl }}
                            </a>
                        </div>
                    @endif

                    @if(!empty($fullAbsoluteFilePath))
                        <div class="w-full pt-2 text-xs text-slate-400 dark:!text-slate-500">
                            <i class="fas fa-folder-open mr-1"></i>
                            Absolute path:
                            <span class="block" style="overflow-wrap: break-word;">{{ $fullAbsoluteFilePath }}</span>
                        </div>
                    @endif

                </div>
            @endif

        @endif
    </div>

    <div class="block w-full h-6"></div>

    {{-- If no file selected / found --}}
    @if($viewingItemType === null || $highlightedItem === null)

        <div class="w-full text-center text-lg text-slate-700 dark:text-white">
            <i class="fas fa-info-circle text-slate-400 mr-1"></i>
            No file selected / found
        </div>

    {{-- If file system not set --}}
    @elseif($currentFileSystemName === null)
        
        <div class="w-full text-center text-lg text-slate-700 dark:text-white">
            <i class="fas fa-info-circle text-slate-400 mr-1"></i>
            No file system found or selected.<br />
            <br />
            Set within wr-laravel-administration.file_manager config.
        </div>

    @endif
</div>