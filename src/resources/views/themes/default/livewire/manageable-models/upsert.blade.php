<div>
    @themeComponent('forms.button', [
        'href' => $manageableModelClass::urlBrowse(),
        'text' => $manageableModelClass::getDisplayName(true),
        'size' => 'small',
        'color' => 'primary',
        'icon' => 'fa fa-arrow-left',
    ])

    <br />

    {{-- Heading --}}
    <div class="flex justify-between">
        <div class="text-xl font-semibold">
            @if(!empty($overrideTitle))
                {{ $overrideTitle }}
            @else
                @if($manageableModel->model()->id == null)
                    Creating new {{ $manageableModel->getDisplayName() }}
                @else
                    Editing {{ $manageableModel->getDisplayName() }} #{{ $manageableModel->model()->id }}
                @endif
            @endif
        </div>

        <div class="flex justify-end gap-2 !text-sm">
            @foreach($manageableModel->getInstanceActionsFinal() as $key => $instanceAction)
                @continue($key == 'edit')
                {!! $instanceAction?->render() ?? '' !!}
            @endforeach
        </div>
    </div>

    {{-- Form --}}
    <form
        id="upsert-form"
        autocomplete="off"
        wire:submit="save"
        class="w-full"
    >
        <div class="flex flex-wrap gap-6 mt-4 p-4 bg-slate-100 dark:bg-slate-800 dark:border-slate-700 border shadow-slate-300 dark:shadow-slate-850 rounded-lg shadow-lg">
            @if(!empty($manageableFields))
                @foreach($manageableFields as $manageableField)
                    {!! $manageableField->renderParent($upsertType, $livewireData) !!}
                @endforeach
            @else
                <div class="text-slate-600 my-3" style="line-height: 2rem;">
                    <b class="font-medium text-primary-600">
                        <i class="fa fa-info-circle mr-0.5"></i>
                        No Manageable Fields found
                    </b><br />
                    Add Manageable Fields for this model in the <b class="font-medium text-primary-600">{{ $manageableModel::class }} -> getManageableFields()</b> method
                </div>
            @endif

            {{-- Display model created / update / deleted datetimes --}}
            @if(!empty($manageableModel->model()->id))
                <div class="w-full flex justify-end items-center gap-3 text-sm text-slate-500">
                    @php
                        $model = $manageableModel->model();
                        $createdAtColumn = method_exists($model, 'getCreatedAtColumn') ? $model->getCreatedAtColumn() : 'created_at';
                        $updatedAtColumn = method_exists($model, 'getUpdatedAtColumn') ? $model->getUpdatedAtColumn() : 'updated_at';
                        $deletedAtColumn = method_exists($model, 'getDeletedAtColumn') ? $model->getDeletedAtColumn() : 'deleted_at';

                        $displayAts = [
                            '<i class="fa fa-plus mr-0.5 opacity-70"></i> Created' => data_get($model, $createdAtColumn),
                            '<i class="fa fa-edit mr-0.5 opacity-70"></i> Last Updated' => data_get($model, $updatedAtColumn),
                            '<i class="fa fa-trash mr-0.5 opacity-70"></i> Deleted' => data_get($model, $deletedAtColumn),
                        ];

                        $displayAts = array_filter($displayAts);
                    @endphp

                    {{-- Display delimited key: datetimes --}}
                    @foreach($displayAts as $key => $value)
                        @php
                            $formattedValue = $value instanceof \Carbon\CarbonInterface
                                ? $value->format('Y-m-d H:i')
                                : (string) $value;
                        @endphp

                        <span>
                            {!! $key !!}: {{ $formattedValue }}
                            @if(!$loop->last) <span class="mx-2">|</span> @endif
                        </span>
                    @endforeach

                    @php
                        unset($model, $createdAtColumn, $updatedAtColumn, $deletedAtColumn, $displayAts, $formattedValue);
                    @endphp
                </div>
            @endif
        </div>

        {{-- Generic error message --}}
        @error('error')
            @themeComponent('alert', ['type' => 'error', 'message' => $message])
        @enderror

        {{-- Inline success message (shown after a successful livewire save, no page refresh) --}}
        @if(!empty($successMessage))
            <div class="mt-10">
                @themeComponent('alert', ['type' => 'success', 'message' => $successMessage])
            </div>
        @endif

        <div class="flex justify-center gap-4 mt-10">
            @themeComponent('forms.button', [
                'type' => 'submit',
                'size' => 'medium',
                'color' => 'primary',
                'text' => 'Save',
                'icon' => 'fa fa-edit',
                'attributes' => Arr::toAttributeBag([
                    'wire:target' => 'save',
                ]),
            ])

            @themeComponent('forms.button', [
                'href' => $manageableModelClass::urlBrowse(),
                'text' => $manageableModelClass::getDisplayName(true),
                'size' => 'medium',
                'color' => 'secondary',
                'icon' => 'fa fa-arrow-left',
            ])
        </div>

    </form>

    @if($WRLAHelper::userIsDev())
        <div class="border border-slate-300 rounded-md p-2 mt-10 text-slate-500">
            <p class=" text-sm font-semibold">Debug Information:</p>
            <hr class="my-1 border-slate-300">
            Upsert version: 0.8.8 <br />
            Render counter: {{ $numberOfRenders }}<br />
            Livewire data ({{ count($livewireData) }}):<br />
            @foreach($livewireData as $key => $value)
                {{ $key }}: <b class="font-medium">{{ is_scalar($value) || $value === null ? $value : '['.gettype($value).']' }}</b><br />
            @endforeach
        </div>
    @endif

    {{-- Gap --}}
    <div class="block h-24"></div>
