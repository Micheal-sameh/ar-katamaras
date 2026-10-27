// Talks to the Katameros API directly (no Laravel server) and answers in the same JSON shape as
// our server's GET /api/readings/{date}, so the web app runs unchanged. Mirrors
// app/Services/KatamerosApiClient.php and app/Http/Resources/DailyReadingResource.php.
const fs = require('fs');
const path = require('path');

const BASE_URL = process.env.KATAMEROS_API_URL || 'https://api.katameros.app';
const LANGUAGE_IDS = { ar: 3, en: 2, fr: 1 };
const BIBLES = {
  ar: { default: 11, available: [[11, 'الكتاب المقدس، فان دايك'], [3, 'Arabic']] },
  en: { default: 2, available: [[2, 'NKJV']] },
  fr: { default: 1, available: [[1, 'Louis Segond 1910']] },
};
const MONTHS = {
  ar: ['توت', 'بابه', 'هاتور', 'كيهك', 'طوبه', 'أمشير', 'برمهات', 'برمودة', 'بشنس', 'بؤونة', 'أبيب', 'مسرى', 'النسيء'],
  en: ['Thout', 'Paopi', 'Hathor', 'Koiak', 'Tobi', 'Meshir', 'Paremhat', 'Paremoude', 'Pashons', 'Paoni', 'Epip', 'Mesori', 'Nasie'],
};

const LABELS = {
  reading: {
    ar: { pauline: 'البولس', catholic: 'الكاثوليكون', praxis: 'الإبركسيس', psalm: 'المزمور', gospel: 'الإنجيل', psalm_gospel: 'المزمور والإنجيل', synaxarium: 'السنكسار', other: 'قراءة' },
    fr: { pauline: 'Épître de Paul', catholic: 'Épître catholique', praxis: 'Actes', psalm: 'Psaume', gospel: 'Évangile', psalm_gospel: 'Psaume et Évangile', synaxarium: 'Synaxaire', other: 'Lecture' },
    en: { pauline: 'Pauline Epistle', catholic: 'Catholic Epistle', praxis: 'Praxis', psalm: 'Psalm', gospel: 'Gospel', psalm_gospel: 'Psalm & Gospel', synaxarium: 'Synaxarium', other: 'Reading' },
  },
  section: {
    ar: { synaxarium: 'السنكسار' },
    fr: { synaxarium: 'Synaxaire' },
    en: { synaxarium: 'Synaxarium' },
  },
};

// Declaration order matters: the combined title is tested before its parts.
const READING_KEYWORDS = [
  ['psalm_gospel', ['المزمور والانجيل', 'psalm & gospel', 'psalm and gospel', 'psaume et evangile']],
  ['pauline', ['بولس', 'pauline', 'epitre de paul']],
  ['catholic', ['كاثوليكون', 'catholic', 'catholique']],
  ['praxis', ['ابركسيس', 'praxis', 'actes', 'acts']],
  ['psalm', ['مزمور', 'psalm', 'psaume']],
  ['gospel', ['انجيل', 'gospel', 'evangile']],
  ['synaxarium', ['سنكسار', 'synaxar', 'synaxair']],
];
const SECTION_KEYWORDS = [
  ['vespers', ['عشيه', 'vespers', 'vepres']],
  ['matins', ['باكر', 'matins', 'matines']],
  ['liturgy', ['قداس', 'liturgy', 'liturgie']],
  ['synaxarium', ['سنكسار', 'synaxar', 'synaxair']],
];

class ReadingsError extends Error {
  constructor(message, status = 503) {
    super(message);
    this.status = status;
  }
}

function forMatching(text) {
  return String(text || '').trim().toLowerCase()
    .replace(/[ً-ٰٟـ]/g, '')
    .replace(/[أإآ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه').replace(/ؤ/g, 'و').replace(/ئ/g, 'ي')
    .replace(/[éèê]/g, 'e').replace(/ô/g, 'o')
    .replace(/\s+/g, ' ');
}

function typeFromTitle(table, title) {
  const normalized = forMatching(title);
  for (const [type, words] of table) {
    if (words.some((w) => normalized.includes(w))) return type;
  }
  return 'other';
}

const ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ' };

function htmlToParagraphs(html) {
  if (!html || !String(html).trim()) return [];
  const text = String(html)
    .replace(/<(script|style)\b[^>]*>[\s\S]*?<\/\1>/gi, '')
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/(p|div|li|h[1-6])>/gi, '\n\n')
    .replace(/<[^>]*>/g, '')
    .replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, (m, e) => {
      if (e[0] === '#') {
        const code = e[1].toLowerCase() === 'x' ? parseInt(e.slice(2), 16) : parseInt(e.slice(1), 10);
        return Number.isFinite(code) && code > 0 && code < 0x110000 ? String.fromCodePoint(code) : m;
      }
      return ENTITIES[e.toLowerCase()] ?? m;
    });
  return text.split(/\n\s*\n/).map((p) => p.replace(/\s+/g, ' ').trim()).filter(Boolean);
}

