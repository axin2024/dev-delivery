'use strict';

const trainStates = {draft:'草案', frozen:'已封板', scheduled:'已预约', running:'执行中', paused:'已暂停', completed:'完成', recovered:'已恢复'};

function trainEditor(title, fields, save) {
  const panel = document.createElement('details');
  const form = document.createElement('form');
  panel.append(textNode('summary', title), form);
  for (const [name, caption, type, choices] of fields) {
    const label = textNode('label', caption);
    const control = document.createElement(choices ? 'select' : type === 'textarea' ? 'textarea' : 'input');
    if (choices) {
      for (const [value, text] of choices) control.add(new Option(text, String(value)));
      control.multiple = type === 'multiple';
    } else if (control.tagName === 'INPUT') {
      control.type = type;
      if (type === 'number') control.min = ['errors','threshold'].includes(name) ? '0' : '1';
      if (name==='measured') control.step='1';
    }
    control.name = name;
    control.required = type !== 'multiple';
    label.append(control);
    form.append(label);
  }
  const submit = textNode('button', title);
  form.append(submit);
  form.addEventListener('submit', e => {
    e.preventDefault();
    run(async () => {
      submit.disabled = true;
      try { await save(new FormData(form)); } finally { submit.disabled = false; }
    });
  });
  return panel;
}

async function trainPages(path) {
  const items = [];
  let after = 0;
  do {
    const page = await call(projectPath(path) + '?after=' + after);
    items.push(...page.items);
    after = page.next_after;
  } while (after);
  return items;
}

async function trainBoard(selected) {
  const base = projectPath('/release-services');
  const [graph, envs, trains, repos, baselines, artifacts] = await Promise.all([
    call(base), trainPages('/environments'), trainPages('/release-trains'), trainPages('/repositories'), trainPages('/baselines'), trainPages('/artifacts')
  ]);
  const byId = new Map(graph.services.map(s => [s.service_id,s]));
  const services = $('train-services');
  services.replaceChildren();
  for (const id of graph.order) {
    const s = byId.get(id);
    const card = document.createElement('article');
    const parents = s.upstream_ids.map(k => byId.get(k).name).join('、');
    card.append(textNode('h4', s.name),textNode('p','负责人 #'+s.owner_id+' · '+(parents?'依赖 '+parents:'可先发布')),
      textNode('code', s.digest || '待负责人签名'));
    if (s.owner_id === state.actor.account_id) {
      const update = (action,body) => call(base+'/'+id+'/'+action,'PUT',{revision:s.revision,...body}).then(() => trainBoard(selected));
      card.append(trainEditor('编辑上游服务',[['parents','选择完整上游集合','multiple',graph.services.filter(x => x.service_id!==id).map(x=>[x.service_id,x.name])]],
        f => update('dependencies',{upstream_ids:f.getAll('parents').map(Number)})));
      const upstream = card.querySelector('select');
      for (const option of upstream.options) option.selected = s.upstream_ids.includes(Number(option.value));
      const eligible = artifacts.filter(a=>a.byte_length);
      if (eligible.length) card.append(trainEditor('签署制品',[['artifact','已校验制品','select',eligible.map(a=>[a.id,a.name+' · '+a.sha256.slice(0,16)])]],
        f => { const a=eligible.find(x=>x.id===Number(f.get('artifact'))); return update('pin',{artifact_id:a.id,digest:a.sha256}); }));
    }
    services.append(card);
  }
  const envList = $('train-environments');
  envList.replaceChildren();
  for (const env of envs.filter(x=>x.stage!=='development')) {
    envList.append(actionButton(env.name+' · '+env.stage, async () => {
      const url = projectPath('/environments/'+env.id+'/train-access');
      const access = await call(url);
      const details = $('train-detail');
      details.replaceChildren(textNode('h3',env.name),textNode('p','环境修订 '+access.environment_rev+' · '+(access.enabled?'已授权':'待授权')),
        textNode('p',access.nodes.map(n=>n.region+'/'+n.node).join('、')));
      if (canPlan()) details.append(trainEditor('指定环境负责人',[['owner','负责人账号 ID','number']], async f=>{
        await call(url+'/owner','PUT',{environment_rev:access.environment_rev,owner_id:Number(f.get('owner'))}); await trainBoard();
      }));
      if (access.owner_id===state.actor.account_id) {
        details.append(trainEditor('授权节点连接器',[['nodes','每行一个 区域/节点','textarea']],async f=>{
          const nodes=String(f.get('nodes')).trim().split(/\r?\n/).map(line=>{
            const parts=line.trim().split('/');
            if(parts.length!==2) throw new Error('每行须填写区域/节点。');
            return {region:parts[0],node:parts[1]};
          });
          await call(url+'/connector','PUT',{environment_rev:access.environment_rev,enabled:true,nodes}); await trainBoard();
        }));
        if(access.enabled) details.append(actionButton('暂停连接器',async()=>{
          await call(url+'/connector','PUT',{environment_rev:access.environment_rev,enabled:false,nodes:access.nodes}); await trainBoard();
        }));
      }
    }));
  }
  const editors=$('train-editors');
  editors.replaceChildren();
  if (canRegisterTargets()) {
    if (repos.length) editors.append(trainEditor('登记服务',[['code','服务代码','text'],['name','服务名称','text'],['owner','服务负责人账号 ID','number'],
      ['repo','仓库','select',repos.map(r=>[r.id,r.name])]],async f=>{
      await call(base,'POST',{code:f.get('code'),name:f.get('name'),repository_id:Number(f.get('repo')),owner_id:Number(f.get('owner'))});await trainBoard();
    }));
    if (baselines.length && graph.services.length) editors.append(trainEditor('创建列车',[['code','列车代码','text'],['title','标题','text'],
      ['baseline','冻结基线','select',baselines.map(b=>[b.id,b.title])],['stage','预发环境','select',envs.filter(e=>e.stage==='staging').map(e=>[e.id,e.name])],
      ['prod','生产环境','select',envs.filter(e=>e.stage==='production').map(e=>[e.id,e.name])],
      ['services','列车组件','multiple',graph.services.map(s=>[s.service_id,s.name])]],async f=>{
      const train=await call(projectPath('/release-trains'),'POST',{code:f.get('code'),title:f.get('title'),baseline_id:Number(f.get('baseline')),
        staging_id:Number(f.get('stage')),production_id:Number(f.get('prod')),service_ids:f.getAll('services').map(Number)});
      await trainBoard(train.id);
    }));
  }
  $('train-list').replaceChildren(...trains.map(t=>actionButton(t.title+' · '+trainStates[t.state],()=>trainDetail(t.id,byId))));
  $('train-detail').replaceChildren();
  $('train-board').hidden=false;
  if (selected) await trainDetail(selected,byId);
}

