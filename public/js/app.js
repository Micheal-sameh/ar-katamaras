/* Coptic Orthodox daily readings — plain JS PWA.
   Expected endpoint:  GET {API_BASE}/{YYYY-MM-DD}?lang=ar|en
   Response shape:     see normalize() below. */
(function () {
  'use strict';

  // ---------------------------------------------------------------- config
  const API_BASE = (window.APP_CONFIG && window.APP_CONFIG.API_BASE) || '/api/readings'; // set in js/config.js
  const DEMO = !!(window.APP_CONFIG && window.APP_CONFIG.ALLOW_DEMO) && new URLSearchParams(location.search).has('demo');
  const STORE_PREFIX = 'reading:';
  // Offline copy kept on the device: a small rolling window, so it never grows (a day is about 40 KB, so this
  // is well under 1 MB per language). Every SYNC_EVERY_DAYS the app asks the server for the next SYNC_DAYS days.
  const KEEP_BEHIND_DAYS = 2;
  const KEEP_AHEAD_DAYS = 16;
  const SYNC_DAYS = 14;
  const SYNC_EVERY_DAYS = 14;
  const SYNC_MIN_AHEAD_DAYS = 7; // also top up early when fewer than this many days are left ahead
  const FONT_MIN = 17;
  const FONT_MAX = 30;
  const FONT_FAMILIES = {
    amiri: ['Amiri', "Amiri, Georgia, serif"],
    naskh: ['Noto Naskh Arabic', "'Noto Naskh Arabic', Georgia, serif"],
    scheherazade: ['Scheherazade New', "'Scheherazade New', Georgia, serif"],
    cairo: ['Cairo', "Cairo, sans-serif"],
    tajawal: ['Tajawal', "Tajawal, sans-serif"],
  };
  const SPEED_MIN = 2; // auto-scroll, pixels per second
  const SPEED_MAX = 30;

  // ---------------------------------------------------------------- i18n
  const T = {
    ar: {
      appName: 'قراءات اليوم', today: 'اليوم', calendar: 'التقويم', synaxarium: 'السنكسار', settings: 'الإعدادات',
      previous: 'اليوم السابق', next: 'اليوم التالي', nextReading: 'القراءة التالية', prevReading: 'القراءة السابقة', selectDate: 'اختر التاريخ', switchLang: 'English',
      focus: 'وضع القراءة', presentMode: 'عرض تقديمي', scrollMode: 'عرض بالتمرير', slideNext: 'الشريحة التالية', slidePrev: 'الشريحة السابقة', headerLabel: 'شريط عنوان القراءة', headerKeep: 'ثابت', headerScroll: 'يتحرك مع النص', fullscreen: 'ملء الشاشة', back: 'رجوع', autoScroll: 'تمرير تلقائي', fontSmaller: 'تصغير الخط', fontLarger: 'تكبير الخط',
      darkMode: 'الوضع الليلي', lightMode: 'الوضع النهاري', nightToggle: 'تبديل الوضع الليلي',
      appearance: 'المظهر والقراءة', langLabel: 'لغة التطبيق', fontSize: 'حجم خط القراءات', fontType: 'نوع الخط', scrollSpeed: 'سرعة التمرير التلقائي', slower: 'أبطأ', faster: 'أسرع', theme: 'السمة',
      light: 'فاتح', dark: 'داكن', auto: 'تلقائي', preview: 'معاينة النص', previewText: 'كلامك سراج لرجلي ونور لسبيلي.',
      offlineBanner: 'أنت غير متصل — تعرض القراءات المحفوظة', savedBanner: 'تعذر الاتصال بالخادم — تعرض نسخة محفوظة على هذا الجهاز',
      errorTitle: 'تعذر تحميل القراءات', errorBody: 'لا توجد نسخة محفوظة لهذا اليوم، وتعذر الاتصال بالخادم. حاول مرة أخرى أو اختر يومًا آخر.',
      codeTitle: 'رمز الكنيسة', codeBody: 'هذا التطبيق لأبناء الكنيسة. اكتب الرمز الذي أعطته لك الكنيسة.', codePlaceholder: 'الرمز', codeSave: 'دخول', codeWrong: 'الرمز غير صحيح. حاول مرة أخرى.', codeBlocked: 'محاولات كثيرة خاطئة. انتظر دقيقة ثم حاول.', codeOffline: 'تعذر التحقق من الرمز الآن. تأكد من الاتصال وحاول مرة أخرى.', codeChange: 'تغيير رمز الكنيسة',
      emptyTitle: 'لا توجد قراءات لهذا اليوم', emptyBody: 'يمكنك اختيار يوم آخر.',
      slowLoading: 'نجلب قراءات هذا اليوم لأول مرة، وقد يستغرق ذلك بضع ثوانٍ…',
      chapter: 'الأصحاح', bibleLabel: 'نسخة الكتاب المقدس', bibleDefault: '(الافتراضية)',
      busyTitle: 'الطلبات كثيرة الآن', busyBody: 'سنحاول مرة أخرى بعد لحظات تلقائيًا.', rateBanner: 'الطلبات كثيرة الآن — تعرض القراءات المحفوظة وسنحاول مجددًا بعد قليل',
      rangeTitle: 'هذا التاريخ غير متاح', rangeBody: 'القراءات متاحة لمدة سنة قبل اليوم وسنة بعده. اختر تاريخًا أقرب.',
      retry: 'إعادة المحاولة', loading: 'جارٍ تحميل القراءات', openReadings: 'فتح قراءات اليوم', todayMark: 'اليوم', selectedMark: 'المحدد',
      prevMonth: 'الشهر السابق', nextMonth: 'الشهر التالي', mainNav: 'التنقل الرئيسي', yearSuffix: 'ش', dayReadings: 'قراءات اليوم',
      noSynax: 'لا يوجد سنكسار لهذا اليوم', locale: 'ar-EG-u-ca-gregory', types: { vespers: 'العشية', matins: 'باكر', liturgy: 'القداس', synaxarium: 'السنكسار' },
    },
    en: {
      appName: "Today's Readings", today: 'Today', calendar: 'Calendar', synaxarium: 'Synaxarium', settings: 'Settings',
      previous: 'Previous day', next: 'Next day', nextReading: 'Next reading', prevReading: 'Previous reading', selectDate: 'Choose a date', switchLang: 'العربية',
      focus: 'Reading focus', presentMode: 'Presentation view', scrollMode: 'Scrolling view', slideNext: 'Next slide', slidePrev: 'Previous slide', headerLabel: 'Reading header bar', headerKeep: 'Stays visible', headerScroll: 'Scrolls away', fullscreen: 'Full screen', back: 'Back', autoScroll: 'Auto-scroll', fontSmaller: 'Smaller text', fontLarger: 'Larger text',
      darkMode: 'Night mode', lightMode: 'Day mode', nightToggle: 'Toggle night mode',
      appearance: 'Appearance & reading', langLabel: 'App language', fontSize: 'Reading font size', fontType: 'Font type', scrollSpeed: 'Auto-scroll speed', slower: 'Slower', faster: 'Faster', theme: 'Theme',
      light: 'Light', dark: 'Dark', auto: 'Auto', preview: 'Text preview', previewText: 'Your word is a lamp to my feet and a light to my path.',
      offlineBanner: 'You are offline — showing saved readings', savedBanner: 'Server unreachable — showing a copy saved on this device',
      errorTitle: 'Could not load readings', errorBody: 'There is no saved copy for this day and the server cannot be reached. Try again or pick another day.',
      codeTitle: 'Church code', codeBody: 'This app is for members of the church. Enter the code the church gave you.', codePlaceholder: 'Code', codeSave: 'Enter', codeWrong: 'That code is not right. Try again.', codeBlocked: 'Too many wrong tries. Wait a minute and try again.', codeOffline: 'Could not check the code right now. Check your connection and try again.', codeChange: 'Change church code',
      emptyTitle: 'No readings for this day', emptyBody: 'Choose another day.',
      slowLoading: 'Fetching this day for the first time. It can take a few seconds…',
      chapter: 'Chapter', bibleLabel: 'Bible version', bibleDefault: '(default)',
      busyTitle: 'Too many requests right now', busyBody: 'We will try again automatically in a moment.', rateBanner: 'Too many requests right now: showing saved readings, trying again shortly',
      rangeTitle: 'This date is not available', rangeBody: 'Readings are available from one year before to one year after today. Pick a closer date.',
      retry: 'Try again', loading: 'Loading readings', openReadings: "Open day's readings", todayMark: 'Today', selectedMark: 'Selected',
      prevMonth: 'Previous month', nextMonth: 'Next month', mainNav: 'Main navigation', yearSuffix: 'AM', dayReadings: 'Daily readings',
      noSynax: 'No Synaxarium entry for this day', locale: 'en-US', types: { vespers: 'Vespers', matins: 'Matins', liturgy: 'Liturgy', synaxarium: 'Synaxarium' },
    },
  };

  // ---------------------------------------------------------------- icons
  const ICONS = {
    cross: '<path d="M9 2h6v5h5v6h-5v9H9v-9H4V7h5V2Z" />',
    calendar: '<rect x="3" y="5" width="18" height="16" rx="2" /><path d="M16 3v4M8 3v4M3 10h18" />',
    sun: '<circle cx="12" cy="12" r="4" /><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.66 6.34l1.41-1.41" />',
    moon: '<path d="M20 15.2A8.5 8.5 0 0 1 8.8 4a8.5 8.5 0 1 0 11.2 11.2Z" />',
    chevron: '<path d="m8 10 4 4 4-4" />',
    back: '<path d="m15 18-6-6 6-6" />',
    expand: '<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" />',
    shrink: '<path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5" />',
    scroll: '<path d="M7 4h11v14a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3V7h12" /><path d="M7 4a3 3 0 0 0-3 3h3V4Z" />',
    refresh: '<path d="M20 11a8 8 0 1 0-2.34 5.66" /><path d="M20 5v6h-6" />',
    book: '<path d="M4 5.5A3.5 3.5 0 0 1 7.5 2H11v18H7.5A3.5 3.5 0 0 0 4 23V5.5ZM20 5.5A3.5 3.5 0 0 0 16.5 2H13v18h3.5a3.5 3.5 0 0 1 3.5 3V5.5Z" />',
    settings: '<circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1 1.55V21h-4v-.08A1.7 1.7 0 0 0 9 19.37a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.63 15a1.7 1.7 0 0 0-1.55-1H3v-4h.08A1.7 1.7 0 0 0 4.63 9a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.63a1.7 1.7 0 0 0 1-1.55V3h4v.08A1.7 1.7 0 0 0 15 4.63a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.37 9a1.7 1.7 0 0 0 1.55 1H21v4h-.08A1.7 1.7 0 0 0 19.37 15Z" />',
    slides: '<rect x="3" y="4" width="18" height="12" rx="2" /><path d="M8 21h8M12 16v5" />',
    wifi: '<path d="M5 13a10 10 0 0 1 14 0M8.5 16.5a5 5 0 0 1 7 0" /><circle cx="12" cy="20" r=".5" />',
  };
  const icon = (name, size = 20) =>
    `<svg class="icon" width="${size}" height="${size}" viewBox="0 0 24 24" fill="${name === 'cross' ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[name]}</svg>`;
  const dirIcon = (reverse) => `<span class="direction-icon${reverse ? ' reverse' : ''}">${icon('back')}</span>`;

  // ---------------------------------------------------------------- helpers
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const pad = (n) => String(n).padStart(2, '0');
  const toISO = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`; // device-local, never UTC
  const parseISO = (iso) => { const [y, m, d] = iso.split('-').map(Number); return new Date(y, m - 1, d); };
  const todayISO = () => toISO(new Date());
  const addDays = (iso, n) => { const d = parseISO(iso); d.setDate(d.getDate() + n); return toISO(d); };

  const store = {
    get(key, fallback) { try { const v = localStorage.getItem(key); return v === null ? fallback : v; } catch (e) { return fallback; } },
    set(key, value) { try { localStorage.setItem(key, value); } catch (e) { /* storage full or blocked */ } },
    remove(key) { try { localStorage.removeItem(key); } catch (e) { /* ignore */ } },
  };

  // ---------------------------------------------------------------- state
  const state = {
    lang: store.get('lang', 'ar') === 'en' ? 'en' : 'ar',
    theme: ['light', 'dark', 'auto'].includes(store.get('theme')) ? store.get('theme') : 'auto',
    fontSize: clamp(parseInt(store.get('fontSize', '21'), 10) || 21, FONT_MIN, FONT_MAX),
    fontFamily: store.get('fontFamily') in FONT_FAMILIES ? store.get('fontFamily') : 'amiri',
    scrollSpeed: clamp(parseInt(store.get('scrollSpeed', '8'), 10) || 8, SPEED_MIN, SPEED_MAX),
    screen: 'today',
    stickyHeader: store.get('stickyHeader', '1') !== '0', // keep the reading header visible while scrolling
    date: todayISO(),
    calMonth: null, // {y, m} (m 0-based); null = follow selected date
    data: null,
    status: 'loading', // loading | ok | error | range | busy | empty
    errorMessage: '', // the server's own (already translated) message for a 503
    slow: false, // loading has taken longer than a few seconds
    rateLimited: false, // the server asked us to slow down (HTTP 429)
    fromStore: false,
    online: navigator.onLine,
    open: -1,
    tabs: {},
    focus: null, // {sec, tab}
    auto: false,
    present: store.get('present', '1') !== '0', // desktop: show the reading as slides
    slide: 0, // current slide; -1 = the last one
    slideCount: 1,
  };
  function clamp(n, lo, hi) { return Math.min(hi, Math.max(lo, n)); }

  const t = () => T[state.lang];
  const isAr = () => state.lang === 'ar';
  const num = (n) => (isAr() ? String(n).replace(/\d/g, (d) => '٠١٢٣٤٥٦٧٨٩'[d]) : String(n));

  // ---------------------------------------------------------------- formatting
  function fmtGregorian(iso) {
    return new Intl.DateTimeFormat(t().locale, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(parseISO(iso));
  }
  function copticOf(iso) {
    const [y, m, d] = iso.split('-').map(Number);
    return window.Coptic.fromGregorian(y, m, d);
  }
  function fmtCoptic(c) {
    return `${num(c.day)} ${window.Coptic.monthName(c.month, state.lang)} ${num(c.year)} ${t().yearSuffix}`;
  }
  const monthLabel = (y, m) => new Intl.DateTimeFormat(t().locale, { month: 'long', year: 'numeric' }).format(new Date(y, m, 1));

  // ---------------------------------------------------------------- data
  /** Accepts the endpoint payload (optionally wrapped in {data}) and returns a safe, plain structure. */
  function normalize(raw) {
    const p = raw && raw.data ? raw.data : raw;
    if (!p || !Array.isArray(p.sections)) return null;
    const str = (v) => (v == null ? '' : String(v));
    const sections = p.sections.map((s) => ({
      type: str(s.type),
      title: str(s.title),
      subSections: (s.subSections || []).map((sub) => ({
        type: str(sub.type),
        title: str(sub.title),
        introduction: str(sub.introduction),
        readings: (sub.readings || []).map((r) => ({
          title: str(r.title),
          label: str(r.label), // "Psalm" / "Gospel", for sub-sections holding several readings
          introduction: str(r.introduction),
          conclusion: str(r.conclusion),
          passages: (r.passages || []).map((ps) => ({
            ref: str(ps.ref),
            book: str(ps.bookTranslation),
            verses: (ps.verses || []).map((v) => ({ number: v.number, text: str(v.text), chapter: Number(v.chapter) || 0 })),
          })),
          // the server already sends plain-text paragraphs; html (demo data only) is stripped here
          paragraphs: Array.isArray(r.paragraphs) ? r.paragraphs.map(str).filter(Boolean) : htmlToParagraphs(r.html),
        })),
      })).filter((sub) => sub.readings.length),
    })).filter((s) => s.subSections.length);
    if (!sections.length) return null;
    const bibles = Array.isArray(p.bibles) ? p.bibles.map((b) => ({ id: Number(b.id), name: str(b.name), default: !!b.default })) : [];
    return { date: str(p.date), copticDate: p.copticDate || null, bible: p.bible ? Number(p.bible.id) : 0, bibles, sections: placeSynaxarium(sections) };
  }

  /** The server sends the Synaxarium as its own section; the Liturgy reads in order: Paul, Catholicon, Praxis, Synaxarium, Psalm & Gospel. */
  function placeSynaxarium(sections) {
    const synax = sections.find((s) => s.type === 'synaxarium');
    const liturgy = sections.find((s) => s.type === 'liturgy');
    if (!synax || !liturgy) return sections;
    const psalmAt = liturgy.subSections.findIndex((s) => ['psalm_gospel', 'psalm', 'gospel'].includes(s.type));
    liturgy.subSections.splice(psalmAt < 0 ? liturgy.subSections.length : psalmAt, 0, ...synax.subSections.filter((s) => !liturgy.subSections.includes(s)));
    return sections.filter((s) => s !== synax);
  }

  /** Never inject API HTML: parse inertly and keep text only. */
  function htmlToParagraphs(html) {
    if (!html) return [];
    const doc = new DOMParser().parseFromString(String(html), 'text/html');
    doc.querySelectorAll('script,style').forEach((n) => n.remove());
    doc.querySelectorAll('br').forEach((n) => n.replaceWith('\n'));
    doc.querySelectorAll('p,div,li,h1,h2,h3,h4,h5,h6').forEach((n) => n.append('\n\n'));
    return (doc.body.textContent || '').split(/\n\s*\n/).map((s) => s.replace(/\s+/g, ' ').trim()).filter(Boolean);
  }

  // The chosen Bible version per language (0 = the default one). Only offered when the server lists several.
  const bibleChoices = (() => { try { return JSON.parse(store.get('bibles', '{}')) || {}; } catch (e) { return {}; } })();
  const bibleFor = (lang) => Number(bibleChoices[lang]) || 0;
  // The church access code (when the server asks for one) goes along with every request.
  const churchCode = () => store.get('churchCode', '');
  const apiHeaders = () => { const h = { Accept: 'application/json' }; const c = churchCode(); if (c) h['X-Church-Code'] = c; return h; };
  const readingUrl = (iso, lang) => `${API_BASE}/${iso}?lang=${lang}${bibleFor(lang) ? `&bible=${bibleFor(lang)}` : ''}`;
  const storeKey = (iso, lang) => `${STORE_PREFIX}${lang}${bibleFor(lang) ? `b${bibleFor(lang)}` : ''}:${iso}`;
  function readStored(iso, lang) {
    try { return normalize(JSON.parse(store.get(storeKey(iso, lang), 'null'))); } catch (e) { return null; }
  }
  function writeStored(iso, lang, raw) {
    store.set(storeKey(iso, lang), JSON.stringify(raw));
    pruneStored();
  }
  /** Drops saved days outside the window (a little behind today, up to KEEP_AHEAD_DAYS ahead). */
  function pruneStored() {
    try {
      const oldest = addDays(todayISO(), -KEEP_BEHIND_DAYS);
      const newest = addDays(todayISO(), KEEP_AHEAD_DAYS);
      Object.keys(localStorage).filter((k) => k.startsWith(STORE_PREFIX)).forEach((k) => {
        const iso = k.split(':')[2];
        if (iso < oldest || iso > newest) store.remove(k);
      });
    } catch (e) { /* ignore */ }
  }

  /** How many of the next days are already saved for this language (stops at the first gap). */
  function savedAhead(lang) {
    let n = 0;
    while (n < SYNC_DAYS && readStored(addDays(todayISO(), n), lang)) n++;
    return n;
  }

  let syncing = false;
  /** Tops up the offline window with ONE request for the next two weeks (stored days only: it never makes the server fetch). */
  async function syncAhead(force = false) {
    if (DEMO || syncing || !state.online || Date.now() < pausedUntil) return;
    const lang = state.lang;
    const key = `syncedAt:${lang}`;
    const last = Number(store.get(key, '0')) || 0;
    const due = Date.now() - last >= SYNC_EVERY_DAYS * 86400000;
    if (!force && !due && savedAhead(lang) >= SYNC_MIN_AHEAD_DAYS) return;
    syncing = true;
    try {
      const from = todayISO();
      const res = await fetch(`${API_BASE}/range?from=${from}&days=${SYNC_DAYS}&lang=${lang}${bibleFor(lang) ? `&bible=${bibleFor(lang)}` : ''}`, { headers: apiHeaders(), priority: 'low' });
      if (res.status === 429) { pauseRequests(clamp(parseInt(res.headers.get('Retry-After'), 10) || 30, 5, 120)); return; }
      if (res.status === 401) return; // the code screen is shown by the next day request
      if (!res.ok) return; // an older server without /range: the app still works day by day
      const body = await res.json();
      const days = body && body.data && Array.isArray(body.data.days) ? body.data.days : [];
      days.forEach((day) => { if (day && day.date) store.set(storeKey(day.date, lang), JSON.stringify(day)); });
      if (days.length) store.set(key, String(Date.now()));
      pruneStored();
    } catch (e) { /* offline or server down: try again next time the app starts */ } finally { syncing = false; }
  }

  async function fetchDay(iso, lang) {
    if (DEMO) return window.DemoReadings.build(iso, lang);
    const res = await fetch(readingUrl(iso, lang), { headers: apiHeaders() });
    if (!res.ok) {
      const err = new Error(`HTTP ${res.status}`);
      err.status = res.status;
      if (res.status === 429) err.retryAfter = clamp(parseInt(res.headers.get('Retry-After'), 10) || 30, 5, 120);
      if (res.status === 503) { // "readings unavailable": the server writes this message in the visitor's language
        try {
          const body = await res.json();
          if (body && typeof body.message === 'string') err.serverMessage = body.message;
        } catch (e) { /* not JSON: use the generic text */ }
      }
      throw err;
    }
    return res.json();
  }

  const SLOW_AFTER_MS = 4000;
  let loadToken = 0;

  function showDay(data, fromStore) {
    state.data = data;
    state.status = 'ok';
    state.fromStore = fromStore;
    const li = data.sections.findIndex((s) => s.type === 'liturgy');
    state.open = li >= 0 ? li : 0;
    state.tabs = {};
  }

  async function load() {
    const token = ++loadToken;
    const { date, lang } = state;
    state.errorMessage = '';
    state.slow = false;

    // A copy already on this device (viewed earlier, or prefetched) shows instantly;
    // the server is still asked below so the text never stays stale.
    const stored = readStored(date, lang);
    const storedRaw = stored ? store.get(storeKey(date, lang), null) : null;
    if (stored) {
      showDay(stored, false);
    } else {
      state.status = 'loading';
      state.fromStore = false;
      setTimeout(() => {
        if (token === loadToken && state.status === 'loading') { state.slow = true; render(); }
      }, SLOW_AFTER_MS);
    }
    render();

    try {
      const raw = await fetchDay(date, lang);
      if (token !== loadToken) return; // user navigated on
      const data = normalize(raw);
      if (!data) {
        if (!stored) { state.status = 'empty'; render(); }
        return;
      }
      writeStored(date, lang, raw); // never store an empty/invalid response
      const bannerWasShown = state.rateLimited || state.fromStore;
      state.rateLimited = false;
      state.fromStore = false;
      if (!stored || JSON.stringify(raw) !== storedRaw) { showDay(data, false); render(); }
      else if (bannerWasShown) render(); // same text as the saved copy, but the "busy / offline" banner must go
      prefetchNeighbours(date, lang);
    } catch (e) {
      if (token !== loadToken) return;
      if (e && e.status === 429) { // the server asked us to slow down: wait, then try again by ourselves
        state.rateLimited = true;
        pauseRequests(e.retryAfter);
        setTimeout(() => { if (token === loadToken) load(); }, (e.retryAfter + 1) * 1000);
        if (!stored) state.status = 'busy';
        render();
        return;
      }
      if (stored) { state.fromStore = true; render(); return; } // offline / server down: keep the saved copy
      if (e && e.status === 401) { state.status = 'locked'; state.codeError = ''; render(); return; }
      if (e && e.status === 422) state.status = 'range';
      else { state.status = 'error'; state.errorMessage = (e && e.serverMessage) || ''; }
      render();
    }
  }

  // Quietly fetch the days either side so stepping through the calendar is instant.
  // This also asks the server for them, so the slow "first time" fetch happens before anyone waits for it.
  const prefetching = new Set();
  let pausedUntil = 0;
  const pauseRequests = (seconds) => { pausedUntil = Date.now() + seconds * 1000; };
  async function prefetch(iso, lang) {
    if (DEMO || Date.now() < pausedUntil || !navigator.onLine || (navigator.connection && navigator.connection.saveData)) return;
    const key = storeKey(iso, lang);
    if (prefetching.has(key) || readStored(iso, lang)) return;
    prefetching.add(key);
    try {
      const res = await fetch(readingUrl(iso, lang), { headers: apiHeaders(), priority: 'low' });
      if (res.status === 401) return;
      if (res.status === 429) pauseRequests(clamp(parseInt(res.headers.get('Retry-After'), 10) || 30, 5, 120));
      if (res.ok) {
        const raw = await res.json();
        if (normalize(raw)) writeStored(iso, lang, raw);
      }
    } catch (e) { /* best effort only */ } finally { prefetching.delete(key); }
  }
  function prefetchNeighbours(iso, lang) {
    setTimeout(() => prefetch(addDays(iso, 1), lang), 800); // one request, not two: the rate limit is per visitor
  }
  // ---------------------------------------------------------------- preferences
  function applyTheme() {
    const dark = state.theme === 'dark' || (state.theme === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('is-dark', dark);
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', dark ? '#000000' : '#faf7f0');
    return dark;
  }
  const isDark = () => state.theme === 'dark' || (state.theme === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
  function setTheme(v) { state.theme = v; store.set('theme', v); }
  function setFamily(k) {
    if (!(k in FONT_FAMILIES)) return;
    state.fontFamily = k;
    store.set('fontFamily', k);
    document.documentElement.style.setProperty('--font-scripture', FONT_FAMILIES[k][1]);
  }
  function setSpeed(n) { state.scrollSpeed = clamp(n, SPEED_MIN, SPEED_MAX); store.set('scrollSpeed', String(state.scrollSpeed)); }
  function setFont(n) { state.fontSize = clamp(n, FONT_MIN, FONT_MAX); store.set('fontSize', String(state.fontSize)); }
  function setLang(l) {
    if (state.lang === l) return;
    state.lang = l;
    store.set('lang', l);
    load();
    setTimeout(syncAhead, 3000);
  }

  // ---------------------------------------------------------------- views
  function button({ act, label, variant = 'ghost', ic = '', extra = '', title = '', active = false, data = '' }) {
    return `<button type="button" class="button button--${variant}${active ? ' is-active' : ''}" data-act="${act}" ${data}${title ? ` title="${esc(title)}" aria-label="${esc(title)}"` : ''}${extra}>${ic}${label ? esc(label) : ''}</button>`;
  }

  function header() {
    const tr = t();
    const c = copticOf(state.date);
    const nav = [['today', tr.today], ['calendar', tr.calendar], ['synaxarium', tr.synaxarium], ['settings', tr.settings]]
      .map(([id, label]) => button({ act: 'nav', label, active: state.screen === id, data: `data-screen="${id}"` })).join('');
    return `<header class="app-header"><div class="header-inner">
      <button type="button" class="brand" data-act="nav" data-screen="today" aria-label="${esc(tr.appName)}">
        <span class="brand-mark">${icon('cross', 18)}</span>
        <span><strong>${esc(tr.appName)}</strong><small>${esc(fmtCoptic(c))} · ${esc(fmtGregorian(state.date))}</small></span>
      </button>
      <nav class="desktop-nav" aria-label="${esc(tr.mainNav)}">${nav}</nav>
      <div class="header-actions">
        ${button({ act: 'toggleTheme', variant: 'icon', title: isDark() ? tr.lightMode : tr.darkMode, ic: icon(isDark() ? 'sun' : 'moon') })}
        ${button({ act: 'nav', variant: 'icon', title: tr.settings, ic: icon('settings'), data: 'data-screen="settings"' })}
      </div>
    </div></header>`;
  }

  function controlBar() {
    const tr = t();
    return `<div class="control-bar"><div class="control-inner">
      <div class="day-controls">
        ${button({ act: 'prev', variant: 'icon', title: tr.previous, ic: dirIcon(false) })}
        ${button({ act: 'today', variant: 'soft', label: tr.today })}
        ${button({ act: 'next', variant: 'icon', title: tr.next, ic: dirIcon(true) })}
        ${button({ act: 'nav', variant: 'icon', title: tr.selectDate, ic: icon('calendar'), data: 'data-screen="calendar"' })}
      </div>
      <div class="reading-controls">
        ${button({ act: 'fontMinus', variant: 'icon', title: tr.fontSmaller, ic: '<span class="a-control">A−</span>', extra: state.fontSize <= FONT_MIN ? ' disabled' : '' })}
        ${button({ act: 'fontPlus', variant: 'icon', title: tr.fontLarger, ic: '<span class="a-control">A+</span>', extra: state.fontSize >= FONT_MAX ? ' disabled' : '' })}
      </div>
    </div></div>`;
  }

  function calendarCard(wide) {
    const tr = t();
    const sel = parseISO(state.date);
    const cm = state.calMonth || { y: sel.getFullYear(), m: sel.getMonth() };
    const first = new Date(cm.y, cm.m, 1).getDay();
    const total = new Date(cm.y, cm.m + 1, 0).getDate();
    const today = todayISO();
    const weekdays = Array.from({ length: 7 }, (_, i) => new Intl.DateTimeFormat(tr.locale, { weekday: 'narrow' }).format(new Date(2023, 0, 1 + i)));
    let cells = '';
    for (let i = 0; i < first; i++) cells += '<span class="date-cell is-empty"></span>';
    for (let d = 1; d <= total; d++) {
      const iso = `${cm.y}-${pad(cm.m + 1)}-${pad(d)}`;
      const c = window.Coptic.fromGregorian(cm.y, cm.m + 1, d);
      const cls = `${iso === today ? ' is-today' : ''}${iso === state.date ? ' is-selected' : ''}`;
      cells += `<button type="button" class="date-cell${cls}" data-act="pick" data-date="${iso}" aria-label="${esc(fmtGregorian(iso))}" ${iso === state.date ? 'aria-current="date"' : ''}><strong>${num(d)}</strong><small>${num(c.day)}</small></button>`;
    }
    return `<div class="calendar-card">
      <div class="calendar-head">
        ${button({ act: 'calPrev', variant: 'icon', title: tr.prevMonth, ic: dirIcon(false) })}
        <strong>${esc(monthLabel(cm.y, cm.m))}</strong>
        ${button({ act: 'calNext', variant: 'icon', title: tr.nextMonth, ic: dirIcon(true) })}
      </div>
      <div class="calendar-grid calendar-weekdays" aria-hidden="true">${weekdays.map((w) => `<span>${esc(w)}</span>`).join('')}</div>
      <div class="calendar-grid">${cells}</div>
      <div class="calendar-legend"><span><i class="legend-today"></i>${esc(tr.todayMark)}</span><span><i class="legend-selected"></i>${esc(tr.selectedMark)}</span></div>
    </div>`;
  }

  function sidebar() {
    const tr = t();
    const items = state.data ? state.data.sections : [];
    const nav = items.map((s, i) => `<button type="button" class="${state.open === i ? 'active' : ''}" data-act="section" data-i="${i}"><span>${esc(s.title || tr.types[s.type] || '')}</span><small>${num(pad(i + 1))}</small></button>`).join('');
    return `<aside class="desktop-sidebar">${calendarCard()}${items.length ? `<div class="quick-card"><span class="eyebrow">${esc(tr.dayReadings)}</span><nav>${nav}</nav></div>` : ''}</aside>`;
  }

  function readingItem(r, sub) {
    let out = '<div class="reading-item">';
    const heading = r.title && r.title !== sub.title ? r.title : (sub.readings.length > 1 ? r.label : '');
    if (heading) out += `<h3>${esc(heading)}</h3>`;
    if (r.introduction) out += `<p class="reading-intro">${esc(r.introduction)}</p>`;
    r.passages.forEach((p) => {
      const ref = [p.book, p.ref].filter(Boolean).join(' ');
      if (ref) out += `<span class="reference-chip">${esc(ref)}</span>`;
      let chapter = 0;
      out += `<div class="scripture">${p.verses.map((v) => {
        // a passage that runs into the next chapter (3:22 to 4:2) gets a small marker, so the numbers do not look repeated
        const mark = v.chapter && chapter && v.chapter !== chapter ? `<div class="chapter-mark">${esc(t().chapter)} ${esc(num(v.chapter))}</div>` : '';
        if (v.chapter) chapter = v.chapter;
        return `${mark}<div class="verse-row">${v.number ? `<sup>${esc(num(v.number))}</sup>` : ''}<span>${esc(v.text)}</span></div>`;
      }).join('')}</div>`;
    });
    if (r.paragraphs.length) out += `<div class="plain-copy">${r.paragraphs.map((x) => `<p>${esc(x)}</p>`).join('')}</div>`;
    if (r.conclusion) out += `<p class="reading-ending">${esc(r.conclusion)}</p>`;
    return `${out}</div>`;
  }

  /** The reading after (sec, tab): the next tab of the section, else the first tab of the next reading section. */
  function nextTarget(sec, tab) {
    const sections = state.data ? state.data.sections : [];
    const cur = sections[sec];
    if (cur && tab + 1 < cur.subSections.length) return { sec, tab: tab + 1, title: cur.subSections[tab + 1].title };
    for (let i = sec + 1; i < sections.length; i++) {
      if (sections[i].type !== 'synaxarium' && sections[i].subSections.length) return { sec: i, tab: 0, title: sections[i].subSections[0].title };
    }
    return null;
  }

  /** The reading before (sec, tab): the previous tab of the section, else the last tab of the previous reading section. */
  function prevTarget(sec, tab) {
    const sections = state.data ? state.data.sections : [];
    const cur = sections[sec];
    if (cur && tab > 0) return { sec, tab: tab - 1, title: cur.subSections[tab - 1].title };
    for (let i = sec - 1; i >= 0; i--) {
      const subs = sections[i].subSections;
      if (sections[i].type !== 'synaxarium' && subs.length) return { sec: i, tab: subs.length - 1, title: subs[subs.length - 1].title };
    }
    return null;
  }

  function nextLink(sec, tab) {
    const tr = t();
    const link = (target, label, reverse) => target
      ? button({ act: 'nextReading', variant: 'soft', label: `${label}${target.title ? ` · ${target.title}` : ''}`, ic: dirIcon(reverse), data: `data-sec="${target.sec}" data-i="${target.tab}"` })
      : '<span></span>';
    const prev = prevTarget(sec, tab);
    const next = nextTarget(sec, tab);
    if (!prev && !next) return '';
    return `<div class="next-reading">${link(prev, tr.prevReading, false)}${link(next, tr.nextReading, true)}</div>`;
  }

  function readingBlock(section, si) {
    const tr = t();
    const tab = clamp(state.tabs[si] || 0, 0, section.subSections.length - 1);
    const sub = section.subSections[tab];
    const tabs = section.subSections.length > 1
      ? `<div class="reading-tabs" role="tablist" aria-label="${esc(section.title)}">${section.subSections.map((s, i) => `<button type="button" role="tab" id="tab-${si}-${i}" aria-selected="${i === tab}" aria-controls="panel-${si}" class="${i === tab ? 'active' : ''}" data-act="tab" data-sec="${si}" data-i="${i}">${esc(s.title)}</button>`).join('')}</div>`
      : '';
    return `<article class="reading-block">
      <div class="reading-heading">
        <div><span class="eyebrow">${esc(section.title)}</span><h2>${esc(sub.title)}</h2></div>
        ${button({ act: 'focus', variant: 'soft', label: tr.focus, ic: icon('book', 17), data: `data-sec="${si}" data-i="${tab}"` })}
      </div>
      ${tabs}
      <div id="panel-${si}" role="${tabs ? 'tabpanel' : 'group'}" ${tabs ? `aria-labelledby="tab-${si}-${tab}"` : ''} style="--reading-size:${state.fontSize}px">
        ${sub.introduction ? `<p class="reading-intro">${esc(sub.introduction)}</p>` : ''}
        ${sub.readings.map((r) => readingItem(r, sub)).join('')}
        ${nextLink(si, tab)}
      </div>
    </article>`;
  }

  function sectionCard(section, i) {
    const tr = t();
    const expanded = state.open === i;
    const title = section.title || tr.types[section.type] || '';
    let body = '';
    if (expanded) {
      if (section.type === 'synaxarium') {
        const r = section.subSections[0].readings[0];
        body = `<div class="card-preview"><p>${esc(r.title || title)}</p>${button({ act: 'nav', variant: 'soft', label: tr.synaxarium, data: 'data-screen="synaxarium"' })}</div>`;
      } else {
        body = readingBlock(section, i);
      }
    }
    return `<section class="section-card${expanded ? ' is-expanded' : ''}">
      <button type="button" class="section-trigger" data-act="section" data-i="${i}" aria-expanded="${expanded}" aria-controls="sec-${i}">
        <span><strong>${esc(title)}</strong></span><span class="section-chevron">${icon('chevron')}</span>
      </button>
      ${expanded ? `<div class="section-content" id="sec-${i}">${body}</div>` : ''}
    </section>`;
  }

  /** Asks for the church code. Shown instead of the readings whenever the server answers 401. */
  function codeBlock() {
    const tr = t();
    return `<div class="empty-state code-gate"><span class="empty-icon">${icon('cross')}</span><strong>${esc(tr.codeTitle)}</strong><p>${esc(tr.codeBody)}</p>
      <form class="code-form" data-form="code"><input class="code-input" type="password" autocomplete="off" autocapitalize="off" spellcheck="false" aria-label="${esc(tr.codePlaceholder)}" placeholder="${esc(tr.codePlaceholder)}" data-input="code">
        <button type="submit" class="button button--primary">${esc(tr.codeSave)}</button></form>
      ${state.codeError ? `<p class="code-error" role="alert">${esc(state.codeError)}</p>` : ''}</div>`;
  }

  function statusBlock() {
    const tr = t();
    if (state.status === 'locked') return codeBlock();
    if (state.status === 'loading') {
      const hint = state.slow ? `<p class="slow-hint">${esc(tr.slowLoading)}</p>` : '';
      return `<div role="status" aria-label="${esc(tr.loading)}">${hint}${[0, 1, 2, 3].map(() => '<div class="skeleton-card"><span></span><span></span><span></span><span></span></div>').join('')}</div>`;
    }
    const isErr = state.status === 'error';
    const isRange = state.status === 'range';
    const isBusy = state.status === 'busy';
    const title = isBusy ? tr.busyTitle : isRange ? tr.rangeTitle : isErr ? tr.errorTitle : tr.emptyTitle;
    const body = isBusy ? tr.busyBody : isRange ? tr.rangeBody : isErr ? (state.errorMessage || tr.errorBody) : tr.emptyBody;
    return `<div class="empty-state"><span class="empty-icon">${icon('calendar')}</span><strong>${esc(title)}</strong><p>${esc(body)}</p>
      <div class="component-row">${isErr || isBusy ? button({ act: 'retry', variant: 'primary', label: tr.retry, ic: icon('refresh', 17) }) : ''}${button({ act: 'nav', variant: 'soft', label: tr.selectDate, data: 'data-screen="calendar"' })}</div></div>`;
  }

  function banner() {
    const tr = t();
    const msg = !state.online ? tr.offlineBanner : state.rateLimited ? tr.rateBanner : state.fromStore ? tr.savedBanner : '';
    return msg ? `<div class="banner" role="status">${icon('wifi', 18)}<span>${esc(msg)}</span>${button({ act: 'retry', variant: 'ghost', title: tr.retry, ic: icon('refresh', 16) })}</div>` : '';
  }

  function todayScreen() {
    const c = copticOf(state.date);
    const list = state.status === 'ok' ? `<div class="sections-list">${state.data.sections.map(sectionCard).join('')}</div>` : statusBlock();
    return `${controlBar()}<div class="page-shell">${sidebar()}<main class="reading-column">
      <div class="mobile-date"><span>${esc(fmtCoptic(c))}</span><strong>${esc(fmtGregorian(state.date))}</strong></div>
      ${banner()}${list}</main></div>`;
  }

  function calendarScreen() {
    const tr = t();
    const c = copticOf(state.date);
    return `<main class="content-page narrow-page">
      <div class="page-title"><span class="eyebrow">${esc(tr.selectDate)}</span><h1>${esc(tr.calendar)}</h1><p>${esc(fmtGregorian(todayISO()))}</p></div>
      ${calendarCard()}
      <div class="selected-date-card"><div><strong>${esc(fmtGregorian(state.date))}</strong><span>${esc(fmtCoptic(c))}</span></div>
        ${button({ act: 'openDay', variant: 'primary', label: tr.openReadings })}</div>
    </main>`;
  }

  function synaxariumScreen() {
    const tr = t();
    const c = copticOf(state.date);
    const synax = state.data && state.data.sections.flatMap((s) => s.subSections).filter((s) => s.type === 'synaxarium');
    const sec = synax && synax.length ? { title: synax[0].title, subSections: synax } : null;
    let inner;
    if (state.status === 'loading') inner = statusBlock();
    else if (!sec) inner = state.status === 'ok' ? `<div class="empty-state"><strong>${esc(tr.noSynax)}</strong></div>` : statusBlock();
    else {
      const reads = sec.subSections.flatMap((s) => s.readings);
      inner = `<div class="page-title centered"><span class="eyebrow">${esc(fmtCoptic(c))}</span><h1>${esc(reads[0].title || sec.title)}</h1><p>${esc(fmtGregorian(state.date))}</p></div>
        <article class="synaxarium-copy" style="--reading-size:${state.fontSize}px">${reads.map((r) => (r.paragraphs.length ? r.paragraphs : r.passages.flatMap((p) => p.verses.map((v) => v.text))).map((p) => `<p>${esc(p)}</p>`).join('')).join('')}</article>`;
    }
    return `<main class="content-page text-page"><div class="ornament"><span></span>${icon('cross', 18)}<span></span></div>${banner()}${inner}<div class="ornament bottom"><span></span>${icon('cross', 14)}<span></span></div></main>`;
  }

  /** The Bible version picker. The list comes from the server, so it only appears when there is a choice. */
  function bibleRow(tr) {
    const list = state.data && state.data.bibles ? state.data.bibles : [];
    if (list.length < 2) return '';
    const chosen = bibleFor(state.lang) || (list.find((b) => b.default) || list[0]).id;
    const options = list.map((b) => `<option value="${b.id}"${b.id === chosen ? ' selected' : ''}>${esc(b.name)}${b.default ? ` ${esc(tr.bibleDefault)}` : ''}</option>`).join('');
    return `<div class="setting-row"><div><strong>${esc(tr.bibleLabel)}</strong></div><select class="font-select" aria-label="${esc(tr.bibleLabel)}" data-input="bible">${options}</select></div>`;
  }

  function settingsScreen() {
    const tr = t();
    const seg = (items) => `<div class="segmented">${items.map(([act, label, active, ic, data]) => button({ act, label, active, ic: ic || '', data })).join('')}</div>`;
    return `<main class="content-page settings-page">
      <div class="page-title"><span class="eyebrow">${esc(tr.appearance)}</span><h1>${esc(tr.settings)}</h1></div>
      <div class="settings-card">
        <div class="setting-row"><div><strong>${esc(tr.langLabel)}</strong><small>${isAr() ? 'العربية' : 'English'}</small></div>
          ${seg([['setLang', 'العربية', isAr(), '', 'data-v="ar"'], ['setLang', 'English', !isAr(), '', 'data-v="en"']])}</div>
        ${bibleRow(tr)}
        <div class="setting-row"><div><strong>${esc(tr.fontType)}</strong></div>
          <select class="font-select" aria-label="${esc(tr.fontType)}" data-input="family">${Object.entries(FONT_FAMILIES).map(([k, [name]]) => `<option value="${k}"${k === state.fontFamily ? ' selected' : ''}>${esc(name)}</option>`).join('')}</select></div>
        <div class="setting-row stacked"><div><strong>${esc(tr.fontSize)}</strong><small>${num(state.fontSize)}px</small></div>
          <input aria-label="${esc(tr.fontSize)}" type="range" min="${FONT_MIN}" max="${FONT_MAX}" value="${state.fontSize}" data-input="font">
          <div class="font-preview" style="--reading-size:${state.fontSize}px"><span>${esc(tr.preview)}</span><p>${esc(tr.previewText)}</p></div></div>
        <div class="setting-row stacked"><div><strong>${esc(tr.scrollSpeed)}</strong><small>${num(state.scrollSpeed)}</small></div>
          <input aria-label="${esc(tr.scrollSpeed)}" type="range" min="${SPEED_MIN}" max="${SPEED_MAX}" value="${state.scrollSpeed}" data-input="speed"></div>
        ${churchCode() ? `<div class="setting-row"><div><strong>${esc(tr.codeTitle)}</strong></div>${button({ act: 'changeCode', label: tr.codeChange })}</div>` : ''}
        <div class="setting-row"><div><strong>${esc(tr.headerLabel)}</strong><small>${esc(state.stickyHeader ? tr.headerKeep : tr.headerScroll)}</small></div>
          ${seg([['setHeader', tr.headerKeep, state.stickyHeader, '', 'data-v="1"'], ['setHeader', tr.headerScroll, !state.stickyHeader, '', 'data-v="0"']])}</div>
        <div class="setting-row"><div><strong>${esc(tr.theme)}</strong><small>${esc(tr[state.theme])}</small></div>
          ${seg([['setTheme', tr.light, state.theme === 'light', icon('sun', 17), 'data-v="light"'], ['setTheme', tr.dark, state.theme === 'dark', icon('moon', 17), 'data-v="dark"'], ['setTheme', tr.auto, state.theme === 'auto', '', 'data-v="auto"']])}</div>
      </div>
    </main>`;
  }

  function focusScreen() {
    const tr = t();
    const section = state.data && state.data.sections[state.focus.sec];
    if (!section) { state.focus = null; return ''; }
    const sub = section.subSections[state.focus.tab] || section.subSections[0];
    const first = sub.readings[0];
    const ref = first && first.passages[0] ? first.passages.map((p) => p.ref).filter(Boolean).join(' · ') : '';
    return `<div class="focus-mode${state.stickyHeader ? '' : ' header-scrolls'}">
      <div class="focus-top">${button({ act: 'back', label: tr.back, ic: dirIcon(false) })}<span>${esc(sub.title)}${ref ? ` · ${esc(ref)}` : ''}</span><span></span></div>
      <main class="focus-reading" style="--reading-size:${state.fontSize + 5}px">
        <span class="cross-divider">${icon('cross', 16)}</span>
        ${sub.introduction ? `<p class="reading-intro">${esc(sub.introduction)}</p>` : ''}
        ${sub.readings.map((r) => readingItem(r, sub)).join('')}
        ${nextLink(state.focus.sec, section.subSections.indexOf(sub))}
      </main>
      <div class="floating-bar">
        ${button({ act: 'back', variant: 'icon', title: tr.back, ic: dirIcon(false) })}<span class="divider"></span>
        ${button({ act: 'fontMinus', variant: 'icon', title: tr.fontSmaller, ic: '<span class="a-control">A−</span>' })}
        ${button({ act: 'fontPlus', variant: 'icon', title: tr.fontLarger, ic: '<span class="a-control">A+</span>' })}
        ${button({ act: 'toggleTheme', variant: 'icon', title: isDark() ? tr.lightMode : tr.darkMode, ic: icon(isDark() ? 'sun' : 'moon') })}
        ${canFullscreen() ? button({ act: 'fullscreen', variant: 'icon', active: inFullscreen(), title: tr.fullscreen, ic: icon(inFullscreen() ? 'shrink' : 'expand') }) : ''}
        ${isDesktop() ? button({ act: 'togglePresent', variant: 'icon', title: tr.presentMode, ic: icon('slides') }) : ''}
        ${button({ act: 'autoScroll', variant: 'icon', active: state.auto, title: tr.autoScroll, ic: icon('scroll') })}
        ${state.auto ? `${button({ act: 'speedDown', variant: 'icon', title: tr.slower, ic: '<span class="a-control">«</span>', extra: state.scrollSpeed <= SPEED_MIN ? ' disabled' : '' })}<span class="speed-value" aria-live="polite">${num(state.scrollSpeed)}</span>${button({ act: 'speedUp', variant: 'icon', title: tr.faster, ic: '<span class="a-control">»</span>', extra: state.scrollSpeed >= SPEED_MAX ? ' disabled' : '' })}` : ''}
      </div></div>`;
  }

  // ---------------------------------------------------------------- presentation (desktop slides)
  const desktopQuery = window.matchMedia('(min-width: 901px)');
  const isDesktop = () => desktopQuery.matches;
  const presenting = () => !!state.focus && state.present && isDesktop();
  const slideSize = () => state.fontSize + 14;

  /** The reading cut into small blocks (a verse, a paragraph, a heading…) that are then packed onto slides. */
  function slideUnits(sub) {
    const units = [];
    let pre = '';
    const push = (html) => { units.push(pre + html); pre = ''; };
    if (sub.introduction) push(`<p class="reading-intro">${esc(sub.introduction)}</p>`);
    sub.readings.forEach((r) => {
      const heading = r.title && r.title !== sub.title ? r.title : (sub.readings.length > 1 ? r.label : '');
      if (heading) pre += `<h3>${esc(heading)}</h3>`;
      if (r.introduction) push(`<p class="reading-intro">${esc(r.introduction)}</p>`);
      r.passages.forEach((p) => {
        const ref = [p.book, p.ref].filter(Boolean).join(' ');
        if (ref) pre += `<div class="chip-row"><span class="reference-chip">${esc(ref)}</span></div>`;
        let chapter = 0;
        p.verses.forEach((v) => {
          const mark = v.chapter && chapter && v.chapter !== chapter ? `<div class="chapter-mark">${esc(t().chapter)} ${esc(num(v.chapter))}</div>` : '';
          if (v.chapter) chapter = v.chapter;
          push(`${mark}<div class="verse-row">${v.number ? `<sup>${esc(num(v.number))}</sup>` : ''}<span>${esc(v.text)}</span></div>`);
        });
      });
      r.paragraphs.forEach((x) => push(`<p class="slide-text">${esc(x)}</p>`));
      if (r.conclusion) push(`<p class="reading-ending">${esc(r.conclusion)}</p>`);
    });
    if (pre) units.push(pre);
    return units;
  }

  /** Packs the blocks onto slides by measuring them in a hidden copy of the stage, so each slide fills the screen without scrolling. */
  function paginate(units) {
    const probe = document.createElement('div');
    probe.className = 'present slide-measure';
    probe.style.setProperty('--reading-size', `${slideSize()}px`);
    probe.innerHTML = '<div class="slide-body"><div class="slide-inner"></div></div>';
    document.body.appendChild(probe);
    const body = probe.firstChild;
    const inner = body.firstChild;
    const slides = [];
    let cur = [];
    units.forEach((u) => {
      inner.insertAdjacentHTML('beforeend', u);
      if (cur.length && inner.offsetHeight > body.clientHeight) {
        slides.push(cur);
        cur = [u];
        inner.innerHTML = u;
      } else cur.push(u);
    });
    if (cur.length) slides.push(cur);
    probe.remove();
    return slides.length ? slides : [['']];
  }

  function presentScreen() {
    const tr = t();
    const section = state.data && state.data.sections[state.focus.sec];
    if (!section) { state.focus = null; return ''; }
    const sub = section.subSections[state.focus.tab] || section.subSections[0];
    const slides = paginate(slideUnits(sub));
    const n = slides.length;
    const i = state.slide < 0 ? n - 1 : Math.min(state.slide, n - 1);
    state.slide = i;
    state.slideCount = n;
    const first = sub.readings[0];
    const ref = first && first.passages[0] ? first.passages.map((p) => p.ref).filter(Boolean).join(' · ') : '';
    const bar = (act, label, ic) => button({ act, variant: 'icon', title: label, ic });
    return `<div class="present" data-act="slideNext" style="--reading-size:${slideSize()}px">
      <div class="present-top" data-act="none">${button({ act: 'back', label: tr.back, ic: dirIcon(false) })}<span>${esc(section.title)} · ${esc(sub.title)}${ref ? ` · ${esc(ref)}` : ''}</span><span class="slide-counter">${num(i + 1)} / ${num(n)}</span></div>
      <div class="slide-body"><div class="slide-inner">${slides[i].join('')}</div></div>
      <div class="present-progress" style="width:${((i + 1) / n) * 100}%"></div>
      <div class="floating-bar" data-act="none">
        ${bar('back', tr.back, dirIcon(false))}<span class="divider"></span>
        ${bar('slidePrev', tr.slidePrev, dirIcon(false))}${bar('slideNext', tr.slideNext, dirIcon(true))}<span class="divider"></span>
        ${bar('fontMinus', tr.fontSmaller, '<span class="a-control">A−</span>')}${bar('fontPlus', tr.fontLarger, '<span class="a-control">A+</span>')}
        ${bar('toggleTheme', isDark() ? tr.lightMode : tr.darkMode, icon(isDark() ? 'sun' : 'moon'))}
        ${canFullscreen() ? button({ act: 'fullscreen', variant: 'icon', active: inFullscreen(), title: tr.fullscreen, ic: icon(inFullscreen() ? 'shrink' : 'expand') }) : ''}
        ${bar('togglePresent', tr.scrollMode, icon('scroll'))}
      </div></div>`;
  }

  function goSlide(delta) {
    if (!state.focus) return;
    if (delta > 0 && state.slide < state.slideCount - 1) state.slide++;
    else if (delta < 0 && state.slide > 0) state.slide--;
    else {
      const target = (delta > 0 ? nextTarget : prevTarget)(state.focus.sec, state.focus.tab);
      if (!target) return;
      state.focus = { sec: target.sec, tab: target.tab };
      state.slide = delta > 0 ? 0 : -1;
    }
    render();
  }

  function mobileNav() {
    const tr = t();
    const items = [['today', 'book', tr.today], ['calendar', 'calendar', tr.calendar], ['synaxarium', 'scroll', tr.synaxarium], ['settings', 'settings', tr.settings]];
    return `<nav class="mobile-nav" aria-label="${esc(tr.mainNav)}">${items.map(([id, ic, label]) => `<button type="button" class="${state.screen === id ? 'active' : ''}" data-act="nav" data-screen="${id}" ${state.screen === id ? 'aria-current="page"' : ''}>${icon(ic)}<span>${esc(label)}</span></button>`).join('')}</nav>`;
  }

  // ---------------------------------------------------------------- render
  const root = document.getElementById('app');
  function render() {
    const dark = applyTheme();
    document.documentElement.style.setProperty('--font-scripture', FONT_FAMILIES[state.fontFamily][1]);
    root.className = `app${dark ? ' theme-dark' : ''}`;
    root.dir = isAr() ? 'rtl' : 'ltr';
    document.documentElement.lang = state.lang;
    document.documentElement.dir = root.dir;
    document.title = t().appName;
    if (state.focus) { root.innerHTML = presenting() ? presentScreen() : focusScreen(); if (!state.focus) render(); return; }
    const screens = { today: todayScreen, calendar: calendarScreen, synaxarium: synaxariumScreen, settings: settingsScreen };
    root.innerHTML = `${header()}${screens[state.screen]()}${mobileNav()}`;
  }

  // ---------------------------------------------------------------- full screen
  // The reading view asks the browser for real full screen (no address bar, no taskbar). It works in browsers,
  // installed apps and the desktop app; iPhone Safari has no such API, so the button is hidden there and the
  // text still fills the whole page.
  const canFullscreen = () => !!(document.fullscreenEnabled && document.documentElement.requestFullscreen);
  const inFullscreen = () => !!document.fullscreenElement;
  function enterFullscreen() {
    if (canFullscreen() && !inFullscreen()) document.documentElement.requestFullscreen({ navigationUI: 'hide' }).catch(() => { /* refused: the page is still full width */ });
  }
  function leaveFullscreen() {
    if (inFullscreen()) document.exitFullscreen().catch(() => {});
  }
  document.addEventListener('fullscreenchange', () => { if (state.focus) render(); });

  // ---------------------------------------------------------------- actions
  function goDate(iso) {
    state.date = iso;
    state.calMonth = null;
    stopAuto();
    load();
  }
  function stopAuto() { state.auto = false; }
  let rafId = 0;
  function autoLoop(last = performance.now(), y = window.scrollY) {
    if (!state.auto || !state.focus) return;
    const now = performance.now();
    // Resync when the reader scrolled by hand; otherwise advance a fractional position every frame.
    if (Math.abs(window.scrollY - y) > 2) y = window.scrollY;
    y += ((now - last) / 1000) * state.scrollSpeed;
    window.scrollTo(0, y);
    rafId = requestAnimationFrame(() => autoLoop(now, y));
  }

  const actions = {
    nav(el) { state.screen = el.dataset.screen; state.focus = null; stopAuto(); window.scrollTo(0, 0); render(); },
    prev() { goDate(addDays(state.date, -1)); },
    next() { goDate(addDays(state.date, 1)); },
    today() { state.screen = 'today'; goDate(todayISO()); },
    pick(el) { goDate(el.dataset.date); },
    openDay() { state.screen = 'today'; window.scrollTo(0, 0); render(); },
    calPrev() { shiftMonth(-1); },
    calNext() { shiftMonth(1); },
    toggleLang() { setLang(isAr() ? 'en' : 'ar'); },
    setLang(el) { setLang(el.dataset.v); },
    changeCode() { state.screen = 'today'; state.status = 'locked'; state.codeError = ''; window.scrollTo(0, 0); render(); },
    setHeader(el) { state.stickyHeader = el.dataset.v !== '0'; store.set('stickyHeader', state.stickyHeader ? '1' : '0'); render(); },
    toggleTheme() { setTheme(isDark() ? 'light' : 'dark'); render(); },
    setTheme(el) { setTheme(el.dataset.v); render(); },
    speedDown() { setSpeed(state.scrollSpeed - 2); render(); },
    speedUp() { setSpeed(state.scrollSpeed + 2); render(); },
    fontMinus() { setFont(state.fontSize - 2); render(); },
    fontPlus() { setFont(state.fontSize + 2); render(); },
    section(el) { const i = Number(el.dataset.i); state.open = state.open === i ? -1 : i; render(); },
    tab(el) { state.tabs[el.dataset.sec] = Number(el.dataset.i); render(); },
    focus(el) { state.focus = { sec: Number(el.dataset.sec), tab: Number(el.dataset.i) }; state.slide = 0; enterFullscreen(); window.scrollTo(0, 0); render(); },
    fullscreen() { if (inFullscreen()) leaveFullscreen(); else enterFullscreen(); },
    nextReading(el) {
      const sec = Number(el.dataset.sec), tab = Number(el.dataset.i);
      if (state.focus) state.focus = { sec, tab }; else { state.open = sec; state.tabs[sec] = tab; }
      window.scrollTo(0, 0);
      render();
    },
    back() { state.focus = null; stopAuto(); leaveFullscreen(); render(); },
    autoScroll() { state.auto = !state.auto; if (state.auto) { cancelAnimationFrame(rafId); autoLoop(); } render(); },
    retry() { load(); },
    none() { /* clicks on the toolbars must not advance the slide */ },
    slideNext() { goSlide(1); },
    slidePrev() { goSlide(-1); },
    togglePresent() { state.present = !state.present; store.set('present', state.present ? '1' : '0'); state.slide = 0; stopAuto(); window.scrollTo(0, 0); render(); },
  };

  function shiftMonth(delta) {
    const sel = parseISO(state.date);
    const cm = state.calMonth || { y: sel.getFullYear(), m: sel.getMonth() };
    const d = new Date(cm.y, cm.m + delta, 1);
    state.calMonth = { y: d.getFullYear(), m: d.getMonth() };
    render();
  }

  document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-act]');
    if (el && actions[el.dataset.act]) actions[el.dataset.act](el);
  });
  // The church code form: the typed code is checked with the server first and only kept when it is right.
  document.addEventListener('submit', async (e) => {
    const form = e.target.closest && e.target.closest('[data-form="code"]');
    if (!form) return;
    e.preventDefault();
    const typed = String(form.querySelector('[data-input="code"]').value || '').trim();
    if (!typed) return;
    const tr = t();
    const button = form.querySelector('button');
    button.disabled = true;
    try {
      const res = await fetch(`${API_BASE}/access`, { headers: { Accept: 'application/json', 'X-Church-Code': typed } });
      if (res.ok) {
        store.set('churchCode', typed);
        state.codeError = '';
        state.status = 'loading';
        render();
        load();
        setTimeout(syncAhead, 1500);
        return;
      }
      state.codeError = res.status === 429 ? tr.codeBlocked : res.status >= 500 ? tr.codeOffline : tr.codeWrong;
    } catch (err) {
      state.codeError = tr.codeOffline;
    }
    render();
  });
  document.addEventListener('input', (e) => {
    if (e.target.dataset && e.target.dataset.input === 'family') setFamily(e.target.value);
    if (e.target.dataset && e.target.dataset.input === 'bible') {
      const list = (state.data && state.data.bibles) || [];
      const picked = Number(e.target.value);
      const chosen = list.find((b) => b.id === picked);
      bibleChoices[state.lang] = chosen && chosen.default ? 0 : picked; // the default Bible is stored as 0
      store.set('bibles', JSON.stringify(bibleChoices));
      load();
    }
    if (e.target.dataset && e.target.dataset.input === 'speed') {
      setSpeed(Number(e.target.value));
      const small = e.target.closest('.setting-row').querySelector('small');
      if (small) small.textContent = num(state.scrollSpeed);
    }
    if (e.target.dataset && e.target.dataset.input === 'font') {
      setFont(Number(e.target.value));
      const prev = document.querySelector('.font-preview');
      if (prev) prev.style.setProperty('--reading-size', `${state.fontSize}px`);
      const small = e.target.closest('.setting-row').querySelector('small');
      if (small) small.textContent = `${num(state.fontSize)}px`;
    }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && state.focus) actions.back();
    if (!presenting() || e.altKey || e.ctrlKey || e.metaKey || /^(INPUT|SELECT|TEXTAREA)$/.test(e.target.tagName)) return;
    const forward = isAr() ? 'ArrowLeft' : 'ArrowRight';
    const backward = isAr() ? 'ArrowRight' : 'ArrowLeft';
    if ([forward, 'ArrowDown', 'PageDown', ' ', 'Enter'].includes(e.key)) goSlide(1);
    else if ([backward, 'ArrowUp', 'PageUp', 'Backspace'].includes(e.key)) goSlide(-1);
    else if (e.key === 'Home') { state.slide = 0; render(); }
    else if (e.key === 'End') { state.slide = -1; render(); }
    else return;
    e.preventDefault();
  });
  let resizeTimer = 0;
  window.addEventListener('resize', () => { if (state.focus) { clearTimeout(resizeTimer); resizeTimer = setTimeout(render, 150); } });
  window.addEventListener('online', () => { state.online = true; render(); load(); setTimeout(syncAhead, 3000); });
  window.addEventListener('offline', () => { state.online = false; render(); });
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => { if (state.theme === 'auto') render(); });
  // device passes midnight while the app stays open: follow the new day if the user was on "today"
  let lastToday = todayISO();
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return;
    const now = todayISO();
    if (now !== lastToday) { if (state.date === lastToday) { lastToday = now; goDate(now); return; } lastToday = now; render(); }
  });

  // ---------------------------------------------------------------- boot
  if ('serviceWorker' in navigator && !DEMO && location.protocol !== 'file:') {
    navigator.serviceWorker.register('sw.js').catch(() => { /* app works without it */ });
  }
  load();
  setTimeout(syncAhead, 3000); // after the first day is on screen
})();
