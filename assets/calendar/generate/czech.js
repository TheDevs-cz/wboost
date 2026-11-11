// Czech calendar vocabulary: months, weekdays, name days, public holidays.

import { addDays, easterSunday, parts } from './dates.js';

export const MONTHS = Object.freeze(['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec']);
export const WEEKDAYS = Object.freeze(['Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota', 'Neděle']);
export const WEEKDAYS_SHORT = Object.freeze(['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne']);
export const WEEKDAY_INITIALS = Object.freeze(['P', 'Ú', 'S', 'Č', 'P', 'S', 'N']);
export const MOON_PHASES = Object.freeze(['Nov', 'První čtvrť', 'Úplněk', 'Poslední čtvrť']);
export const SEASONS = Object.freeze(['Jaro', 'Léto', 'Podzim', 'Zima']);
export const WEEK_RULES = Object.freeze([
    { key: 'w', label: 'Každý týden' },
    { key: '2e', label: 'Sudé týdny' },
    { key: '2o', label: 'Liché týdny' },
    { key: '4w', label: 'Každý 4. týden' },
    { key: '4w2', label: 'Každý 4. týden (o 2 později)' },
    { key: 'm', label: 'První v měsíci' },
]);

/** Czech name days: 12 months (January first), index = day − 1; '' = no name day. February has 29 entries. */
// prettier-ignore
export const NAME_DAYS = Object.freeze([
    ['', 'Karina', 'Radmila', 'Diana', 'Dalimil', 'Tři králové', 'Vilma', 'Čestmír', 'Vladan', 'Břetislav', 'Bohdana', 'Pravoslav', 'Edita', 'Radovan', 'Alice', 'Ctirad', 'Drahoslav', 'Vladislav', 'Doubravka', 'Ilona', 'Běla', 'Slavomír', 'Zdeněk', 'Milena', 'Miloš', 'Zora', 'Ingrid', 'Otýlie', 'Zdislava', 'Robin', 'Marika'],
    ['Hynek', 'Nela', 'Blažej', 'Jarmila', 'Dobromila', 'Vanda', 'Veronika', 'Milada', 'Apolena', 'Mojmír', 'Božena', 'Slavěna', 'Věnceslav', 'Valentýn', 'Jiřina', 'Ljuba', 'Miloslava', 'Gizela', 'Patrik', 'Oldřich', 'Lenka', 'Petr', 'Svatopluk', 'Matěj', 'Liliana', 'Dorota', 'Alexandr', 'Lumír', 'Horymír'],
    ['Bedřich', 'Anežka', 'Kamil', 'Stela', 'Kazimír', 'Miroslav', 'Tomáš', 'Gabriela', 'Františka', 'Viktorie', 'Anděla', 'Řehoř', 'Růžena', 'Rút / Matylda', 'Ida', 'Elena / Herbert', 'Vlastimil', 'Eduard', 'Josef', 'Světlana', 'Radek', 'Leona', 'Ivona', 'Gabriel', 'Marián', 'Emanuel', 'Dita', 'Soňa', 'Taťána', 'Arnošt', 'Kvido'],
    ['Hugo', 'Erika', 'Richard', 'Ivana', 'Miroslava', 'Vendula', 'Heřman / Hermína', 'Ema', 'Dušan', 'Darja', 'Izabela', 'Julius', 'Aleš', 'Vincenc', 'Anastázie', 'Irena', 'Rudolf', 'Valérie', 'Rostislav', 'Marcela', 'Alexandra', 'Evženie', 'Vojtěch', 'Jiří', 'Marek', 'Oto', 'Jaroslav', 'Vlastislav', 'Robert', 'Blahoslav'],
    ['', 'Zikmund', 'Alexej', 'Květoslav', 'Klaudie', 'Radoslav', 'Stanislav', '', 'Ctibor', 'Blažena', 'Svatava', 'Pankrác', 'Servác', 'Bonifác', 'Žofie', 'Přemysl', 'Aneta', 'Nataša', 'Ivo', 'Zbyšek', 'Monika', 'Emil', 'Vladimír', 'Jana', 'Viola', 'Filip', 'Valdemar', 'Vilém', 'Maxmilián', 'Ferdinand', 'Kamila'],
    ['Laura', 'Jarmil', 'Tamara', 'Dalibor', 'Dobroslav', 'Norbert', 'Iveta / Slavoj', 'Medard', 'Stanislava', 'Gita', 'Bruno', 'Antonie', 'Antonín', 'Roland', 'Vít', 'Zbyněk', 'Adolf', 'Milan', 'Leoš', 'Květa', 'Alois', 'Pavla', 'Zdeňka', 'Jan', 'Ivan', 'Adriana', 'Ladislav', 'Lubomír', 'Petr / Pavel', 'Šárka'],
    ['Jaroslava', 'Patricie', 'Radomír', 'Prokop', '', '', 'Bohuslava', 'Nora', 'Drahoslava', 'Libuše / Amálie', 'Olga', 'Bořek', 'Markéta', 'Karolína', 'Jindřich', 'Luboš', 'Martina', 'Drahomíra', 'Čeněk', 'Ilja', 'Vítězslav', 'Magdaléna', 'Libor', 'Kristýna', 'Jakub', 'Anna', 'Věroslav', 'Viktor', 'Marta', 'Bořivoj', 'Ignác'],
    ['Oskar', 'Gustav', 'Miluše', 'Dominik', 'Kristián', 'Oldřiška', 'Lada', 'Soběslav', 'Roman', 'Vavřinec', 'Zuzana', 'Klára', 'Alena', 'Alan', 'Hana', 'Jáchym', 'Petra', 'Helena', 'Ludvík', 'Bernard', 'Johana', 'Bohuslav', 'Sandra', 'Bartoloměj', 'Radim', 'Luděk', 'Otakar', 'Augustýn', 'Evelína', 'Vladěna', 'Pavlína'],
    ['Linda / Samuel', 'Adéla', 'Bronislav', 'Jindřiška', 'Boris', 'Boleslav', 'Regína', 'Mariana', 'Daniela', 'Irma', 'Denisa', 'Marie', 'Lubor', 'Radka', 'Jolana', 'Ludmila', 'Naděžda', 'Kryštof', 'Zita', 'Oleg', 'Matouš', 'Darina', 'Berta', 'Jaromír', 'Zlata', 'Andrea', 'Jonáš', 'Václav', 'Michal', 'Jeroným'],
    ['Igor', 'Olivie / Oliver', 'Bohumil', 'František', 'Eliška', 'Hanuš', 'Justýna', 'Věra', 'Štefan / Sára', 'Marina', 'Andrej', 'Marcel', 'Renáta', 'Agáta', 'Tereza', 'Havel', 'Hedvika', 'Lukáš', 'Michaela', 'Vendelín', 'Brigita', 'Sabina', 'Teodor', 'Nina', 'Beáta', 'Erik', 'Šarlota / Zoe', '', 'Silvie', 'Tadeáš', 'Štěpánka'],
    ['Felix', 'Památka zesnulých', 'Hubert', 'Karel', 'Miriam', 'Liběna', 'Saskie', 'Bohumír', 'Bohdan', 'Evžen', 'Martin', 'Benedikt', 'Tibor', 'Sáva', 'Leopold', 'Otmar', '', 'Romana', 'Alžběta', 'Nikola', 'Albert', 'Cecílie', 'Klement', 'Emílie', 'Kateřina', 'Artur', 'Xenie', 'René', 'Zina', 'Ondřej'],
    ['Iva', 'Blanka', 'Svatoslav', 'Barbora', 'Jitka', 'Mikuláš', 'Ambrož / Benjamín', 'Květoslava', 'Vratislav', 'Julie', 'Dana', 'Simona', 'Lucie', 'Lýdie', 'Radana / Radan', 'Albína', 'Daniel', 'Miloslav', 'Ester', 'Dagmar', 'Natálie', 'Šimon', 'Vlasta', 'Adam / Eva', '', 'Štěpán', 'Žaneta', 'Bohumila', 'Judita', 'David', 'Silvestr'],
]);

