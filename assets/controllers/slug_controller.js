import { Controller } from '@hotwired/stimulus';

/**
 * Live-fills a slug field from a source (name) field as the user types,
 * e.g. "Test 2" -> "test-2". Stops as soon as the user edits the slug
 * field directly, so a manual value is never silently overwritten. Also
 * does nothing if the slug already has a value on connect (editing an
 * existing client).
 */
export default class extends Controller {
    static targets = ['source', 'slug'];

    connect() {
        this.locked = this.slugTarget.value.trim() !== '';
    }

    sync() {
        if (this.locked) {
            return;
        }
        this.slugTarget.value = this.slugify(this.sourceTarget.value);
    }

    lock() {
        this.locked = true;
    }

    slugify(value) {
        const combiningMarks = /[\u0300-\u036f]/g;

        return value
            .normalize('NFD')
            .replace(combiningMarks, '')
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }
}
