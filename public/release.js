'use strict';

let selectedCandidate = null;
const candidateStates = {draft: '草稿', frozen: '范围已冻结', testing: '测试中', pending_approval: '待审批',
  approved: '已批准', superseded: '已被后继取代', deployed: '已上线', accepted: '已签收'};

function resetRelease() {
  selectedCandidate = null;
  for (const id of ['candidates', 'candidate-detail', 'candidate-approvals']) $(id).replaceChildren();
  for (const id of ['create-candidate', 'replace-candidate', 'approve-candidate', 'delegate-candidate', 'upload-artifact']) $(id).reset();
  updateHotfixFields();
  for (const id of ['candidate-editor', 'candidate-replacement-editor', 'approve-candidate', 'candidate-delegation-editor']) $(id).hidden = true;
}

function readCandidates() {
  $('candidate-editor').hidden = !canRegisterTargets();
  return readPage('/candidates', 'candidates', item => actionButton(
    item.code + ' v' + item.revision + '　' + item.title + ' · ' + (item.track === 'hotfix' ? '热修版' : '正式版')
    + ' · ' + candidateStates[item.state], () => viewCandidate(item.id)));
}

async function viewCandidate(id) {
  const candidate = await call(projectPath('/candidates/') + id);
  const oldSelection = selectedCandidate;
  selectedCandidate = candidate;
  const {manifest, quality, approval_delegation: delegation} = candidate;
  const detail = $('candidate-detail');
  detail.replaceChildren(textNode('h3', '候选 #' + id + '　' + candidate.title),
    textNode('p', candidateStates[candidate.state] + (candidate.approval_valid ? ' · 当前审批有效' : '')),
    textNode('p', '基线 #' + manifest.baseline_id + ' · 制品 #' + manifest.artifact_id + ' · 预发计划 #' + manifest.test_plan_id),
    textNode('p', '仓库 #' + manifest.repository_id + ' · ' + manifest.branch + ' · 构建 #' + manifest.build_id + ' · ' + manifest.provider_run_id),
    textNode('p', '提交 ' + manifest.commit_sha), textNode('p', '制品 SHA-256 ' + manifest.artifact_sha256),
    textNode('p', '范围清单 SHA-256 ' + candidate.manifest_digest),
    textNode('p', quality.artifact_verified ? '文件摘要已校验' : '待上传制品文件'),
    textNode('p', Object.entries(quality.counts).map(([state, count]) => outcomes[state] + ' ' + count).join(' · ')));
  if (manifest.production_base) {
    const base = manifest.production_base;
    detail.append(textNode('p', '热修生产基点 #' + base.deployment_id + ' · ' + base.commit_sha + (quality.production_base_current ? '' : ' · 生产版本已变更')),
      actionButton('查看热修生产基点', () => viewDeployment(base.deployment_id)));
  }
  if (quality.stale_criteria.length) detail.append(textNode('p', '标准已有新版本 ' + quality.stale_criteria.map(row => '#' + row.criterion_id).join('、')));
  if (delegation) {
    const delegationStates = {revoked: '已撤销', approved: '已代办批准', approved_directly: '负责人已直接批准', replaced: '已换人',
      candidate_evidence_changed: '门禁已变更', candidate_replaced: '候选已被替换'};
    detail.append(textNode('p', delegation.delegated_by_name + ' 委托 ' + delegation.delegated_to_name + ' · '
      + (delegationStates[delegation.closed_reason] ?? '待代办') + ' · ' + delegation.reason));
  }
  const frozen = document.createElement('details');
  frozen.append(textNode('summary', '核对冻结范围及必过用例'));
  for (const criterion of manifest.scope) {
    const cases = manifest.required_cases.filter(row => row.criterion_revision_id === criterion.revision_id);
    frozen.append(textNode('p', '标准 #' + criterion.criterion_id + ' · 修订 #' + criterion.revision_id + ' · 用例版本 '
      + cases.map(row => '#' + row.case_version_id).join('、')));
  }
  detail.append(frozen);
  if (candidate.previous_id) detail.append(actionButton('查看前一版候选', () => viewCandidate(candidate.previous_id)));
  if (candidate.successor_id) detail.append(actionButton('查看后继候选', () => viewCandidate(candidate.successor_id)));
  detail.append(actionButton('查看候选测试计划', () => viewTestPlan(candidate.test_plan_id)),
    actionButton('查看审批记录', () => readCandidateApprovals(id)), timelineControl('/candidates/' + id + '/timeline'));
  const phases = {draft: ['冻结候选范围', 'freeze'], frozen: ['开始候选测试', 'start-testing'],
    testing: ['提交候选审批', 'submit'], pending_approval: ['重新核验并提交', 'submit'], approved: ['重新核验并提交', 'submit']};
  if (canRegisterTargets() && phases[candidate.state]) {
    const [label, action] = phases[candidate.state];
    detail.append(actionButton(label, async () => {
      await call(projectPath('/candidates/') + id + '/' + action, 'POST', {manifest_digest: candidate.manifest_digest});
      await Promise.all([viewCandidate(id), readCandidates(), readTimeline()]);
    }));
  }
  if (canRegisterTargets() && !['draft', 'superseded'].includes(candidate.state)) {
    detail.append(actionButton('使用候选申请部署', () => chooseDeploymentCandidate(candidate.id, manifest.artifact_sha256)));
  }
  const canApproveAsDelegate = delegation && !delegation.closed_at && delegation.delegated_to === state.actor.account_id
    && delegation.gate_digest === candidate.gate_digest;
  $('approve-candidate').hidden = candidate.state !== 'pending_approval' || !quality.ready || quality.digest !== candidate.gate_digest
    || (!candidate.can_delegate_approval && !canApproveAsDelegate);
  const delegateEditor = $('candidate-delegation-editor');
  delegateEditor.hidden = !candidate.can_delegate_approval;
  const replacement = $('candidate-replacement-editor');
  replacement.hidden = !canRegisterTargets() || !phases[candidate.state];
  if (oldSelection?.id !== id) {
    $('candidate-approvals').replaceChildren();
    $('approve-candidate').reset();
    $('delegate-candidate').reset();
    delegateEditor.open = false;
    replacement.open = false;
    const form = $('replace-candidate');
    for (const key of ['title', 'baseline_id', 'artifact_id', 'test_plan_id']) form.elements[key].value = candidate[key];
  }
}