async function trainDetail(id,services) {
  const path=projectPath('/release-trains/'+id);
  const t=await call(path);
  const detail=$('train-detail');
  detail.replaceChildren(textNode('h3',t.title+' · '+trainStates[t.state]),textNode('p','基线 #'+t.baseline_id+' · 修订 '+t.train_rev));
  const act=async (action,input) => {await call(path+'/'+action,'POST',{revision:t.train_rev,...input}); await trainBoard(id);};
  if (!t.train_rev) {
    if(canRegisterTargets()) detail.append(actionButton('封板并生成清单',()=>act('freeze',{})));
    return;
  }
  const gates=await call(path+'/gates');
  const download=textNode('a','下载修订 '+t.train_rev+' 的制品清单');
  download.href=path+'/manifests/'+t.train_rev;
  detail.append(download);
  for(const c of gates.components) {
    const card=document.createElement('article');
    card.append(textNode('h4',c.code),textNode('code',c.digest),textNode('p',c.approval?'签核 #'+c.approval.id+' · 有效至 '+c.approval.expires_at+' UTC':'待运维签核'));
    for(const n of gates.staging.nodes) {
      const check=c.targets.find(x=>x.target===n.node);
      card.append(textNode('p',n.region+'/'+n.node+' · '+({passed:'探针通过',failed:'探针失败',pending:'等待回执'}[check?.state] || '待预演')));
      if(check?.receipt) card.append(textNode('code',JSON.stringify(check.receipt.probe)));
      if(t.state==='frozen' && gates.staging.enabled && gates.staging.owner_id===state.actor.account_id && !['passed','failed'].includes(check?.state))
        card.append(actionButton('预演 '+c.code+' / '+n.node,()=>act('rehearse',{service_id:c.service_id,node:n.node})));
    }
    if (c.passed && t.state==='frozen' && gates.production.owner_id===state.actor.account_id) card.append(trainEditor('签核 '+c.code,
      [['expires','签核有效至（UTC）','datetime-local']],f=>act('approve',{service_id:c.service_id,digest:c.digest,
        staging_rev:gates.staging.environment_rev,production_rev:gates.production.environment_rev,expires_at:f.get('expires')+':00Z'})));
    const latest=services.get(c.service_id);
    if(canRegisterTargets() && ['frozen','scheduled'].includes(t.state) && latest?.digest && latest.digest!==c.digest)
      card.append(trainEditor('替换 '+c.code,[['reason','变更原因','text']],f=>act('replace',{service_id:c.service_id,digest:latest.digest,reason:f.get('reason')})));
    detail.append(card);
  }
  if(gates.ready && canRegisterTargets() && t.state==='frozen') detail.append(trainEditor('预约生产窗口',
    [['start','开始（UTC）','datetime-local'],['end','结束（UTC）','datetime-local']],f=>act('window',{starts_at:f.get('start')+':00Z',ends_at:f.get('end')+':00Z'})));
  await trainWaveBoard(detail,path,t,gates,()=>trainDetail(id,services));
}

