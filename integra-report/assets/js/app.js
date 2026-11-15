/* Integra Report — interfaccia guidata (JavaScript puro, nessuna dipendenza). */
(function () {
  'use strict';

  const B = window.IR_BOOT;
  const C = B.catalog;
  const root = document.getElementById('ir-app');

  const S = {
    view: 'list',
    list: [],
    rep: null,
    dataset: null,
    kpi: null,
    doc: null,
    filter: 'tutti',
    trace: [],
    busy: false,
    msg: null,
    poll: null,
    inlineTried: false,
    uploading: {},
  };

  // ------------------------------------------------------------------ utilità

  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const nf = (d) => new Intl.NumberFormat('it-IT', { minimumFractionDigits: d, maximumFractionDigits: d });
  const num = (v, d = 2) => (v == null || !isFinite(v) ? 'n.d.' : (v < 0 ? '−' : '') + nf(d).format(Math.abs(v)));
  const fmt = (v, unit) => {
    if (v == null) return 'n.d.';
    switch (unit) {
      case 'eur': return num(v, 2) + ' €';
      case 'pct': return num(v, 1) + '%';
      case 'x': return num(v, 2) + 'x';
      case 'gg': return num(v, 0) + ' gg';
      case 'num': return num(v, 0);
      default: return num(v, 2);
    }
  };
  const nodeVal = (n) => (n.value == null ? 'n.v.' : n.display || fmt(n.value, n.unit));
  const plabel = (p) => {
    let m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(p);
    if (m) return `${m[3]}/${m[2]}/${m[1]}`;
    m = /^(\d{4})-(\d{2})$/.exec(p);
    if (m) return `${m[2]}/${m[1]}`;
    if (p === 'prosp') return 'Prospettico';
    if (p === 'tot') return 'Complessivo';
    return p;
  };
  const dateIt = (ts) => (ts ? new Date(ts * 1000).toLocaleString('it-IT', { dateStyle: 'short', timeStyle: 'short' }) : '');
  const levelTxt = { good: 'Adeguato', warn: 'Attenzione', bad: 'Critico' };
  const LEVEL_DOC = { base: 'Base', sugg: 'Consigliato', opt: 'Opzionale' };

  async function api(path, opts = {}) {
    const headers = { 'X-WP-Nonce': B.nonce };
    let body = opts.body;
    if (opts.json !== undefined) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(opts.json);
    }
    const r = await fetch(B.rest + path, { method: opts.method || 'GET', headers, body, credentials: 'same-origin' });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(j.message || 'Errore ' + r.status);
    return j;
  }

  function flash(text, type = 'info') {
    S.msg = { text, type };
    render();
    if (type !== 'error') setTimeout(() => { if (S.msg && S.msg.text === text) { S.msg = null; render(); } }, 5000);
  }

  async function guard(fn) {
    if (S.busy) return;
    S.busy = true;
    render();
    try { await fn(); } catch (e) { S.msg = { text: e.message, type: 'error' }; }
    S.busy = false;
    render();
  }

  const fileUrl = (fid, page) => `${B.rest}/reports/${S.rep.id}/files/${fid}?_wpnonce=${encodeURIComponent(B.nonce)}${page ? '#page=' + page : ''}`;

  // mappa inversa documento -> sezioni
  const DOC_SECTIONS = {};
  Object.entries(C.sections).forEach(([sid, s]) => {
    s.required.flat().forEach((d) => { (DOC_SECTIONS[d] = DOC_SECTIONS[d] || []).push({ sid, n: s.n, req: true }); });
    s.optional.forEach((d) => { (DOC_SECTIONS[d] = DOC_SECTIONS[d] || []).push({ sid, n: s.n, req: false }); });
  });

  // ------------------------------------------------------------------ caricamento

  async function loadList() {
    S.list = await api('/reports');
  }
  async function openReport(id) {
    S.rep = await api('/reports/' + id);
    S.view = 'report';
    S.dataset = null; S.kpi = null; S.doc = null; S.trace = [];
    if (S.rep.kpi && S.rep.kpi.nodes) S.kpi = S.rep.kpi;
    if (S.rep.step >= 4) await loadDataset();
    if (S.rep.step >= 5 && S.rep.report) await loadDoc();
    startPolling();
  }
  async function reload() {
    S.rep = await api('/reports/' + S.rep.id);
    if (S.rep.kpi && S.rep.kpi.nodes) S.kpi = S.rep.kpi; else S.kpi = null;
  }
  async function loadDataset() { S.dataset = await api(`/reports/${S.rep.id}/dataset`); }
  async function loadDoc() { S.doc = await api(`/reports/${S.rep.id}/document`); }

  async function save(patch) {
    S.rep = await api('/reports/' + S.rep.id, { method: 'POST', json: patch });
    if (S.rep.kpi && S.rep.kpi.nodes) S.kpi = S.rep.kpi; else S.kpi = null;
  }

  function jobActive() {
    const j = S.rep && S.rep.job;
    return j && (j.status === 'queued' || j.status === 'running');
  }

  function startPolling() {
    stopPolling();
    if (!jobActive()) return;
    S.inlineTried = false;
    S.poll = setInterval(async () => {
      try {
        const wasType = S.rep.job.type;
        await reload();
        const j = S.rep.job;
        if (j && j.status === 'queued' && !S.inlineTried && Date.now() / 1000 - j.created > 15) {
          // il loopback non è partito: esegue il lavoro direttamente dal browser
          S.inlineTried = true;
          api(`/reports/${S.rep.id}/run-inline`, { method: 'POST' }).catch((e) => flash(e.message, 'error'));
        }
        if (!jobActive()) {
          stopPolling();
          if (wasType === 'extract') { await loadDataset(); flash('Estrazione completata. Verifica i dati estratti.', 'ok'); }
          if (wasType === 'write') { await loadDoc(); flash('Report generato.', 'ok'); }
        }
        render();
      } catch (e) { /* rete instabile: riprova al prossimo giro */ }
    }, 3000);
  }
  function stopPolling() { if (S.poll) clearInterval(S.poll); S.poll = null; }

  // ------------------------------------------------------------------ vista elenco

  function viewList() {
    const rows = S.list.map((r) => `
      <tr>
        <td><a href="#" data-a="open" data-id="${r.id}"><strong>${esc(r.title)}</strong></a></td>
        <td>${esc((C.finalita[r.finalita] || {}).label || '')}</td>
        <td>${r.generated ? '<span class="ir-badge ir-good">Report generato</span>' : `<span class="ir-badge">Passo ${r.step} di 5</span>`}</td>
        <td>${esc(r.author)}</td>
        <td>${dateIt(r.modified)}</td>
        <td class="ir-right"><button class="button" data-a="open" data-id="${r.id}">Apri</button> <button class="button ir-link-danger" data-a="del-report" data-id="${r.id}">Elimina</button></td>
      </tr>`).join('');
    return `
      <div class="ir-head"><h1>Report di consulenza</h1></div>
      ${!B.hasKey ? `<div class="ir-alert ir-alert-error">La chiave API di Anthropic non è configurata: l'estrazione e la stesura non funzioneranno. ${B.settingsUrl ? `<a href="${B.settingsUrl}">Vai alle impostazioni</a>` : 'Contatta un amministratore.'}</div>` : ''}
      <div class="ir-card ir-new">
        <h2>Nuovo report</h2>
        <form data-a="create" class="ir-inline-form">
          <input type="text" name="title" placeholder="Ragione sociale dell'azienda da analizzare" required>
          <button class="button button-primary" type="submit">Crea report</button>
        </form>
        <p class="ir-muted">Il percorso è sempre lo stesso: 1. Azienda · 2. Documenti · 3. Capitoli · 4. Dati e KPI · 5. Report.</p>
      </div>
      <div class="ir-card">
        <h2>Report esistenti</h2>
        ${S.list.length ? `<table class="ir-table"><thead><tr><th>Azienda</th><th>Finalità</th><th>Stato</th><th>Creato da</th><th>Ultima modifica</th><th></th></tr></thead><tbody>${rows}</tbody></table>` : '<p class="ir-muted">Nessun report ancora.</p>'}
      </div>`;
  }

  // ------------------------------------------------------------------ stepper

  const STEPS = ['Azienda', 'Documenti', 'Capitoli', 'Dati e KPI', 'Report'];
  function maxStep() {
    const r = S.rep;
    let m = 1;
    if (r.profile.ragione_sociale && r.profile.finalita) m = 2;
    if (m >= 2 && r.files.length) m = 3;
    if (m >= 3 && r.sections_selected.length) m = 4;
    if (m >= 4 && r.kpi && r.kpi.approved_at) m = 5;
    return m;
  }
  function stepper() {
    const mx = maxStep();
    return `<ol class="ir-steps">${STEPS.map((t, i) => {
      const n = i + 1;
      const cls = n === S.rep.step ? 'current' : n < S.rep.step ? 'done' : '';
      return `<li class="${cls} ${n > mx ? 'locked' : ''}"><button data-a="goto" data-step="${n}" ${n > mx ? 'disabled' : ''}><span>${n}</span>${t}</button></li>`;
    }).join('')}</ol>`;
  }

  function viewReport() {
    const r = S.rep;
    const body = [null, step1, step2, step3, step4, step5][r.step]();
    return `
      <div class="ir-head">
        <button class="button" data-a="back">← Tutti i report</button>
        <h1>${esc(r.profile.ragione_sociale || 'Nuovo report')}</h1>
        <span class="ir-muted">Modello: ${esc(B.model)}</span>
      </div>
      ${stepper()}
      ${jobPanel()}
      <div class="ir-body">${body}</div>
      ${traceDrawer()}`;
  }

  function jobPanel() {
    const j = S.rep.job;
    if (!j || !(j.status === 'queued' || j.status === 'running')) return '';
    const total = j.items.length;
    const done = j.done.length;
    const pct = total ? Math.round((done / total) * 100) : 0;
    const cur = j.current ? (j.type === 'extract' ? fileName(j.current) : sectionTitle(j.current)) : 'in attesa di avvio…';
    return `<div class="ir-job">
      <div class="ir-job-row"><strong>${j.type === 'extract' ? 'Estrazione dati in corso' : 'Stesura del report in corso'}</strong> — ${done} di ${total} completati
      <button class="button button-small" data-a="cancel-job">Interrompi</button></div>
      <div class="ir-progress"><div style="width:${pct}%"></div></div>
      <div class="ir-muted">In elaborazione: ${esc(cur)}. Ogni elemento può richiedere da uno a qualche minuto. Puoi lasciare la pagina: il lavoro prosegue sul server.</div>
    </div>`;
  }

  const fileName = (fid) => { const f = S.rep.files.find((x) => x.id === fid); return f ? `${f.slot} — ${f.name}` : fid; };
  const sectionTitle = (sid) => (sid === 'S0' ? 'Sommario esecutivo' : sid === 'CTX' ? 'Inquadramento dell\'impresa (qualifica dimensionale, gruppo, settore)' : C.sections[sid] ? C.sections[sid].title : sid);

  // ------------------------------------------------------------------ passo 1

  function step1() {
    const p = S.rep.profile;
    const opt = (arr, val) => arr.map((o) => `<option ${o === val ? 'selected' : ''}>${esc(o)}</option>`).join('');
    const optK = (obj, val) => Object.entries(obj).map(([k, l]) => `<option value="${k}" ${k === val ? 'selected' : ''}>${esc(l)}</option>`).join('');
    const fin = Object.entries(C.finalita).map(([k, f]) => `
      <label class="ir-choice ${p.finalita === k ? 'on' : ''}"><input type="radio" name="finalita" value="${k}" ${p.finalita === k ? 'checked' : ''}><strong>${k}.</strong> ${esc(f.label)}</label>`).join('');
    return `
      <form data-a="save-profile" class="ir-card ir-form">
        <h2>1. Profilo dell'azienda e finalità del report</h2>
        <p class="ir-muted">Questi dati orientano l'analisi (benchmark di settore, modello di Z-Score, agevolazioni). I dati anagrafici ufficiali verranno comunque letti dalla visura e citati con la fonte.</p>
        <div class="ir-grid">
          <label>Ragione sociale *<input name="ragione_sociale" value="${esc(p.ragione_sociale)}" required></label>
          <label>Forma giuridica<select name="forma_giuridica">${opt(C.forme, p.forma_giuridica)}</select></label>
          <label>Partita IVA<input name="partita_iva" value="${esc(p.partita_iva)}"></label>
          <label>Attività / codice ATECO<input name="ateco" value="${esc(p.ateco)}" placeholder="es. 10.71.10 — Produzione di prodotti di panetteria"></label>
          <label>Macro-settore per i benchmark<select name="settore">${optK(C.settori, p.settore)}</select><small>Determina i valori di confronto e la scelta tra Z' (manifattura) e Z''.</small></label>
          <label>Dimensione (Racc. UE 2003/361)<select name="dimensione">${optK(C.dimensioni, p.dimensione)}</select><small>Usata per l'aliquota ZES Unica.</small></label>
          <label>Sede legale<input name="sede_legale" value="${esc(p.sede_legale)}"></label>
          <label>Sede operativa<input name="sede_operativa" value="${esc(p.sede_operativa)}"></label>
          <label>Provincia<select name="provincia">${opt(C.province, p.provincia)}</select></label>
          <label>Esercizi da analizzare<input name="esercizi" value="${esc(p.esercizi)}" placeholder="es. 2022, 2023, 2024"></label>
        </div>
        <h3>Finalità del report *</h3>
        <div class="ir-choices">${fin}</div>
        <label class="ir-full">Specifica (solo per "Altro")<input name="finalita_altro" value="${esc(p.finalita_altro)}"></label>
        <label class="ir-full">Note per l'analisi (facoltative)<textarea name="note" rows="3">${esc(p.note)}</textarea></label>
        <div class="ir-actions"><button class="button button-primary button-large" type="submit" ${S.busy ? 'disabled' : ''}>Salva e passa ai documenti →</button></div>
      </form>`;
  }

  // ------------------------------------------------------------------ passo 2

  function step2() {
    const r = S.rep;
    const docs = C.documents;
    const fin = C.finalita[r.profile.finalita] || { mandatory: [], recommended: [] };
    const byArea = {};
    Object.entries(docs).forEach(([code, d]) => { (byArea[d.area] = byArea[d.area] || []).push([code, d]); });

    const areaHtml = Object.entries(byArea).map(([a, list]) => `
      <div class="ir-area">
        <h3><span class="ir-area-code">${a === 'X' ? '✎' : a}</span> ${esc(C.areas[a])}</h3>
        ${list.map(([code, d]) => docRow(code, d)).join('')}
      </div>`).join('');

    const enabled = r.derived.enabled;
    const secList = Object.entries(C.sections).map(([sid, s]) => {
      const e = enabled[sid];
      const mand = fin.mandatory.includes(sid);
      const miss = e.enabled ? '' : `<div class="ir-miss">Manca: ${e.missing.map((g) => g.map((c) => `<b>${c}</b>`).join(' o ')).join(' + ')}</div>`;
      return `<li class="${e.enabled ? 'on' : 'off'}"><span class="ir-dot"></span><div><strong>§${s.n}</strong> ${esc(s.title)} ${mand ? '<span class="ir-badge ir-mand">obbligatoria</span>' : ''}${s.sicilia ? '<span class="ir-badge ir-sic">Sicilia</span>' : ''}${miss}</div></li>`;
    }).join('');
    const nOn = Object.values(enabled).filter((e) => e.enabled).length;
    const mandMissing = fin.mandatory.filter((sid) => !enabled[sid].enabled);
    const baseMissing = Object.entries(docs).filter(([c, d]) => d.level === 'base' && !r.derived.available.includes(c)).map(([c]) => c);

    return `
      <div class="ir-two">
        <div class="ir-card">
          <h2>2. Documenti disponibili</h2>
          <p class="ir-muted">Carica i documenti nella riga corretta. Ogni documento abilita uno o più capitoli del report (colonna destra, aggiornata in tempo reale). Formati: ${B.exts.join(', ')} — massimo ${B.maxMb} MB per file. Per i bilanci carica un file per esercizio.</p>
          ${areaHtml}
          <div class="ir-actions"><button class="button" data-a="goto" data-step="1">← Indietro</button><button class="button button-primary button-large" data-a="goto" data-step="3" ${r.files.length ? '' : 'disabled'}>Passa ai capitoli →</button></div>
        </div>
        <aside class="ir-card ir-sticky">
          <h2>Capitoli abilitati: ${nOn} di 22</h2>
          ${baseMissing.length ? `<div class="ir-alert ir-alert-warn">Documenti di base non ancora caricati: ${baseMissing.join(', ')}.</div>` : ''}
          ${mandMissing.length ? `<div class="ir-alert ir-alert-warn">Capitoli obbligatori per la finalità "${esc(fin.label)}" non ancora abilitati: ${mandMissing.map((s) => '§' + C.sections[s].n).join(', ')}.</div>` : ''}
          <ul class="ir-seclist">${secList}</ul>
        </aside>
      </div>`;
  }

  function docRow(code, d) {
    const r = S.rep;
    const files = r.files.filter((f) => f.slot === code);
    const chips = (DOC_SECTIONS[code] || []).map((x) => `<span class="ir-chip ${x.req ? 'req' : ''}" title="${x.req ? 'Necessario' : 'Facoltativo'} per §${x.n}">§${x.n}</span>`).join('');
    if (d.virtual) {
      return `<div class="ir-doc ${r.interview.text ? 'has' : ''}">
        <div class="ir-doc-main"><span class="ir-code">${code}</span><div><strong>${esc(d.name)}</strong> <span class="ir-lvl ${d.level}">${LEVEL_DOC[d.level]}</span><div class="ir-muted">${esc(d.hint)}</div><div class="ir-chips">${chips}</div></div></div>
        <div class="ir-interview">
          <label>Data dell'intervista <input type="date" data-f="int-date" value="${esc(r.interview.date)}"></label>
          <textarea data-f="int-text" rows="5" placeholder="Annota le informazioni raccolte dal management: modello di business, mercato, clienti chiave, strategie, criticità percepite. Queste note saranno citate come fonte.">${esc(r.interview.text)}</textarea>
          <button class="button" data-a="save-interview">Salva note</button>
        </div>
      </div>`;
    }
    const fl = files.map((f) => {
      const st = f.extraction.status;
      const badge = st === 'done' ? '<span class="ir-badge ir-good">estratto</span>' : st === 'error' ? `<span class="ir-badge ir-bad" title="${esc(f.extraction.error || '')}">errore</span>` : '<span class="ir-badge">da elaborare</span>';
      return `<li><a href="${fileUrl(f.id)}" target="_blank" rel="noopener">${esc(f.name)}</a> <span class="ir-muted">${Math.round(f.size / 1024)} KB</span> ${badge} <button class="ir-x" data-a="del-file" data-id="${f.id}" title="Rimuovi">×</button></li>`;
    }).join('');
    const up = S.uploading[code];
    return `<div class="ir-doc ${files.length ? 'has' : ''}" data-drop="${code}">
      <div class="ir-doc-main"><span class="ir-code">${code}</span><div><strong>${esc(d.name)}</strong> <span class="ir-lvl ${d.level}">${LEVEL_DOC[d.level]}</span><div class="ir-muted">${esc(d.hint)}</div><div class="ir-chips">${chips}</div></div></div>
      <div class="ir-doc-files">
        ${fl ? `<ul>${fl}</ul>` : ''}
        <label class="button ir-upload">${up ? 'Caricamento…' : '+ Carica file'}<input type="file" multiple data-upload="${code}" accept="${B.exts.map((e) => '.' + e).join(',')}" hidden></label>
      </div>
    </div>`;
  }

  async function uploadFiles(slot, fileList) {
    S.uploading[slot] = true; render();
    try {
      for (const f of fileList) {
        if (f.size > B.maxMb * 1024 * 1024) { flash(`${f.name}: supera il limite di ${B.maxMb} MB.`, 'error'); continue; }
        const fd = new FormData();
        fd.append('slot', slot);
        fd.append('file', f);
        S.rep = await api(`/reports/${S.rep.id}/files`, { method: 'POST', body: fd });
      }
    } catch (e) { flash(e.message, 'error'); }
    delete S.uploading[slot];
    render();
  }

  // ------------------------------------------------------------------ passo 3

  function defaultSelection() {
    const r = S.rep;
    const fin = C.finalita[r.profile.finalita] || { mandatory: [], recommended: [] };
    const en = r.derived.enabled;
    const sel = new Set();
    [...fin.mandatory, ...fin.recommended, 'S17', 'S20'].forEach((s) => { if (en[s] && en[s].enabled) sel.add(s); });
    return Object.keys(C.sections).filter((s) => sel.has(s));
  }

  function step3() {
    const r = S.rep;
    const fin = C.finalita[r.profile.finalita] || { mandatory: [], recommended: [] };
    const en = r.derived.enabled;
    const sel = new Set(r.sections_selected.length ? r.sections_selected : defaultSelection());
    const rows = Object.entries(C.sections).map(([sid, s]) => {
      const e = en[sid];
      const mand = fin.mandatory.includes(sid);
      const rec = fin.recommended.includes(sid);
      const why = e.enabled ? '' : `<div class="ir-miss">Non disponibile — manca: ${e.missing.map((g) => g.join(' o ')).join(' + ')}</div>`;
      return `<label class="ir-sec ${e.enabled ? '' : 'disabled'}">
        <input type="checkbox" data-sec="${sid}" ${sel.has(sid) && e.enabled ? 'checked' : ''} ${e.enabled ? '' : 'disabled'} ${mand ? 'data-mand="1"' : ''}>
        <span class="ir-kind ${s.kind}">${s.kind === 'analitica' ? 'Calcoli' : 'Qualitativa'}</span>
        <span><strong>§${s.n}</strong> ${esc(s.title)}
          ${mand ? '<span class="ir-badge ir-mand">obbligatoria</span>' : ''}${rec ? '<span class="ir-badge">consigliata</span>' : ''}${s.sicilia ? '<span class="ir-badge ir-sic">Sicilia — valore aggiunto</span>' : ''}${s.min_years ? `<span class="ir-badge">richiede ≥ ${s.min_years} esercizi</span>` : ''}
          ${why}</span>
      </label>`;
    }).join('');
    return `<div class="ir-card">
      <h2>3. Capitoli del report</h2>
      <p class="ir-muted">Sono preselezionati i capitoli obbligatori e consigliati per la finalità "${esc(fin.label)}", più quelli specifici per la Sicilia. Il Sommario esecutivo, la Premessa metodologica, la Checklist, le Fonti, il Disclaimer e le appendici di tracciabilità sono sempre inclusi.</p>
      <div class="ir-seclist2">${rows}</div>
      <div class="ir-actions"><button class="button" data-a="goto" data-step="2">← Indietro</button><button class="button button-primary button-large" data-a="save-sections">Salva e passa ai dati →</button></div>
    </div>`;
  }

  // ------------------------------------------------------------------ passo 4

  function step4() {
    const r = S.rep;
    const files = r.files;
    const pending = files.filter((f) => f.extraction.status !== 'done').length;
    const ds = S.dataset;
    const sum = r.derived.dataset;
    const fileRows = files.map((f) => {
      const st = f.extraction.status;
      const doc = ds && ds.docs[f.id];
      const info = st === 'done' ? `${f.extraction.count} ${f.extraction.count === 1 ? 'valore' : 'valori'}, ${f.extraction.info} ${f.extraction.info === 1 ? 'informazione' : 'informazioni'}` : st === 'error' ? `<span class="ir-bad-t">${esc(f.extraction.error)}</span>` : 'da elaborare';
      const mismatch = doc && !doc.match ? `<div class="ir-alert ir-alert-warn">Il documento non sembra corrispondere alla categoria ${f.slot}: ${esc(doc.note)}</div>` : '';
      return `<tr><td><span class="ir-code">${f.slot}</span></td><td><a href="${fileUrl(f.id)}" target="_blank" rel="noopener">${esc(f.name)}</a>${doc ? `<div class="ir-muted">Riconosciuto come: ${esc(doc.tipo)} · scala: ${esc(doc.scala)}</div>` : ''}${mismatch}</td><td>${info}</td><td class="ir-right"><button class="button button-small" data-a="reextract" data-id="${f.id}" ${jobActive() ? 'disabled' : ''}>${st === 'done' ? 'Ri-estrai' : 'Estrai'}</button></td></tr>`;
    }).join('');

    return `
      <div class="ir-card">
        <h2>4a. Estrazione dei dati dai documenti</h2>
        <p class="ir-muted">Claude legge ogni documento ed estrae i singoli valori con pagina e citazione testuale. Non esegue calcoli: somme, indici e verifiche sono svolti dal plugin con formule fisse.</p>
        <table class="ir-table"><thead><tr><th>Doc.</th><th>File</th><th>Esito</th><th></th></tr></thead><tbody>${fileRows}</tbody></table>
        <div class="ir-actions"><button class="button button-primary" data-a="extract" ${pending && !jobActive() ? '' : 'disabled'}>Estrai dati da ${pending} document${pending === 1 ? 'o' : 'i'} da elaborare</button></div>
      </div>
      ${ds ? datasetCard(ds, sum) : ''}
      ${ds && ds.entries.length ? paramsCard() : ''}
      ${S.kpi && S.kpi.nodes ? kpiCard() : ''}
      <div class="ir-actions"><button class="button" data-a="goto" data-step="3">← Indietro</button></div>`;
  }

  function datasetCard(ds, sum) {
    const dict = C.dictionary;
    const filt = S.filter;
    const entries = ds.entries.filter((e) => filt === 'tutti' || (filt === 'conflitti' && e.status === 'conflict') || (filt === 'verificare' && e.status === 'to_verify') || (filt === 'manuali' && (e.status === 'manual' || e.status === 'chosen' || e.status === 'excluded')) || (filt === 'automatici' && e.status === 'auto'));
    const groups = {};
    entries.forEach((e) => { const g = dict[e.code].group; (groups[g] = groups[g] || []).push(e); });
    const order = Object.keys(dict);
    const gpos = (g) => order.findIndex((k) => dict[k].group === g);
    const html = Object.entries(groups).sort((a, b) => gpos(a[0]) - gpos(b[0])).map(([g, list]) => {
      const periods = [...new Set(list.map((e) => e.period))].sort();
      const codes = [...new Set(list.map((e) => e.code))].sort((a, b) => order.indexOf(a) - order.indexOf(b));
      const rows = codes.map((code) => `<tr><td class="ir-lbl">${esc(dict[code].label)}</td>${periods.map((p) => {
        const e = list.find((x) => x.code === code && x.period === p);
        if (!e) return '<td class="ir-right ir-muted">—</td>';
        const cls = { conflict: 'c-bad', to_verify: 'c-warn', manual: 'c-man', chosen: 'c-man', excluded: 'c-ex', auto: 'c-man' }[e.status] || '';
        const icon = { conflict: '⚠ ', to_verify: '? ', manual: '✎ ', chosen: '✓ ', excluded: '⊘ ', auto: '⟳ ' }[e.status] || (e.verified ? '✓ ' : '');
        const v = e.status === 'conflict' ? 'conflitto' : e.status === 'excluded' ? 'escluso' : fmt(e.value, e.unit);
        const abs = e.source && e.source.stato === 'assente' ? ' <span class="ir-muted" title="Voce assente nel prospetto">(ass.)</span>' : '';
        return `<td class="ir-right ir-cell ${cls}"><a href="#" data-a="entry" data-key="${esc(e.key)}">${icon}${v}</a>${abs}${e.confirmations > 1 ? `<sup title="Confermato da ${e.confirmations} fonti">×${e.confirmations}</sup>` : ''}</td>`;
      }).join('')}</tr>`).join('');
      return `<h3>${esc(g)}</h3><div class="ir-scroll"><table class="ir-table ir-data"><thead><tr><th>Voce</th>${periods.map((p) => `<th class="ir-right">${plabel(p)}</th>`).join('')}</tr></thead><tbody>${rows}</tbody></table></div>`;
    }).join('');

    const warn = ds.warnings.length ? `<details class="ir-details"><summary>Avvisi dell'estrazione (${ds.warnings.length})</summary><ul>${ds.warnings.map((w) => `<li><span class="ir-muted">${esc(fileName(w.file_id))}:</span> ${esc(w.msg)}</li>`).join('')}</ul></details>` : '';
    const info = ds.info.length ? `<details class="ir-details"><summary>Informazioni qualitative estratte (${ds.info.length})</summary><table class="ir-table"><thead><tr><th>Campo</th><th>Valore</th><th>Fonte</th></tr></thead><tbody>${ds.info.map((i) => `<tr><td>${esc(C.info_fields[i.field] || i.field)}</td><td>${esc(i.value)}</td><td><a href="${fileUrl(i.file_id, i.page)}" target="_blank" rel="noopener">${esc(fileName(i.file_id))}${i.page ? ', pag. ' + i.page : i.pos ? ', ' + esc(i.pos) : ''}</a><div class="ir-quote">«${esc(i.quote)}»</div></td></tr>`).join('')}</tbody></table></details>` : '';
    const tab = (k, l, n) => `<button class="ir-tab ${filt === k ? 'on' : ''}" data-a="filter" data-f="${k}">${l}${n != null ? ` (${n})` : ''}</button>`;

    return `<div class="ir-card">
      <h2>4b. Verifica dei dati estratti</h2>
      <p class="ir-muted">Clicca su un valore per vedere documento, pagina e citazione, aprire il PDF alla pagina giusta, confermare, scegliere tra fonti discordanti o correggere (con nota obbligatoria). I valori con <strong>⚠</strong> hanno fonti discordanti e vanno risolti prima del calcolo; quelli con <strong>?</strong> hanno affidabilità bassa e vanno controllati. I valori con <strong>⟳</strong> avevano fonti discordanti e sono stati risolti con una regola contabile fissa (prevale il bilancio più recente che riespone l'esercizio; a parità di bilancio prevale il prospetto ufficiale): puoi sempre scegliere un'altra fonte.</p>
      <div class="ir-stats">
        <span class="ir-stat">${sum.totale}<small>dati</small></span>
        <span class="ir-stat ${sum.conflitti ? 'bad' : ''}">${sum.conflitti}<small>conflitti</small></span>
        <span class="ir-stat ${sum.da_verificare ? 'warn' : ''}">${sum.da_verificare}<small>da verificare</small></span>
        <span class="ir-stat">${sum.manuali}<small>manuali</small></span>
      </div>
      <div class="ir-tabs">${tab('tutti', 'Tutti')}${tab('conflitti', 'Conflitti', sum.conflitti)}${tab('verificare', 'Da verificare', sum.da_verificare)}${tab('manuali', 'Interventi manuali')}${sum.automatici ? tab('automatici', 'Risolti con regola contabile', sum.automatici) : ''}<button class="button button-small" data-a="add-manual">+ Aggiungi un dato mancante</button></div>
      ${html || '<p class="ir-muted">Nessun dato in questa vista.</p>'}
      ${warn}${info}
    </div>`;
  }

  function paramsCard() {
    const p = S.rep.params;
    const settore = C.settori[S.rep.profile.settore] || '';
    return `<div class="ir-card">
      <h2>4c. Parametri di calcolo</h2>
      <div class="ir-grid">
        <label>Modello di Z-Score<select data-p="zscore_model">
          <option value="auto" ${p.zscore_model === 'auto' ? 'selected' : ''}>Automatico in base al settore (${esc(settore)})</option>
          <option value="Z1" ${p.zscore_model === 'Z1' ? 'selected' : ''}>Z' — manifatturiere non quotate</option>
          <option value="Z2" ${p.zscore_model === 'Z2' ? 'selected' : ''}>Z'' — servizi, commercio, non manifatturiere</option></select></label>
        <label class="ir-check"><input type="checkbox" data-p="soci_onerosi" ${p.soci_onerosi ? 'checked' : ''}> I finanziamenti dei soci (D.3) sono onerosi: includili nella PFN</label>
        <label class="ir-check"><input type="checkbox" data-p="infragruppo_commerciali" ${p.infragruppo_commerciali ? 'checked' : ''}> I debiti verso imprese del gruppo (D.9, D.10, D.11, D.11-bis) hanno natura commerciale: includili nel capitale circolante commerciale e nel ciclo di cassa<small>Il DPO viene comunque esposto in due versioni: verso fornitori terzi e complessivo.</small></label>
        <label>Coefficiente di riparto ZES stimato (%)<input type="number" step="0.01" min="0" max="100" data-p="riparto_zes" value="${p.riparto_zes == null ? '' : p.riparto_zes}" placeholder="predefinito: 60"><small>Lascia vuoto per il valore dei parametri (60%, prudenziale sul riparto 2025).</small></label>
      </div>
      <div class="ir-actions"><button class="button button-primary button-large" data-a="compute" ${S.busy || jobActive() ? 'disabled' : ''}>${S.kpi ? 'Ricalcola KPI' : 'Calcola KPI'}</button></div>
    </div>`;
  }

  const KPI_GROUPS = [
    ['Conto economico', ['RICAVI', 'VP', 'VA', 'EBITDA', 'EBIT', 'EBT', 'UTILE', 'EBITDA_M', 'ROS', 'NET_M', 'VAR_RICAVI', 'VAR_EBITDA']],
    ['Stato patrimoniale', ['AC', 'AI', 'AT', 'PC', 'PCNS', 'PN', 'CCN', 'DEBFIN', 'PFN', 'CIN', 'CCN_COMM', 'IMM_NETTE', 'ALTRE_NETTE']],
    ['Indici reddituali', ['ROE', 'ROI', 'ROA', 'ROD', 'LEVA']],
    ['Liquidità e circolante', ['CR', 'QR', 'CASH_R', 'CCN_RICAVI', 'DSO', 'DPO', 'DPO_TOT', 'DIO', 'CCC']],
    ['Solidità patrimoniale', ['AUTONOMIA', 'INDEBITAMENTO', 'PN_TERZI', 'MS_PRIMARIO', 'MS_SECONDARIO']],
    ['Gestione finanziaria', ['PFN_EBITDA', 'PFN_PN', 'LEVERAGE', 'COPERTURA_OF', 'OF_RICAVI']],
    ['Z-Score', ['Z_X1', 'Z_X2', 'Z_X3', 'Z_X4', 'Z_X5', 'ZSCORE']],
    ['Rendiconto finanziario', ['RF_A', 'RF_B', 'RF_C', 'FCF']],
    ['Fiscale', ['ETR']],
    ['Rating', ['RATING']],
  ];

  function kpiCard() {
    const k = S.kpi;
    const nodes = k.nodes;
    const annual = (k.periods && k.periods.annual) || [];
    const checks = k.checks || [];
    const failed = checks.filter((c) => c.ok === false);
    const notRun = checks.filter((c) => c.ok === null);

    const checkRows = checks.map((c) => `<tr class="${c.ok === false ? 'row-bad' : ''}"><td>${esc(c.label)}</td><td>${plabel(c.period)}</td>
      <td class="ir-right">${c.a.ref ? `<a href="#" data-a="trace" data-ref="${esc(c.a.ref)}">${fmt(c.a.value, 'eur')}</a>` : '—'}</td>
      <td class="ir-right">${c.b.ref ? `<a href="#" data-a="trace" data-ref="${esc(c.b.ref)}">${fmt(c.b.value, 'eur')}</a>` : '—'}</td>
      <td class="ir-right">${c.diff == null ? '—' : fmt(c.diff, 'eur')}</td>
      <td>${c.ok === true ? '<span class="ir-badge ir-good">superato</span>' : c.ok === false ? '<span class="ir-badge ir-bad">NON superato</span>' : '<span class="ir-badge">non eseguibile</span>'}${c.ok === false && c.note ? `<div class="ir-muted">${esc(c.note)}</div>` : ''}</td></tr>`).join('');

    const groupTables = KPI_GROUPS.map(([title, keys]) => {
      const rows = keys.filter((key) => annual.some((p) => nodes[`${key}@${p}`])).map((key) => {
        const any = annual.map((p) => nodes[`${key}@${p}`]).find(Boolean);
        return `<tr><td class="ir-lbl">${esc(any.label)}</td>${annual.map((p) => kpiCell(nodes[`${key}@${p}`])).join('')}</tr>`;
      }).join('');
      return rows ? `<h3>${title}</h3><div class="ir-scroll"><table class="ir-table ir-kpi"><thead><tr><th>Indicatore</th>${annual.map((p) => `<th class="ir-right">${p}</th>`).join('')}</tr></thead><tbody>${rows}</tbody></table></div>` : '';
    }).join('');

    const others = Object.values(nodes).filter((n) => !/^\d{4}$/.test(n.period) && !['CRED_ENTRO', 'CRED_OLTRE', 'DEB_ENTRO', 'DEB_OLTRE'].includes(n.key));
    const otherRows = others.map((n) => `<tr><td class="ir-lbl">${esc(n.label)}</td><td>${plabel(n.period)}</td>${kpiCell(n)}<td>${n.eval ? `<span class="ir-badge ir-${n.eval.level}">${esc(n.eval.text)}</span>` : ''}</td></tr>`).join('');

    const approved = k.approved_at;
    const conflicts = S.rep.derived.dataset.conflitti;
    return `<div class="ir-card">
      <h2>4d. Cruscotto KPI — anteprima per validazione</h2>
      <p class="ir-muted">Clicca su qualunque valore per vederne formula, valori utilizzati e fonte di ogni dato. "n.v." = non valutabile: il pannello indica cosa manca.</p>
      <h3>Controlli di quadratura ${failed.length ? `<span class="ir-badge ir-bad">${failed.length} non superati</span>` : checks.length ? '<span class="ir-badge ir-good">nessun errore</span>' : ''} ${notRun.length ? `<span class="ir-badge">${notRun.length} non eseguibili</span>` : ''}</h3>
      ${failed.length ? '<div class="ir-alert ir-alert-error">Uno o più controlli di quadratura non sono superati: probabile errore di estrazione o voce mancante. Accanto a ogni controllo fallito è indicata, quando individuabile, la possibile causa. Verifica i dati coinvolti prima di approvare.</div>' : ''}
      <details class="ir-details" ${failed.length ? 'open' : ''}><summary>Dettaglio controlli (${checks.length})</summary><div class="ir-scroll"><table class="ir-table"><thead><tr><th>Controllo</th><th>Periodo</th><th class="ir-right">Valore A</th><th class="ir-right">Valore B</th><th class="ir-right">Differenza</th><th>Esito</th></tr></thead><tbody>${checkRows}</tbody></table></div></details>
      ${groupTables}
      ${otherRows ? `<h3>Altri indicatori (Centrale Rischi, scadenziari, CCII, DSCR, stress test, ZES, debito)</h3><div class="ir-scroll"><table class="ir-table ir-kpi"><thead><tr><th>Indicatore</th><th>Riferimento</th><th class="ir-right">Valore</th><th>Esito</th></tr></thead><tbody>${otherRows}</tbody></table></div>` : ''}
      <div class="ir-approve">
        ${approved ? `<div class="ir-alert ir-alert-ok">Cruscotto approvato da ${esc(k.approved_by)} il ${dateIt(k.approved_at)}.</div>` : ''}
        <button class="button button-primary button-large" data-a="approve" ${conflicts || S.busy ? 'disabled' : ''}>${approved ? 'Vai al report →' : 'Confermo dati e KPI: procedi alla stesura →'}</button>
      </div>
    </div>`;
  }

  function kpiCell(n) {
    if (!n) return '<td class="ir-right ir-muted">—</td>';
    const lvl = n.eval ? 'l-' + n.eval.level : n.value == null ? 'l-nv' : '';
    return `<td class="ir-right ir-cell ${lvl}"><a href="#" data-a="trace" data-ref="K:${esc(n.id)}" title="${esc(n.eval ? n.eval.text : n.formula)}">${nodeVal(n)}</a></td>`;
  }

  // ------------------------------------------------------------------ passo 5

  function step5() {
    const r = S.rep;
    const j = r.job;
    const gen = r.report && r.report.generated_at;
    const secs = (r.report && r.report.sections) || {};
    const errors = Object.entries(secs).filter(([, s]) => s.status === 'error');
    const order = Object.keys(C.sections).filter((s) => r.sections_selected.includes(s));
    if (!gen && !jobActive()) {
      return `<div class="ir-card ir-center">
        <h2>5. Generazione del report</h2>
        <p>Verranno redatti il Sommario esecutivo e ${order.length} capitoli: ${order.map((s) => esc(C.sections[s].title)).join('; ')}. I capitoli saranno numerati in ordine progressivo.</p>
        <p class="ir-muted">Ogni capitolo è scritto da Claude a partire dalle tabelle e dagli indicatori approvati: i numeri sono inseriti dal plugin tramite riferimenti tracciati. Tempo indicativo: 1-2 minuti per capitolo.</p>
        ${errors.length ? `<div class="ir-alert ir-alert-error">Errori nella precedente generazione: ${errors.map(([s, e]) => esc(sectionTitle(s) + ': ' + e.error)).join('; ')}</div>` : ''}
        <button class="button button-primary button-hero" data-a="write" ${S.busy ? 'disabled' : ''}>Genera il report</button>
      </div>`;
    }
    if (jobActive() && j.type === 'write') {
      return `<div class="ir-card"><h2>5. Stesura in corso</h2><ul class="ir-gen">${j.items.map((s) => `<li class="${j.done.includes(s) ? (j.errors && j.errors[s] ? 'err' : 'ok') : j.current === s ? 'run' : ''}">${esc(sectionTitle(s))}${j.errors && j.errors[s] ? ' — ' + esc(j.errors[s]) : ''}${secs[s] && secs[s].status === 'skipped' ? ' — esclusa: ' + esc(secs[s].reason) : ''}</li>`).join('')}</ul></div>`;
    }
    return viewer();
  }

  function viewer() {
    const d = S.doc;
    if (!d || !d.model) return '<div class="ir-card"><p>Caricamento del report…</p></div>';
    const m = d.model;
    const r = S.rep;
    const secs = (r.report && r.report.sections) || {};
    const errors = Object.entries(secs).filter(([, s]) => s.status === 'error');
    const skipped = Object.entries(secs).filter(([, s]) => s.status === 'skipped');
    const allWarn = m.sections.reduce((a, s) => a + s.warnings.length, 0);
    const toc = ['<li><a href="#ir-premessa">Premessa metodologica</a></li>', ...m.sections.map((s) => `<li><a href="#ir-${s.id}">${esc(s.title)}</a>${s.warnings.length ? ` <span class="ir-badge ir-warn" title="Avvisi">${s.warnings.length}</span>` : ''}</li>`), '<li><a href="#ir-documentazione">Documentazione esaminata</a></li>', '<li><a href="#ir-fonti">Fonti normative</a></li>', '<li><a href="#ir-disclaimer">Disclaimer</a></li>', '<li><a href="#ir-checklist">Checklist interna (non per il cliente)</a></li>'].join('');
    const ctx = r.report && r.report.context;
    const q = ctx && ctx.data && ctx.data.qualifica_dimensionale;
    const esiti = { pmi: 'rientra nella definizione di PMI', non_pmi: 'NON rientra nella definizione di PMI', da_verificare: 'qualifica di PMI da verificare' };
    const ctxBox = ctx && ctx.status === 'error'
      ? `<div class="ir-alert ir-alert-warn ir-internal">Scheda di inquadramento non generata (${esc(ctx.error)}): i capitoli sono stati scritti senza la verifica della qualifica dimensionale e dei rapporti di gruppo. Usa «Rigenera tutto» per riprovare.</div>`
      : q ? `<div class="ir-alert ir-alert-info ir-internal"><strong>Inquadramento usato in tutti i capitoli:</strong> ${esc(esiti[q.esito] || '')}. ${esc(q.motivazione || '')}${q.verifiche_necessarie && q.verifiche_necessarie.length ? ` <em>Da verificare: ${esc(q.verifiche_necessarie.join('; '))}</em>` : ''}</div>` : '';

    const sections = m.sections.map((s) => `
      <section id="ir-${s.id}" class="ir-doc-sec">
        <div class="ir-sec-tools"><button class="button button-small" data-a="rewrite" data-sid="${s.id}" ${jobActive() ? 'disabled' : ''}>Rigenera questo capitolo</button></div>
        <h1>${esc(s.title)}</h1>
        ${s.warnings.length ? `<div class="ir-alert ir-alert-warn"><strong>Controlli sul testo:</strong><ul>${s.warnings.map((w) => `<li>${esc(w)}</li>`).join('')}</ul></div>` : ''}
        ${renderBlocks(s.blocks, s)}
      </section>`).join('');

    return `<div class="ir-viewer">
      <aside class="ir-toc ir-card">
        <h3>Indice</h3><ol>${toc}</ol>
        <div class="ir-toc-actions">
          <a class="button button-primary" href="${B.rest}/reports/${r.id}/docx?kind=cliente&_wpnonce=${encodeURIComponent(B.nonce)}">Report per il cliente (.docx)</a>
          <a class="button" href="${B.rest}/reports/${r.id}/docx?kind=audit&_wpnonce=${encodeURIComponent(B.nonce)}">Fascicolo di audit interno (.docx)</a>
          <button class="button" data-a="print">Stampa / PDF del report cliente</button>
          <button class="button" data-a="write" ${jobActive() ? 'disabled' : ''}>Rigenera tutto</button>
        </div>
        <p class="ir-muted">Il report per il cliente non contiene nomi di file, operatori né note di lavorazione. Il fascicolo di audit (checklist, controlli di quadratura, tracciabilità di ogni numero, citazioni) resta allo studio. I file Word si aprono anche in Google Docs.</p>
      </aside>
      <article class="ir-paper">
        ${d.stale ? '<div class="ir-alert ir-alert-warn">I dati o i KPI sono stati modificati dopo la generazione: rigenera i capitoli interessati o l\'intero report.</div>' : ''}
        ${errors.length ? `<div class="ir-alert ir-alert-error">Capitoli non generati: ${errors.map(([s, e]) => `${esc(sectionTitle(s))} (${esc(e.error)}) <button class="button button-small" data-a="rewrite" data-sid="${s}">Riprova</button>`).join(' · ')}</div>` : ''}
        ${skipped.length ? `<div class="ir-alert ir-alert-info">Capitoli esclusi perché privi di indicatori calcolabili con i documenti forniti: ${skipped.map(([s]) => esc(sectionTitle(s))).join(', ')}. Sono elencati nella checklist interna.</div>` : ''}
        ${ctxBox}
        ${m.meta.perimetro_parziale ? `<div class="ir-alert ir-alert-warn">Il report non contiene tutte le analisi previste dalla finalità «${esc(m.meta.finalita_dichiarata)}»: in copertina la finalità è riportata come perimetro parziale e la premessa elenca le analisi mancanti.</div>` : ''}
        ${allWarn ? `<div class="ir-alert ir-alert-warn">Il testo contiene ${allWarn} avvisi (numeri non collegati a una fonte o riferimenti non validi, evidenziati in giallo/rosso). Verificali prima della consegna.</div>` : ''}
        <div class="ir-cover"><div class="ir-band"></div><div class="ir-cover-body">
          <div class="ir-cover-t">R E P O R T</div><div class="ir-cover-s">Analisi Economico-Finanziaria e Strategica</div>
          <div class="ir-cover-l">PREPARATO PER</div><div class="ir-cover-n">${esc(m.meta.ragione_sociale)}</div>
          <div>${esc(m.meta.forma)}${m.meta.piva ? '  │  P.IVA ' + esc(m.meta.piva) : ''}</div>
          <div class="ir-cover-d">${esc(m.meta.data)}</div><div class="ir-cover-p">Periodo di riferimento: ${esc(m.meta.periodo)}</div><div>Finalità: ${esc(m.meta.finalita)}</div>
        </div></div>
        <section id="ir-premessa" class="ir-doc-sec"><h1>Premessa metodologica</h1>${renderBlocks(m.premessa)}</section>
        ${sections}
        <section id="ir-documentazione" class="ir-doc-sec"><h1>Documentazione esaminata e perimetro dell'analisi</h1>${renderBlocks(m.documentazione)}</section>
        ${m.appendice && m.appendice.length ? `<section id="ir-appendice" class="ir-doc-sec"><h1>Appendice — Prospetti contabili riclassificati e indici</h1>${m.appendice.map(renderTable).join('')}</section>` : ''}
        <section id="ir-fonti" class="ir-doc-sec"><h1>Fonti normative e bibliografia</h1>${renderBlocks(m.fonti)}</section>
        <section id="ir-disclaimer" class="ir-doc-sec"><h1>Disclaimer professionale</h1>${renderBlocks(m.disclaimer)}</section>
        <section id="ir-checklist" class="ir-doc-sec ir-internal"><h1>Checklist delle operazioni</h1><div class="ir-alert ir-alert-info">Sezione a uso interno: non compare nel report per il cliente né nella stampa. È riportata nel fascicolo di audit.</div>${renderBlocks(m.checklist)}</section>
      </article>
    </div>`;
  }

  function renderInline(runs, sec) {
    return runs.map((r) => {
      const t = r.b ? (s) => `<strong>${s}</strong>` : (s) => s;
      switch (r.t) {
        case 'text': return t(esc(r.v));
        case 'ref': return t(`<a href="#" class="ir-ref" data-a="trace" data-ref="${esc(r.ref)}">${esc(r.v)}</a>`);
        case 'flag': return `<mark class="ir-flag" title="Numero scritto senza collegamento a una fonte: verificare">${esc(r.v)}</mark>`;
        case 'bad': return `<mark class="ir-badref">${esc(r.v)}</mark>`;
        case 'link': return `<a href="${esc(r.href)}" target="_blank" rel="noopener">${esc(r.v)}</a>`;
        case 'cite': return sec ? `<sup class="ir-internal"><a href="#" class="ir-cite" data-a="cite" data-sid="${sec.id}" data-c="${r.c}">[${r.c + 1}]</a></sup>` : '';
        case 'src': return `<sup class="ir-internal"><a href="#" class="ir-cite" data-a="trace" data-ref="${esc(r.ref)}" title="Fonte del fatto riportato">fonte</a></sup>`;
        default: return '';
      }
    }).join('');
  }

  function renderBlocks(blocks, sec) {
    return blocks.map((b) => {
      switch (b.type) {
        case 'h2': return `<h2>${renderInline(b.inline, sec)}</h2>`;
        case 'p': return `<p>${renderInline(b.inline, sec)}</p>`;
        case 'ul': return `<ul>${b.items.map((i) => `<li>${renderInline(i, sec)}</li>`).join('')}</ul>`;
        case 'ol': return `<ol>${b.items.map((i) => `<li>${renderInline(i, sec)}</li>`).join('')}</ol>`;
        case 'table': return sec && sec.tables[b.id] ? renderTable(sec.tables[b.id]) : '';
        default: return '';
      }
    }).join('');
  }

  function renderTable(t) {
    return `<figure class="ir-tbl"><figcaption>${esc(t.title)}</figcaption><div class="ir-scroll"><table><thead><tr>${t.columns.map((c, i) => `<th class="${i ? 'ir-right' : ''}">${esc(c)}</th>`).join('')}</tr></thead><tbody>${t.rows.map((r) => `<tr class="${r.total ? 'tot' : ''}"><td>${esc(r.label)}</td>${r.cells.map((c) => `<td class="ir-right ${c.level ? 'l-' + c.level : ''}">${c.ref ? `<a href="#" class="ir-ref" data-a="trace" data-ref="${esc(c.ref)}">${esc(c.v)}</a>` : esc(c.v)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>${t.notes.length ? `<div class="ir-notes">${t.notes.map((n) => `<div>${esc(n)}</div>`).join('')}</div>` : ''}</figure>`;
  }

  // ------------------------------------------------------------------ pannello di tracciabilità

  function getNode(id) {
    const fromDoc = S.doc && S.doc.nodes && S.doc.nodes[id];
    const fromKpi = S.kpi && S.kpi.nodes && S.kpi.nodes[id];
    return S.rep.step === 5 ? fromDoc || fromKpi : fromKpi || fromDoc;
  }
  function getData(key) {
    const fromDs = S.dataset && S.dataset.entries.find((e) => e.key === key);
    const fromDoc = S.doc && S.doc.data && S.doc.data[key];
    return S.rep.step === 5 ? fromDoc || fromDs : fromDs || fromDoc;
  }
  function getInfo(id) {
    const list = (S.doc && S.doc.info) || (S.dataset && S.dataset.info) || [];
    return list.find((i) => i.id === id);
  }

  function sourceHtml(c) {
    if (!c) return '';
    const pos = c.page ? `pag. ${c.page}${c.pos ? ', ' + esc(c.pos) : ''}` : esc(c.pos || 'posizione n.d.');
    const ext = (S.rep.files.find((f) => f.id === c.file_id) || {}).ext;
    return `<div class="ir-src">
      <div><strong>${esc(fileName(c.file_id))}</strong> — ${pos}</div>
      <div class="ir-quote">«${esc(c.quote)}»</div>
      <div class="ir-muted">Valore stampato: ${num(c.raw, 2)}${c.scale !== 1 ? ` × ${num(c.scale, 0)} (scala del documento)` : ''} · stato: ${c.stato === 'assente' ? 'voce assente nel prospetto (zero)' : 'rilevato'} · affidabilità: ${esc(c.aff)}${c.note ? ' · ' + esc(c.note) : ''}</div>
      <a class="button button-small" href="${fileUrl(c.file_id, ext === 'pdf' ? c.page : null)}" target="_blank" rel="noopener">Apri il documento${c.page && ext === 'pdf' ? ' a pagina ' + c.page : ''}</a>
    </div>`;
  }

  function traceDrawer() {
    if (!S.trace.length) return '';
    const ref = S.trace[S.trace.length - 1];
    let body = '';
    if (ref.startsWith('K:')) {
      const n = getNode(ref.slice(2));
      if (!n) body = '<p>Indicatore non trovato.</p>';
      else {
        body = `<div class="ir-tr-kind">Indicatore calcolato</div>
          <h2>${esc(n.label)}</h2><div class="ir-muted">Periodo: ${plabel(n.period)}</div>
          <div class="ir-tr-val ${n.eval ? 'l-' + n.eval.level : ''}">${nodeVal(n)}</div>
          ${n.eval ? `<div class="ir-tr-eval"><span class="ir-badge ir-${n.eval.level}">${esc(n.eval.text)}</span> ${esc(n.eval.soglia)}<div class="ir-muted">Fonte della soglia: ${esc(n.eval.fonte)}</div></div>` : ''}
          <h3>Formula</h3><div class="ir-formula">${esc(n.formula)}</div>
          <h3>Valori utilizzati</h3>
          <table class="ir-table"><tbody>${n.inputs.map((i) => `<tr><td><strong>${esc(i.sym)}</strong><div class="ir-muted">${esc(i.label)}</div></td><td class="ir-right">${i.param ? `${i.value != null && Math.abs(i.value) < 1 && i.value !== 0 ? num(i.value * 100, 4) + '%' : num(i.value, 2)}<div class="ir-muted">parametro: ${esc(i.param)}</div>` : i.ref ? `<a href="#" data-a="trace" data-ref="${esc(i.ref)}">${inputVal(i)}</a><div class="ir-muted">${i.ref.startsWith('V:') ? 'dato dal documento →' : 'indicatore →'}</div>` : num(i.value)}</td></tr>`).join('')}</tbody></table>
          ${n.value == null ? `<div class="ir-alert ir-alert-warn"><strong>Non valutabile.</strong> ${esc(n.nv_reason || '')}${n.missing.length ? '<ul>' + n.missing.map((x) => `<li>${esc(x)}</li>`).join('') + '</ul>' : ''}</div>` : ''}
          ${n.note ? `<h3>Nota</h3><p>${esc(n.note)}</p>` : ''}`;
      }
    } else if (ref.startsWith('V:')) {
      const e = getData(ref.slice(2));
      if (!e) body = '<p>Dato non trovato.</p>';
      else {
        const s = e.source;
        body = `<div class="ir-tr-kind">Dato estratto da documento</div>
          <h2>${esc(e.label)}</h2><div class="ir-muted">Periodo: ${plabel(e.period)}</div>
          <div class="ir-tr-val">${e.value == null ? 'non disponibile' : fmt(e.value, e.unit)}</div>
          ${e.status === 'conflict' ? '<div class="ir-alert ir-alert-error">Fonti discordanti: scegli il valore corretto.</div>' : ''}
          ${s && s.manual ? `<div class="ir-alert ir-alert-warn">Inserito manualmente da ${esc(s.by)} il ${dateIt(s.at)}. Motivazione: ${esc(s.note)}</div>` : ''}
          ${e.verified ? `<div class="ir-alert ir-alert-ok">Verificato da ${esc(e.verified.by)} il ${dateIt(e.verified.at)}.</div>` : ''}
          ${e.status === 'chosen' ? '<div class="ir-alert ir-alert-ok">Valore scelto dal consulente tra fonti discordanti.</div>' : ''}
          ${e.status === 'auto' && e.auto ? `<div class="ir-alert ir-alert-info">Fonti discordanti risolte con regola contabile. ${esc(e.auto.note)} Valori scartati: ${e.auto.discarded.map((x) => fmt(x.value, e.unit)).join(', ')}.</div>` : ''}
          <h3>${e.candidates.length > 1 ? `Fonti (${e.candidates.length})` : 'Fonte'}</h3>
          ${e.candidates.map((c) => `<div class="ir-cand ${s && s.id === c.id ? 'sel' : ''}">${sourceHtml(c)}${S.rep.step === 4 && e.candidates.length > 1 ? `<button class="button button-small" data-a="choose" data-key="${esc(e.key)}" data-c="${c.id}">Usa questo valore (${fmt(c.value, e.unit)})</button>` : ''}</div>`).join('') || '<p class="ir-muted">Nessuna fonte documentale (dato manuale).</p>'}
          ${S.rep.step === 4 ? entryActions(e) : ''}`;
      }
    } else if (ref.startsWith('I:')) {
      const i = getInfo(ref.slice(2));
      body = i ? `<div class="ir-tr-kind">Informazione dal documento</div><h2>${esc(C.info_fields[i.field] || i.field)}</h2><div class="ir-tr-val ir-tr-text">${esc(i.value)}</div>${sourceHtml({ ...i, raw: null, scale: 1, stato: 'rilevato', aff: '—' })}` : '<p>Informazione non trovata.</p>';
    } else if (ref.startsWith('CITE:')) {
      const [, sid, ci] = ref.split(':');
      const sec = S.doc.model.sections.find((x) => x.id === sid);
      const c = sec && sec.citations[+ci];
      if (c) {
        const src = c.src.kind === 'file' ? fileName(c.src.file_id) : c.src.kind === 'interview' ? 'Note dell\'intervista al management' : c.src.kind === 'norm' ? 'Riferimento normativo interno (maggio 2026)' : c.title;
        body = `<div class="ir-tr-kind">Citazione documentale</div><h2>${esc(src)}</h2>${c.page_start ? `<div class="ir-muted">Pagina ${c.page_start}${c.page_end && c.page_end !== c.page_start ? '-' + c.page_end : ''}</div>` : ''}<div class="ir-quote ir-quote-big">«${esc(c.cited_text)}»</div>${c.src.kind === 'file' ? `<a class="button" href="${fileUrl(c.src.file_id, c.page_start)}" target="_blank" rel="noopener">Apri il documento</a>` : ''}`;
      }
    } else if (ref === 'ADD') {
      body = addManualForm();
    } else if (ref.startsWith('EDIT:')) {
      body = editForm(ref.slice(5));
    }
    return `<div class="ir-drawer-bg" data-a="close-trace"></div><aside class="ir-drawer">
      <div class="ir-drawer-top">${S.trace.length > 1 ? '<button class="button button-small" data-a="trace-back">← Indietro</button>' : '<span></span>'}<button class="button button-small" data-a="close-trace">Chiudi ×</button></div>
      ${body}</aside>`;
  }

  function inputVal(i) {
    if (i.ref.startsWith('K:')) { const n = getNode(i.ref.slice(2)); return n ? nodeVal(n) : 'n.d.'; }
    const e = getData(i.ref.slice(2));
    return e && e.value != null ? fmt(e.value, e.unit) : i.value != null ? num(i.value) : 'non disponibile';
  }

  function entryActions(e) {
    return `<h3>Azioni</h3><div class="ir-btns">
      ${e.status === 'to_verify' || (e.source && !e.verified && !e.source.manual) ? `<button class="button" data-a="confirm" data-key="${esc(e.key)}">Ho verificato: il valore è corretto</button>` : ''}
      <button class="button" data-a="edit" data-key="${esc(e.key)}">Correggi manualmente</button>
      ${e.status !== 'excluded' ? `<button class="button" data-a="exclude" data-key="${esc(e.key)}">Escludi questo dato</button>` : ''}
      ${e.override ? `<button class="button" data-a="reset" data-key="${esc(e.key)}">Annulla l'intervento</button>` : ''}
    </div>`;
  }

  function editForm(key) {
    const e = getData(key);
    return `<div class="ir-tr-kind">Correzione manuale</div><h2>${esc(e ? e.label : key)}</h2><div class="ir-muted">Periodo: ${plabel(key.split('@')[1])}</div>
      <form data-a="save-manual" data-key="${esc(key)}" class="ir-form">
        <label>Valore corretto (in euro, senza scala; usa il punto o la virgola per i decimali)<input name="value" required value="${e && e.value != null ? e.value : ''}"></label>
        <label>Nota obbligatoria: fonte del valore o motivo della correzione<textarea name="note" rows="3" required placeholder="es. Nota Integrativa pag. 12, tabella debiti per scadenza: la quota oltre 12 mesi è 85.000"></textarea></label>
        <button class="button button-primary" type="submit">Salva correzione</button>
      </form>`;
  }

  function addManualForm() {
    const opts = Object.entries(C.dictionary).map(([k, d]) => `<option value="${k}">${esc(d.group)} — ${esc(d.label)}</option>`).join('');
    return `<div class="ir-tr-kind">Inserimento manuale</div><h2>Aggiungi un dato mancante</h2>
      <p class="ir-muted">Usa questa funzione solo per dati presenti in un documento che l'estrazione non ha rilevato, o per dati comunicati formalmente dall'azienda. Il dato sarà marcato come "manuale" in tutte le tracce del report.</p>
      <form data-a="save-manual-new" class="ir-form">
        <label>Voce<select name="code">${opts}</select></label>
        <label>Periodo (AAAA per esercizi, AAAA-MM-GG per date, AAAA-MM per mesi della Centrale Rischi)<input name="period" required pattern="\\d{4}(-\\d{2}(-\\d{2})?)?"></label>
        <label>Valore (in euro, senza scala)<input name="value" required></label>
        <label>Nota obbligatoria: documento, pagina o motivazione<textarea name="note" rows="3" required></textarea></label>
        <button class="button button-primary" type="submit">Aggiungi</button>
      </form>`;
  }

  // ------------------------------------------------------------------ render ed eventi

  function render() {
    const msg = S.msg ? `<div class="ir-alert ir-alert-${S.msg.type === 'error' ? 'error' : S.msg.type === 'ok' ? 'ok' : 'info'} ir-flash">${esc(S.msg.text)} <button class="ir-x" data-a="close-msg">×</button></div>` : '';
    const scrollY = window.scrollY;
    const drawer = document.querySelector('.ir-drawer');
    const dScroll = drawer ? drawer.scrollTop : 0;
    root.innerHTML = msg + (S.view === 'list' ? viewList() : viewReport());
    window.scrollTo(0, scrollY);
    const nd = document.querySelector('.ir-drawer');
    if (nd) nd.scrollTop = dScroll;
    document.body.classList.toggle('ir-busy', S.busy);
  }

  const parseNum = (s) => {
    s = String(s).trim().replace(/\s/g, '').replace(/€/g, '');
    if (/^-?\d{1,3}(\.\d{3})+(,\d+)?$/.test(s)) s = s.replace(/\./g, '').replace(',', '.');
    else s = s.replace(',', '.');
    const v = parseFloat(s);
    if (!isFinite(v)) throw new Error('Valore numerico non valido.');
    return v;
  };

  async function override(key, type, extra = {}) {
    S.rep = await api(`/reports/${S.rep.id}/override`, { method: 'POST', json: { key, type, ...extra } });
    S.kpi = null;
    await loadDataset();
  }

  root.addEventListener('click', (ev) => {
    const el = ev.target.closest('[data-a]');
    if (!el || el.tagName === 'FORM') return;
    const a = el.dataset.a;
    if (el.tagName === 'A' && el.getAttribute('href') === '#') ev.preventDefault();
    if (el.tagName === 'A' && el.getAttribute('href') !== '#' && !['trace', 'cite', 'entry', 'open'].includes(a)) return;
    switch (a) {
      case 'close-msg': S.msg = null; render(); break;
      case 'open': ev.preventDefault(); guard(() => openReport(+el.dataset.id)); break;
      case 'del-report':
        if (confirm('Eliminare definitivamente il report e i documenti caricati?')) guard(async () => { await api('/reports/' + el.dataset.id, { method: 'DELETE' }); await loadList(); });
        break;
      case 'back': stopPolling(); S.view = 'list'; S.rep = null; guard(loadList); break;
      case 'goto': guard(async () => {
        const step = +el.dataset.step;
        if (step === 4 && !S.dataset) await loadDataset();
        if (step === 5) await loadDoc();
        await save({ step });
        window.scrollTo(0, 0);
      }); break;
      case 'save-interview': {
        const text = root.querySelector('[data-f="int-text"]').value;
        const date = root.querySelector('[data-f="int-date"]').value;
        guard(async () => { await save({ interview: { text, date } }); flash('Note dell\'intervista salvate.', 'ok'); });
        break;
      }
      case 'del-file':
        if (confirm('Rimuovere il file e i dati estratti da esso?')) guard(async () => { S.rep = await api(`/reports/${S.rep.id}/files/${el.dataset.id}`, { method: 'DELETE' }); if (S.dataset) await loadDataset(); });
        break;
      case 'save-sections': {
        const boxes = [...root.querySelectorAll('[data-sec]')];
        const unchecked = boxes.filter((b) => b.dataset.mand && !b.checked && !b.disabled);
        if (unchecked.length && !confirm(`Stai escludendo ${unchecked.length} capitoli obbligatori per la finalità scelta. Confermi?`)) return;
        const sel = boxes.filter((b) => b.checked).map((b) => b.dataset.sec);
        if (!sel.length) { flash('Seleziona almeno un capitolo.', 'error'); return; }
        guard(async () => { await save({ sections_selected: sel, step: 4 }); await loadDataset(); window.scrollTo(0, 0); });
        break;
      }
      case 'extract': guard(async () => { const r = await api(`/reports/${S.rep.id}/extract`, { method: 'POST', json: {} }); S.rep.job = r.job; startPolling(); }); break;
      case 'reextract': guard(async () => { const r = await api(`/reports/${S.rep.id}/extract`, { method: 'POST', json: { files: [el.dataset.id] } }); S.rep.job = r.job; startPolling(); }); break;
      case 'cancel-job': guard(async () => { S.rep = await api(`/reports/${S.rep.id}/cancel`, { method: 'POST' }); stopPolling(); }); break;
      case 'filter': S.filter = el.dataset.f; render(); break;
      case 'entry': S.trace = ['V:' + el.dataset.key]; render(); break;
      case 'trace': ev.preventDefault(); S.trace.push(el.dataset.ref); render(); break;
      case 'cite': ev.preventDefault(); S.trace = ['CITE:' + el.dataset.sid + ':' + el.dataset.c]; render(); break;
      case 'trace-back': S.trace.pop(); render(); break;
      case 'close-trace': S.trace = []; render(); break;
      case 'add-manual': S.trace = ['ADD']; render(); break;
      case 'edit': S.trace.push('EDIT:' + el.dataset.key); render(); break;
      case 'choose': guard(async () => { await override(el.dataset.key, 'choice', { candidate: el.dataset.c }); }); break;
      case 'confirm': guard(async () => { await override(el.dataset.key, 'confirm'); }); break;
      case 'exclude': {
        const note = prompt('Motivo dell\'esclusione (obbligatorio):');
        if (note && note.trim().length >= 5) guard(async () => { await override(el.dataset.key, 'exclude', { note }); });
        break;
      }
      case 'reset': guard(async () => { await override(el.dataset.key, 'reset'); }); break;
      case 'compute': guard(async () => {
        const r = await api(`/reports/${S.rep.id}/compute`, { method: 'POST' });
        S.rep = r;
        S.kpi = { ...r.kpi, nodes: r.kpi_full.nodes, checks: r.kpi_full.checks, periods: r.kpi_full.periods };
        flash('KPI calcolati. Verifica il cruscotto e i controlli di quadratura.', 'ok');
      }); break;
      case 'approve': {
        if (S.kpi.approved_at) { guard(async () => { await save({ step: 5 }); await loadDoc(); window.scrollTo(0, 0); }); break; }
        const failed = (S.kpi.checks || []).filter((c) => c.ok === false).length;
        const tv = S.rep.derived.dataset.da_verificare;
        let msg = 'Confermi che dati e KPI sono corretti e possono essere usati per la stesura del report?';
        if (failed) msg = `ATTENZIONE: ${failed} controlli di quadratura NON sono superati.\n\n` + msg;
        if (tv) msg = `Ci sono ${tv} dati con affidabilità bassa non ancora verificati.\n\n` + msg;
        if (confirm(msg)) guard(async () => { S.rep = await api(`/reports/${S.rep.id}/approve`, { method: 'POST' }); S.kpi = S.rep.kpi; await loadDoc(); window.scrollTo(0, 0); });
        break;
      }
      case 'write':
        if (S.rep.report && S.rep.report.generated_at && !confirm('Rigenerare l\'intero report? Il testo attuale sarà sostituito.')) return;
        guard(async () => { const r = await api(`/reports/${S.rep.id}/write`, { method: 'POST', json: {} }); S.rep.job = r.job; S.rep.report = { sections: {} }; startPolling(); });
        break;
      case 'rewrite': guard(async () => { const r = await api(`/reports/${S.rep.id}/write`, { method: 'POST', json: { sections: [el.dataset.sid] } }); S.rep.job = r.job; startPolling(); }); break;
      case 'print': printReport(); break;
    }
  });

  root.addEventListener('submit', (ev) => {
    const f = ev.target;
    const a = f.dataset.a;
    ev.preventDefault();
    const fd = Object.fromEntries(new FormData(f).entries());
    if (a === 'create') {
      guard(async () => { const r = await api('/reports', { method: 'POST', json: { title: fd.title } }); await openReport(r.id); });
    } else if (a === 'save-profile') {
      const keys = ['ragione_sociale', 'forma_giuridica', 'partita_iva', 'ateco', 'settore', 'dimensione', 'sede_legale', 'sede_operativa', 'provincia', 'esercizi', 'finalita', 'finalita_altro', 'note'];
      const profile = {};
      keys.forEach((k) => { if (fd[k] !== undefined) profile[k] = fd[k]; });
      guard(async () => { await save({ profile, step: 2 }); window.scrollTo(0, 0); });
    } else if (a === 'save-manual') {
      guard(async () => { await override(f.dataset.key, 'manual', { value: parseNum(fd.value), note: fd.note }); S.trace = ['V:' + f.dataset.key]; });
    } else if (a === 'save-manual-new') {
      guard(async () => { const key = fd.code + '@' + fd.period; await override(key, 'manual', { value: parseNum(fd.value), note: fd.note }); S.trace = ['V:' + key]; });
    }
  });

  root.addEventListener('change', (ev) => {
    const el = ev.target;
    if (el.dataset.upload) { uploadFiles(el.dataset.upload, [...el.files]); return; }
    if (el.name === 'finalita') { root.querySelectorAll('.ir-choice').forEach((c) => c.classList.toggle('on', c.querySelector('input').checked)); return; }
    if (el.dataset.p) {
      const p = {};
      p[el.dataset.p] = el.type === 'checkbox' ? el.checked : el.value;
      guard(async () => { await save({ params: p }); });
    }
  });

  // trascina e rilascia sui documenti
  root.addEventListener('dragover', (ev) => { const d = ev.target.closest('[data-drop]'); if (d) { ev.preventDefault(); d.classList.add('drag'); } });
  root.addEventListener('dragleave', (ev) => { const d = ev.target.closest('[data-drop]'); if (d) d.classList.remove('drag'); });
  root.addEventListener('drop', (ev) => { const d = ev.target.closest('[data-drop]'); if (d) { ev.preventDefault(); d.classList.remove('drag'); uploadFiles(d.dataset.drop, [...ev.dataTransfer.files]); } });
  document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape' && S.trace.length) { S.trace = []; render(); } });

  function printReport() {
    const paper = document.querySelector('.ir-paper');
    if (!paper) return;
    const w = window.open('', '_blank');
    const css = [...document.querySelectorAll('link[rel="stylesheet"]')].filter((l) => l.href.includes('integra-report')).map((l) => `<link rel="stylesheet" href="${l.href}">`).join('');
    const clone = paper.cloneNode(true);
    clone.querySelectorAll('.ir-internal, .ir-sec-tools, .ir-alert').forEach((el) => el.remove());
    clone.querySelectorAll('mark.ir-flag, mark.ir-badref').forEach((el) => el.replaceWith(document.createTextNode(el.textContent)));
    clone.querySelectorAll('a.ir-ref').forEach((el) => el.replaceWith(document.createTextNode(el.textContent)));
    w.document.write(`<!doctype html><html lang="it"><head><meta charset="utf-8"><title>${esc(S.rep.profile.ragione_sociale)} — Report</title>${css}</head><body class="ir-print"><article class="ir-paper">${clone.innerHTML}</article></body></html>`);
    w.document.close();
    setTimeout(() => w.print(), 600);
  }

  // avvio
  guard(loadList);
})();
