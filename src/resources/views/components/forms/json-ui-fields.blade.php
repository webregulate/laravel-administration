@props([
    'json' => '',
    'debug' => false,
    'hideKeyValues' => false, // false, or array of dotted keys to hide, can use wildcards e.g. '*.something'
])

{{-- @dd($hideKeyValues) --}}

<div
    wire:key="wrla-json-ui-{{ $attributes->get('name') }}-{{ md5((string) $json) }}"
    x-data="{
    data: {},
    hideKeyValues: @js($hideKeyValues),
    init() {
        try {
            this.data = { data: {{ $json }} };
        } catch(e) {
            this.data = {};
        }
    },
    isObject(val) { return typeof val === 'object' && val !== null; },
    objectAsString(obj) {
        return JSON.parse(JSON.stringify(obj)); // For debugging only
    },
    // Use colon (:) as separator for nested keys
    separator: ':',
    dataGet(obj, path, defaultValue = undefined) {
        if (!path) return obj;
        return path.split(this.separator).reduce((acc, key) => {
            if (acc && Object.prototype.hasOwnProperty.call(acc, key)) return acc[key];
            return defaultValue;
        }, obj);
    },
    dataSet(obj, path, value, type = 'string') {
        var way = path.replace(/\[/g, this.separator).replace(/\]/g, '').split(this.separator),
            last = way.pop();
        if (type == 'number') { 
            value = Number(value);
        } else if(type == 'boolean') {
            if(value == 1) value = true;
            else if(value == 0) value = false;
            else if(value == 'true') value = true;
            else if(value == 'false') value = false;
        }
        way.reduce(function (o, k, i, kk) {
            return o[k] = o[k] || (isFinite(i + 1 in kk ? kk[i + 1] : last) ? [] : {});
        }, obj)[last] = value;
    },
    dataDelete(obj, path) {
        var way = path.replace(/\[/g, this.separator).replace(/\]/g, '').split(this.separator),
            last = way.pop();
        let parts = path.split(this.separator);
        let current = obj;
        for (let i = 0; i < parts.length - 1; i++) {
            if (!current.hasOwnProperty(parts[i])) return;
            current = current[parts[i]];
        }
        if (current instanceof Array && !isNaN(last)) {
            current.splice(last, 1);
        } else {
            delete current[parts[parts.length - 1]];
        }
    },
    entries(obj) {
        // Sort so that non-array items come first, as in PHP
        const allEntries = Object.entries(obj).map(([k,v]) => [k, v]);
        const nonArrays = allEntries.filter(([,v]) => !Array.isArray(v) && !this.isObject(v));
        const arrays    = allEntries.filter(([,v]) => Array.isArray(v) || this.isObject(v));
        return [...nonArrays, ...arrays];
    },
    addAction(addType, dottedPath) { // objType only used with 'group' addType
        // Get data and type at dottedPath
        let thisData = this.dataGet(this.data, dottedPath, null);
        let thisType = thisData instanceof Array ? 'array' : 'object';
        let newKey, newFullKeyPath, newValue = null;
        let validKeyFound = false;
        while(!validKeyFound) {
            if(addType == 'group') {
                newValue = {};
                newKey = prompt('New key name', 'new_group');
                if(newKey == null || newKey == '') return;
                newFullKeyPath = dottedPath ? `${dottedPath}${this.separator}${newKey}` : newKey;
            } else if(addType == 'item') {
                newKey = prompt('New key name', 'new_key');
                if(newKey == null || newKey == '') return;
                newValue = '';
                newFullKeyPath = dottedPath ? `${dottedPath}${this.separator}${newKey}` : newKey;
            }
            // Check if newKey already exists
            let newKeyExists = this.dataGet(this.data, newFullKeyPath, null);
            if(newKeyExists !== null) {
                let overrideKey = confirm('`'+newKey+'` key already exists, override this key?');
                if(!overrideKey) continue;
            }
            validKeyFound = true;
        }
        this.dataSet(this.data, newFullKeyPath, newValue);

        // Render the data again
        this.render(this.data, null);

        // Focus new input field
        setTimeout(() => {
            let newInput = document.getElementById(`wrla-json-ui-input-${newFullKeyPath}`);
            if(newInput) {
                newInput.focus();
                newInput.select();
            }
        }, 50);
    },
    renameAction(dottedPath) {
        // Get parent dotted path so we can check if this key already exists
        let parentPath = dottedPath.split(this.separator).slice(0, -1).join(this.separator);
        let validKeyFound = false;
        let newKey = null;
        while(!validKeyFound) {
            newKey = prompt('New key name', dottedPath.split(this.separator).pop());
            if(newKey == null || newKey == '') return;
            let newFullKeyPath = parentPath ? `${parentPath}${this.separator}${newKey}` : newKey;
            let newKeyExists = this.dataGet(this.data, newFullKeyPath, null);
            if(newKeyExists !== null) {
                let overrideKey = confirm('`'+newKey+'` key already exists, override this key?');
                if(!overrideKey) continue;
            }
            validKeyFound = true;
        }
        let thisData = this.dataGet(this.data, dottedPath, null);
        let oldKey = dottedPath.split(this.separator).pop();
        let newFullKeyPath = parentPath ? `${parentPath}${this.separator}${newKey}` : newKey;
        this.dataSet(this.data, newFullKeyPath, thisData);
        this.dataDelete(this.data, dottedPath);
    },
    deleteAction(dottedPath) {
        this.dataDelete(this.data, dottedPath);
    },
    updateValueAction(dottedPath, value, type) {
        this.dataSet(this.data, dottedPath, value, type);
    },
    switchTypeAction(dottedPath) {
        let currentValue = this.dataGet(this.data, dottedPath, null);

        let newType = prompt(
            'Switch to type: string, number, boolean, object, array',
            (Array.isArray(currentValue) 
                || this.isObject(currentValue)) ? 'string' : 'number'
        );

        if(!newType) return; 
        let newValue;

        switch(newType) {
            case 'number':
                newValue = Number(currentValue) || 0; 
                break;
            case 'boolean':
                newValue = Boolean(currentValue && currentValue != 'false');
                break;
            case 'object':
                newValue = {}; 
                break;
            case 'array':
                newValue = []; 
                break;
            default:
                newValue = String(currentValue);
        }
        
        this.dataSet(this.data, dottedPath, newValue, newType);
        this.render(this.data, null);
    },
    render(obj, dottedPath = null) {
        let html = '';
        let baseDottedPath = dottedPath;

        for (const [key, value] of this.entries(obj)) {
            // Get colon-separated path for this key
            dottedPath = (baseDottedPath !== null ? `${baseDottedPath}${this.separator}` : ``) + key;

            // If in hideKeyValues array, skip this key
            if(this.hideKeyValues && this.hideKeyValues.length > 0) {
                let keyExists = this.hideKeyValues.some(hideKey => {
                    // Prepend data. to hideKey
                    hideKey = 'data.' + hideKey;

                    // Check if hideKey is a wildcard (e.g. *.something)
                    if(hideKey.includes('*')) {
                        // Regex fixed hideKey
                        hideKey = hideKey.replace(/\*/g, '.*');
                        let regex = new RegExp(hideKey.replace(/\*/g, '.*'));
                        return regex.test(dottedPath);
                    } else {
                        return dottedPath == hideKey;
                    }
                });
                if(keyExists) continue;
            }
            
            if (this.isObject(value)) {
                let keyIsInt = !isNaN(Number(key));

                html += `
                    <div class='group flex flex-row items-center `+(keyIsInt ? 'mt-2.5' : 'mt-2 mb-1')+` text-slate-900'>
                        <label class='text-sm font-bold whitespace-nowrap'>
                            <span class='`+(value instanceof Array ? 'text-teal-600' : 'text-amber-600')+`'>
                                <i class='`+(value instanceof Array ? 'fas fa-list-ul' : 'far fa-folder')+` mr-1.5'></i>
                                <span x-on:click='` + 'renameAction(`' + dottedPath + '`)' + `' title='Rename' class='cursor-text'>
                                    ${dottedPath == 'data' ? '' : (keyIsInt ? '#' + key : key)}
                                </span>
                            </span>
                            <span class='opacity-30 dark:text-white'>➤
                                {{-- ${dottedPath}: ${value instanceof Array ? 'array' : 'object'} --}}
                            </span>
                        </label>
                        {{-- Options --}}
                        <div class='relative top-[-1px] flex items-center gap-5 ml-3 opacity-0 group-hover:opacity-100 transition-opacity duration-300 font-bold'>
                            <button type='button' class='text-sm text-slate-600 dark:text-slate-300 hover:text-teal-500'
                                x-on:click.prevent='` + 'addAction(`group`, `' + dottedPath + '`)' + `'
                                title='Add group'
                            >
                                <i class='fa-solid fa-plus fa-xs align-middle'></i> group
                            </button>
                            <button type='button' class='text-sm text-slate-600 dark:text-slate-300 hover:text-teal-500'
                                x-on:click.prevent='` + 'addAction(`item`, `' + dottedPath + '`)' + `'
                                title='Add item'
                            >
                                <i class='fa-solid fa-plus fa-xs align-middle'></i> item
                            </button>
                            <button type='button' class='text-sm text-slate-600 dark:text-slate-300 hover:text-teal-500'
                                x-on:click.prevent='switchTypeAction(\`${dottedPath}\`)'
                                title='Switch type'
                            >
                                <i class='fa-solid fa-arrows-rotate fa-xs align-middle'></i> switch
                            </button>
                            <button type='button' class='text-sm text-slate-600 dark:text-slate-300 hover:text-teal-500'
                                x-on:click.prevent='` + 'deleteAction(`' + dottedPath + '`)' + `'
                                title='Delete'
                            >
                                <i class='fa-solid fa-trash fa-xs align-middle mr-0.5'></i> remove
                            </button>
                        </div>
                    </div>
                    <div class='flex flex-col `+(value instanceof Array ? 'border-teal-600' : 'border-amber-600')+` border-l-2 border-dotted ml-1 pl-3'>
                        ${this.render(value, dottedPath)}
                    </div>
                `;
            } else {
                let type = 'text';
                let fieldType = typeof value;
                let selectoptions = null;

                if(fieldType == 'number') {
                    type = 'number';
                } else if(fieldType == 'boolean') {
                    type = 'checkbox';
                    checkboxValue = value ? 1 : 0;
                } else if(fieldType == 'select') {
                    type = 'select';
                    selectOptions = [
                        {text: 'true', value: 1},
                        {text: 'false', value: 0},
                    ];
                } else if(fieldType == 'non_editable') {
                    type = 'non_editable';
                }

                // If type is 'select', set selected to true for the current value
                if(type == 'select') {
                    selectOptions = selectOptions.map(option => {
                        option.selected = (option.value == value);
                        return option;
                    });
                }

                html += `
                    <div class='group flex gap-4 items-center py-1 text-slate-800'>
                        <label x-on:click='` + 'renameAction(`' + dottedPath + '`)' + `' title='Rename' class='cursor-text w-min whitespace-nowrap'>
                            <span class='text-sm font-bold !text-slate-600 dark:!text-white'>${key}</span>
                        </label>
                        <div class='flex items-center gap-3'>

                            {{-- Display HTML element based on type --}}
                            ` + (() => {
                                if (type === 'select') {
                                    return `
                                        <select
                                            x-ref='wrla-json-ui-input-` + dottedPath + `'
                                            id='wrla-json-ui-input-` + dottedPath + `'
                                            class='w-72 px-2 py-0.5 border border-slate-400 text-black dark:text-black rounded-md text-sm'
                                            name='${key}'
                                            x-on:change='updateValueAction(\`` + dottedPath + `\`, $event.target.value, \``+ fieldType +`\`)'
                                        >
                                            <template x-for='(option) in selectOptions'>
                                                <option :value='option.value' :selected='option.selected ?? false'>
                                                    <span x-text='option.text'></span>
                                                </option>
                                            </template>
                                        </select>
                                    `;
                                } else if (type === 'checkbox') {
                                    return `
                                        <input
                                            type='checkbox'
                                            id='wrla-json-ui-input-` + dottedPath + `'
                                            class='w-4 h-4 accent-teal-600 border-slate-400 rounded-md cursor-pointer'
                                            name='${key}'
                                            x-on:change='updateValueAction(\`` + dottedPath + `\`, $event.target.checked ? 1 : 0, \``+ fieldType +`\`)'
                                            x-bind:checked='` + (checkboxValue ? 'true' : 'false') + `'
                                        />
                                    `;
                                } else if (type === 'non_editable') {
                                    return `
                                        <p
                                            id='wrla-json-ui-input-` + dottedPath + `'
                                            class='text-sm text-slate-800'
                                        >
                                            ${String(value)}
                                        </p>
                                    `;
                                } else {
                                    return `
                                        <input
                                            type='` + type + `'
                                            id='wrla-json-ui-input-` + dottedPath + `'
                                            class='w-72 px-2 py-0.5 border border-slate-400 text-black dark:text-black rounded-md text-sm'
                                            name='${key}'
                                            value='${String(value)}'
                                            x-on:change='updateValueAction(\`` + dottedPath + `\`, $event.target.value, \``+ fieldType +`\`)'
                                        />
                                    `;
                                }
                            })() + `
                            

                            <div class='flex items-center gap-5 ml-2 opacity-0 group-hover:opacity-100 transition-opacity duration-300 font-bold'>
                                <button
                                    type='button'
                                    class='text-sm text-slate-300 hover:text-teal-500'
                                    x-on:click.prevent='switchTypeAction(\`${dottedPath}\`)'
                                    title='Switch type'
                                >
                                    <i class='fa-solid fa-arrows-rotate fa-xs align-middle mr-0.5'></i> switch
                                </button>
                                <button type='button' class='text-sm text-slate-300 hover:text-teal-500'
                                    x-on:click.prevent='` + 'deleteAction(`'+dottedPath+'`)' + `'
                                    title='Delete'
                                >
                                    <i class='fa-solid fa-trash fa-xs align-middle mr-0.5'></i> remove
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            }
        }
        return html;
    },
    renderDisplayJson() {
        return JSON.stringify(this.data.data, null, 2);
    }
}">
    <!-- Render element -->
    <div x-html="render(data)"></div>

    {{-- Hidden input to store JSON data, may have options to show this at some point --}}
    <div x-show="@js($debug)" class="text-sm text-slate-700 dark:text-white mt-7 px-6 py-4 bg-white">
        <span class="font-bold">JSON:</span>
        <textarea
            {{ $attributes->merge()->except(['class']) }}
            class="w-full h-64 px-2 py-0.5 border border-slate-400 text-black dark:text-black rounded-md text-sm"
            x-bind:value="renderDisplayJson()"
            x-effect="
                $el.value = renderDisplayJson();
                $el.dispatchEvent(new Event('input', { bubbles: true }));
                $el.dispatchEvent(new Event('change', { bubbles: true }));
            "
            x-on:change="$event.target.dispatchEvent(new Event('input', { bubbles: true }))"></textarea>
    </div>

</div>