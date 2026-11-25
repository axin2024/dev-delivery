'use strict';

const operation = {milestone: null, package: null, packageSource: null};
const milestonePhases = {
  development: '开发中', waiting_for_test: '等待测试', testing: '测试中', waiting_for_approval: '等待审批',
  waiting_for_production: '等待上线', deploying: '部署中', deployment_unknown: '部署结果待核实', deployment_blocked: '部署受阻',
  waiting_for_signoff: '等待业务签收', accepted: '已签收', previous_production: '生产已换版', candidate_replaced: '候选已被取代'
};
const milestoneWindows = {development: '开发', waiting_for_test: '等待测试', testing: '测试执行',
  waiting_for_approval: '等待审批', waiting_for_production: '等待上线', waiting_for_signoff: '等待业务签收'};

function resetOperations() {
  operation.milestone = operation.package = operation.packageSource = null;
  for (const id of ['milestones', 'milestone-detail', 'milestone-ownership', 'milestone-metrics', 'review-packages', 'review-package-detail',
    'review-grants', 'external-packages', 'external-package-detail', 'work-ownership']) $(id).replaceChildren();
  for (const id of ['create-milestone', 'plan-milestone', 'transfer-milestone-owner', 'transfer-work-owner', 'create-package', 'grant-review-package']) $(id).reset();
  for (const id of ['milestone-editor', 'milestone-replan', 'milestone-ownership-editor', 'package-editor']) {
    $(id).hidden = true;
    $(id).open = false;
  }
  $('grant-review-package').hidden = true;
  resetPackageCriteria();
}

function readOperations() {
  $('milestone-editor').hidden = $('package-editor').hidden = !canPlan();
  return Promise.all([readMilestones(), readPackages()]);
}

function readMilestones() {
  return readPage('/milestones', 'milestones', row => actionButton(row.code + ' · ' + row.title + ' · '
    + milestonePhases[row.phase] + (row.overdue ? ' · 已逾期' : ''), () => viewMilestone(row.id)));
}

function milestonePath(id) {
  return '/milestones/' + id;
}

function formatElapsed(milliseconds) {
  const seconds = Math.floor(milliseconds / 1000);
  if (seconds < 60) return (milliseconds / 1000).toFixed(1) + ' 秒';
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return minutes + ' 分 ' + seconds % 60 + ' 秒';
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return hours + ' 小时 ' + minutes % 60 + ' 分钟';
  return Math.floor(hours / 24) + ' 天 ' + hours % 24 + ' 小时';
}

function milestoneIntervals(elapsed) {
  const list = document.createElement('ul');
  for (const [key, label] of Object.entries(milestoneWindows)) {
    const interval = elapsed[key];
    list.append(textNode('li', label + ' · ' + (interval.start_at ? formatElapsed(interval.elapsed_ms)
      + (interval.end_at ? '' : ' · 进行中') : '尚未开始')));
  }
  return list;
}

