@props(['options' => [], 'label' => null, 'tagValue' => ''])

@php
    // Set id from name if unset
    $id = empty($attributes->get('id')) ? 'wrinput-'.$attributes->get('name') : $attributes->get('id');

    // Common tags
    $commonTags = $options['commonTags'] ?? [];

    // Max tags (null = unlimited)
    $maxTags = $options['maxTags'] ?? null;

    $tagValue = is_string($tagValue) ? $tagValue : (string) ($attributes->get('value') ?? '');
    $storeAsJson = (bool) ($options['storeAsJson'] ?? false);
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

{{-- x-data stays inline so Livewire morphs do not depend on a script tag running.
     The attribute is double-quoted, so this script must not contain a raw double quote.
     &amp; is written twice because the browser decodes the attribute before Alpine parses it. --}}
<div x-data="{
    tags: [],
    newTag: '',
    editingIndex: null,
    editValue: '',
    storeAsJson: false,
    preserveInitial: false,
    initialStoredValue: '',
    maxTags: null,
    get isFull() {
        return this.maxTags !== null && this.tags.length >= this.maxTags;
    },
    get storedValue() {
        if (this.preserveInitial) {
            return this.initialStoredValue;
        }
        return this.storeAsJson ? JSON.stringify(this.tags) : this.tags.join(',');
    },
    init() {
        const rawMax = this.$el.dataset.maxTags;
        this.maxTags = rawMax === undefined || rawMax === '' ? null : Number(rawMax);
        this.storeAsJson = this.$el.dataset.storeAsJson === '1';
        const initial = this.$el.dataset.tagValue ?? '';
        this.initialStoredValue = initial;
        const parsed = this.parseStored(initial);
        const trimmed = String(initial).trim();
        if (this.storeAsJson && parsed.length === 0 && trimmed !== '' && trimmed !== '[]') {
            this.preserveInitial = true;
            return;
        }
        parsed.forEach(tag => this.addTag(tag, false));
    },
    parseStored(initialVal) {
        const trimmed = String(initialVal ?? '').trim();
        if (trimmed === '') {
            return [];
        }
        if (this.storeAsJson) {
            try {
                const decoded = JSON.parse(trimmed);
                if (Array.isArray(decoded) && decoded.every(tag => typeof tag === 'string' || typeof tag === 'number')) {
                    return decoded.filter(tag => String(tag).trim() !== '');
                }
            } catch (error) {}
            return [];
        }
        return trimmed.split(',').map(tag => tag.trim()).filter(tag => tag !== '');
    },
    tagKey(tag) {
        return String(tag ?? '').trim();
    },
    escapeHtml(value) {
        const quote = String.fromCharCode(34);
        return String(value)
            .replace(/&/g, '&amp;amp;')
            .replace(/</g, '&amp;lt;')
            .replace(/>/g, '&amp;gt;')
            .replace(new RegExp(quote, 'g'), '&amp;quot;')
            .replace(/'/g, '&amp;#39;');
    },
    notifyDuplicate(tag) {
        const quote = String.fromCharCode(34);
        this.$dispatch('wrla-alert', {
            type: 'warning',
            message: 'The tag ' + quote + this.escapeHtml(tag) + quote + ' has already been added.',
            url: null,
        });
    },
    addTag(tag, notify = true) {
        const text = this.tagKey(tag);
        if (this.isFull) {
            this.newTag = '';
            return;
        }
        if (text === '') {
            this.newTag = '';
            return;
        }
        if (this.tags.some(existing => this.tagKey(existing) === text)) {
            this.newTag = '';
            if (notify) {
                this.notifyDuplicate(text);
            }
            return;
        }
        this.preserveInitial = false;
        this.tags.push(typeof tag === 'number' && String(tag) === text ? tag : text);
        this.newTag = '';
    },
    removeTag(index) {
        this.tags.splice(index, 1);
        if (this.editingIndex === index) {
            this.editingIndex = null;
            this.editValue = '';
        }
        this.$refs.newTagInput.focus();
    },
    editTag(index) {
        this.editingIndex = index;
        this.editValue = this.tags[index];
        setTimeout(function () {
            const input = document.querySelector('input[data-edit]');
            if (input) {
                input.focus();
                const len = input.value.length;
                input.setSelectionRange(len, len);
            }
        }, 50);
    },
    commitEdit() {
        if (this.editingIndex === null) {
            return;
        }
        const val = this.editValue.trim();
        if (val && this.tags.some((tag, index) => index !== this.editingIndex && this.tagKey(tag) === val)) {
            this.notifyDuplicate(val);
            this.editingIndex = null;
            this.editValue = '';
            return;
        }
        if (val && this.tagKey(this.tags[this.editingIndex]) !== val) {
            this.tags[this.editingIndex] = val;
        }
        this.editingIndex = null;
        this.editValue = '';
    },
    handleInput(e) {
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
data-tag-value="{{ $tagValue }}"
data-store-as-json="{{ $storeAsJson ? '1' : '0' }}"
data-max-tags="{{ $maxTags === null ? '' : (int) $maxTags }}"
class="min-w-0 w-full"
style="width: 100%; max-width: 100%;"
>
    <div class="w-full flex flex-wrap items-center gap-1 px-1.5 py-1 border border-slate-400 dark:border-slate-500 bg-slate-200 dark:bg-slate-900
        focus:outline-none focus:ring-1 focus:ring-primary-500 dark:focus:ring-primary-500 rounded-md shadow-sm">
        <template x-for="(tag, index) in tags" :key="index">
            <div class="inline-flex items-center shrink-0 max-w-full">
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
                    <span class="inline-flex items-center px-2 bg-primary-500 bg-opacity-5 border-2 border-primary-500 rounded-md whitespace-nowrap max-w-full" style="line-height: 20px;">
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
            class="pl-1 border-none outline-none focus:ring-0 flex-1 min-w-[8rem] bg-transparent
            placeholder-slate-400 dark:placeholder-slate-600 disabled:cursor-not-allowed"
        />
    </div>

    {{-- Already deduped. Alpine keeps comma-separated text or a JSON list, matching the column. --}}
    <input type="hidden" name="{{ $attributes['name'] }}" value="{{ $tagValue }}" x-bind:value="storedValue" />

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
