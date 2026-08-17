import { Controller } from '@hotwired/stimulus';

/**
 * Auto-dismisses a flash message after 10 seconds, with a short fade-out.
 */
export default class extends Controller {
    connect() {
        this.timeout = setTimeout(() => {
            this.element.classList.add('is-dismissing');
            setTimeout(() => this.element.remove(), 300);
        }, 10000);
    }

    disconnect() {
        clearTimeout(this.timeout);
    }
}
