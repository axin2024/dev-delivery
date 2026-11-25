'use strict';

const deploymentStates = {queued: '待执行', running: '执行中', unknown: '待核实', succeeded: '已成功', failed: '已失败', cancelled: '已取消'};
const deployment = {selected: null, restore: null};

function resetDeployments() {
  deployment.selected = deployment.restore = null;
  for (const id of ['deployments', 'deployment-detail', 'deployment-receipts', 'deployment-connector', 'deployment-restore-target']) $(id).replaceChildren();
  for (const id of ['request-deployment', 'lookup-deployment', 'mark-deployment-unknown', 'import-deployment-receipt', 'rollback-deployment']) $(id).reset();
  for (const id of ['deployment-editor', 'deployment-timeout', 'deployment-import', 'deployment-rollback']) {
    $(id).open = false;
    $(id).hidden = true;
  }
}

function readDeployments() {
  $('deployment-editor').hidden = !canRegisterTargets();
  return readPage('/deployments', 'deployments', row => actionButton(
    '#' + row.id + ' · ' + row.environment_code + ' · ' + row.request_key + ' · ' + deploymentStates[row.status], () => viewDeployment(row.id)));
}

function chooseDeploymentCandidate(id, sha) {
  const fields = $('request-deployment').elements;
  fields.candidate_id.value = id;
  fields.artifact_sha256.value = sha;
  fields.promotion_source_id.value = '';
  $('deployment-editor').open = true;
  fields.environment_id.focus();
}

async function viewDeployment(id) {
  const row = await call(projectPath('/deployments/') + id);
  const changed = deployment.selected?.id !== id;
  deployment.selected = row;
  const controls = canRegisterTargets();
  const detail = $('deployment-detail');
  detail.replaceChildren(textNode('h3', '部署 #' + id + ' · ' + deploymentStates[row.status]),
    textNode('p', (row.kind === 'rollback' ? '回滚' : '环境晋级') + ' · ' + environmentStages[row.stage] + ' · ' + row.environment_code + ' (#' + row.environment_id + ')'),
    textNode('p', '请求键 ' + row.request_key + ' · 工具运行 ' + (row.external_run_id || '待登记')),
    textNode('p', row.current_head_id ? '环境当前生效部署 #' + row.current_head_id : '环境尚无成功部署'),
    textNode('p', row.reason), textNode('code', row.artifact_sha256));
  detail.append(actionButton('查看对应候选', () => viewCandidate(row.candidate_id)));
  for (const [key, label] of [['promotion_source_id', '查看前一级环境部署'], ['previous_head_id', '查看原生效部署'], ['restored_from_id', '查看恢复来源']]) {
    if (row[key]) detail.append(actionButton(label, () => viewDeployment(row[key])));
  }
  if (controls && row.status === 'succeeded' && row.stage !== 'production') {
    detail.append(actionButton('用作晋级来源', () => {
      chooseDeploymentCandidate(row.candidate_id, row.artifact_sha256);
      $('request-deployment').elements.promotion_source_id.value = row.id;
    }));
  }
  if (controls && row.stage === 'production' && row.current_head_id === id) {
    detail.append(actionButton('使用当前生产版本发起热修', () => chooseHotfixBase(row.environment_id, row.id)));
  }
  if (row.kind === 'promote' && row.stage === 'production' && row.status === 'succeeded') {
    detail.append(actionButton('查看此部署的业务签收', () => viewSignoff(row.id)));
  }
  detail.append(actionButton('查看工具回执', () => readDeploymentReceipts(id)), timelineControl('/deployments/' + id + '/timeline'));
  $('deployment-timeout').hidden = !controls || !['queued', 'running'].includes(row.status);
  $('deployment-import').hidden = !controls;
  if (controls) detail.append(actionButton('读取环境签名配置', async () => {
    const config = await call(projectPath('/environments/') + row.environment_id + '/deployment-connector', 'POST');
    $('deployment-connector').replaceChildren(textNode('h3', '当前环境签名配置'), textNode('p', config.path), textNode('code', config.signing_secret),
      actionButton('隐藏签名配置', () => $('deployment-connector').replaceChildren()));
  }));
  deployment.restore = controls && row.stage === 'production' && row.current_head_id === id && row.previous_head_id
    ? await call(projectPath('/deployments/') + row.previous_head_id) : null;
  const restore = deployment.restore;
  $('deployment-rollback').hidden = !restore || restore.artifact_sha256 === row.artifact_sha256;
  $('deployment-restore-target').textContent = restore ? '恢复部署 #' + restore.id + ' · SHA-256 ' + restore.artifact_sha256 : '';
  if (changed) {
    for (const node of ['deployment-connector', 'deployment-receipts']) $(node).replaceChildren();
    for (const node of ['deployment-timeout', 'deployment-import', 'deployment-rollback']) $(node).open = false;
    $('mark-deployment-unknown').reset();
    $('rollback-deployment').reset();
    $('import-deployment-receipt').elements.receipt.value = JSON.stringify({version: 1, deployment_id: row.id, request_key: row.request_key,
      environment_id: row.environment_id, artifact_sha256: row.artifact_sha256, external_run_id: row.external_run_id || '',
      event_id: '', sequence: row.external_sequence + 1, status: row.status === 'queued' ? 'running' : ''}, null, 2);
  }
}

function readDeploymentReceipts(id) {
  return readPage('/deployments/' + id + '/receipts', 'deployment-receipts', row => {
    const result = row.response.applied ? '已应用' : '已留存';
    return textNode('p', row.event_id + ' · 序号 ' + row.external_sequence + ' · ' + deploymentStates[row.reported_status]
      + ' · ' + result + ' · ' + (row.imported_by ? '导入成员 #' + row.imported_by : '签名连接器'));
  });
}

async function refreshDeployment(id) {
  await Promise.all([viewDeployment(id), readDeployments(), readEnvironments(), readTimeline(), readCandidates(), readSignoffs(), readMilestones()]);
}

form('request-deployment', async fields => {
  const item = await call(projectPath('/deployments'), 'POST', {...fields, candidate_id: Number(fields.candidate_id),
    environment_id: Number(fields.environment_id), promotion_source_id: Number(fields.promotion_source_id) || null,
    expected_head_id: Number(fields.expected_head_id) || null});
  $('request-deployment').reset();
  await refreshDeployment(item.id);
});
form('lookup-deployment', async fields => {
  const item = await call(projectPath('/deployments/lookup?') + new URLSearchParams(fields));
  await viewDeployment(item.id);
});
form('mark-deployment-unknown', async fields => {
  const row = deployment.selected;
  await call(projectPath('/deployments/') + row.id + '/timeout', 'POST', {...fields, request_key: row.request_key});
  $('mark-deployment-unknown').reset();
  await refreshDeployment(row.id);
});
form('import-deployment-receipt', async fields => {
  let receipt;
  try {
    receipt = JSON.parse(fields.receipt);
  } catch {
    throw new Error('请粘贴部署工具的 JSON 回执。');
  }
  const id = deployment.selected.id;
  await call(projectPath('/deployments/') + id + '/receipts', 'POST', receipt);
  await Promise.all([refreshDeployment(id), readDeploymentReceipts(id)]);
});
form('rollback-deployment', async fields => {
  const row = deployment.selected;
  const rollback = await call(projectPath('/deployments/') + row.id + '/rollback', 'POST', {...fields,
    environment_id: row.environment_id, expected_head_id: row.id, artifact_sha256: deployment.restore.artifact_sha256});
  await refreshDeployment(rollback.id);
});