function readCandidateApprovals(id) {
  return readPage('/candidates/' + id + '/approvals', 'candidate-approvals', row => {
    const details = document.createElement('details');
    details.append(textNode('summary', (row.revoked_at ? '已撤销' : '批准记录') + ' · 成员 #' + row.approved_by + ' · ' + row.approved_at + ' UTC'),
      textNode('p', row.note), textNode('p', '结果 ' + row.result_ids.map(id => '#' + id).join('、')), textNode('code', row.gate_digest));
    if (row.delegation_id) details.append(textNode('p', '代办 #' + row.delegation_id + ' · 委托人 #' + row.delegated_by));
    return details;
  });
}

function candidateInput(fields) {
  const {production_environment_id, production_base_id, ...input} = fields;
  return {...input, baseline_id: Number(fields.baseline_id), artifact_id: Number(fields.artifact_id), test_plan_id: Number(fields.test_plan_id),
    ...(fields.track === 'hotfix' ? {production_environment_id: Number(production_environment_id), production_base_id: Number(production_base_id)} : {})};
}

function updateHotfixFields() {
  const fields = $('create-candidate').elements;
  const hotfix = fields.track.value === 'hotfix';
  $('candidate-hotfix-base').hidden = !hotfix;
  for (const name of ['production_environment_id', 'production_base_id']) fields[name].required = hotfix;
}

function chooseHotfixBase(environment, deploymentId) {
  const {elements} = $('create-candidate');
  elements.track.value = 'hotfix';
  elements.production_environment_id.value = environment;
  elements.production_base_id.value = deploymentId;
  updateHotfixFields();
  $('candidate-editor').open = true;
  elements.code.focus();
}

$('create-candidate').elements.track.addEventListener('change', updateHotfixFields);

form('upload-artifact', async fields => {
  const body = fields.artifact_file;
  if (!body.size || body.size > 8 * 1024 * 1024) throw new Error('请选择 8 MiB 以内的非空制品文件。');
  await requestJson(projectPath('/artifacts/') + fields.artifact_id + '/content', {method: 'PUT', body, headers: {
    'Content-Type': 'application/octet-stream', 'X-CSRF-Token': state.csrf
  }});
  $('upload-artifact').reset();
  await Promise.all([readArtifacts(), readTimeline(), ...(selectedCandidate ? [viewCandidate(selectedCandidate.id)] : [])]);
  showMessage('已校验并保存制品文件。');
});
form('create-candidate', async fields => {
  const candidate = await call(projectPath('/candidates'), 'POST', candidateInput(fields));
  $('create-candidate').reset();
  updateHotfixFields();
  await Promise.all([viewCandidate(candidate.id), readCandidates(), readTimeline()]);
});
form('replace-candidate', async fields => {
  const next = await call(projectPath('/candidates/') + selectedCandidate.id + '/replacements', 'POST', candidateInput(fields));
  await Promise.all([viewCandidate(next.id), readCandidates(), readTimeline()]);
});
form('approve-candidate', async fields => {
  const item = selectedCandidate;
  const delegation = item.approval_delegation;
  await call(projectPath('/candidates/') + item.id + '/approve', 'POST', {...fields,
    manifest_digest: item.manifest_digest, gate_digest: item.gate_digest,
    ...(delegation?.delegated_to === state.actor.account_id ? {delegation_id: delegation.id} : {})});
  $('approve-candidate').reset();
  await Promise.all([viewCandidate(item.id), readCandidates(), readCandidateApprovals(item.id), readTimeline()]);
});
form('delegate-candidate', async fields => {
  const item = selectedCandidate;
  await call(projectPath('/candidates/') + item.id + '/approval-delegation', 'PUT', {
    delegate_id: fields.delegate_id ? Number(fields.delegate_id) : null, expected_delegation_id: item.approval_delegation?.id ?? null,
    manifest_digest: item.manifest_digest, gate_digest: item.gate_digest, reason: fields.reason
  });
  $('delegate-candidate').reset();
  await Promise.all([viewCandidate(item.id), readTimeline()]);
  showMessage('审批代办记录已更新。');
});
