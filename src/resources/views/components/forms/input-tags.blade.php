@props(['options' => [], 'label' => null])

@php
    // Set id from name if unset
    $id = empty($attributes->get('id')) ? 'wrinput-'.$attributes->get('name') : $attributes->get('id');

    // Common tags
    $commonTags = $options['commonTags'] ?? [];

    // Max tags (null = unlimited)
    $maxTags = $options['maxTags'] ?? null;
@endphp

<div class="{{ $options['containerClass'] ?? 'w-full flex-1 flex flex-col gap-1 md:flex-auto' }}">

@if(!empty($label))
    @themeComponent('forms.label', [
        'label' => $label,
        'attributes' => Arr::toAttributeBag([
            'for' => $id,
            'class' => $options['labelClass'] ?? ''
        ])
    ])
@endif

<div x-data="{
    tags: [],
    newTag: '',
    editingIndex: null,
    editValue: '',
    maxTags: {{ is_null($maxTags) ? 'null' : (int) $maxTags }},
    get isFull() {
        return this.maxTags !== null && this.tags.length >= this.maxTags;
    },
    init() {
        const initialVal = '{{ old($attributes->get('name'), $attributes->get('value') ?? '') }}';
        if (initialVal.trim()) {
            initialVal.split(',').forEach(tag => this.addTag(tag.trim()));
        }
    },
    addTag(tag) {
        tag = tag.trim();

        if (this.isFull) {
            this.newTag = '';
            return;
        }

        if (tag && !this.tags.includes(tag)) {
            this.tags.push(tag);
            this.newTag = '';
        }
    },
    removeTag(index) {
        this.tags.splice(index, 1);
        // If the removed tag was being edited, reset edit mode
        if (this.editingIndex === index) {
            this.editingIndex = null;
            this.editValue = '';
        }
        this.$refs.newTagInput.focus();
    },
    editTag(index) {
        this.editingIndex = index;
        this.editValue = this.tags[index];
        // Focus the inline edit input after it is rendered
        setTimeout(function(){
            const input = document.querySelector('input[data-edit]');
            if (input) {
                input.focus();
                const len = input.value.length;
                input.setSelectionRange(len, len);
            }
        }, 50);
    },
    commitEdit() {
        if (this.editingIndex !== null) {
            const val = this.editValue.trim();
            if (val) {
                this.tags[this.editingIndex] = val;
            }
            this.editingIndex = null;
            this.editValue = '';
        }
    },
    handleInput(e) {
        // Comma or return
        if ((e.data === ',' || e.data === '\n') || e.inputType === 'insertFromPaste') {
            const parts = this.newTag.split(',').map(t => t.trim()).filter(Boolean);
            parts.forEach(t => this.addTag(t));
        }
    },
    handleEditKey(e) {
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            this.commitEdit();
        }
    }
}"
x-init="init()"
class="whitespace-nowrap"
style="width: 100%; max-width: 100%;"
>
    <div class="w-full flex items-center overflow-x-auto gap-1 px-1.5 py-1 border border-slate-400 dark:border-slate-500 bg-slate-200 dark:bg-slate-900
        focus:outline-none focus:ring-1 focus:ring-primary-500 dark:focus:ring-primary-500 rounded-md shadow-sm">
        <template x-for="(tag, index) in tags" :key="index">
            <div class="inline-flex items-center">
                <template x-if="editingIndex === index">
                    <input
                        type="text"
                        data-edit
                        x-model="editValue"
                        @blur="commitEdit()"
                        @keydown="handleEditKey($event)"
                        class="inline-flex items-center px-2 bg-primary-500 bg-opacity-5 border-2 border-primary-500 rounded-md focus:outline-none"
                        style="line-height: 20px; min-width: 40px;"
                    />
                </template>
                <template x-if="editingIndex !== index">
                    <span class="inline-flex items-center px-2 bg-primary-500 bg-opacity-5 border-2 border-primary-500 rounded-md" style="line-height: 20px;">
                        <span x-text="tag" class="relative font-medium pr-1.5 cursor-pointer" style="top: -1px;" @click="editTag(index)"></span>
                        <button type="button" class="relative top-[-1px] text-primary-600 font-medium" @click="removeTag(index)">x</button>
                    </span>
                </template>
            </div>
        </template>
        <input
            id="{{ $id }}"
            x-ref="newTagInput"
            x-model="newTag"
            @input="handleInput($event)"
            @blur="addTag(newTag)"
            @keydown.enter.prevent="addTag(newTag)"
            @keydown.backspace="if (!newTag) removeTag(tags.length - 1)"
            x-bind:disabled="isFull"
            x-bind:placeholder="isFull ? ' - Maximum of ' + maxTags + ' tags reached' : ''"
            class="pl-1 border-none outline-none focus:ring-0 flex-1 bg-transparent
            placeholder-slate-400 dark:placeholder-slate-600 disabled:cursor-not-allowed"
        />
    </div>

    {{-- Actual input field --}}
    <input type="hidden" name="{{ $attributes['name'] }}" x-bind:value="tags.join(',')" />

    @if(!empty($commonTags))
        <div class="mt-2 flex flex-wrap gap-2">
            @foreach($commonTags as $tag)
                <button
                    type="button"
                    class="inline-flex items-center px-2 bg-gray-100 hover:bg-primary-500 hover:bg-opacity-5 hover:border-primary-500 rounded-md border-2 border-gray-200 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-gray-100 disabled:hover:border-gray-200" style="line-height: 20px;"
                    title="Add tag"
                    x-bind:disabled="isFull || tags.includes('{{ addslashes($tag) }}')"
                    x-on:click="addTag('{{ addslashes($tag) }}')"
                >{{ $tag }}</button>
            @endforeach
        </div>
    @endif
</div>

{{-- Field notes (if options has notes key) --}}
@if(!empty($options['notes']))
    @themeComponent('forms.field-notes', ['notes' => $options['notes']])
@endif

@if($options['showError'] ?? true)
    @error($attributes->get('name'))
        @themeComponent('alert', ['type' => 'error', 'message' => $message, 'class' => 'mt-2'])
    @enderror
@endif

</div>