@props(['fileSystem' => null, 'publicUrl' => '', 'publicUrlWithoutDomain' => '', 'options' => [], 'label' => null])

@php
    // Set id from name if unset
    $id = empty($attributes->get('id')) ? 'wrinput-'.$attributes->get('name') : $attributes->get('id');

    // Get $name and $value from attribute as it's used alot here
    $name = $attributes->get('name');
    $value = $attributes->get('value');

    // Check if http image
    $isHttpImage = preg_match('/^http(s)?:\/\//', $value);

    // Check that $value exists as an image, if not then we use the $options['defaultImage']
    $src = $fileSystemImageExists ? $publicUrl : $options['defaultImage'];
    $imageExistsHtml = $fileSystemImageExists
        ? '<span class="float-right text-green-500">Image found</span>'
        : '<span class="float-right text-red-500">Image not found</span>';
@endphp

<div wire:ignore class="wrla_image_field {{ $options['containerClass'] ?? 'w-full flex-1 md:flex-auto' }}">

@if(!empty($label))
    @themeComponent('forms.label', [
        'label' => $label,
        'attributes' => Arr::toAttributeBag([
            'for' => $id,
            'class' => $options['labelClass'] ?? ''
        ])
    ])
@endif

<div class="flex justify-start items-center gap-6 mt-2">
    {{-- Preview image --}}
    @if($options['showPreview'] ?? true)
        <div class="{{ $options['previewContainerClass'] ?? '' }}">
            @themeComponent('forced-aspect-image', [
                'src' => $src,
                'originalSrc' => $publicUrlWithoutDomain,
                'class' => "wrla_image_preview {$options['class']} ".($fileSystemImageExists ? '' : 'wrla_no_image'),
                'aspect' => $options["aspect"],
                'objectFit' => $options['objectFit'] ?? 'cover',
            ])
        </div>
    @endif

    {{-- File input and notes container --}}
    <div class="flex flex-1 flex-col justify-center items-center pl-5 pr-10">
        <div class="flex w-full justify-between">
            {{-- File input --}}
            <input {{ $attributes->merge([
                'id' => $id,
                'accept' => 'image/*',
                'class' => 'wrla_image_input text-sm focus:outline-none focus:ring-1 focus:ring-primary-500 dark:focus:ring-primary-500 placeholder-slate-400 dark:placeholder-slate-600'
            ]) }}
                onchange="wrla_setPreviewImage(this)"
            />

            {{-- Remove button (if image does not yet exist anyway, or options has allowRemove true) --}}
            @if(!$fileSystemImageExists || ($fileSystemImageExists && $options['allowRemove'] == true))
                @themeComponent('forms.button', [
                    'size' => 'small',
                    'color' => 'danger',
                    'text' => 'Remove',
                    'icon' => 'fa fa-trash relative text-xs',
                    'attributes' => Arr::toAttributeBag([
                        'type' => 'button',
                        'title' => 'Remove',
                        'class' => 'text-sm',
                        'onclick' => 'wrla_removeImage(this)',
                        'style' => $fileSystemImageExists ? 'display: block;' : 'display: none;'
                    ])
                ])

                <input class="wrla_remove_input" type="hidden" name="wrla_remove_{!! $name !!}" value="false" />
            @endif
        </div>

        {{-- Field notes (if options has notes key) --}}
        @if(!empty($options['notes']))
            @themeComponent('forms.field-notes', ['notes' => $options['notes']])
        @endif

        @if($fileSystemImageExists)
            @themeComponent('forms.field-notes', [
                'notes' => $fileSystemImageExists || (!$isHttpImage && !$fileSystemImageExists)
                    ? '<a href="'.$publicUrl.'" target="_blank" class="underline">'.$publicUrlWithoutDomain.'</a>'.$imageExistsHtml
                    : 'No image set',
                'attributes' => Arr::toAttributeBag([
                    'class' => '!text-xs !px-2 !py-1',
                ])
            ])
        @endif

        {{-- Rotation controls (if allowed) --}}
        @if($options['allowRotation'] ?? true)
            <div class="wrla_rotation_controls w-full flex items-center gap-1.5 mt-3" style="{{ $fileSystemImageExists ? '' : 'display: none;' }}">
                <button type="button" title="Rotate left" onclick="wrla_rotateImage(this, -90)"
                    class="inline-flex items-center justify-center w-8 h-8 rounded-md bg-slate-100 dark:bg-slate-700 border border-slate-300 dark:border-slate-500 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-600 transition-colors">
                    <i class="fa fa-rotate-left text-xs"></i>
                </button>
                <button type="button" title="Rotate right" onclick="wrla_rotateImage(this, 90)"
                    class="inline-flex items-center justify-center w-8 h-8 rounded-md bg-slate-100 dark:bg-slate-700 border border-slate-300 dark:border-slate-500 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-600 transition-colors">
                    <i class="fa fa-rotate-right text-xs"></i>
                </button>
                <span class="wrla_rotation_label text-sm text-slate-500 dark:text-slate-400 ml-1">Rotation: 0&deg;</span>
            </div>

            <input class="wrla_rotation_input" type="hidden" name="wrla_rotation_{!! $name !!}" value="0" />
        @endif
    </div>
