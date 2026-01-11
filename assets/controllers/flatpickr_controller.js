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
    connect() {
        this.picker = flatpickr(this.element, {
            dateFormat: 'Y-m-d',
            altInput: true,
            altFormat: 'j. n. Y',
            locale: Czech
        });
    }

    disconnect() {
        if (this.picker) {
            this.picker.destroy();
        }
    }
}
