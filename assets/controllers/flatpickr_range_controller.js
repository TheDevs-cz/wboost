import { Controller } from '@hotwired/stimulus';
import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.min.css';

const Czech = {
    weekdays: {
        shorthand: ["Ne", "Po", "Út", "St", "Čt", "Pá", "So"],
        longhand: ["Neděle", "Pondělí", "Úterý", "Středa", "Čtvrtek", "Pátek", "Sobota"],
    },
    months: {
        shorthand: ["Led", "Ún", "Bře", "Dub", "Kvě", "Čer", "Čvc", "Srp", "Zář", "Říj", "Lis", "Pro"],
        longhand: ["Leden", "Únor", "Březen", "Duben", "Květen", "Červen", "Červenec", "Srpen", "Září", "Říjen", "Listopad", "Prosinec"],
    },
    firstDayOfWeek: 1,
    ordinal: () => ".",
    rangeSeparator: " do ",
    weekAbbreviation: "Týd.",
    scrollTitle: "Rolujte pro změnu",
    toggleTitle: "Přepnout dopoledne/odpoledne",
    time_24hr: true,
};

export default class extends Controller {
    static targets = ['from', 'to'];

    connect() {
        const defaultDates = [];
        if (this.hasFromTarget && this.fromTarget.value) {
            defaultDates.push(this.fromTarget.value);
        }
        if (this.hasToTarget && this.toTarget.value) {
            defaultDates.push(this.toTarget.value);
        }

        this.picker = flatpickr(this.element, {
            dateFormat: 'Y-m-d',
            altInput: true,
            altFormat: 'j. n. Y',
            locale: Czech,
            mode: 'range',
            wrap: true,
            defaultDate: defaultDates,
            onChange: (selectedDates) => {
                if (this.hasFromTarget) {
                    this.fromTarget.value = selectedDates[0] ? this.formatDate(selectedDates[0]) : '';
                    this.fromTarget.dispatchEvent(new Event('change', { bubbles: true }));
                }
                if (this.hasToTarget) {
                    this.toTarget.value = selectedDates[1] ? this.formatDate(selectedDates[1]) : '';
                    this.toTarget.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
        });
    }

    formatDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    disconnect() {
        if (this.picker) {
            this.picker.destroy();
        }
    }
}
