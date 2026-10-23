/* Sample payload used only with ?demo=1 (UI development without the Laravel endpoint).
   Same shape the real endpoint returns. */
(function (root) {
  const content = {
    ar: {
      sections: [
        { type: 'vespers', title: 'العشية', subs: [['المزمور والإنجيل', 'مزمور ١١٩: ١٠٥', 'سراج لرجلي كلامك ونور لسبيلي.']] },
        { type: 'matins', title: 'باكر', subs: [['المزمور والإنجيل', 'مزمور ٥١: ١', 'ارحمني يا الله حسب رحمتك، وحسب كثرة رأفتك امح معاصيّ.']] },
        {
          type: 'liturgy', title: 'القداس',
          subs: [
            ['البولس', '١ كورنثوس ٣: ٤–٢٣', null],
            ['الكاثوليكون', '١ يوحنا ٢: ١–١٧', 'يا أولادي، أكتب إليكم هذا لكي لا تخطئوا.'],
            ['الإبركسيس', 'أعمال ٤: ١–٢٢', 'وبينما هما يكلمان الشعب، أقبل عليهما الكهنة.'],
            ['المزمور والإنجيل', 'مزمور ١١٩: ٩٧ — لوقا ١٠: ٢٥–٣٧', 'كلامك سراج لرجلي ونور لسبيلي.'],
          ],
        },
        { type: 'synaxarium', title: 'السنكسار', synax: true },
      ],
      paul: {
        intro: 'من رسالة معلمنا بولس الرسول الأولى إلى أهل كورنثوس، بركته المقدسة تكون معنا. آمين.',
        ending: 'نعمة الله الآب تكون مع جميعكم. آمين.',
        verses: [
          'لأنه متى قال واحد: أنا لبولس، وآخر: أنا لأبلوس، أفلستم جسديين؟',
          'فمن هو بولس؟ ومن هو أبلوس؟ بل خادمان آمنتم بواسطتهما، وكما أعطى الرب لكل واحد.',
          'أنا غرست وأبلوس سقى، لكن الله كان ينمي.',
          'إذًا ليس الغارس شيئًا ولا الساقي، بل الله الذي ينمي.',
        ],
      },
      synaxTitle: 'نياحة القديس الأنبا زكريا أسقف سخا',
      synaxHtml: '<p>في مثل هذا اليوم تنيّح القديس العظيم الأنبا زكريا أسقف مدينة سخا. كان هذا الأب منذ حداثته محبًا للعبادة، كثير الصوم والصلاة، فاختاره شعب المدينة راعيًا لهم.</p><p>ولما جلس على الكرسي الأسقفي اعتنى بشعبه، وثبّتهم في الإيمان المستقيم، وكان رحيمًا بالفقراء والمحتاجين. وبعد جهاد صالح تنيّح بسلام. بركة صلواته تكون معنا. آمين.</p>',
    },
    en: {
      sections: [
        { type: 'vespers', title: 'Vespers', subs: [['Psalm & Gospel', 'Psalm 119:105', 'Your word is a lamp to my feet and a light to my path.']] },
        { type: 'matins', title: 'Matins', subs: [['Psalm & Gospel', 'Psalm 51:1', 'Have mercy upon me, O God, according to Your lovingkindness.']] },
        {
          type: 'liturgy', title: 'Liturgy',
          subs: [
            ['Pauline Epistle', '1 Corinthians 3:4–23', null],
            ['Catholic Epistle', '1 John 2:1–17', 'My little children, these things I write to you, so that you may not sin.'],
            ['Praxis', 'Acts 4:1–22', 'Now as they spoke to the people, the priests came upon them.'],
            ['Psalm & Gospel', 'Psalm 119:97 — Luke 10:25–37', 'Your word is a lamp to my feet and a light to my path.'],
          ],
        },
        { type: 'synaxarium', title: 'Synaxarium', synax: true },
      ],
      paul: {
        intro: 'A reading from the first epistle of our teacher St. Paul to the Corinthians. May his blessing be with us. Amen.',
        ending: 'The grace of God the Father be with you all. Amen.',
        verses: [
          'For when one says, “I am of Paul,” and another, “I am of Apollos,” are you not carnal?',
          'Who then is Paul, and who is Apollos, but ministers through whom you believed, as the Lord gave to each one?',
          'I planted, Apollos watered, but God gave the increase.',
          'So then neither he who plants is anything, nor he who waters, but God who gives the increase.',
        ],
      },
      synaxTitle: 'Departure of St. Zacharias, Bishop of Sakha',
      synaxHtml: '<p>On this day, the great saint Anba Zacharias, Bishop of Sakha, departed. From his youth he loved worship, fasting and prayer, and the people of the city chose him to shepherd them.</p><p>When he sat upon the episcopal throne, he cared for his people and confirmed them in the Orthodox faith. He showed mercy to the poor and needy. After a good struggle, he departed in peace. May his prayers be with us. Amen.</p>',
    },
  };

  const subTypes = ['pauline', 'catholic', 'praxis', 'psalm_gospel'];

  function build(iso, lang) {
    const c = content[lang] || content.en;
    const [y, m, d] = iso.split('-').map(Number);
    const cd = root.Coptic.fromGregorian(y, m, d);
    return {
      date: iso,
      language: lang,
      copticDate: { day: cd.day, month: cd.month, year: cd.year },
      source: 'demo',
      sections: c.sections.map((s) => {
        if (s.synax) {
          return { type: s.type, title: s.title, subSections: [{ type: 'synaxarium', title: s.title, introduction: null, readings: [{ title: c.synaxTitle, introduction: null, conclusion: null, passages: [], html: c.synaxHtml }] }] };
        }
        return {
          type: s.type,
          title: s.title,
          subSections: s.subs.map(([title, ref, text], i) => {
            const isPaul = text === null;
            return {
              type: s.type === 'liturgy' ? subTypes[i] : 'psalm_gospel',
              title,
              introduction: null,
              readings: [{
                title,
                introduction: isPaul ? c.paul.intro : null,
                conclusion: isPaul ? c.paul.ending : null,
                passages: [{
                  bookTranslation: null,
                  ref,
                  verses: (isPaul ? c.paul.verses : [text]).map((t, k) => ({ number: (isPaul ? 4 : 1) + k, text: t })),
                }],
                html: null,
              }],
            };
          }),
        };
      }),
    };
  }

  root.DemoReadings = { build };
})(window);
