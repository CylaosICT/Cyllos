import { Controller } from '@hotwired/stimulus';

/**
 * Minimal custom dropdown: a styled trigger button that toggles a floating
 * menu, used to replace plain native elements with something that actually
 * matches the app's design system. Two modes, both optional and combinable:
 *
 * - Navigation menu (e.g. the payment list's client/status filters): menu
 *   items are plain links, markup is fully author-supplied in Twig.
 * - Select replacement (the `select` target): menu items are generated from
 *   the bound <select>'s own <option>s, so it stays the single source of
 *   truth for the current value and for form submission — the styled UI is
 *   just a skin on top, kept hidden but present and functional.
 */
export default class extends Controller {
    static targets = ['trigger', 'menu', 'select', 'label'];

    connect() {
        if (this.hasSelectTarget) {
            this.buildMenuFromSelect();
            // Keyboard support for the select replacement: arrows, Home/End, Escape, type-ahead.
            this.onKeydown = (event) => this.keydown(event);
            this.element.addEventListener('keydown', this.onKeydown);
        }
    }

    disconnect() {
        if (this.onKeydown) {
            this.element.removeEventListener('keydown', this.onKeydown);
        }
    }

    toggle(event) {
        event.stopPropagation();
        // One open menu at a time (the click is stopped, so the others never see it).
        document.querySelectorAll('.dropdown__menu.is-open').forEach((menu) => {
            if (menu !== this.menuTarget) {
                menu.classList.remove('is-open');
                menu.closest('.dropdown')?.querySelector('[aria-expanded]')?.setAttribute('aria-expanded', 'false');
            }
        });
        const isOpen = this.menuTarget.classList.toggle('is-open');
        this.triggerTarget.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        if (isOpen && this.hasSelectTarget) {
            this.focusItem(this.items().findIndex((item) => item.classList.contains('is-selected')));
        }
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

    items() {
        return Array.from(this.menuTarget.querySelectorAll('.dropdown__item'));
    }

    focusItem(index) {
        const items = this.items();
        if (items.length === 0) {
            return;
        }
        const target = items[Math.max(0, Math.min(items.length - 1, index))];
        items.forEach((item) => item.classList.toggle('is-active', item === target));
        target.focus({ preventScroll: true });
        target.scrollIntoView({ block: 'nearest' });
    }

    keydown(event) {
        const isOpen = this.menuTarget.classList.contains('is-open');
        const items = this.items();
        const current = items.findIndex((item) => item === document.activeElement);

        switch (event.key) {
            case 'ArrowDown':
            case 'ArrowUp':
                event.preventDefault();
                if (!isOpen) {
                    this.triggerTarget.click();
                    break;
                }
                this.focusItem(current + (event.key === 'ArrowDown' ? 1 : -1));
                break;
            case 'Home':
            case 'End':
                if (isOpen) {
                    event.preventDefault();
                    this.focusItem(event.key === 'Home' ? 0 : items.length - 1);
                }
                break;
            case 'Escape':
                if (isOpen) {
                    this.close();
                    this.triggerTarget.focus();
                }
                break;
            case 'Tab':
                this.close();
                break;
            default:
                // Type-ahead: jump to the next entry starting with the typed character.
                if (isOpen && event.key.length === 1 && !event.ctrlKey && !event.metaKey) {
                    const letter = event.key.toLowerCase();
                    const from = current + 1;
                    const next = [...items.slice(from), ...items.slice(0, from)].find((item) => item.textContent.trim().toLowerCase().startsWith(letter));
                    if (next) {
                        this.focusItem(items.indexOf(next));
                    }
                }
        }
    }

    buildMenuFromSelect() {
        this.menuTarget.innerHTML = '';
        this.menuTarget.setAttribute('role', 'listbox');
        Array.from(this.selectTarget.options).forEach((option) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'dropdown__item' + (option.selected ? ' is-selected' : '');
            item.textContent = option.text;
            item.dataset.value = option.value;
            item.dataset.action = 'dropdown#pick';
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', option.selected ? 'true' : 'false');
            this.menuTarget.appendChild(item);
        });
        this.syncLabelFromSelect();
    }

    /** Click handler for a select-mode menu item: mirrors it onto the bound <select>. */
    pick(event) {
        const value = event.currentTarget.dataset.value;
        this.selectTarget.value = value;
        this.selectTarget.dispatchEvent(new Event('change', { bubbles: true }));

        this.menuTarget.querySelectorAll('.dropdown__item').forEach((item) => {
            const selected = item.dataset.value === value;
            item.classList.toggle('is-selected', selected);
            item.setAttribute('aria-selected', selected ? 'true' : 'false');
        });
        this.triggerTarget.focus();
        this.syncLabelFromSelect();
        this.close();
    }

    syncLabelFromSelect() {
        if (!this.hasLabelTarget) {
            return;
        }
        const selected = this.selectTarget.options[this.selectTarget.selectedIndex];
        this.labelTarget.textContent = selected ? selected.text : '';
        // An empty value is the "default / none" entry: shown muted, like a placeholder.
        this.labelTarget.classList.toggle('is-placeholder', !selected || selected.value === '');
    }
}
