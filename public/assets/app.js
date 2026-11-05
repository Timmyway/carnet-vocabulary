const blank = () => ({ term: '', translation: '', example: '', tag: '' });

async function api(path, { method = 'GET', body } = {}) {
  const res = await fetch(`/api${path}`, {
    method,
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const data = res.status === 204 ? null : await res.json().catch(() => null);
  if (!res.ok) throw new Error(data?.error ?? `Request failed (${res.status})`);
  return data;
}

const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

document.addEventListener('alpine:init', () => {
  Alpine.data('vocab', () => ({
    view: 'notebook',
    dark: document.documentElement.classList.contains('dark'),
    words: [], tags: [], stats: { total: 0, due: 0, boxes: [0, 0, 0, 0, 0] },
    search: '', tagFilter: '', loadingWords: true,
    sort: 'newest', seed: 0, total: 0, pageSize: 50, loadingMore: false, reqId: 0,
    form: blank(), editingId: null, formError: '', saving: false,
    importText: '', importing: false, importMsg: '', importErr: '',
    setup: { mode: 'due', direction: 'term', limit: 20, tag: '' },
    queue: [], flipped: false, busy: false, sessionError: '',
    session: { active: false, done: false, total: 0, answered: 0, known: 0, missed: 0 },

    async init() {
      await Promise.all([this.loadWords(), this.loadMeta()]);
      window.addEventListener('keydown', (e) => this.onKey(e));
    },

    toggleTheme() {
      this.dark = !this.dark;
      document.documentElement.classList.toggle('dark', this.dark);
      try { localStorage.setItem('theme', this.dark ? 'dark' : 'light'); } catch {}
    },

    /* ---------- notebook ---------- */
    /** Loads the first page (append = false) or the next one (append = true). */
    async loadWords(append = false) {
      const req = ++this.reqId; // ignore answers that arrive out of order (fast typing)
      const p = new URLSearchParams({ limit: this.pageSize, offset: append ? this.words.length : 0, sort: this.sort });
      if (this.sort === 'random') p.set('seed', this.seed);
      if (this.search.trim()) p.set('q', this.search.trim());
      if (this.tagFilter) p.set('tag', this.tagFilter);
      if (append) this.loadingMore = true;
      try {
        const data = await api(`/words?${p}`);
        if (req !== this.reqId) return;
        if (append) {
          const seen = new Set(this.words.map((w) => w.id));
          this.words = this.words.concat(data.items.filter((w) => !seen.has(w.id)));
        } else {
          this.words = data.items;
        }
        this.total = data.total;
      } catch (e) {
        this.formError = e.message;
      } finally {
        this.loadingWords = false;
        this.loadingMore = false;
      }
    },

    newSeed() { this.seed = Math.floor(Math.random() * 4294967296); },

    changeSort() {
      if (this.sort === 'random') this.newSeed();
      this.loadWords();
    },

    reshuffle() {
      this.newSeed();
      this.loadWords();
    },

    async loadMeta() {
      try {
        [this.stats, this.tags] = await Promise.all([api('/stats'), api('/tags')]);
      } catch (e) {
        this.formError = e.message;
      }
    },

    async save() {
      this.formError = '';
      this.saving = true;
      try {
        if (this.editingId) await api(`/words/${this.editingId}`, { method: 'PUT', body: this.form });
        else await api('/words', { method: 'POST', body: this.form });
        this.cancelEdit();
        await Promise.all([this.loadWords(), this.loadMeta()]);
        this.$refs.term.focus();
      } catch (e) {
        this.formError = e.message;
      } finally {
        this.saving = false;
      }
    },

    async importWords() {
      this.importMsg = '';
      this.importErr = '';
      let items;
      try {
        // Tolerate a ```json fence around the AI's answer.
        const cleaned = this.importText.trim().replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/, '');
        items = JSON.parse(cleaned);
      } catch {
        this.importErr = 'This is not valid JSON. Paste the AI answer exactly as it was given.';
        return;
      }
      this.importing = true;
      try {
        const r = await api('/words/import', { method: 'POST', body: { items } });
        this.importMsg = `${r.imported} added, ${r.skipped} skipped (already in your notebook)`
          + (r.errors.length ? `, ${r.errors.length} invalid. ${r.errors.slice(0, 3).join(' ')}` : '') + '.';
        if (!r.errors.length) this.importText = '';
        await Promise.all([this.loadWords(), this.loadMeta()]);
      } catch (e) {
        this.importErr = e.message;
      } finally {
        this.importing = false;
      }
    },

    edit(w) {
      this.editingId = w.id;
      this.form = { term: w.term, translation: w.translation, example: w.example ?? '', tag: w.tag ?? '' };
      window.scrollTo({ top: 0, behavior: reducedMotion() ? 'auto' : 'smooth' });
      this.$nextTick(() => this.$refs.term.focus());
    },

    cancelEdit() {
      this.editingId = null;
      this.form = blank();
      this.formError = '';
    },

    async remove(w) {
      if (!confirm(`Delete “${w.term}”?`)) return;
      try {
        await api(`/words/${w.id}`, { method: 'DELETE' });
        if (this.editingId === w.id) this.cancelEdit();
        await Promise.all([this.loadWords(), this.loadMeta()]);
      } catch (e) {
        this.formError = e.message;
      }
    },

    /* ---------- practice ---------- */
    get current() { return this.queue[0] ?? null; },
    get frontText() { const c = this.current; return c ? (c.reversed ? c.translation : c.term) : ''; },
    get backText() { const c = this.current; return c ? (c.reversed ? c.term : c.translation) : ''; },
    get progress() { return this.session.total ? Math.round((this.session.answered / this.session.total) * 100) : 0; },

    async startSession() {
      this.sessionError = '';
      const p = new URLSearchParams({ mode: this.setup.mode, limit: this.setup.limit });
      if (this.setup.tag) p.set('tag', this.setup.tag);
      try {
        const cards = await api(`/review/queue?${p}`);
        const d = this.setup.direction;
        this.queue = cards.map((c) => ({
          ...c, retry: false,
          reversed: d === 'reverse' || (d === 'mixed' && Math.random() < 0.5),
        }));
        this.session = { active: true, done: cards.length === 0, total: cards.length, answered: 0, known: 0, missed: 0 };
        this.flipped = false;
      } catch (e) {
        this.sessionError = e.message;
      }
    },

    flip() {
      if (this.current && !this.busy) this.flipped = !this.flipped;
    },

    async answer(correct) {
      const card = this.current;
      if (!card || this.busy || !this.flipped) return;
      this.busy = true;
      this.flipped = false;

      // Only the first attempt counts. A missed card comes back once at the end, unscored.
      if (!card.retry) {
        this.session.answered++;
        correct ? this.session.known++ : this.session.missed++;
        api(`/review/${card.id}`, { method: 'POST', body: { correct } })
          .catch(() => { this.sessionError = 'The last answer could not be saved.'; });
      }

      await wait(reducedMotion() ? 0 : 280); // let the card turn back before swapping its text
      this.queue.shift();
      if (!correct && !card.retry) { card.retry = true; this.queue.push(card); }
      if (this.queue.length === 0) { this.session.done = true; this.loadMeta(); }
      this.busy = false;
    },

    endSession() {
      this.session.active = false;
      this.queue = [];
      this.flipped = false;
      this.loadMeta();
    },

    onKey(e) {
      if (this.view !== 'practice' || !this.session.active || this.session.done) return;
      if (e.metaKey || e.ctrlKey || e.altKey) return;
      if (e.target.closest('input, textarea, select, button, a')) return;
      if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); this.flip(); }
      else if (e.key === 'ArrowRight' || e.key === '2') this.answer(true);
      else if (e.key === 'ArrowLeft' || e.key === '1') this.answer(false);
    },
  }));
});
