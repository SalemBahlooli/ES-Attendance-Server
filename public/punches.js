/* Punch editor: add, correct or delete the punches of one employee on one date */

const PD_SOURCE = { adms: 'Device', file: 'USB / file', lan: 'LAN', manual: 'Manual' };
const pd = { pin: '', date: '', name: '', onChange: null };

function openPunchEditor(pin, date, name, onChange) {
  Object.assign(pd, { pin, date, name: name || '', onChange });
  $('pdTitle').textContent = `Punches · ${name || 'ID ' + pin}`;
  $('pdSub').textContent = ovParse(date).toLocaleDateString('en-GB',
    { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
  $('pdNew').value = '';
  $('pdNote').value = '';
  $('pdList').innerHTML = '<div class="muted small">Loading…</div>';
  $('punchDialog').showModal();
  pdLoad();
}

async function pdLoad() {
  let rows;
  try { rows = await api(`/api/employees/${encodeURIComponent(pd.pin)}/punches?date=${pd.date}`); }
  catch (_) { return; }
  $('pdList').innerHTML = rows.length ? rows.map((r) => `
    <div class="pd-row" data-id="${r.id}">
      <input type="time" step="1" value="${r.time.slice(11, 19)}" data-orig="${r.time.slice(11, 19)}" aria-label="Punch time">
      <span class="pill ${r.source === 'manual' ? 'info' : 'off'}" title="${esc(r.source === 'manual'
        ? `Added by ${r.created_by || 'a user'}${r.note ? ': ' + r.note : ''}` : (r.device_name || r.sn || ''))}">${PD_SOURCE[r.source] || esc(r.source || 'Device')}</span>
      <span class="spacer"></span>
      <button type="button" class="btn small primary" data-save hidden>Save</button>
      <button type="button" class="btn small danger" data-del>Delete</button>
    </div>`).join('')
    : '<div class="muted small pd-empty">No punches on this day. Add one below.</div>';

  $('pdList').querySelectorAll('.pd-row').forEach((row) => {
    const input = row.querySelector('input');
    const save = row.querySelector('[data-save]');
    const norm = (v) => (v.length === 5 ? v + ':00' : v);
    input.oninput = () => { save.hidden = !input.value || norm(input.value) === input.dataset.orig; };
    save.onclick = async () => {
      const t = input.value.length === 5 ? input.value + ':00' : input.value;
      try { await send('/api/punches/' + row.dataset.id, 'PUT', { time: `${pd.date} ${t}`, note: $('pdNote').value }); }
      catch (_) { return; }
      toast('Punch updated');
      pdChanged();
    };
    row.querySelector('[data-del]').onclick = async () => {
      if (!confirm(`Delete the punch at ${input.dataset.orig.slice(0, 5)}?`)) return;
      try { await send('/api/punches/' + row.dataset.id, 'DELETE', { note: $('pdNote').value }); }
      catch (_) { return; }
      toast('Punch deleted');
      pdChanged();
    };
  });
}

$('pdAdd').onclick = async () => {
  const t = $('pdNew').value;
  if (!t) { $('pdNew').focus(); return toast('Enter the punch time'); }
  try { await send('/api/punches', 'POST', { pin: pd.pin, time: `${pd.date} ${t.slice(0, 5)}`, note: $('pdNote').value }); }
  catch (_) { return; }
  $('pdNew').value = '';
  toast('Punch added');
  pdChanged();
};
$('pdNew').onkeydown = (e) => { if (e.key === 'Enter') { e.preventDefault(); $('pdAdd').click(); } };
$('pdDone').onclick = () => $('punchDialog').close();

// Everything that shows attendance must recalculate after a correction
function pdChanged() {
  report = null;
  ov.data = null;
  calCache.clear();
  ovBounds = null;
  pdLoad();
  if (pd.onChange) pd.onChange();
}
