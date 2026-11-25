'use strict';

const signoffStatuses = {pending: '待签收', accepted: '通过', blocked: '阻断', waived: '已豁免', waiver_expired: '豁免已过期'};
const signoff = {current: null, criterion: null};

function resetSignoffs() {
  signoff.current = signoff.criterion = null;
  for (const id of ['signoffs', 'signoff-detail', 'signoff-filter', 'signoff-criteria', 'signoff-history', 'signoff-report']) $(id).replaceChildren();
  $('signoff-filter-label').hidden = $('signoff-decision').hidden = true;
}

function readSignoffs() {
  return readPage('/signoffs', 'signoffs', row => {
    const status = row.archived_at ? '报告已归档' : row.id === row.current_head_id ? '待完成签收' : '历史部署';
    return actionButton(row.candidate_code + ' v' + row.candidate_revision + ' · ' + row.environment_code + ' · #' + row.id
      + ' · ' + status, () => viewSignoff(row.id));
  });
}

async function viewSignoff(id) {
  const filter = signoff.current?.deployment_id === id ? $('signoff-filter').value : '';
  const row = await call(projectPath('/signoffs/') + id);
  signoff.current = row;
  signoff.criterion = null;
  $('signoff-decision').hidden = true;
  $('signoff-history').replaceChildren();
  $('signoff-report').replaceChildren();
  const detail = $('signoff-detail');
  detail.replaceChildren(textNode('h3', '业务签收 #' + row.deployment_id + ' · ' + row.candidate_code + ' v' + row.candidate_revision),
    textNode('p', row.environment_code + ' · 上线 ' + row.completed_at + ' UTC · 指定业务签收人 #' + row.business_signer_id));
  if (row.report) {
    const download = textNode('a', '下载归档报告 JSON');
    download.href = projectPath('/signoffs/') + id + '/report';
    download.download = 'delivery-report-' + id + '.json';
    const digest = document.createElement('p');
    digest.append(textNode('code', row.report.digest));
    detail.append(textNode('p', '报告已归档 · ' + row.report.archived_at + ' UTC'), digest, download);
  } else {
    detail.append(textNode('p', row.deployment_id !== row.current_head_id ? '此版本已下线，历史签收可查。'
      : row.blocking_criterion_ids.length + ' 项标准待处理'));
  }
  const actions = document.createElement('div');
  actions.className = 'item-list';
  actions.append(actionButton('查看生产部署', () => viewDeployment(id)), actionButton('查看追溯矩阵', () => viewSignoffReport(id)),
    timelineControl('/signoffs/' + id + '/timeline'));
  if (row.report && canPlan()) actions.append(actionButton('用此报告生成外部交付包', () => prepareReviewPackage(row)));
  detail.append(actions);
  const requirements = new Map(row.criteria.map(item => [item.requirement.id, item.requirement]));
  const select = $('signoff-filter');
  select.replaceChildren(new Option('全部需求', ''));
  for (const req of requirements.values()) select.add(new Option(req.code + ' ' + req.title, req.id));
  if (requirements.has(Number(filter))) select.value = filter;
  $('signoff-filter-label').hidden = requirements.size <= 1;
  renderSignoffCriteria();
}

function renderSignoffCriteria() {
  const row = signoff.current;
  const selected = Number($('signoff-filter').value);
  const area = $('signoff-criteria');
  area.replaceChildren();
  for (const item of row.criteria) {
    if (selected && selected !== item.requirement.id) continue;
    const section = document.createElement('article');
    section.append(textNode('h3', item.requirement.code + ' / ' + item.code + ' v' + item.version + ' · ' + signoffStatuses[item.status]),
      textNode('p', item.title + ' · ' + item.statement));
    const result = item.decision;
    if (result) {
      section.append(textNode('p', result.signer_name + ' · ' + result.decided_at + ' UTC · ' + result.note));
      if (result.responsible_id) section.append(textNode('p', '补验由 ' + result.responsible_name + ' 负责 · 截止 ' + result.retest_due_on + ' UTC'));
    }
    const actions = document.createElement('div');
    actions.className = 'item-list';
    if (row.can_decide) actions.append(actionButton('填写业务结论', () => editSignoffCriterion(item)));
    if (result) actions.append(actionButton('查看结论历史', () => viewSignoffHistory(item)));
    section.append(actions);
    area.append(section);
  }
}