const nullableText = (value) => {
  const text = String(value ?? '').trim();
  return text === '' ? null : text;
};

function readingType(subType, bookId, index, count) {
  if (subType !== 'psalm_gospel') return subType;
  if (bookId === 19) return 'psalm';
  if (bookId !== null && bookId >= 40 && bookId <= 43) return 'gospel';
  if (count === 2 && index === 0) return 'psalm';
  if (count === 2 && index === 1) return 'gospel';
  return 'psalm_gospel';
}

function mapReading(reading, subType, index, count) {
  let bookId = null;
  const passages = [];
  for (const passage of reading.passages || []) {
    if (!passage || typeof passage !== 'object') continue; // null for readings without scripture (the Synaxarium)
    if (bookId === null && passage.bookId != null) bookId = Number(passage.bookId);
    passages.push({
      bookTranslation: nullableText(passage.bookTranslation),
      ref: String(passage.ref ?? ''),
      verses: (passage.verses || []).map((v) => ({ number: Number(v.number) || 0, text: String(v.text ?? '').trim(), chapter: Number(v.chapter) || 0 })),
    });
  }
  return {
    title: String(reading.title ?? ''),
    type: readingType(subType, bookId, index, count),
    introduction: nullableText(reading.introduction),
    conclusion: nullableText(reading.conclusion),
    passages,
    paragraphs: htmlToParagraphs(reading.html),
  };
}

/** The API nests the Synaxarium inside the Liturgy; the app shows it as its own section. */
function hoistSynaxarium(sections, lang) {
  const result = [];
  let synaxarium = [];
  for (const section of sections) {
    if (section.type === 'synaxarium') {
      synaxarium = synaxarium.concat(section.subSections);
      continue;
    }
    const own = section.subSections.filter((s) => s.type !== 'synaxarium');
    synaxarium = synaxarium.concat(section.subSections.filter((s) => s.type === 'synaxarium'));
    if (own.length) result.push({ ...section, subSections: own });
  }
  if (synaxarium.length) result.push({ type: 'synaxarium', title: LABELS.section[lang].synaxarium, subSections: synaxarium });
  return result;
}

function copticDate(value, isoDate) {
  const m = typeof value === 'string' && /^(\d{1,2})\/(\d{1,2})\/(\d{3,4})$/.exec(value.trim());
  if (m && Number(m[2]) >= 1 && Number(m[2]) <= 13) return { day: Number(m[1]), month: Number(m[2]), year: Number(m[3]) };
  const [y, mo, d] = isoDate.split('-').map(Number);
  require('../app/js/coptic.js');
  return globalThis.Coptic.fromGregorian(y, mo, d);
}

function bibleList(lang) {
  const bibles = BIBLES[lang];
  return bibles.available.map(([id, name]) => ({ id, name, default: id === bibles.default }));
}

