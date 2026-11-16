import { Controller } from '@hotwired/stimulus';

/**
 * Colour picker for a client's payment page: ready-made palettes, a native
 * colour picker per field kept in sync with its text field, a theme generated
 * from the main colour, a suggestion taken from the client's logo, and a live
 * preview that mirrors how cyllos-pay derives the colours left empty.
 *
 * Targets: the four text inputs (primary, background, surface, text), their
 * native pickers (xxxPicker), and the preview box. The text inputs stay the
 * source of truth that gets submitted.
 */
export default class extends Controller {
    static targets = [
        'primary', 'background', 'surface', 'text',
        'primaryPicker', 'backgroundPicker', 'surfacePicker', 'textPicker',
        'preview', 'message', 'swatch', 'filterButton',
    ];
    static values = { logo: String };

    connect() {
        this.refresh();
    }

    // --- Actions

    /** A palette button carries its four colours as data attributes. */
    applyPalette(event) {
        const d = event.currentTarget.dataset;
        this.set({ primary: d.primary, background: d.background, surface: d.surface, text: d.text });
        this.say(`Palette « ${d.name} » appliquée.`);
    }

    /** Shows only the dark / light palettes (or all of them). */
    filter(event) {
        const mode = event.currentTarget.dataset.mode;
        this.filterButtonTargets.forEach((b) => b.classList.toggle('is-on', b === event.currentTarget));
        this.swatchTargets.forEach((s) => {
            s.hidden = mode !== 'all' && s.dataset.mode !== mode;
        });
    }

    /** A native picker changed: copy its value into the text field. */
    pick(event) {
        const field = event.currentTarget.dataset.field;
        this.targetFor(field).value = event.currentTarget.value;
        this.refresh();
    }

    /** A text field was typed in. */
    input() {
        this.refresh();
    }

    clear() {
        this.set({ primary: '', background: '', surface: '', text: '' });
        this.say('Couleurs réinitialisées : le thème par défaut sera utilisé.');
    }

    theme(event) {
        const primary = this.valid(this.primaryTarget.value);
        if (!primary) {
            this.say("Choisissez d'abord une couleur principale.");
            return;
        }
        const dark = event.currentTarget.dataset.mode === 'dark';
        this.set({
            primary,
            background: dark ? this.mix(primary, '#000000', 0.88) : this.mix(primary, '#ffffff', 0.94),
            surface: dark ? this.mix(primary, '#000000', 0.8) : '#ffffff',
            text: dark ? '#f1f5f9' : '#0f172a',
        });
        this.say(dark ? 'Thème sombre généré à partir de la couleur principale.' : 'Thème clair généré à partir de la couleur principale.');
    }

    /** Most frequent vivid colour of the client's logo becomes the main colour. */
    async fromLogo() {
        if (!this.logoValue) {
            this.say("Aucun logo enregistré pour ce client : ajoutez-en un sur sa fiche d'abord.");
            return;
        }
        try {
            const color = await this.dominantColor(this.logoValue);
            if (!color) {
                this.say('Aucune couleur nette trouvée dans le logo.');
                return;
            }
            this.set({ primary: color });
            this.say(`Couleur principale prise du logo : ${color}.`);
        } catch (e) {
            this.say('Impossible de lire le logo.');
        }
    }

    // --- Internals

    set(values) {
        for (const [field, value] of Object.entries(values)) {
            this.targetFor(field).value = value;
        }
        this.refresh();
    }

    targetFor(field) {
        return this[`${field}Target`];
    }

    refresh() {
        for (const field of ['primary', 'background', 'surface', 'text']) {
            const color = this.valid(this.targetFor(field).value);
            const picker = this[`${field}PickerTarget`];
            if (color && picker.value !== color) {
                picker.value = color;
            }
        }
        this.renderPreview();
        this.markActivePalette();
    }

    /** Highlights the palette matching the four current colours, if any. */
    markActivePalette() {
        const now = ['primary', 'background', 'surface', 'text'].map((f) => this.valid(this.targetFor(f).value));
        this.swatchTargets.forEach((s) => {
            const d = s.dataset;
            s.classList.toggle('is-active', [d.primary, d.background, d.surface, d.text].every((c, i) => c === now[i]));
        });
    }

