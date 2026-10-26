/** Progressive enhancement: the original select remains the form's source of truth. */
const controls = new WeakMap();
const installations = new WeakMap();
let serial = 0;

function enhance(select, installation) {
  if (controls.has(select) || select.dataset.nativeSelect !== undefined) return;
  const doc = select.ownerDocument;
  const view = doc.defaultView;
  const id = `umax-select-${++serial}`;
  const wrapper = doc.createElement('div');
  wrapper.className = 'umax-select';
  const trigger = doc.createElement('button');
  trigger.type = 'button';
  trigger.className = 'umax-select-trigger';
  trigger.id = `${id}-trigger`;
  trigger.setAttribute('role', 'combobox');
  trigger.setAttribute('aria-haspopup', 'listbox');
  trigger.setAttribute('aria-expanded', 'false');
  trigger.setAttribute('aria-controls', `${id}-list`);
  const caption = doc.createElement('span');
  caption.className = 'umax-select-caption';
  const arrow = doc.createElement('span');
  arrow.className = 'umax-select-arrow';
  arrow.setAttribute('aria-hidden', 'true');
  trigger.append(caption, arrow);
  const labelText = [...(select.labels || [])].map(label => {
    const copy = label.cloneNode(true);
    copy.querySelectorAll('select,button,input,textarea,.umax-select').forEach(node => node.remove());
    return copy.textContent.trim();
  }).filter(Boolean).join(' ');
  const accessibleName = select.getAttribute('aria-label') || labelText || select.title || '请选择';
  const labelledBy = select.getAttribute('aria-labelledby');
  if (labelledBy) trigger.setAttribute('aria-labelledby', labelledBy);
  else {
    const label = doc.createElement('span');
    label.id = `${id}-label`;
    label.className = 'umax-select-sr';
    label.textContent = accessibleName;
    wrapper.append(label);
    trigger.setAttribute('aria-labelledby', label.id);
  }
  const describedBy = select.getAttribute('aria-describedby');
  if (describedBy) trigger.setAttribute('aria-describedby', describedBy);
  const panel = doc.createElement('div');
  panel.className = 'umax-select-panel';
  panel.hidden = true;
  if (typeof panel.showPopover === 'function') panel.setAttribute('popover', 'manual');
  const search = doc.createElement('input');
  search.type = 'search';
  search.className = 'umax-select-search';
  search.placeholder = '搜索选项…';
  search.setAttribute('aria-label', `搜索${accessibleName}`);
  search.setAttribute('aria-controls', `${id}-list`);
  search.autocomplete = 'off';
  const list = doc.createElement('div');
  list.id = `${id}-list`;
  list.className = 'umax-select-list';
  list.setAttribute('role', 'listbox');
  list.setAttribute('aria-label', accessibleName);
  const empty = doc.createElement('div');
  empty.className = 'umax-select-empty';
  empty.textContent = '没有匹配的选项';
  empty.setAttribute('role', 'status');
  panel.append(search, list, empty);
  select.before(wrapper);
  wrapper.append(select, trigger, panel);
  select.classList.add('umax-select-native');
  select.tabIndex = -1;
  select.setAttribute('aria-hidden', 'true');
  let opened = false;
  let active = -1;
  let rows = [];
  let typeahead = '';
  let typedAt = 0;
  let fallbackPortal = false;

  const unavailable = option => option.disabled || (option.parentElement?.tagName === 'OPTGROUP' && option.parentElement.disabled);
  const available = () => rows.filter(row => !row.element.hidden && !unavailable(row.option));
  function markActive(index, scroll = true) {
    active = index;
    for (const row of rows) row.element.classList.toggle('is-active', row.index === index);
    const row = rows.find(item => item.index === index && !item.element.hidden);
    for (const node of [trigger, search]) {
      if (opened && row) node.setAttribute('aria-activedescendant', row.element.id);
      else node.removeAttribute('aria-activedescendant');
    }
    if (opened && row && scroll) row.element.scrollIntoView({block: 'nearest'});
  }
  function filter() {
    const query = search.value.trim().toLocaleLowerCase();
    for (const row of rows) row.element.hidden = row.option.hidden || (row.option.parentElement?.hidden ?? false) || !row.option.textContent.toLocaleLowerCase().includes(query);
    empty.hidden = rows.some(row => !row.element.hidden);
    const candidates = available();
    if (!candidates.some(row => row.index === active)) markActive(candidates[0]?.index ?? -1, false);
  }
  function render() {
    rows = [];
    list.replaceChildren();
    list.setAttribute('aria-multiselectable', String(select.multiple));
    let group;
    [...select.options].forEach((option, index) => {
      const parent = option.parentElement;
      if (parent?.tagName === 'OPTGROUP' && group !== parent) {
        group = parent;
        const heading = doc.createElement('div');
        heading.className = 'umax-select-group';
        heading.textContent = parent.label;
        heading.setAttribute('role', 'presentation');
        list.append(heading);
      }
      const element = doc.createElement('div');
      element.id = `${id}-option-${index}`;
      element.className = 'umax-select-option';
      element.setAttribute('role', 'option');
      element.setAttribute('aria-selected', String(option.selected));
      element.setAttribute('aria-disabled', String(unavailable(option)));
      const text = doc.createElement('span');
      text.textContent = option.label;
      const check = doc.createElement('span');
      check.className = 'umax-select-check';
      check.setAttribute('aria-hidden', 'true');
      check.textContent = '✓';
      element.append(text, check);
      element.addEventListener('pointerdown', event => event.preventDefault());
      element.addEventListener('pointermove', () => { if (!unavailable(option)) markActive(index, false); });
      element.addEventListener('click', () => choose(index));
      list.append(element);
      rows.push({element, option, index});
    });
    search.hidden = select.options.length <= 8;
    filter();
  }
  function refresh(rebuild = false) {
    const selected = [...select.selectedOptions];
    caption.textContent = selected.map(option => option.label).join('、') || '请选择';
    trigger.title = caption.textContent;
    trigger.disabled = select.matches(':disabled');
    trigger.setAttribute('aria-required', String(select.required));
    wrapper.classList.toggle('is-disabled', trigger.disabled);
    wrapper.hidden = select.hidden;
    if (select.validity.valid) trigger.removeAttribute('aria-invalid');
    if (rebuild) render();
    else for (const row of rows) row.element.setAttribute('aria-selected', String(row.option.selected));
    if ((trigger.disabled || select.hidden) && opened) close();
  }
  function position() {
    if (!opened) return;
    if (!select.isConnected || !trigger.getClientRects().length || !select.closest('dialog')?.open && select.closest('dialog')) { close(); return; }
    const rect = trigger.getBoundingClientRect();
    const width = Math.min(Math.max(rect.width, 220), view.innerWidth - 24);
    const below = view.innerHeight - rect.bottom - 12;
    const above = rect.top - 12;
    const upwards = below < 200 && above > below;
    const height = Math.max(80, Math.min(340, upwards ? above : below));
    panel.style.width = `${width}px`;
    panel.style.maxHeight = `${height}px`;
    panel.style.left = `${Math.max(12, Math.min(rect.left, view.innerWidth - width - 12))}px`;
    panel.style.top = `${upwards ? Math.max(8, rect.top - panel.getBoundingClientRect().height - 6) : rect.bottom + 6}px`;
  }
  function open() {
    refresh(true);
    if (trigger.disabled || opened) return;
    installation.current?.close();
    installation.current = control;
    opened = true;
    trigger.setAttribute('aria-expanded', 'true');
    wrapper.classList.add('is-open');
    search.value = '';
    filter();
    panel.hidden = false;
    // Popovers participate in the top layer even when nested in modal dialogs.
    // The portal fallback avoids clipping from scrollable dialog bodies.
    if (panel.hasAttribute('popover')) panel.showPopover();
    else {
      (select.closest('dialog') || doc.body).append(panel);
      fallbackPortal = true;
    }
    position();
    markActive(available().find(row => row.option.selected)?.index ?? available()[0]?.index ?? -1);
    if (!search.hidden) search.focus({preventScroll: true});
  }
  function close(restoreFocus = false) {
    if (!opened) return;
    opened = false;
    if (panel.hasAttribute('popover') && panel.matches(':popover-open')) panel.hidePopover();
    panel.hidden = true;
    if (fallbackPortal) { wrapper.append(panel); fallbackPortal = false; }
    wrapper.classList.remove('is-open');
    trigger.setAttribute('aria-expanded', 'false');
    markActive(-1, false);
    if (installation.current === control) installation.current = null;
    if (restoreFocus) trigger.focus({preventScroll: true});
  }
  function choose(index) {
    const option = select.options[index];
    if (!option || unavailable(option) || trigger.disabled) return;
    const before = [...select.selectedOptions].map(item => item.index).join(',');
    if (select.multiple) option.selected = !option.selected;
    else select.selectedIndex = index;
    refresh();
    if (!select.multiple) close(true);
    if (before !== [...select.selectedOptions].map(item => item.index).join(',')) {
      select.dispatchEvent(new view.Event('input', {bubbles: true}));
      select.dispatchEvent(new view.Event('change', {bubbles: true}));
    }
  }
  function keydown(event) {
    if (event.isComposing) return;
    const key = event.key;
    const isSearch = event.target === search;
    if (key === 'Escape' && opened) { event.preventDefault(); event.stopPropagation(); close(true); return; }
    if (key === 'Tab') { close(isSearch); return; }
    if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(key) && (!isSearch || key.startsWith('Arrow'))) {
      event.preventDefault();
      if (!opened) open();
      const candidates = available();
      const current = candidates.findIndex(row => row.index === active);
      const next = key === 'Home' ? 0 : key === 'End' ? candidates.length - 1 : Math.max(0, Math.min(candidates.length - 1, current + (key === 'ArrowDown' ? 1 : -1)));
      markActive(candidates[next]?.index ?? -1);
      return;
    }
    if (key === 'Enter' || key === ' ' && !isSearch) {
      event.preventDefault();
      if (opened) choose(active); else open();
      return;
    }
    if (!isSearch && key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
      event.preventDefault();
      const now = Date.now();
      typeahead = now - typedAt < 650 ? typeahead + key : key;
      typedAt = now;
      if (!opened) open();
      const query = typeahead.toLocaleLowerCase();
      const repeated = [...query].every(char => char === query[0]);
      const candidates = available();
      const after = candidates.findIndex(row => row.index === active);
      const ordered = repeated ? [...candidates.slice(after + 1), ...candidates.slice(0, after + 1)] : candidates;
      const match = ordered.find(row => row.option.label.toLocaleLowerCase().startsWith(repeated ? query[0] : query));
      if (match) markActive(match.index);
    }
  }
  const control = {close, refresh, position, wrapper, panel, trigger};
  controls.set(select, control);
  trigger.addEventListener('click', () => opened ? close() : open());
  trigger.addEventListener('keydown', keydown);
  search.addEventListener('keydown', keydown);
  search.addEventListener('input', filter);
  select.addEventListener('input', () => refresh());
  select.addEventListener('change', () => refresh());
  select.addEventListener('focus', () => trigger.focus());
  select.addEventListener('invalid', event => {
    event.preventDefault();
    trigger.setAttribute('aria-invalid', 'true');
    if (!installation.invalidTarget) {
      installation.invalidTarget = select;
      trigger.focus();
      setTimeout(() => { installation.invalidTarget = null; }, 0);
    }
  });
  // Existing code sets .value after injecting forms. Keep the visible caption in
  // sync without replacing native value semantics or dispatching extra changes.
  for (const property of ['value', 'selectedIndex']) {
    const descriptor = Object.getOwnPropertyDescriptor(view.HTMLSelectElement.prototype, property);
    if (descriptor?.get && descriptor?.set && !Object.hasOwn(select, property)) {
      Object.defineProperty(select, property, {
        configurable: true,
        get() { return descriptor.get.call(this); },
        set(value) { descriptor.set.call(this, value); refresh(); }
      });
    }
  }
  refresh(true);
}