</div>

{{-- Sync all native (non-file) form inputs into this component's livewireData just
    before the wire:submit handler runs, so save() receives them via the rebuilt
    request. This covers composite fields (multi-image / multi-field) whose nested
    livewire components write native inputs, as well as standard fields. File
    inputs are handled separately as native livewire uploads. --}}
@once
@push('append-body')
<script>
    if (!window.wrlaSyncFormToLivewire) {
        // Parse an input name (supporting bracket notation) into a path array.
        // e.g. "field_groups[0][key]" -> ["field_groups", "0", "key"], "tags[]" -> ["tags", ""].
        window.wrlaParseInputName = function (name) {
            var path = [];
            var match = name.match(/^[^\[\]]+/);
            if (!match) return [name];
            path.push(match[0]);
            var rest = name.slice(match[0].length);
            var re = /\[([^\[\]]*)\]/g;
            var m;
            while ((m = re.exec(rest)) !== null) {
                path.push(m[1]);
            }
            return path;
        };

        // Assign a value into a nested object/array structure following the path.
        window.wrlaAssignPath = function (root, path, value) {
            var node = root;
            for (var i = 0; i < path.length; i++) {
                var key = path[i];
                var last = i === path.length - 1;

                if (key === '') {
                    // Push semantics for "name[]" (defensive: only if node is an array).
                    if (!Array.isArray(node)) {
                        continue;
                    }
                    if (last) {
                        node.push(value);
                    } else {
                        node.push({});
                        node = node[node.length - 1];
                    }
                    continue;
                }

                if (last) {
                    node[key] = value;
                } else {
                    if (node[key] === undefined || node[key] === null) {
                        node[key] = {};
                    }
                    node = node[key];
                }
            }
        };

        // Serialize all native, non-file inputs of the form into livewireData.
        window.wrlaSyncFormToLivewire = function (form, wire) {
            if (!form || !wire) return;

            var grouped = {};
            var data = new FormData(form);

            data.forEach(function (value, name) {
                // Files are handled as native livewire uploads, not serialized here.
                if (value instanceof File) return;

                var path = window.wrlaParseInputName(name);

                // Initialise the top-level container based on whether it is nested.
                if (grouped[path[0]] === undefined) {
                    grouped[path[0]] = path.length > 1 ? {} : value;
                }

                if (path.length > 1) {
                    window.wrlaAssignPath(grouped, path, value);
                } else {
                    grouped[path[0]] = value;
                }
            });

            Object.keys(grouped).forEach(function (topKey) {
                wire.set('livewireData.' + topKey, grouped[topKey], false);
            });
        };
    }

    if (!window.wrlaUpsertSubmitSyncBound) {
        window.wrlaUpsertSubmitSyncBound = true;

        // Capture-phase: sync native inputs before livewire's wire:submit handler
        // runs. Does not preventDefault, so livewire still performs the save.
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || form.id !== 'upsert-form') return;

            var root = form.closest('[wire\\:id]');
            if (!root || !window.Livewire) return;

            var wire = window.Livewire.find(root.getAttribute('wire:id'));
            if (!wire) return;

            // Mark that an upsert save was just submitted so the livewire commit hook
            // knows to scroll to the first validation error if the save is rejected.
            window.wrlaUpsertSubmitPending = true;

            // Let fields flush any external state into their native form controls
            // before FormData is read (eg. WYSIWYG editors whose content lives in an
            // iframe/hidden element). Editors register callbacks in this registry from
            // their own setup JS, keeping this page editor-agnostic.
            if (Array.isArray(window.wrlaBeforeFormSync)) {
                window.wrlaBeforeFormSync.forEach(function (fn) {
                    try { fn(form); } catch (err) { /* ignore individual hook failures */ }
                });
            }

            window.wrlaSyncFormToLivewire(form, wire);
        }, true);
    }

    if (!window.wrlaFastSmoothScroll) {
        // Fast, custom smooth scroll (native behavior:'smooth' speed is not
        // controllable). Animates scrollTop of the given scroller (or the window)
        // to `top` over `duration` ms using ease-out.
        window.wrlaFastSmoothScroll = function (scroller, top, duration) {
            duration = duration || 250;
            var isWindow = !scroller;
            var start = isWindow ? window.pageYOffset : scroller.scrollTop;
            var change = top - start;
            if (change === 0) return;
            var startTime = null;

            var step = function (now) {
                if (startTime === null) startTime = now;
                var elapsed = now - startTime;
                var t = Math.min(elapsed / duration, 1);
                var eased = 1 - Math.pow(1 - t, 3); // ease-out cubic
                var pos = start + change * eased;
                if (isWindow) {
                    window.scrollTo(0, pos);
                } else {
                    scroller.scrollTop = pos;
                }
                if (t < 1) requestAnimationFrame(step);
            };

            requestAnimationFrame(step);
        };
    }

    if (!window.wrlaScrollToFirstError) {
        // Find the nearest scrollable ancestor. The admin layout scrolls inside a
        // container div (its overflow-x-auto forces overflow-y to auto), not the
        // window, so window.scrollTo would do nothing.
        window.wrlaGetScrollParent = function (node) {
            var el = node ? node.parentElement : null;
            while (el) {
                var style = window.getComputedStyle(el);
                var oy = style.overflowY;
                if ((oy === 'auto' || oy === 'scroll' || oy === 'overlay') &&
                    el.scrollHeight > el.clientHeight) {
                    return el;
                }
                el = el.parentElement;
            }
            return null; // fall back to the window
        };

        // Smooth-scroll so the topmost error message sits a little below the top of
        // its scroll area, giving context to the field it relates to. Covers the
        // various ways errors are rendered (error alerts, inline red text, upload errors).
        window.wrlaScrollToFirstError = function () {
            var selectors = [
                '.alert .bg-red-100',      // themed error alert (field + generic errors)
                'p.text-red-500',          // inline errors (checkbox / button fields)
                '.border-red-400.bg-red-50' // multi-image / upload errors
            ];

            var target = null;
            var targetTop = Infinity;

            document.querySelectorAll(selectors.join(',')).forEach(function (el) {
                // Skip anything not currently rendered (eg. hidden alerts).
                if (el.getClientRects().length === 0) return;
                var top = el.getBoundingClientRect().top;
                if (top < targetTop) {
                    targetTop = top;
                    target = el;
                }
            });

            if (!target) return false;

            var offset = 140; // leave room above the error for the related field
            var scroller = window.wrlaGetScrollParent(target);

            if (scroller) {
                var delta = target.getBoundingClientRect().top
                    - scroller.getBoundingClientRect().top - offset;
                window.wrlaFastSmoothScroll(
                    scroller,
                    Math.max(scroller.scrollTop + delta, 0),
                    250
                );
            } else {
                window.wrlaFastSmoothScroll(
                    null,
                    Math.max(targetTop + window.pageYOffset - offset, 0),
                    250
                );
            }

            return true;
        };
    }

    if (!window.wrlaUpsertErrorScrollBound) {
        window.wrlaUpsertErrorScrollBound = true;

        var bindErrorScrollHook = function () {
            if (!window.Livewire || !window.Livewire.hook) return;

            // After the save commit is applied to the DOM, scroll to the first error
            // (if any) but only when it followed an upsert form submit.
            window.Livewire.hook('commit', function (payload) {
                var succeed = payload.succeed;
                if (typeof succeed !== 'function') return;

                succeed(function () {
                    if (!window.wrlaUpsertSubmitPending) return;
                    window.wrlaUpsertSubmitPending = false;

                    // Wait for the morph to apply so error nodes exist before scrolling.
                    requestAnimationFrame(function () {
                        requestAnimationFrame(function () {
                            window.wrlaScrollToFirstError();
                        });
                    });
                });
            });
        };

        if (window.Livewire && window.Livewire.hook) {
            bindErrorScrollHook();
        } else {
            document.addEventListener('livewire:init', bindErrorScrollHook);
        }
    }
</script>
@endpush
@endonce

@if($usesWysiwyg === true)
    @push('append-body')
        {!! $WRLAHelper::getWysiwygEditorSetupJS() !!}
    @endpush
@endif
