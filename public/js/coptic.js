/* Coptic calendar helpers (Gregorian <-> Coptic). No dependencies. */
(function (root) {
  const COPTIC_EPOCH_JDN = 1825030; // 1 Thout 1 AM (29 Aug 284 Julian)

  const MONTHS = {
    ar: ['توت', 'بابه', 'هاتور', 'كيهك', 'طوبه', 'أمشير', 'برمهات', 'برمودة', 'بشنس', 'بؤونة', 'أبيب', 'مسرى', 'النسيء'],
    en: ['Thout', 'Paopi', 'Hathor', 'Koiak', 'Tobi', 'Meshir', 'Paremhat', 'Paremoude', 'Pashons', 'Paoni', 'Epip', 'Mesori', 'Nasie'],
  };

  function gregorianToJdn(y, m, d) {
    const a = Math.floor((14 - m) / 12);
    const yy = y + 4800 - a;
    const mm = m + 12 * a - 3;
    return d + Math.floor((153 * mm + 2) / 5) + 365 * yy + Math.floor(yy / 4) - Math.floor(yy / 100) + Math.floor(yy / 400) - 32045;
  }

  const yearStart = (y) => 365 * (y - 1) + Math.floor(y / 4);

  /** @returns {{day:number, month:number, year:number}} month is 1..13 */
  function fromGregorian(y, m, d) {
    const n = gregorianToJdn(y, m, d) - COPTIC_EPOCH_JDN;
    let year = Math.floor(n / 365.25) + 1;
    while (yearStart(year + 1) <= n) year++;
    while (yearStart(year) > n) year--;
    const doy = n - yearStart(year);
    return { day: (doy % 30) + 1, month: Math.floor(doy / 30) + 1, year };
  }

  function monthName(month, lang) {
    return (MONTHS[lang] || MONTHS.en)[month - 1] || '';
  }

  root.Coptic = { fromGregorian, monthName, MONTHS };
})(typeof window !== 'undefined' ? window : globalThis);