/** Fixed-date public holidays, key 'M-D'. */
export const FIXED_HOLIDAYS = Object.freeze({
    '1-1': 'Nový rok / Den obnovy samostatného českého státu',
    '5-1': 'Svátek práce',
    '5-8': 'Den vítězství',
    '7-5': 'Den slovanských věrozvěstů Cyrila a Metoděje',
    '7-6': 'Den upálení mistra Jana Husa',
    '9-28': 'Den české státnosti',
    '10-28': 'Den vzniku samostatného československého státu',
    '11-17': 'Den boje za svobodu a demokracii',
    '12-24': 'Štědrý den',
    '12-25': '1. svátek vánoční',
    '12-26': '2. svátek vánoční',
});

export function nameDay(m, d) {
    const month = NAME_DAYS[m - 1];
    return (month && month[d - 1]) || '';
}

/** Public holiday name of a date, '' when none. */
export function holidayName(isoDate) {
    const p = parts(isoDate);
    const fixed = FIXED_HOLIDAYS[`${p.m}-${p.d}`];
    if (fixed) return fixed;
    if (p.m === 3 || p.m === 4) {
        const easter = easterSunday(p.y);
        if (isoDate === addDays(easter, -2)) return 'Velký pátek';
        if (isoDate === addDays(easter, 1)) return 'Velikonoční pondělí';
    }
    return '';
}

export function pluralPages(n) {
    return n === 1 ? 'strana' : n >= 2 && n <= 4 ? 'strany' : 'stran';
}

export function pluralTerms(n) {
    return n === 1 ? 'termín' : n >= 2 && n <= 4 ? 'termíny' : 'termínů';
}
