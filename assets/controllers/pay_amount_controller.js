import { Controller } from '@hotwired/stimulus';

/**
 * Amount rules of a client's payment page: shows only the fields that belong to
 * the selected mode (range / list of amounts / single amount). Hidden fields
 * keep their value and are still submitted, so switching mode back and forth
 * loses nothing; the server only looks at the fields of the chosen mode.
 *
 * Targets: the mode radios, and the panels (data-modes="range list" = shown for those modes).
 */
export default class extends Controller {
    static targets = ['mode', 'panel'];

    connect() {
        this.update();
    }

    update() {
        const selected = this.modeTargets.find((radio) => radio.checked)?.value ?? 'range';

        this.panelTargets.forEach((panel) => {
            panel.hidden = !panel.dataset.modes.split(' ').includes(selected);
        });
        this.modeTargets.forEach((radio) => {
            radio.closest('.mode-card')?.classList.toggle('is-on', radio.checked);
        });
    }
}
