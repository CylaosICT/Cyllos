import { Controller } from '@hotwired/stimulus';

/**
 * Minimal custom dropdown: a styled trigger button that toggles a floating
 * menu of links/actions. Used to replace plain native <select> filters with
 * something that actually matches the app's design system.
 */
export default class extends Controller {
    static targets = ['trigger', 'menu'];

    toggle(event) {
        event.stopPropagation();
        const isOpen = this.menuTarget.classList.toggle('is-open');
        this.triggerTarget.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }

    close() {
        this.menuTarget.classList.remove('is-open');
        this.triggerTarget.setAttribute('aria-expanded', 'false');
    }

    closeOnClickOutside(event) {
        if (!this.element.contains(event.target)) {
            this.close();
        }
    }

    closeOnEscape(event) {
        if (event.key === 'Escape') {
            this.close();
        }
    }
}
