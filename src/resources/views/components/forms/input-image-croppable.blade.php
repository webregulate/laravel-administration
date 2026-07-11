@props(['fileSystem' => null, 'publicUrl' => '', 'publicUrlWithoutDomain' => '', 'options' => [], 'label' => null])

<link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.js"></script>

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

<div wire:ignore class="{{ $options['containerClass'] ?? 'w-full flex-1 md:flex-auto' }}">

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
    {{-- Preview image container --}}
    <div class="{{ $options['previewContainerClass'] ?? 'w-2/12'  }}">
        @themeComponent('forced-aspect-image', [
            'src' => $src,
            'originalSrc' => $publicUrlWithoutDomain,
            'class' => "wrla_image_preview {$options['class']} ".($fileSystemImageExists ? '' : 'wrla_no_image'),
            'aspect' => $options["aspect"],
            'objectFit' => $options['objectFit'] ?? 'cover',
            'attributes' => Arr::toAttributeBag([
                'id' => 'croppedImagePreview',
            ]),
        ])
    </div>

    {{-- File input and notes container --}}
    <div class="flex flex-1 flex-col justify-center items-center pl-5 pr-10">
        <div class="flex w-full justify-between">
            {{-- File input --}}
            <input {{ $attributes->merge([
                'id' => 'imageInput',
                'accept' => 'image/*',
                'class' => 'wrla_image_input text-sm focus:outline-none focus:ring-1 focus:ring-primary-500 dark:focus:ring-primary-500 placeholder-slate-400 dark:placeholder-slate-600',
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
            <div id="wrla_croppable_rotation_controls" class="w-full flex items-center gap-1.5 mt-3" style="{{ $fileSystemImageExists ? '' : 'display: none;' }}">
                <button type="button" title="Rotate left" data-rotate="-90"
                    class="wrla_rotate_btn inline-flex items-center justify-center w-8 h-8 rounded-md bg-slate-100 dark:bg-slate-700 border border-slate-300 dark:border-slate-500 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-600 transition-colors">
                    <i class="fa fa-rotate-left text-xs"></i>
                </button>
                <button type="button" title="Rotate right" data-rotate="90"
                    class="wrla_rotate_btn inline-flex items-center justify-center w-8 h-8 rounded-md bg-slate-100 dark:bg-slate-700 border border-slate-300 dark:border-slate-500 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-600 transition-colors">
                    <i class="fa fa-rotate-right text-xs"></i>
                </button>
                <span id="wrla_croppable_rotation_label" class="text-sm text-slate-500 dark:text-slate-400 ml-1">Rotation: 0&deg;</span>
            </div>

            <input id="wrla_croppable_rotation_input" type="hidden" name="wrla_rotation_{!! $name !!}" value="0" />
        @endif
    </div>
</div>

{{-- Cropper JS preview / cropper area --}}
<div id="imageToCropContainer" class="flex justify-center items-center mt-4 p-3 bg-slate-200 dark:bg-slate-900 rounded-md">
    <div style="width: 100%; max-width: 600px; margin-auto;">
        <img id="imageToCrop" src="" alt="Image to crop" style="max-width: 100%;">
    </div>
</div>

@once
<script>
    function wrla_setPreviewImage(input) {
        if (input.files && input.files[0]) {
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
        }
    }

    function wrla_removeImage(button) {
        var input = button.parentElement.parentElement.querySelector('.wrla_image_input');
        var previewImageElement = input.parentElement.parentElement.parentElement.querySelector('.wrla_image_preview');
        var removeInput = button.parentElement.querySelector('.wrla_remove_input');
        
        input.value = '';
        button.style.display = 'none';
        previewImageElement.src = '{{ $WRLAHelper::getCurrentThemeData('no_image_src') }}';
        previewImageElement.classList.add('wrla_no_image');
        previewImageElement.style.transform = 'none';

        // Reset the rotation controls (if present)
        var rotationControls = document.getElementById('wrla_croppable_rotation_controls');
        var rotationInput = document.getElementById('wrla_croppable_rotation_input');
        var rotationLabel = document.getElementById('wrla_croppable_rotation_label');
        if (rotationControls) { rotationControls.style.display = 'none'; }
        if (rotationInput) { rotationInput.value = '0'; }
        if (rotationLabel) { rotationLabel.textContent = 'Rotation: 0\u00B0'; }

        // Pass $fileSystemImageExists to JS
        var imageExists = @json($fileSystemImageExists);

        // We only need to set the removeInput value to true if a file already exists
        if(imageExists) {
            removeInput.value = 'true';
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.querySelector('#upsert-form');
        const imageInput = document.getElementById('imageInput');
        const imageToCrop = document.getElementById('imageToCrop');
        const imageToCropContainer = document.getElementById('imageToCropContainer');
        const croppedImagePreview = document.getElementById('croppedImagePreview');

        // Hide preview om start
        imageToCropContainer.style.display = 'none';

        let cropper;

        // Rotation controls (Option B): when a cropper is active (new upload) the
        // rotation is baked into the cropped canvas via Cropper.js, so it's fully
        // WYSIWYG and no server-side rotation is needed. When there's no cropper
        // (rotating an already-stored image) we defer to the inherited server-side
        // rotateExistingFile() by carrying the angle in the wrla_rotation_ input.
        const rotationControls = document.getElementById('wrla_croppable_rotation_controls');
        const rotationInput = document.getElementById('wrla_croppable_rotation_input');
        const rotationLabel = document.getElementById('wrla_croppable_rotation_label');

        function wrla_croppable_updateRotationLabel(degrees) {
            if (rotationLabel) {
                rotationLabel.textContent = 'Rotation: ' + degrees + '\u00B0';
            }
        }

        function wrla_croppable_handleRotate(amount) {
            // New upload: rotation is baked into the cropper output canvas.
            if (cropper) {
                cropper.rotate(amount);
                updatePreview();
                return;
            }

            // Existing stored image: accumulate the angle in the hidden input and
            // preview it with a CSS transform. The server rotates the stored file.
            var current = parseInt(rotationInput ? rotationInput.value : '0', 10) || 0;
            current = (((current + amount) % 360) + 360) % 360;
            if (rotationInput) {
                rotationInput.value = current;
            }
            wrla_croppable_updateRotationLabel(current);
            if (croppedImagePreview) {
                croppedImagePreview.style.transform = 'rotate(' + current + 'deg)';
            }
        }

        if (rotationControls) {
            rotationControls.querySelectorAll('.wrla_rotate_btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    wrla_croppable_handleRotate(parseInt(btn.getAttribute('data-rotate'), 10) || 0);
                });
            });
        }

        let updatePreview = function() {
            // Get the cropped canvas
            const canvas = cropper.getCroppedCanvas({
                width: 500, // Set desired width
                height: 500, // Set desired height
            });

            // Convert canvas to a Blob
            canvas.toBlob(function (blob) {
                // Create a URL for the blob and set it as the src of the preview image
                const url = URL.createObjectURL(blob);
                croppedImagePreview.src = url;
                croppedImagePreview.style.display = 'block'; // Show the preview
            }, 'image/png'); // Specify the output format
        }

        imageInput.addEventListener('change', function (e) {
            const files = e.target.files;
            if (files && files.length > 0) {
                const file = files[0];
                const reader = new FileReader();

                // Show image preview on crop
                imageToCropContainer.style.display = 'flex';

                // New upload: rotation is handled by Cropper.js, so clear any pending
                // server-side rotation and reset the existing-image preview transform.
                if (rotationInput) {
                    rotationInput.value = '0';
                }
                wrla_croppable_updateRotationLabel(0);
                croppedImagePreview.style.transform = 'none';
                if (rotationControls) {
                    rotationControls.style.display = 'flex';
                }

                // Calculated aspect ratio from setting, is a string with format "1/2" or "16/9" etc, needs to be calculated
                let aspectRatioCalculation = @js($options['aspect']);
                if (aspectRatioCalculation) {
                    const parts = aspectRatioCalculation.split('/');
                    try {
                        aspectRatioCalculation = parseFloat(parts[0]) / parseFloat(parts[1]);
                    }
                    catch (error) {
                        console.error('Invalid aspect ratio:', aspectRatioCalculation, error);
                        aspectRatioCalculation = 1; // Default to 1 if not valid
                    }
                } else {
                    aspectRatioCalculation = null; // Default to 1 if not set
                }

                reader.onload = function (event) {
                    imageToCrop.src = event.target.result;

                    // Destroy previous cropper instance if it exists
                    if (cropper) {
                        cropper.destroy();
                    }

                    // Initialize Cropper.js
                    cropper = new Cropper(imageToCrop, {
                        aspectRatio: aspectRatioCalculation,
                        viewMode: 1,
                        autoCropArea: 1,
                        // Add other Cropper.js options here

                        // Update preview on ready and cropend
                        ready: () => {
                            updatePreview();
                        },
                        cropend: () => updatePreview(),
                    });
                };

                reader.readAsDataURL(file);
            }
        });

        // Override standard form submission
        form.addEventListener('submit', function (e) {
            try {
                if (!cropper) return; // No cropper, let it submit normally
    
                e.preventDefault(); // Stop it briefly while we insert the cropped file
    
                cropper.getCroppedCanvas().toBlob(function (blob) {
                    const file = new File([blob], 'cropped.png', { type: 'image/png' });
    
                    // Create a DataTransfer to simulate file input
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(file);
    
                    // Replace the hidden file input's files
                    imageInput.files = dataTransfer.files;
    
                    form.submit(); // Now submit the form normally
                }, 'image/png');
            } catch (error) {
                alert('Error during form submission:' + error);
            }
        });
    });
</script>
@endonce

@error($name)
    @themeComponent('alert', ['type' => 'error', 'message' => $message, 'class' => 'mt-2'])
@enderror

</div>