/** Enhance all current and future select controls. Safe to call after each render. */
export function installCustomSelects(root = document) {
  const doc = root.nodeType === 9 ? root : root.ownerDocument;
  let installation = installations.get(doc);
  if (!installation) {
    installation = {current: null};
    installations.set(doc, installation);
    doc.addEventListener('pointerdown', event => {
      const current = installation.current;
      if (current && !current.wrapper.contains(event.target) && !current.panel.contains(event.target)) current.close();
    });
    doc.addEventListener('focusin', event => {
      const current = installation.current;
      if (current && !current.wrapper.contains(event.target) && !current.panel.contains(event.target)) current.close();
    });
    doc.addEventListener('cancel', event => {
      if (installation.current) { event.preventDefault(); installation.current.close(true); }
    }, true);
    doc.addEventListener('reset', event => {
      setTimeout(() => event.target.querySelectorAll('select').forEach(select => controls.get(select)?.refresh()), 0);
    });
    doc.addEventListener('scroll', event => {
      const current = installation.current;
      if (current && !current.panel.contains(event.target)) current.position();
    }, true);
    doc.defaultView.addEventListener('resize', () => installation.current?.position());
    installation.observer = new MutationObserver(records => {
      const dirty = new Set();
      for (const record of records) {
        if (record.type === 'childList') for (const added of record.addedNodes) {
          if (added.nodeType !== 1 || added.closest('.umax-select-panel')) continue;
          if (added.matches('select')) enhance(added, installation);
          added.querySelectorAll('select').forEach(select => enhance(select, installation));
        }
        const target = record.target.nodeType === 1 ? record.target : record.target.parentElement;
        const select = target?.closest('select');
        if (select) dirty.add(select);
        if (target?.matches('fieldset')) target.querySelectorAll('select').forEach(select => dirty.add(select));
      }
      dirty.forEach(select => controls.get(select)?.refresh(true));
      installation.current?.position();
    });
    installation.observer.observe(doc.documentElement, {
      subtree: true, childList: true, characterData: true, attributes: true,
      attributeFilter: ['disabled', 'required', 'selected', 'label', 'hidden', 'multiple', 'aria-describedby']
    });
  }
  if (root.matches?.('select')) enhance(root, installation);
  root.querySelectorAll('select').forEach(select => {
    enhance(select, installation);
    controls.get(select)?.refresh();
  });
}
