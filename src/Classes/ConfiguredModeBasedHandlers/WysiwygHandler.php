<?php
namespace WebRegulate\LaravelAdministration\Classes\ConfiguredModeBasedHandlers;

use Illuminate\Http\Request;
use Intervention\Image\ImageManager;
use Illuminate\Support\Facades\Blade;
use Intervention\Image\Drivers\Gd\Driver;
use WebRegulate\LaravelAdministration\Classes\ConfiguredModeBasedHandler;

class WysiwygHandler extends ConfiguredModeBasedHandler
{
    /**
     * Base configuration path
     */
    public function baseConfigurationPath(): string {
        return 'wysiwyg_editors';
    }

    /**
     * Get HTML element, used in wysiwyg.blade.php based on current configuration mode.
     */
    public function getHTMLElement() {
        return match ($this->mode) {
            'tinymce' => 'textarea',
            'quill' => 'div',
            default => 'textarea',
        };
    }

    /**
     * Get view based on current configuration mode.
     */
    public function getWysiwygEditorSetupJS() {
        return match ($this->mode) {
            'tinymce' => $this->getTinyMCESetupJS(),
            'quill' => $this->getQuillSetupJS(),
            default => '',
        };
    }

    /**
     * Whether content should be escaped (Eg. rendered in view with {{  }} or {{!! !!}})
     */
    public function shouldEscapeContent(): bool {
        return match ($this->mode) {
            'tinymce' => true,
            'quill' => false,
            default => false,
        };
    }

    /**
     * Handle uploading of WYSIWYG images based on current configuration mode.
     */
    public function uploadWysiwygImage(Request $request)
    {
        return match ($this->mode) {
            'tinymce' => $this->uploadImageTinyMCE($request),
            'quill' => $this->uploadImageQuill($request),
            default => abort(404, 'Unsupported WYSIWYG editor mode.'),
        };
    }

    /* TINY MCE
    ----------------------------------------------------------------------------*/
    /**
     * Get TinyMCE setup JavaScript.
     */
    public function getTinyMCESetupJS() {
        return Blade::render(<<<'HTML'
            <script src="https://cdn.tiny.cloud/1/{{ $currentWysiwygEditorSettings['apikey'] }}/tinymce/7/tinymce.min.js" referrerpolicy="origin"></script>
            <script>
                tinymce.init({
                    selector: '.wrla_wysiwyg',
                    plugins: '{{ $currentWysiwygEditorSettings["plugins"] }}',
                    menubar: '{{ $currentWysiwygEditorSettings["menubar"] }}',
                    toolbar: '{{ $currentWysiwygEditorSettings["toolbar"] }}',
                    paste_data_images: true,
                    // Keep the underlying textarea in sync on every change so the
                    // capture-phase livewire form sync always reads the latest content
                    // (a submit-time save would run too late and submit stale/empty data).
                    setup: (editor) => {
                        editor.on('change input undo redo', () => editor.save());
                    },
                    // images_upload_url: '{{ route("wrla.upload-wysiwyg-image") }}',
                    images_upload_handler: (blobInfo, progress) => new Promise((resolve, reject) => {
                        var xhr, formData;
                        xhr = new XMLHttpRequest();
                        xhr.withCredentials = false;

                        xhr.open('POST', '{{ route("wrla.upload-wysiwyg-image") }}');
                        var token = document.head.querySelector("[name=csrf-token]").content;
                        xhr.setRequestHeader("X-CSRF-Token", token);

                        xhr.onload = function() {
                            var json;

                            if (xhr.status != 200) {
                                reject('HTTP Error: ' + xhr.status + '. ' + xhr.statusText);
                                return;
                            }

                            json = JSON.parse(xhr.responseText);

                            if (!json || typeof json.location != 'string') {
                                reject('Invalid JSON: ' + xhr.responseText);
                                return;
                            }

                            resolve(json.location);
                        };

                        formData = new FormData();
                        formData.append('image', blobInfo.blob(), blobInfo.filename());

                        xhr.send(formData);
                    }),
                    relative_urls : false,
                    content_style: `{{ config('wr-laravel-administration.wysiwyg_css') }}`,
                });
            </script>
        HTML, [
            'currentWysiwygEditorSettings' => $this->currentConfiguration,
        ]);
    }

    /**
     * Handle image upload for TinyMCE.
     */
    public function uploadImageTinyMCE(Request $request)
    {
        $wysiwygEditorSettings = $this->getCurrentConfiguration();

        if ($request->hasFile('image')) {  // 'image' is the default name TinyMCE sends

            $image = $request->file('image');

            // Intervention image
            $interventionImage = new ImageManager(new Driver);
            $imageInterface = $interventionImage->read($image);

            // If invalid image, return error
            if ($imageInterface === false) {
                return response()->json(['error' => 'File must be an image.'], 400); // Handle errors
            }

            // Limit image to 1000px on either side but keep aspect ratio
            if ($imageInterface->width() > 1000) {
                $imageInterface = $imageInterface->scaleDown(1000, null);
            }
            if ($imageInterface->height() > 1000) {
                $imageInterface = $imageInterface->scaleDown(null, 1000);
            }
            $imageInterface = $imageInterface->encode();

            // Get path
            $publicPath = str_replace('\\', '/', public_path($wysiwygEditorSettings['image_uploads']['path']));

            // If directory doesn't exist, create it
            if (! is_dir($publicPath)) {
                mkdir($publicPath, 0777, true);
            }

            $finalPath = '/'.ltrim((string) $wysiwygEditorSettings['image_uploads']['path'], '/').'/'.$image->hashName();
            $finalPathAbsolute = public_path($finalPath);
            $imageInterface->save($finalPathAbsolute);

            return response()->json(['location' => $finalPath]); // MUST return location key!
        }

        return response()->json(['error' => 'Wysiwyg editor not set to TinyMCE.'], 400); // Handle errors
    }

    /* QUILL
    ----------------------------------------------------------------------------*/
    /**
     * Get Quill setup JavaScript.
     */
    public function getQuillSetupJS()
    {
        return Blade::render(<<<'HTML'
            <link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet" />
            <script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>

            <script>
                document.addEventListener('DOMContentLoaded', () => {
                    const quillElements = document.querySelectorAll('.wrla_wysiwyg');

                    quillElements.forEach((el) => {
                        const quill = new Quill(el, {
                            {!! $currentWysiwygEditorSettings["initialise"] !!}
                        });

                        // Find the parent form
                        const form = el.closest('form');
                        if (!form) return;

                        // Create or reuse a hidden input
                        let elName = el.getAttribute('name');
                        let hidden = form.querySelector('input[type="hidden"][name="' + elName + '"]');
                        if (!hidden) {
                            hidden = document.createElement('input');
                            hidden.type = 'hidden';
                            hidden.name = elName;
                            form.appendChild(hidden);
                        }

                        // Keep the hidden input in sync with the editor at all times. The
                        // livewire form sync reads FormData in the capture phase, which runs
                        // before any bubble-phase submit listener could copy the value in, so
                        // relying on 'submit' alone would submit an empty value and wipe the
                        // field. Seed it now and update on every edit.
                        hidden.value = quill.root.innerHTML;
                        quill.on('text-change', () => {
                            hidden.value = quill.root.innerHTML;
                        });
                    });
                });
            </script>
        HTML, [
            'currentWysiwygEditorSettings' => $this->currentConfiguration,
        ]);
    }


    /**
     * Handle image upload for Quill.
     */
    public function uploadImageQuill(Request $request)
    {
        // Implementation for Quill image upload goes here
    }
}