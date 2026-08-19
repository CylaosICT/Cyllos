import { Controller } from '@hotwired/stimulus';

/**
 * Generic open/close toggle for a simple modal dialog (not the destructive-
 * action confirm modal, nor the API log modal — those have their own
 * controllers with extra behaviour). Usage: data-controller="modal" on the
 * backdrop, data-modal-target="backdrop" on itself, and actions like
 * modal#open / modal#close wired to triggers/buttons.
 */
export default class extends Controller {
    static targets = ['backdrop'];

    open() {
        this.backdropTarget.classList.add('is-open');
        this.backdropTarget.querySelector('input, select, textarea')?.focus();
    }

    close() {
        this.backdropTarget.classList.remove('is-open');
    }

    closeOnBackdrop(event) {
        if (event.target === this.backdropTarget) {
            this.close();
        }
    }

    closeOnEscape(event) {
        if (event.key === 'Escape') {
            this.close();
        }
    }
}