    /** Same derivation rules as cyllos-pay's PayClient::themeCss(). */
    renderPreview() {
        if (!this.hasPreviewTarget) {
            return;
        }
        const primary = this.valid(this.primaryTarget.value);
        let background = this.valid(this.backgroundTarget.value);
        let surface = this.valid(this.surfaceTarget.value);
        let text = this.valid(this.textTarget.value);
        const style = this.previewTarget.style;

        let dark = true;
        if (background || surface || text) {
            const base = background || surface;
            dark = base ? this.luminance(base) < 0.4 : this.luminance(text || '#000000') > 0.5;
            text = text || (dark ? '#f8fafc' : '#0f172a');
            background = background || (surface ? this.mix(surface, '#000000', dark ? 0.35 : 0.04) : (dark ? '#0f172a' : '#f4f5f7'));
            surface = surface || (dark ? this.mix(background, '#ffffff', 0.07) : '#ffffff');
        } else {
            background = '#11141c';
            surface = '#1c212c';
            text = '#e8ebf2';
        }
        const accent = primary || '#63a4ff';

        style.setProperty('--pp-bg', background);
        style.setProperty('--pp-card', surface);
        style.setProperty('--pp-fg', text);
        style.setProperty('--pp-accent', accent);
        style.setProperty('--pp-on-accent', this.luminance(accent) > 0.55 ? '#0f172a' : '#ffffff');
    }

    say(message) {
        if (this.hasMessageTarget) {
            this.messageTarget.textContent = message;
        }
    }

    valid(value) {
        const v = (value || '').trim();
        return /^#[0-9a-fA-F]{6}$/.test(v) ? v.toLowerCase() : null;
    }

    rgb(hex) {
        return [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
    }

    luminance(hex) {
        const [r, g, b] = this.rgb(hex).map((c) => {
            const v = c / 255;
            return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
        });
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    }

    mix(from, towards, ratio) {
        const a = this.rgb(from);
        const b = this.rgb(towards);
        return '#' + a.map((x, i) => Math.round(x + (b[i] - x) * ratio).toString(16).padStart(2, '0')).join('');
    }

    /** Quantises the logo to coarse buckets and keeps the most frequent saturated, mid-light one. */
    dominantColor(url) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => {
                const size = 64;
                const canvas = document.createElement('canvas');
                canvas.width = size;
                canvas.height = size;
                const ctx = canvas.getContext('2d', { willReadFrequently: true });
                ctx.drawImage(img, 0, 0, size, size);
                let data;
                try {
                    data = ctx.getImageData(0, 0, size, size).data;
                } catch (e) {
                    reject(e);
                    return;
                }
                const buckets = new Map();
                for (let i = 0; i < data.length; i += 4) {
                    const [r, g, b, a] = [data[i], data[i + 1], data[i + 2], data[i + 3]];
                    if (a < 200) {
                        continue;
                    }
                    const max = Math.max(r, g, b);
                    const min = Math.min(r, g, b);
                    const saturation = max === 0 ? 0 : (max - min) / max;
                    if (saturation < 0.35 || max < 70 || max > 250 && min > 220) {
                        continue;
                    }
                    const key = [r, g, b].map((c) => c >> 5).join(',');
                    const entry = buckets.get(key) || { n: 0, r: 0, g: 0, b: 0 };
                    entry.n += 1;
                    entry.r += r;
                    entry.g += g;
                    entry.b += b;
                    buckets.set(key, entry);
                }
                let best = null;
                for (const entry of buckets.values()) {
                    if (!best || entry.n > best.n) {
                        best = entry;
                    }
                }
                resolve(best
                    ? '#' + [best.r, best.g, best.b].map((c) => Math.round(c / best.n).toString(16).padStart(2, '0')).join('')
                    : null);
            };
            img.onerror = reject;
            img.src = url;
        });
    }
}
