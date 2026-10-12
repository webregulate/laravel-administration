const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const views = path.join(__dirname, '../src/resources/views/components/forms');
const templates = {
    plain: fs.readFileSync(path.join(views, 'input-image.blade.php'), 'utf8'),
    cropped: fs.readFileSync(path.join(views, 'input-image-croppable.blade.php'), 'utf8'),
};

function scriptFor(type) {
    const source = templates[type].split('@script')[1];
    assert.ok(source, `${type} image scripts must initialize in Livewire modals`);
    let script = source.match(/<script>([\s\S]*?)<\/script>/)[1];
    if (type === 'cropped') {
        script = script.split('window.wrla_croppable_initialize =')[0];
    }
    return script
        .replaceAll('@json($fileSystemImageExists)', 'false')
        .replaceAll("{{ $WRLAHelper::getCurrentThemeData('no_image_src') }}", '/no-image.png');
}

function imageInput(name) {
    const preview = { src: '', style: {}, classList: { remove() {} } };
    const rotation = { value: '0' };
    const remove = { value: 'false', parentElement: { querySelector: () => ({ style: {} }) } };
    const root = {
        getAttribute: () => name,
        querySelector(selector) {
            if (selector === '.wrla_image_preview') return preview;
            if (selector === '.wrla_rotation_input') return rotation;
            if (selector === '.wrla_rotation_label') return { textContent: '' };
            if (selector === '.wrla_rotation_controls') return { style: {} };
            return null;
        },
    };
    const input = {
        files: [{ name: 'icon.png' }],
        closest: selector => selector === '[wire\\:id]' ? { getAttribute: () => 'upsert' } : root,
        parentElement: {
            querySelector: () => remove,
            parentElement: { parentElement: root },
        },
    };
    return { input, preview };
}

for (const order of [['plain', 'cropped'], ['cropped', 'plain']]) {
    test(`plain uploads survive ${order.join(' then ')} script initialization`, () => {
        const uploads = [];
        const updates = [];
        const context = vm.createContext({
            FileReader: class {
                readAsDataURL() {
                    this.onload({ target: { result: 'data:image/png;base64,preview' } });
                }
            },
            Livewire: {
                find: () => ({
                    upload: (key, file) => uploads.push({ key, file }),
                    set: (...args) => updates.push(args),
                }),
            },
        });
        context.window = context;
        for (const type of order) {
            vm.runInContext(`(function () { ${scriptFor(type)} })()`, context);
        }

        const plain = imageInput('yourmoo_all_products_icon');
        const cropped = imageInput('client_logo');
        context.wrla_croppable_setPreviewImage(cropped.input);
        assert.equal(uploads.length, 0);
        context.wrla_image_setPreviewImage(plain.input);

        assert.equal(uploads.length, 1);
        assert.equal(uploads[0].key, 'livewireData.yourmoo_all_products_icon');
        assert.equal(uploads[0].file, plain.input.files[0]);
        assert.equal(plain.preview.src, 'data:image/png;base64,preview');
        assert.equal(cropped.preview.src, 'data:image/png;base64,preview');
        assert.ok(updates.some(([key, value]) => key === 'livewireData.wrla_remove_yourmoo_all_products_icon' && value === 'false'));
        assert.equal(typeof context.wrla_image_removeImage, 'function');
        assert.equal(typeof context.wrla_croppable_removeImage, 'function');
        assert.notEqual(context.wrla_image_removeImage, context.wrla_croppable_removeImage);
        assert.ok(templates.plain.includes('onchange="wrla_image_setPreviewImage(this)"'));
        assert.ok(templates.cropped.includes('onchange="wrla_croppable_setPreviewImage(this)"'));
    });
}