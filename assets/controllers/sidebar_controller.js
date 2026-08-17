import { Controller } from '@hotwired/stimulus';

/**
 * Mobile sidebar drawer: toggles an off-canvas sidebar open/closed below the
 * responsive breakpoint, with a backdrop to close on outside click.
 */
export default class extends Controller {
    static targets = ['sidebar', 'backdrop'];

    toggle() {
        this.sidebarTarget.classList.toggle('is-open');
        this.backdropTarget.classList.toggle('is-open');
    }

    close() {
        this.sidebarTarget.classList.remove('is-open');
        this.backdropTarget.classList.remove('is-open');
    }
}