function map(json, isoDate, lang, bibleId) {
  const sections = (json.sections || []).map((section) => {
    const subSections = (section.subSections || []).map((sub) => {
      const subTitle = String(sub.title ?? '');
      const subType = typeFromTitle(READING_KEYWORDS, subTitle);
      const raw = (sub.readings || []).filter((r) => r && typeof r === 'object');
      return {
        type: subType,
        title: subTitle,
        introduction: nullableText(sub.introduction),
        readings: raw.map((r, i) => mapReading(r, subType, i, raw.length)),
      };
    });
    const title = String(section.title ?? '');
    return { type: typeFromTitle(SECTION_KEYWORDS, title), title, subSections };
  });

  const hoisted = hoistSynaxarium(sections, lang);
  const hasText = hoisted.some((s) => s.subSections.some((sub) => sub.readings.some((r) => r.paragraphs.length || r.passages.some((p) => p.verses.some((v) => v.text)))));
  if (!hasText) throw new ReadingsError(`Katameros returned no readable text for ${isoDate}.`);

  const coptic = copticDate(json.copticDate, isoDate);
  const effectiveBible = bibleId > 0 ? bibleId : BIBLES[lang].default;
  for (const section of hoisted) {
    for (const sub of section.subSections) {
      for (const reading of sub.readings) reading.label = LABELS.reading[lang][reading.type] ?? null;
    }
  }

  return {
    date: isoDate,
    language: lang,
    copticDate: { ...coptic, monthName: MONTHS[lang === 'ar' ? 'ar' : 'en'][coptic.month - 1], formatted: `${coptic.day} ${MONTHS[lang === 'ar' ? 'ar' : 'en'][coptic.month - 1]} ${coptic.year}` },
    bible: { id: effectiveBible, name: (BIBLES[lang].available.find(([id]) => id === effectiveBible) || [])[1] ?? null },
    bibles: bibleList(lang),
    sections: hoisted,
  };
}

class KatamerosDirect {
  /** @param {string} cacheDir folder where every fetched day is kept, so the app also works offline */
  constructor(cacheDir) {
    this.cacheDir = cacheDir;
    fs.mkdirSync(cacheDir, { recursive: true });
  }

  cacheFile(iso, lang, bibleId) {
    return path.join(this.cacheDir, `${iso}.${lang}.${bibleId}.json`);
  }

  /** Deletes saved days outside a small window (2 days back, 16 ahead), so the folder never grows. */
  prune(today = new Date()) {
    const iso = (n) => { const d = new Date(today.getFullYear(), today.getMonth(), today.getDate() + n); return [d.getFullYear(), String(d.getMonth() + 1).padStart(2, '0'), String(d.getDate()).padStart(2, '0')].join('-'); };
    const oldest = iso(-2), newest = iso(16);
    for (const name of fs.readdirSync(this.cacheDir)) {
      const day = /^(\d{4}-\d{2}-\d{2})\./.exec(name);
      if (day && (day[1] < oldest || day[1] > newest)) { try { fs.unlinkSync(path.join(this.cacheDir, name)); } catch (e) { /* in use: next start */ } }
    }
  }

  readCache(iso, lang, bibleId) {
    try { return JSON.parse(fs.readFileSync(this.cacheFile(iso, lang, bibleId), 'utf8')); } catch (e) { return null; }
  }

  async get(iso, lang, requestedBible = 0) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(iso) || Number.isNaN(Date.parse(iso))) throw new ReadingsError('Invalid date.', 422);
    if (!LANGUAGE_IDS[lang]) throw new ReadingsError('Unknown language.', 422);
    const bibles = BIBLES[lang];
    if (requestedBible !== 0 && !bibles.available.some(([id]) => id === requestedBible)) throw new ReadingsError('Unknown Bible.', 422);
    const bibleId = requestedBible === bibles.default ? 0 : requestedBible; // the default is stored as 0

    const cached = this.readCache(iso, lang, bibleId);
    if (cached) return cached;

    const [y, m, d] = iso.split('-');
    const query = new URLSearchParams({ languageId: String(LANGUAGE_IDS[lang]), bibleId: String(bibleId || bibles.default) });
    let json;
    try {
      const response = await fetch(`${BASE_URL}/readings/gregorian/${d}-${m}-${y}?${query}`, {
        headers: { Accept: 'application/json' },
        signal: AbortSignal.timeout(15000),
      });
      if (response.status === 429) throw new ReadingsError('Katameros asked us to slow down.', 429);
      if (!response.ok) throw new ReadingsError(`Katameros API answered HTTP ${response.status}.`);
      json = await response.json();
    } catch (e) {
      if (e instanceof ReadingsError) throw e;
      throw new ReadingsError(`Katameros API unreachable: ${e.message}`);
    }
    if (!json || !Array.isArray(json.sections)) throw new ReadingsError('Katameros API returned an unexpected body.');

    const day = map(json, iso, lang, bibleId);
    fs.writeFileSync(this.cacheFile(iso, lang, bibleId), JSON.stringify(day));
    return day;
  }
}

module.exports = { KatamerosDirect, ReadingsError, map, htmlToParagraphs, typeFromTitle };
