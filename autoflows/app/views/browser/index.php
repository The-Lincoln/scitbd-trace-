<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h3 class="mb-1">🌐 Browser Automation <small class="text-muted fs-6">agent-browser</small></h3>
    <p class="text-muted mb-0">Rendered-DOM research, monitors and form actions. Sessions are isolated per module (<code><?= htmlspecialchars($status['session'] ?? '') ?></code>).</p>
  </div>
  <div>
    <span class="badge <?= !empty($status['ok']) ? 'bg-success' : 'bg-warning text-dark' ?>"><?= !empty($status['ok']) ? '● ready' : '● not installed' ?></span>
    <small class="text-muted ms-2"><?= htmlspecialchars($status['version'] ?? '') ?></small>
  </div>
</div>

<?php if (empty($status['ok'])): ?>
<div class="alert alert-warning">agent-browser CLI not found. <code>npm install -g agent-browser &amp;&amp; agent-browser install</code> — see <code>docs/AGENT_BROWSER_INTEGRATION.md</code>.</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card"><div class="card-body">
      <h5>Run a browser plan</h5>
      <div class="mb-2"><label class="form-label">Plan</label>
        <select id="brPlan" class="form-select">
          <?php foreach (($plans ?? ['research','monitor','act','extract']) as $p): ?><option value="<?= $p ?>"><?= $p ?></option><?php endforeach; ?>
        </select></div>
      <div class="mb-2"><label class="form-label">URL</label>
        <input id="brUrl" class="form-control" placeholder="https://example.com"></div>
      <div class="mb-2"><label class="form-label">Goal (for AI report)</label>
        <input id="brGoal" class="form-control" placeholder="Summarise for CRM + score the lead"></div>
      <div class="d-flex gap-2">
        <button id="brRun" class="btn btn-primary">▶ Run with trace</button>
        <button id="brShot" class="btn btn-outline-secondary">📸 Screenshot</button>
        <button id="brClose" class="btn btn-outline-danger">✕ Close</button>
      </div>
      <hr>
      <h6>Single action</h6>
      <div class="input-group mb-2">
        <select id="brDo" class="form-select" style="max-width:130px">
          <option value="click">click</option><option value="fill">fill</option>
          <option value="type">type</option><option value="press">press</option>
          <option value="wait">wait</option><option value="eval">eval</option>
          <option value="get">get</option>
        </select>
        <input id="brSel" class="form-control" placeholder="@e2 or #submit">
        <input id="brText" class="form-control" placeholder="text / js / ms">
        <button id="brAct" class="btn btn-outline-primary">Send</button>
      </div>
      <small class="text-muted">Tip: <code>snapshot</code> first, then use <code>@eN</code> refs. Refs expire after navigation — snapshot again.</small>
    </div></div>
  </div>
  <div class="col-lg-7">
    <div class="card"><div class="card-body">
      <h5>Live trace</h5>
      <div id="brLog" class="trace-json" style="min-height:220px">Idle. Run a plan to stream steps here.</div>
      <h6 class="mt-3">Report</h6>
      <div id="brReport" class="p-2 border rounded bg-light" style="min-height:120px;white-space:pre-wrap"></div>
    </div></div>
  </div>
</div>

<script>
(function(){
  const log = (t)=>{ const el=document.getElementById('brLog'); el.textContent += "\n"+t; el.scrollTop = el.scrollHeight; };
  async function api(route, body){
    const r = await AF.api(route, body||{});
    return r;
  }
  document.getElementById('brShot').onclick = async ()=>{
    const r = await api('api/browser/shot', {});
    log('shot ok='+r.ok+' '+(r.result&&r.result.path||''));
    if(r.web){ log('view: '+r.web); }
  };
  document.getElementById('brClose').onclick = async ()=>{
    const r = await api('api/browser/close', {});
    log('close ok='+r.ok);
  };
  document.getElementById('brAct').onclick = async ()=>{
    const action = { do: document.getElementById('brDo').value, sel: document.getElementById('brSel').value, text: document.getElementById('brText').value, key: document.getElementById('brText').value, js: document.getElementById('brText').value, target: document.getElementById('brText').value, what:'text' };
    const r = await api('api/browser/act', {action});
    log('act ok='+r.ok+' :: '+(r.result&&r.result.text||'').slice(0,300));
  };
  document.getElementById('brRun').onclick = async ()=>{
    document.getElementById('brLog').textContent = 'starting…';
    document.getElementById('brReport').textContent = '';
    const body = { plan: document.getElementById('brPlan').value, url: document.getElementById('brUrl').value, goal: document.getElementById('brGoal').value };
    try{
      await AF.stream('api/browser/run', body, (frame)=>{
        if(frame.type==='browser_step'||frame.type==='step') log('• '+(frame.label||frame.id)+' ok='+(frame.ok??''));
        else if(frame.type==='done'){ document.getElementById('brReport').textContent = frame.report||JSON.stringify(frame); log('done ms='+(frame.ms||'')); }
        else log(JSON.stringify(frame).slice(0,300));
      });
    }catch(e){ log('ERR '+e.message); }
  };
})();
</script>