function editSignoffCriterion(item) {
  signoff.criterion = item;
  const editor = $('signoff-decision');
  editor.reset();
  editor.hidden = false;
  editor.elements.decision.value = item.decision?.decision || 'accepted';
  editor.elements.decision.options[2].disabled = !['blocked', 'waived'].includes(item.decision?.decision);
  $('signoff-decision-title').textContent = item.code + ' v' + item.version + ' · ' + item.title;
  updateSignoffWaiver();
  editor.elements.decision.focus();
}

function updateSignoffWaiver() {
  const fields = $('signoff-waiver-fields');
  fields.hidden = fields.disabled = $('signoff-decision').elements.decision.value !== 'waived';
}

async function viewSignoffHistory(item) {
  const id = signoff.current.deployment_id;
  return readPage('/signoffs/' + id + '/criteria/' + item.criterion_id, 'signoff-history', row => textNode('p',
    item.code + ' · ' + signoffStatuses[row.decision] + ' · ' + row.signer_name + ' · ' + row.decided_at + ' UTC · ' + row.note
    + (row.retest_due_on ? ' · 补验 ' + row.responsible_name + ' / ' + row.retest_due_on : '')));
}

async function viewSignoffReport(id) {
  const preview = await call(projectPath('/signoffs/') + id + '/report-preview');
  const target = $('signoff-report');
  const matrix = preview.content;
  target.replaceChildren(textNode('h3', preview.archived ? '归档追溯矩阵' : '交付报告预览'),
    textNode('p', '构建 #' + matrix.candidate.manifest.build_id + ' · 提交 ' + matrix.candidate.manifest.commit_sha),
    textNode('p', '生产 → 预发 → 开发 · ' + matrix.deployment.map(leg => leg.environment_code + ' #' + leg.id).join(' → ')),
    textNode('p', '审批 #' + matrix.approval.id + ' · ' + matrix.approval.approver_name),
    textNode('code', preview.digest));
  if (signoff.current?.deployment_id === id && signoff.current.can_decide && !signoff.current.blocking_criterion_ids.length && !preview.archived) {
    target.append(textNode('p', '归档后可下载已冻结的证据。'), actionButton('归档交付报告', async () => {
      await call(projectPath('/signoffs/') + id + '/report', 'POST', {digest: preview.digest});
      await Promise.all([viewSignoff(id), readSignoffs(), readCandidates(), readTimeline(), readMilestones()]);
    }));
  }
  for (const row of matrix.criteria) {
    const article = document.createElement('article');
    article.append(textNode('h3', row.requirement.code + ' / ' + row.code + ' v' + row.version),
      textNode('p', row.title + ' · ' + signoffStatuses[row.status]));
    if (!row.work_items.length) article.append(textNode('p', '缺少研发任务关联'));
    for (const work of row.work_items) {
      article.append(actionButton('任务 #' + work.id + ' · ' + work.title, () => viewWork(work.id)),
        textNode('p', work.git_links.map(link => link.commit_sha + (link.matches_build_commit ? '（构建提交）' : '（任务关联）')).join('、') || '缺少提交关联'));
    }
    for (const result of row.test_results) article.append(actionButton('查看结果 #' + result.id + ' 的报告', () => viewTestReport(result.report_id)));
    for (const defect of row.defect_retests) article.append(actionButton('查看缺陷 #' + defect.id + ' 与复测', () => viewDefect(defect.id)));
    target.append(article);
  }
}

$('signoff-filter').addEventListener('change', renderSignoffCriteria);
$('signoff-decision').elements.decision.addEventListener('change', updateSignoffWaiver);
form('signoff-decision', async fields => {
  const item = signoff.criterion;
  const id = signoff.current.deployment_id;
  await call(projectPath('/signoffs/') + id + '/criteria/' + item.criterion_id, 'PUT', {...fields,
    responsible_id: Number(fields.responsible_id) || null, revision_id: item.revision_id, expected_decision_id: item.decision?.id || null});
  await Promise.all([viewSignoff(id), readTimeline()]);
});