</div>

@once
<script>
    function wrla_getImageFieldRoot(el) {
        return el.closest('.wrla_image_field');
    }

    function wrla_applyRotationPreview(root) {
        var previewImageElement = root.querySelector('.wrla_image_preview');
        var rotationInput = root.querySelector('.wrla_rotation_input');
        var rotationLabel = root.querySelector('.wrla_rotation_label');

        if (!rotationInput) {
            return;
        }

        var degrees = parseInt(rotationInput.value, 10) || 0;

        if (previewImageElement) {
            previewImageElement.style.transform = 'rotate(' + degrees + 'deg)';
        }

        if (rotationLabel) {
            rotationLabel.textContent = 'Rotation: ' + degrees + '\u00B0';
        }
    }

    function wrla_resetRotation(root) {
        var rotationInput = root.querySelector('.wrla_rotation_input');
        if (rotationInput) {
            rotationInput.value = '0';
        }
        wrla_applyRotationPreview(root);
    }

    function wrla_showRotationControls(root, show) {
        var controls = root.querySelector('.wrla_rotation_controls');
        if (controls) {
            controls.style.display = show ? 'flex' : 'none';
        }
    }

    function wrla_rotateImage(button, amount) {
        var root = wrla_getImageFieldRoot(button);
        var rotationInput = root.querySelector('.wrla_rotation_input');
        if (!rotationInput) {
            return;
        }

        var degrees = (parseInt(rotationInput.value, 10) || 0) + amount;
        degrees = ((degrees % 360) + 360) % 360;
        rotationInput.value = degrees;

        wrla_applyRotationPreview(root);
    }

    function wrla_setPreviewImage(input) {
        if (input.files && input.files[0]) {
            var root = wrla_getImageFieldRoot(input);
            var previewImageElement = input.parentElement.parentElement.parentElement.querySelector('.wrla_image_preview');

            var reader = new FileReader();
            
            reader.onload = function (e) {
                previewImageElement.src = e.target.result;
                previewImageElement.classList.remove('wrla_no_image');
            }
            
            reader.readAsDataURL(input.files[0]);

            // Show remove button (If exists)
            var removeButton = input.parentElement.querySelector('.wrla_remove_input');
            if (removeButton) {
                removeButton.value = 'false';
                removeButton.parentElement.querySelector('button').style.display = 'block';
            }

            // Reset any previous rotation and reveal the rotation controls
            wrla_resetRotation(root);
            wrla_showRotationControls(root, true);
        }
    }

    function wrla_removeImage(button) {
        var root = wrla_getImageFieldRoot(button);
        var input = button.parentElement.parentElement.querySelector('.wrla_image_input');
        var previewImageElement = input.parentElement.parentElement.parentElement.querySelector('.wrla_image_preview');
        var removeInput = button.parentElement.querySelector('.wrla_remove_input');
        
        input.value = '';
        button.style.display = 'none';
        previewImageElement.src = '{{ $WRLAHelper::getCurrentThemeData('no_image_src') }}';
        previewImageElement.classList.add('wrla_no_image');

        // Reset rotation and hide the rotation controls
        wrla_resetRotation(root);
        wrla_showRotationControls(root, false);

        // Pass $fileSystemImageExists to JS
        var imageExists = @json($fileSystemImageExists);

        // We only need to set the removeInput value to true if a file already exists
        if(imageExists) {
            removeInput.value = 'true';
        }
    }
</script>
@endonce

@error($name)
    @themeComponent('alert', ['type' => 'error', 'message' => $message, 'class' => 'mt-2'])
@enderror

</div>