async function viewMilestone(id) {
  const row = await call(projectPath(milestonePath(id)));
  operation.milestone = row;
  const detail = $('milestone-detail');
  detail.replaceChildren(textNode('h3', row.title + ' · ' + milestonePhases[row.phase]),
    textNode('p', '计划 ' + row.due_on + ' UTC · ' + row.environment_code + ' · 责任人 ' + row.owner_name + ' (#' + row.owner_id + ')'),
    milestoneIntervals(row.elapsed));
  if (row.overdue || row.signed_off_late) {
    const alert = textNode('p', row.signed_off_late ? '已逾期签收' : '已逾期 · 当前环节 ' + milestonePhases[row.phase]);
    alert.className = 'alert';
    detail.append(alert);
  }
  if (row.signoff_blocking_criterion_ids) {
    detail.append(textNode('p', '待处理标准 ' + row.signoff_blocking_criterion_ids.length + ' 项 · 业务签收人 #' + row.business_signer_id));
  }
  if (row.quality) {
    const counts = row.quality.counts;
    detail.append(textNode('p', '门禁 · 已通过 ' + counts.passed + ' · 失败 ' + counts.failed + ' · 缺结果 ' + counts.missing
      + ' · 跳过 ' + counts.skipped + (row.quality.stale_criteria.length ? ' · 标准已修订 ' + row.quality.stale_criteria.length : '')));
    if (row.phase === 'waiting_for_production' && !row.approval_valid) detail.append(textNode('p', '待重新核验发布门禁'));
  }
  const actions = document.createElement('div');
  actions.className = 'item-list';
  actions.append(actionButton('查看里程碑基线', () => viewBaseline(row.baseline_id)),
    actionButton('查看里程碑移交记录', () => readOwnership(milestonePath(id), 'milestone-ownership')),
    actionButton('查看周期快照', () => readMilestoneMetrics(id)), timelineControl(milestonePath(id) + '/timeline'));
  if (row.candidate_id) actions.append(actionButton('查看里程碑候选', () => viewCandidate(row.candidate_id)));
  if (row.deployment_id) actions.append(actionButton('查看里程碑部署', () => viewDeployment(row.deployment_id)));
  if (row.production_id) actions.append(actionButton('查看里程碑签收', () => viewSignoff(row.production_id)));
  if (canPlan()) actions.append(actionButton('保存当前周期快照', async () => {
    await call(projectPath(milestonePath(id)) + '/metrics', 'POST');
    await Promise.all([readMilestoneMetrics(id), readTimeline()]);
  }));
  detail.append(actions);
  $('milestone-ownership').replaceChildren();
  $('milestone-metrics').replaceChildren();
  $('transfer-milestone-owner').reset();
  $('milestone-replan').hidden = !canPlan() || Boolean(row.signed_off_at);
  $('milestone-ownership-editor').hidden = !canPlan();
  const fields = $('plan-milestone').elements;
  fields.baseline_id.value = row.baseline_id;
  fields.candidate_id.value = row.candidate_id ?? '';
  fields.due_on.value = row.due_on;
}

function readMilestoneMetrics(id) {
  return readPage(milestonePath(id) + '/metrics', 'milestone-metrics', snapshot => {
    const section = document.createElement('details');
    section.append(textNode('summary', snapshot.window_end + ' UTC · ' + milestonePhases[snapshot.data.phase]),
      milestoneIntervals(snapshot.data.elapsed));
    return section;
  });
}

function readOwnership(path, target) {
  return readPage(path + '/ownership', target, row => textNode('p', row.previous_owner_name + ' → ' + row.next_owner_name
    + ' · ' + row.changed_at + ' UTC · 成员 #' + row.changed_by + ' · ' + row.reason));
}

function readPackages() {
  return readPage('/review-packages', 'review-packages', row => actionButton(row.title + ' · #' + row.id, () => viewPackage(row.id)));
}

function renderPackageContent(content, target) {
  const release = content.release;
  target.append(textNode('p', release.code + ' v' + release.version + ' · 上线 ' + content.released_at + ' UTC'));
  for (const item of content.criteria) {
    const article = document.createElement('article');
    article.append(textNode('h3', item.requirement.code + ' / ' + item.code + ' v' + item.version),
      textNode('p', item.requirement.title + ' · ' + item.title), textNode('p', item.statement), textNode('p', signoffStatuses[item.status]
      + (item.retest_due_on ? ' · 补验截至 ' + item.retest_due_on + ' UTC' : '')));
    target.append(article);
  }
}

async function viewPackage(id) {
  const row = await call(projectPath('/review-packages/') + id);
  operation.package = row;
  const target = $('review-package-detail');
  target.replaceChildren(textNode('h3', row.title + ' · #' + id), textNode('code', row.digest),
    timelineControl('/review-packages/' + id + '/timeline'));
  renderPackageContent(row.content, target);
  $('grant-review-package').hidden = !canPlan();
  $('grant-review-package').reset();
  await readPackageGrants(id);
}

function readPackageGrants(id) {
  return readPage('/review-packages/' + id + '/grants', 'review-grants', grant => {
    const row = document.createElement('article');
    row.append(textNode('p', grant.name + ' (#' + grant.id + ') · ' + (grant.active ? '已授权至 ' + grant.expires_on + ' UTC'
      : grant.revoked_at ? '已撤回' : '已过期')));
    if (canPlan() && !grant.revoked_at) row.append(actionButton('撤回此授权', async () => {
      await call(projectPath('/review-packages/') + id + '/grants/' + grant.id + '/revoke', 'POST');
      await Promise.all([readPackageGrants(id), readTimeline()]);
    }));
    return row;
  });
}

function resetPackageCriteria() {
  operation.packageSource = null;
  $('package-criterion-options').replaceChildren();
  $('package-criteria').hidden = $('save-review-package').disabled = true;
}