async function trainWaveBoard(detail,path,t,gates,refresh) {
  const board=await call(path+'/wave-plan');
  const owner=gates.production.owner_id===state.actor.account_id;
  const manager=canRegisterTargets();
  const post=async (url,body,headers)=>{await call(url,'POST',body,headers);await refresh();};
  const plan=board.plan;
  if ((!plan || plan.production_rev!==board.environment_rev) && manager && ['frozen','scheduled'].includes(t.state)) {
    detail.append(trainEditor('编排生产波次',[['waves','每行填写百分比和区域，如 10 cn eu','textarea'],
      ['minimum','每个观测窗口最少请求数','number'],['threshold','错误率上限（万分之一）','number'],['interval','前后观测各取多少秒','number']],f=>{
        const waves=f.get('waves').trim().split(/\r?\n/).map(line=>{
          const [percent,...regions]=line.trim().split(/\s+/);
          return {percent:Number(percent),regions};
        });
        return post(path+'/wave-plan',{revision:t.train_rev,waves,min_requests:Number(f.get('minimum')),
          max_error_bps:Number(f.get('threshold')),interval_seconds:Number(f.get('interval'))});
      }));
  }
  const recovery=['paused','recovered'].includes(t.state) ? await call(path+'/recovery') : null;
  const failure=board.waves.some(w=>w.state==='failed');
  if((owner || manager) && !recovery?.recovery && !failure && ['scheduled','running','paused'].includes(t.state)) {
    const paused=t.state==='paused';
    detail.append(trainEditor(paused?'继续原定波次':'暂停发布',[['reason','操作原因','text']],
      f=>post(path+'/'+(paused?'resume':'pause'),{reason:f.get('reason')})));
  }
  if(recovery) {
    const recPath=path+'/recovery';
    if(!recovery.recovery && owner) detail.append(trainEditor('准备恢复已变更节点',[['reason','恢复原因','text']],
      f=>post(recPath,{revision:t.train_rev,reason:f.get('reason')})));
    if(recovery.recovery) {
      const rec=recovery.recovery;
      const names={inspecting:'兼容性核验中',ready:'兼容性核验通过',restoring:'恢复中',repair:'需要前向修复',done:'恢复完成'};
      detail.append(textNode('h4','恢复 #'+rec.recovery_id+' · '+names[rec.state]),textNode('p',rec.reason));
      for(const item of recovery.items) {
        detail.append(textNode('p',item.node+' / 服务 #'+item.service_id+' · 原版本 '+(item.previous_digest || '未安装')+
          (item.verified_at?' · 已回读 '+(item.receipt.verified_digest || '未安装'):' · 待恢复')));
      }
      if(recovery.repair) detail.append(textNode('p','前向修复单 #'+recovery.repair.id),textNode('code',JSON.stringify(recovery.repair.evidence)));
      if(owner && ['inspecting','ready','restoring'].includes(rec.state)) {
        const inspect=rec.state==='inspecting';
        const next=recovery.items.find(x=>inspect?!x.inspection:!x.verified_at);
        if(next) detail.append(actionButton((inspect?'检查旧版本兼容性 · ':'恢复并回读 · ')+next.node,
          ()=>post(recPath+'/step',{revision:t.train_rev,item_id:next.id,phase:inspect?'inspect':'restore'})));
      }
    }
  }
  if(!plan) return;
  detail.append(textNode('h4','生产波次'),textNode('p','每侧最少 '+plan.min_requests+' 次请求 · 错误率上限 '+plan.max_error_bps/100+'% · 观测窗口 '+plan.interval_seconds+' 秒'));
  for(const wave of board.waves) {
    const card=document.createElement('article');
    const wavePath=path+'/waves/'+wave.wave_id;
    const statuses={waiting:'待放量',accepted:'已受理',running:'执行中',done:'完成',failed:'失败',recovered:'已恢复'};
    card.append(textNode('h4','第 '+wave.sequence_no+' 波 · '+(statuses[wave.state] || wave.state)),textNode('p','固定目标 '+wave.targets.join('、')));
    const obs=wave.observation;
    if(obs) {
      const decisions={allow:'可推进',pause:'超出错误率上限',await_samples:'样本不足'};
      card.append(textNode('p','观测 #'+obs.observation_id+' · '+decisions[obs.decision]+' · 变更前错误 '+obs.before_errors+'/'+obs.before_requests
        +' · 变更后错误 '+obs.after_errors+'/'+obs.after_requests));
      if(obs.waiver_id) card.append(textNode('p','豁免 #'+obs.waiver_id+' 有效至 '+obs.expires_at+' UTC'));
    }
    if(wave.execution_id) {
      card.append(actionButton('查看节点回执与调用时间线',async()=>{
        const execution=await call(path+'/executions/'+wave.execution_id);
        let report=card.querySelector('.wave-trace');
        if(!report) {report=document.createElement('section');report.className='wave-trace';card.append(report);}
        report.replaceChildren(textNode('p','执行 #'+execution.execution_id+' · Trace '+execution.trace_id));
        for(const node of execution.targets) {
          report.append(textNode('p',node.node+' / 服务 #'+node.service_id+' · '+node.state),
            textNode('p','目标 '+node.digest),textNode('p','实际 '+(node.receipt?.observed_digest || '待回读')));
          if(owner && ['unknown','done','failed'].includes(node.state)) report.append(trainEditor('登记 '+node.node+' 手工接管回执',
            [['reference','外部工单或回执编号','text']],f=>post(path+'/executions/'+wave.execution_id+'/handover',
              {operation:node.operation_key,node:node.node,digest:node.digest,external_receipt:f.get('reference')})));
        }
        for(const handover of execution.handovers) report.append(textNode('p','外部回执 '+handover.details.external_receipt+' · '+handover.details.node));
        for(const event of execution.timeline) report.append(textNode('p',event.started_at+' UTC · '+event.node+' · '+event.command+' · '+event.outcome));
      }));
    } else if (['scheduled','running','paused'].includes(t.state) && (canTest() || manager)) {
      card.append(trainEditor('录入监控样本',[['key','采样编号','text'],['measured','测量时间（UTC）','datetime-local'],
        ['requests','请求数','number'],['errors','错误数','number']],f=>post(wavePath+'/samples',{sample_key:f.get('key'),
          measured_at:f.get('measured').padEnd(19,':00')+'Z',requests:Number(f.get('requests')),errors:Number(f.get('errors'))})),
        actionButton('计算观测窗口',()=>post(wavePath+'/observations',{})));
      if(obs?.decision==='pause' && owner && !obs.waiver_id) card.append(trainEditor('登记限时豁免',[['reason','豁免依据','text'],
        ['expiry','到期时间（UTC）','datetime-local']],f=>post(wavePath+'/waivers',{observation_id:obs.observation_id,reason:f.get('reason'),expires_at:f.get('expiry')+':00Z'})));
      if(manager && board.window && (obs?.decision==='allow' || obs?.waiver_id) && t.state!=='paused') {
        const key=['wave',t.id,wave.wave_id,t.train_rev,obs.observation_id,board.window.approval_id].join('-');
        card.append(actionButton('推进第 '+wave.sequence_no+' 波',()=>post(wavePath+'/advance',{revision:t.train_rev,approval_id:board.window.approval_id,
          observation_id:obs.observation_id},{'Idempotency-Key':key})));
      }
    }
    detail.append(card);
  }
}

$('open-trains').addEventListener('click',()=>run(()=>trainBoard()));