function prepareReviewPackage(report) {
  resetPackageCriteria();
  const form = $('create-package');
  form.elements.deployment_id.value = report.deployment_id;
  form.elements.title.value = '';
  operation.packageSource = report.deployment_id;
  const options = $('package-criterion-options');
  for (const criterion of report.criteria) {
    const label = textNode('label', criterion.requirement.code + ' / ' + criterion.code + ' · ' + criterion.title);
    label.className = 'option';
    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.name = 'criterion_id';
    checkbox.value = criterion.criterion_id;
    label.prepend(checkbox);
    options.append(label);
  }
  $('package-editor').open = true;
  $('package-criteria').hidden = false;
  $('review-package-panel').scrollIntoView({block: 'start'});
}

function readExternalPackages() {
  return readPagedCollection('/api/review-packages', 'external-packages', row => actionButton(row.title + ' · 授权至 ' + row.expires_on,
    () => viewExternalPackage(row.id)));
}

async function viewExternalPackage(id) {
  const target = $('external-package-detail');
  target.replaceChildren();
  const row = await call('/api/review-packages/' + id);
  const download = textNode('a', '下载交付包 JSON');
  download.href = '/api/review-packages/' + id + '/content';
  download.download = 'review-package-' + id + '.json';
  target.append(textNode('h2', row.title), textNode('p', '授权至 ' + row.expires_on + ' UTC'), download);
  renderPackageContent(row.content, target);
}

function ownerTransfer(fields, previous) {
  return {...fields, expected_owner_id: previous, next_owner_id: Number(fields.next_owner_id)};
}

form('create-milestone', async fields => {
  const row = await call(projectPath('/milestones'), 'POST', {...fields, baseline_id: Number(fields.baseline_id),
    candidate_id: Number(fields.candidate_id) || null, environment_id: Number(fields.environment_id), owner_id: Number(fields.owner_id)});
  $('create-milestone').reset();
  await Promise.all([readMilestones(), viewMilestone(row.id), readTimeline()]);
});

form('plan-milestone', async fields => {
  const row = operation.milestone;
  await call(projectPath(milestonePath(row.id)), 'PUT', {...fields, version: row.version,
    baseline_id: Number(fields.baseline_id), candidate_id: Number(fields.candidate_id) || null});
  await Promise.all([readMilestones(), viewMilestone(row.id), readTimeline()]);
});

form('transfer-milestone-owner', async fields => {
  const row = operation.milestone;
  await call(projectPath(milestonePath(row.id)) + '/ownership', 'PUT', ownerTransfer(fields, row.owner_id));
  await Promise.all([viewMilestone(row.id), readTimeline()]);
  await readOwnership(milestonePath(row.id), 'milestone-ownership');
});

form('transfer-work-owner', async fields => {
  const item = state.work;
  await call(workPath(item.id) + '/ownership', 'PUT', ownerTransfer(fields, item.owner_id));
  await Promise.all([viewWork(item.id), readTimeline()]);
  await readOwnership('/work-items/' + item.id, 'work-ownership');
});

$('load-package-criteria').addEventListener('click', () => run(async () => {
  const id = Number($('create-package').elements.deployment_id.value);
  const row = await call(projectPath('/signoffs/') + id);
  if (!row.report) throw new Error('请先归档该部署的交付报告。');
  prepareReviewPackage(row);
}));
$('create-package').elements.deployment_id.addEventListener('input', resetPackageCriteria);
$('package-criteria').addEventListener('change', () => {
  $('save-review-package').disabled = !$('package-criteria').querySelector('input:checked');
});
form('create-package', async fields => {
  const ids = new FormData($('create-package')).getAll('criterion_id').map(Number);
  if (Number(fields.deployment_id) !== operation.packageSource || !ids.length) throw new Error('请选择本部署已归档的验收标准。');
  const row = await call(projectPath('/review-packages'), 'POST', {deployment_id: operation.packageSource, title: fields.title, criterion_ids: ids});
  $('create-package').reset();
  resetPackageCriteria();
  await Promise.all([viewPackage(row.id), readPackages(), readTimeline()]);
});
form('grant-review-package', async fields => {
  const id = operation.package.id;
  await call(projectPath('/review-packages/') + id + '/grants/' + Number(fields.reviewer_id), 'PUT', {expires_on: fields.expires_on});
  $('grant-review-package').reset();
  await Promise.all([readPackageGrants(id), readTimeline()]);
});
