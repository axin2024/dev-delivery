'use strict';

const $ = id => document.getElementById(id);
const state = {actor: null, csrf: null, project: null, scope: null, projectsAfter: null, accountsAfter: null,
  baselinesAfter: null, eventsAfter: null, baseline: null, change: null, work: null, sprint: null, repository: null};
const eventTitles = {
  created: '创建项目', charter_revised: '修订项目章程', member_added: '加入项目成员', member_removed: '移出项目成员',
  requirement_added: '新增需求', criterion_added: '新增验收标准', criterion_revised: '修订验收标准',
  baseline_frozen: '冻结基线', change_proposed: '提交变更', change_approved: '批准变更', change_rejected: '驳回变更',
  sprint_created: '创建迭代', work_item_created: '创建任务', work_item_scheduled: '调整任务排期',
  dependency_saved: '保存依赖', dependency_removed: '移除依赖', test_case_created: '登记用例',
  repository_registered: '登记代码仓库', work_item_claimed: '认领任务', git_snapshot_linked: '关联提交快照',
  test_case_revised: '修订用例', test_plan_created: '创建测试计划', artifact_registered: '登记制品',
  environment_registered: '登记环境', test_result_recorded: '记录测试结果', defect_opened: '登记缺陷',
  defect_fixed: '提交修复', defect_closed: '复测关闭缺陷'
};
const errors = {
  invalid_credentials: '租户、邮箱或密码有误。',
  baseline_scope_changed: '范围已更新，请重新打开项目核对。',
  baseline_scope_already_frozen: '这份范围已冻结，请查看已冻结基线。',
  requirements_without_criteria: '请先给每条需求添加验收标准。',
  baseline_requires_criteria: '请先添加验收标准。',
  criterion_change_review_required: '此条款已冻结，后续修订须走变更评审。',
  project_version_conflict: '章程版本已变动，请重新打开项目核对。',
  change_baseline_stale: '请打开最新冻结基线发起变更。',
  change_revision_stale: '其中一项标准已有新版本，请核对后重新发起。',
  change_duplicate_criterion: '同一标准只能出现一次。',
  change_already_reviewed: '该变更已完成评审，请重新打开确认结果。',
  criterion_revision_unchanged: '请修改验收标准名称或验收条件。',
  sprint_capacity_exceeded: '此迭代的剩余容量不足。',
  sprint_team_mismatch: '请选择任务所属团队的迭代。',
  dependency_cycle: '请移除循环依赖后再安排迭代。',
  dependency_schedule_conflict: '请调整前置任务排期或依赖的截止日期。',
  repository_identity_exists: '此平台和仓库标识已登记。',
  connector_repository_mismatch: '回执中的平台和仓库标识必须与当前仓库一致。',
  connector_not_configured: '管理员需要先在服务器设置连接器主密钥。',
  connector_event_content_conflict: '此事件编号已用于不同的回执内容。',
  git_reference_stale: '分支已经移动，请核对原提交与当前提交。',
  connector_snapshot_commit_mismatch: '回执里的提交与所指向的快照不一致。',
  test_case_version_conflict: '用例已更新，请重新打开并核对当前版本。',
  test_case_unchanged: '当前内容与已保存用例版本相同。',
  test_case_outside_plan_baseline: '部分用例版本绑定的验收标准不在所选基线中。',
  test_plan_duplicate_case: '同一个用例或 JUnit 标识只能出现一次。',
  test_execution_target_mismatch: '请确认报告对应的构建、制品摘要和测试环境。',
  test_report_content_conflict: '此报告编号已用于其他内容。',
  test_attempt_already_recorded: '该执行序号已有报告。',
  junit_no_mapped_cases: '报告里的类名和用例名没有匹配本计划的用例。',
  junit_duplicate_mapped_case: '报告含两个同名结果，无法确定该用例的结果。',
  junit_invalid_document: '请选择完整的 JUnit XML 报告。',
  junit_unsupported_xml: '该 XML 使用了不支持的声明或嵌套。',
  defect_retest_evidence_mismatch: '请选择修复制品在原环境中通过的对应用例结果。',
  defect_fix_requires_new_build: '请选择新的构建和制品摘要。',
  csrf_invalid: '会话已过期，请刷新页面后登录。'
};
let busy = false;

async function call(path, method = 'GET', input) {
  const options = {method, headers: {'Accept': 'application/json'}};
  if (method !== 'GET') {
    options.headers['Content-Type'] = 'application/json';
    options.headers['X-CSRF-Token'] = state.csrf;
    options.body = JSON.stringify(input || {});
  }
  return requestJson(path, options);
}

async function requestJson(path, options) {
  const response = await fetch(path, options);
  const body = await response.json();
  if (body.csrf_token) state.csrf = body.csrf_token;
  if (!response.ok) {
    const detail = body.details || {};
    const conflict = conflictText(detail);
    throw new Error([errors[body.error] || body.error || '请求失败', detail.field, ...conflict].filter(Boolean).join(' '));
  }
  return body;
}

function textNode(tag, value) {
  const element = document.createElement(tag);
  element.textContent = value;
  return element;
}

function showMessage(message, error = false) {
  const element = $('message');
  element.textContent = message;
  element.hidden = !message;
  element.classList.toggle('error', error);
}

async function run(action) {
  if (busy) return;
  busy = true;
  const main = document.querySelector('main');
  main.setAttribute('aria-busy', 'true');
  $('logout').disabled = true;
  showMessage('');
  try {
    await action();
  } catch (error) {
    showMessage(error.message, true);
  } finally {
    busy = false;
    main.setAttribute('aria-busy', 'false');
    $('logout').disabled = false;
  }
}

function actionButton(label, action) {
  const button = textNode('button', label);
  button.type = 'button';
  button.addEventListener('click', () => run(action));
  return button;
}

function form(id, action) {
  $(id).addEventListener('submit', event => {
    event.preventDefault();
    const element = event.currentTarget;
    const button = event.submitter || element.querySelector('button:not([type="button"])');
    run(async () => {
      button.disabled = true;
      try {
        await action(Object.fromEntries(new FormData(element)));
      } finally {
        button.disabled = false;
      }
    });
  });
}

function projectPath(suffix = '') {
  return '/api/projects/' + state.project.id + suffix;
}

function canPlan() {
  return state.actor.roles.includes('admin') || state.actor.account_id === state.project.product_owner_id;
}

function conflictText(detail) {
  const alerts = [];
  if (detail.cycle?.length) alerts.push('循环任务 ' + detail.cycle.join(' → '));
  for (const edge of detail.schedule_conflicts || []) {
    const reason = edge.reason === 'predecessor_unscheduled' ? '尚未排期' : '结束日期晚于交接日期';
    alerts.push('任务 #' + edge.work_item_id + ' 的前置任务 #' + edge.predecessor_id + ' ' + reason);
  }
  if (detail.affected_sprint_ids?.length) alerts.push('受影响迭代 #' + detail.affected_sprint_ids.join('、#'));
  return alerts;
}

async function readProjects(append = false) {
  const data = await call('/api/projects?after=' + (append ? state.projectsAfter : 0));
  if (!append) $('projects').replaceChildren();
  for (const item of data.items) {
    $('projects').append(actionButton(item.code + '　' + item.title, () => viewProject(item.id)));
  }
  state.projectsAfter = data.next_after;
  $('more-projects').hidden = data.next_after === null;
}

async function readAccounts(append = false) {
  const data = await call('/api/accounts?after=' + (append ? state.accountsAfter : 0));
  if (!append) $('account-list').replaceChildren();
  for (const item of data.items) $('account-list').append(textNode('li', item.id + '　' + item.name + '　' + item.roles.join('、')));
  state.accountsAfter = data.next_after;
  $('more-accounts').hidden = data.next_after === null;
}

async function readBaselines(append = false) {
  const data = await call(projectPath('/baselines?after=') + (append ? state.baselinesAfter : 0));
  if (!append) $('baselines').replaceChildren();
  for (const item of data.items) {
    $('baselines').append(actionButton('B' + item.sequence + '　' + item.title, () => viewBaseline(item.id)));
  }
  state.baselinesAfter = data.next_after;
  $('more-baselines').hidden = data.next_after === null;
}

async function readTimeline(append = false) {
  const data = await call(projectPath('/timeline?after=') + (append ? state.eventsAfter : 0));
  if (!append) $('timeline').replaceChildren();
  for (const row of data.items) {
    $('timeline').append(textNode('li',
      row.occurred_at + ' UTC　' + (eventTitles[row.action] || row.action) + '　成员 ' + row.actor_id));
  }
  state.eventsAfter = data.next_after;
  $('more-events').hidden = data.next_after === null;
}

function showCriterion(item, edit) {
  const row = document.createElement('article');
  row.append(textNode('h3', item.requirement.code + ' / ' + item.code + '　' + item.title + ' · v' + item.version),
    textNode('p', item.statement), textNode('code', item.digest));
  const history = document.createElement('div');
  history.hidden = true;
  const openHistory = actionButton('查看版本记录', async () => {
    if (!history.hidden) {
      history.hidden = true;
      openHistory.textContent = '查看版本记录';
      return;
    }
    history.replaceChildren();
    await readCriterionHistory(item.criterion_id, history, 0);
    history.hidden = false;
    openHistory.textContent = '收起版本记录';
  });
  const controls = document.createElement('div');
  controls.append(openHistory);
  row.append(controls, history);
  if (edit) {
    const details = document.createElement('details');
    details.append(textNode('summary', '修订验收标准'));
    const editor = document.createElement('form');
    const titleLabel = textNode('label', '标准名称');
    const title = document.createElement('input');
    title.value = item.title;
    title.required = true;
    titleLabel.append(title);
    const statementLabel = textNode('label', '验收条件');
    const statement = document.createElement('textarea');
    statement.value = item.statement;
    statement.required = true;
    statementLabel.append(statement);
    editor.append(titleLabel, statementLabel, textNode('button', '提交修订'));
    editor.addEventListener('submit', event => {
      event.preventDefault();
      run(async () => {
        await call(projectPath('/criteria/') + item.criterion_id, 'PUT', {title: title.value, statement: statement.value, version: item.version});
        await readScope();
        await readTimeline();
        showMessage('验收标准已新增版本。');
      });
    });
    details.append(editor);
    row.append(details);
  }
  return row;
}

async function readCriterionHistory(id, target, after) {
  const result = await call(projectPath('/criteria/') + id + '?after=' + after);
  for (const revision of result.revisions) {
    target.append(textNode('h3', '版本 ' + revision.version + '　' + revision.title), textNode('p', revision.statement));
  }
  if (result.next_after !== null) {
    const more = actionButton('更多版本', async () => {
      await readCriterionHistory(id, target, result.next_after);
      more.remove();
    });
    target.append(more);
  }
}

async function viewBaseline(id) {
  const baseline = await call(projectPath('/baselines/') + id);
  state.baseline = baseline;
  $('baseline-detail').replaceChildren(textNode('h3', 'B' + baseline.sequence + '　' + baseline.title),
    textNode('p', '基线 ID #' + baseline.id),
    textNode('p', '章程 v' + baseline.charter_version + '　' + baseline.charter_snapshot.charter),
    textNode('code', baseline.manifest_digest),
    ...baseline.criteria.map(item => showCriterion(item, false)));
  if (canPlan()) {
    resetProposal();
    $('proposal-baseline').textContent = 'B' + baseline.sequence + '　' + baseline.title;
    for (const id of ['add-proposal-criterion', 'proposal-reason']) $(id).hidden = false;
    $('submit-change').disabled = false;
    addProposalCriterion();
  }
}

async function readScope() {
  state.scope = await call(projectPath('/scope'));
  const requirements = new Map();
  for (const item of state.scope.uncovered_requirements) requirements.set(item.id, item);
  for (const item of state.scope.criteria) requirements.set(item.requirement.id, item.requirement);
  $('requirement-options').replaceChildren();
  for (const item of requirements.values()) $('requirement-options').add(new Option(item.code + '　' + item.title, item.id));
  const options = $('create-work').elements.criterion_id;
  options.replaceChildren();
  for (const item of state.scope.criteria) {
    options.add(new Option(item.requirement.code + ' / ' + item.code + '　' + item.title + ' · v' + item.version, item.criterion_id));
  }
  const missing = state.scope.uncovered_requirements;
  $('scope').replaceChildren(...missing.map(item => textNode('p', item.code + '　待添加验收标准')),
    ...state.scope.criteria.map(item => showCriterion(item, true)));
  if (!missing.length && !state.scope.criteria.length) $('scope').append(textNode('p', '请先添加需求和验收标准。'));
  await readCaseCriteria();
}

function resetProposal() {
  $('propose-change').reset();
  $('proposal-baseline').textContent = '请先打开要修订的基线。';
  $('proposal-criteria').replaceChildren();
  for (const id of ['add-proposal-criterion', 'proposal-reason']) $(id).hidden = true;
  $('submit-change').disabled = true;
}

function addProposalCriterion() {
  const wrapper = $('proposal-criteria');
  if (wrapper.children.length >= Math.min(30, state.baseline.criteria.length)) return;
  const fieldset = document.createElement('fieldset');
  const label = textNode('label', '验收标准');
  const select = document.createElement('select');
  select.name = 'criterion_id';
  select.required = true;
  select.add(new Option('请选择验收标准', ''));
  for (const item of state.baseline.criteria) {
    select.add(new Option(item.requirement.code + ' / ' + item.code + ' · v' + item.version, item.criterion_id));
  }
  label.append(select);
  const titleLabel = textNode('label', '变更后名称');
  const title = document.createElement('input');
  title.name = 'title';
  title.required = true;
  titleLabel.append(title);
  const statementLabel = textNode('label', '变更后验收条件');
  const statement = document.createElement('textarea');
  statement.name = 'statement';
  statement.required = true;
  statementLabel.append(statement);
  select.addEventListener('change', () => {
    const current = state.baseline.criteria.find(item => item.criterion_id === Number(select.value));
    title.value = current?.title || '';
    statement.value = current?.statement || '';
  });
  fieldset.append(textNode('legend', '修订项'), label, titleLabel, statementLabel,
    actionButton('移除此修订项', () => {
      fieldset.remove();
      if (!wrapper.children.length) addProposalCriterion();
    }));
  wrapper.append(fieldset);
}

async function readPage(path, target, render, after = 0) {
  const data = await call(projectPath(path) + '?after=' + after);
  const area = $(target);
  if (!after) area.replaceChildren();
  for (const item of data.items) area.append(render(item));
  if (!after && !data.items.length) area.append(textNode('p', '暂无记录。'));
  if (data.next_after !== null) {
    const more = actionButton('加载更多', async () => {
      await readPage(path, target, render, data.next_after);
      more.remove();
    });
    area.append(more);
  }
}

const changeStatus = {pending: '待评审', approved: '已批准', rejected: '已驳回'};
const linkLabels = {work_items: '研发任务', test_cases: '测试用例', signoff_items: '签收记录'};

function readChanges() {
  return readPage('/changes', 'changes', item => actionButton(
    '#' + item.id + '　' + changeStatus[item.status] + '　' + item.reason.slice(0, 80), () => viewChange(item.id)));
}

async function viewObjectTimeline(path, target, after = 0) {
  const data = await call(projectPath(path) + '?after=' + after);
  for (const row of data.items) {
    target.append(textNode('li', row.occurred_at + ' UTC　' + (eventTitles[row.action] || row.action) + '　成员 #' + row.actor_id));
  }
  if (data.next_after !== null) {
    const more = document.createElement('li');
    more.append(actionButton('更多对象事件', async () => {
      await viewObjectTimeline(path, target, data.next_after);
      more.remove();
    }));
    target.append(more);
  }
}

function timelineControl(path) {
  const details = document.createElement('details');
  const list = document.createElement('ol');
  details.append(textNode('summary', '对象时间线'), list);
  details.addEventListener('toggle', () => {
    if (details.open && !list.children.length) run(() => viewObjectTimeline(path, list));
  });
  return details;
}

async function viewChange(id) {
  const change = await call(projectPath('/changes/') + id);
  state.change = change;
  const target = $('change-detail');
  target.replaceChildren(textNode('h3', '变更 #' + id + '　' + changeStatus[change.status]),
    textNode('p', '基线 ID ' + change.baseline_id + ' · 发起人 #' + change.proposed_by),
    textNode('p', change.reason));
  if (change.reviewed_by !== null) {
    target.append(textNode('p', '评审人 #' + change.reviewed_by + '　' + change.decision_note));
  }
  for (const [index, item] of change.criteria.entries()) {
    const impact = change.impact[index];
    const article = document.createElement('article');
    article.append(textNode('h3', item.title + '　标准 #' + item.criterion_id),
      textNode('p', '原条件 · ' + item.previous_title + '\n' + item.previous_statement),
      textNode('p', '新条件 · ' + item.title + '\n' + item.statement));
    for (const [field, label] of Object.entries(linkLabels)) {
      const linked = impact[field].map(record => record.code + ' ' + record.title).join('、');
      article.append(textNode('p', label + ' · ' + (linked || '缺少关联')));
    }
    target.append(article);
  }
  target.append(timelineControl('/changes/' + id + '/timeline'));
  $('review-change').reset();
  $('review-change').hidden = change.status !== 'pending' || state.actor.account_id !== state.project.business_signer_id;
}

function readSprints() {
  return readPage('/sprints', 'sprints', item => actionButton('#' + item.id + '　' + item.team + ' / ' + item.title
    + '　' + item.used + ' / ' + item.capacity + ' 点', () => viewSprint(item.id)));
}

async function viewSprint(id, after = 0) {
  const sprint = await call(projectPath('/sprints/') + id + '?after=' + after);
  state.sprint = sprint;
  const target = $('sprint-detail');
  let taskList = target.querySelector('ul');
  if (!after) {
    taskList = document.createElement('ul');
    target.replaceChildren(textNode('h3', '#' + id + '　' + sprint.title),
      textNode('p', sprint.team + '　' + sprint.starts_on + ' 至 ' + sprint.ends_on + '　' + sprint.used + ' / ' + sprint.capacity + ' 点'),
      taskList);
    if (!sprint.items.length) target.append(textNode('p', '本迭代暂无任务。'));
  }
  for (const item of sprint.items) {
    const row = document.createElement('li');
    row.append(actionButton(item.code + '　' + item.effort + ' 点', () => viewWork(item.id)));
    taskList.append(row);
  }
  if (sprint.next_after !== null) {
    const more = actionButton('更多迭代任务', async () => {
      await viewSprint(id, sprint.next_after);
      more.remove();
    });
    target.append(more);
  }
}

function readWork() {
  return readPage('/work-items', 'work-items', item => actionButton(
    '#' + item.id + '　' + item.code + (item.review_change_id ? '　待重新确认' : ''), () => viewWork(item.id)));
}

function workPath(id) {
  return projectPath('/work-items/') + id;
}

async function viewWork(id) {
  const work = await call(workPath(id));
  state.work = work;
  const target = $('work-detail');
  target.replaceChildren(textNode('h3', '#' + id + '　' + work.title), textNode('p', work.team + ' · 责任人 #' + work.owner_id
    + ' · ' + work.effort + ' 点 · ' + (work.sprint_id ? '迭代 #' + work.sprint_id : '待排期')));
  for (const alert of conflictText(work)) {
    const line = textNode('p', alert);
    line.className = 'alert';
    target.append(line);
  }
  if (work.review_change_id) {
    target.append(actionButton('查看待确认变更 #' + work.review_change_id, () => viewChange(work.review_change_id)));
  }
  const mayClaim = state.actor.roles.includes('engineer') && state.actor.account_id === work.owner_id;
  if (work.claimed_by) {
    target.append(textNode('p', '已认领 · 成员 #' + work.claimed_by + ' · ' + work.claimed_at + ' UTC'));
  } else if (mayClaim) {
    target.append(actionButton('认领任务', async () => {
      await call(workPath(id) + '/claim', 'POST');
      await viewWork(id);
      showMessage('任务已认领。');
    }));
  }
  const dependencies = document.createElement('ul');
  for (const edge of work.dependencies) {
    const row = textNode('li', '前置任务 #' + edge.predecessor_id + ' ' + edge.team + ' / ' + edge.code
      + '　责任人 #' + edge.owner_id + '　交接 ' + edge.due_on + '　');
    if (canPlan()) {
      row.append(actionButton('删除依赖', async () => {
        await call(workPath(id) + '/dependencies/' + edge.predecessor_id, 'DELETE');
        await viewWork(id);
        await readTimeline();
      }));
    }
    dependencies.append(row);
  }
  target.append(dependencies, timelineControl('/work-items/' + id + '/timeline'));
  $('work-actions').hidden = !canPlan();
  $('schedule-work').elements.sprint_id.value = work.sprint_id ?? '';
  $('save-dependency').reset();
  $('link-git').reset();
  $('link-git').hidden = !mayClaim || !work.claimed_by;
  await readWorkLinks(id);
}

function readWorkLinks(id) {
  return readPage('/work-items/' + id + '/git-links', 'git-links', link => actionButton(
    link.repository_name + ' / ' + link.branch + '　' + link.commit_sha.slice(0, 12),
    async () => {
      await viewRepository(link.repository_id);
      await viewSnapshot(link.repository_id, link.snapshot_id);
      $('snapshot-detail').scrollIntoView({block: 'start'});
    }));
}

const buildStatus = {queued: '排队中', running: '执行中', passed: '通过', failed: '失败', cancelled: '已取消'};
const mergeStatus = {open: '未合并', merged: '已合并', closed: '已关闭'};

function readRepositories() {
  return readPage('/repositories', 'repositories', item => actionButton('#' + item.id + '　' + item.name + ' · ' + item.provider,
    () => viewRepository(item.id)));
}

async function viewRepository(id, after = 0) {
  const repository = await call(projectPath('/repositories/') + id + '?after=' + after);
  state.repository = repository;
  const target = $('repository-detail');
  if (!after) {
    target.replaceChildren(textNode('h3', '仓库 #' + id + '　' + repository.name),
      textNode('p', repository.provider + ' / ' + repository.provider_repository_id));
    target.append(actionButton('查看回执记录', () => readRepositoryEvents(id)));
    if (canPlan()) target.append(actionButton('读取连接器配置', async () => {
      const secret = await call(projectPath('/repositories/') + id + '/connector', 'POST');
      const configuration = document.createElement('article');
      configuration.append(textNode('h3', '连接器配置'), textNode('p', '端点 ' + secret.path),
        textNode('p', '签名密钥，仅用于连接器配置'), textNode('code', secret.signing_secret));
      target.append(configuration);
    }));
    $('snapshot-detail').replaceChildren();
    $('repository-events').replaceChildren();
    $('import-git').reset();
    $('import-editor').hidden = !canPlan();
  }
  for (const snapshot of repository.items) {
    const row = document.createElement('article');
    row.append(textNode('h3', snapshot.branch),
      textNode('code', snapshot.commit_sha),
      textNode('p', '快照 #' + snapshot.id + ' · 第 ' + snapshot.generation + ' 次推进 · '
        + (buildStatus[snapshot.build_status] || '等待构建回执') + (snapshot.forced ? ' · 强制更新' : '')),
      actionButton('查看快照 #' + snapshot.id, () => viewSnapshot(id, snapshot.id)));
    target.append(row);
  }
  if (repository.next_after !== null) {
    const more = actionButton('更多分支', async () => {
      await viewRepository(id, repository.next_after);
      more.remove();
    });
    target.append(more);
  }
}

async function viewSnapshot(repo, id) {
  const snapshot = await call(projectPath('/repositories/') + repo + '/snapshots/' + id);
  const target = $('snapshot-detail');
  const article = document.createElement('article');
  article.append(textNode('h3', '快照 #' + id + '　' + snapshot.branch + (snapshot.current ? ' · 当前' : ' · 历史')),
    textNode('code', snapshot.commit_sha), textNode('p', snapshot.message));
  if (!snapshot.build_runs.length) article.append(textNode('p', '等待构建回执'));
  for (const build of snapshot.build_runs) {
    article.append(textNode('p', '构建 #' + build.id + ' · ' + build.provider_run_id + ' · ' + buildStatus[build.status]));
  }
  for (const merge of snapshot.merge_requests) {
    article.append(textNode('p', '合并请求 ' + merge.remote_id + ' · ' + mergeStatus[merge.state] + ' · ' + merge.title));
  }
  target.replaceChildren(article);
}

function readRepositoryEvents(id) {
  return readPage('/repositories/' + id + '/events', 'repository-events', row => {
    const details = document.createElement('details');
    details.append(textNode('summary', row.external_id + '　' + row.type + '　' + row.received_at + ' UTC'),
      textNode('p', row.imported_by ? '导入成员 #' + row.imported_by : '连接器签名验证通过'),
      textNode('code', row.payload_digest),
      textNode('p', '处理结果 ' + row.receipt.state));
    return details;
  });
}

function resetDeliveryViews() {
  state.baseline = state.change = state.work = state.sprint = state.repository = null;
  resetProposal();
  for (const id of ['changes', 'change-detail', 'sprints', 'sprint-detail', 'work-items', 'work-detail',
    'repositories', 'repository-detail', 'snapshot-detail', 'repository-events', 'git-links']) {
    $(id).replaceChildren();
  }
  for (const id of ['create-sprint', 'create-work', 'review-change', 'schedule-work', 'save-dependency',
    'register-repository', 'import-git', 'link-git']) $(id).reset();
  for (const id of ['review-change', 'work-actions', 'import-editor', 'link-git']) $(id).hidden = true;
  resetTesting();
}

function criterionInput(fields) {
  const criterion = state.scope.criteria.find(item => item.criterion_id === Number(fields.criterion_id));
  return {...fields, criterion_id: criterion.criterion_id, revision_id: criterion.revision_id};
}

async function viewProject(id) {
  $('project-detail').hidden = true;
  state.project = await call('/api/projects/' + id);
  resetDeliveryViews();
  $('project-title').textContent = state.project.title;
  $('project-charter').textContent = '章程 v' + state.project.version + '　' + state.project.charter;
  $('edit-project').elements.title.value = state.project.title;
  $('edit-project').elements.charter.value = state.project.charter;
  $('baseline-detail').replaceChildren();
  const editable = canPlan();
  for (const id of ['planning', 'change-editor', 'sprint-editor', 'work-editor', 'repository-editor']) $(id).hidden = !editable;
  await Promise.all([
    ...(editable ? [readScope()] : []), readBaselines(), readTimeline(),
    readChanges(), readSprints(), readWork(), readRepositories(), readTesting()
  ]);
  $('project-detail').hidden = false;
}

async function showSession() {
  for (const id of ['projects', 'account-list', 'project-title', 'project-charter', 'scope',
    'requirement-options', 'baselines', 'baseline-detail', 'timeline']) {
    $(id).replaceChildren();
  }
  for (const id of ['more-projects', 'more-accounts', 'more-baselines', 'more-events']) $(id).hidden = true;
  for (const id of ['create-account', 'create-project', 'edit-project', 'project-member',
    'create-requirement', 'create-criterion', 'freeze']) {
    $(id).reset();
  }
  resetDeliveryViews();
  $('workspace').hidden = !state.actor;
  $('authentication').hidden = Boolean(state.actor);
  $('logout').hidden = !state.actor;
  $('identity').textContent = state.actor?.name || '';
  $('project-detail').hidden = true;
  state.project = null;
  state.scope = null;
  if (!state.actor) return;
  const admin = state.actor.roles.includes('admin');
  $('accounts').hidden = !admin;
  $('new-project').hidden = !admin && !state.actor.roles.includes('product');
  $('create-project').elements.product_owner_id.value = admin ? '' : state.actor.account_id;
  await readProjects();
  if (admin) await readAccounts();
}

form('login', async fields => {
  state.actor = (await call('/api/login', 'POST', fields)).actor;
  $('login').reset();
  await showSession();
});
form('create-account', async fields => {
  await call('/api/accounts', 'POST', {name: fields.name, email: fields.email, password: fields.password, roles: [fields.role]});
  $('create-account').reset();
  await readAccounts();
  showMessage('成员已创建。');
});
form('create-project', async fields => {
  const result = await call('/api/projects', 'POST', {...fields,
    product_owner_id: Number(fields.product_owner_id), business_signer_id: Number(fields.business_signer_id)});
  $('create-project').reset();
  if (!state.actor.roles.includes('admin')) $('create-project').elements.product_owner_id.value = state.actor.account_id;
  $('new-project').open = false;
  await readProjects();
  await viewProject(result.id);
});
form('edit-project', async fields => {
  await call(projectPath(), 'PUT', {...fields, version: state.project.version});
  await readProjects();
  await viewProject(state.project.id);
  showMessage('章程已保存为新版本。');
});
form('project-member', async fields => {
  await call(projectPath('/members/') + fields.member_id, 'PUT', {enabled: fields.enabled === 'true'});
  await readTimeline();
  showMessage('项目成员已更新。');
});
form('create-requirement', async fields => {
  await call(projectPath('/requirements'), 'POST', fields);
  $('create-requirement').reset();
  await readScope();
  await readTimeline();
  showMessage('需求已添加。');
});
form('create-criterion', async fields => {
  const {requirement, ...criterion} = fields;
  await call(projectPath('/requirements/') + requirement + '/criteria', 'POST', criterion);
  $('create-criterion').reset();
  await readScope();
  await readTimeline();
  showMessage('验收标准已添加。');
});
form('freeze', async fields => {
  const baseline = await call(projectPath('/baselines'), 'POST', {...fields, scope_digest: state.scope.scope_digest});
  $('freeze').reset();
  await readBaselines();
  await viewBaseline(baseline.id);
  await readTimeline();
  showMessage('基线已冻结。');
});
$('add-proposal-criterion').addEventListener('click', () => addProposalCriterion());
form('propose-change', async fields => {
  const criteria = [...$('proposal-criteria').querySelectorAll('fieldset')].map(row => ({
    criterion_id: Number(row.querySelector('[name="criterion_id"]').value),
    title: row.querySelector('[name="title"]').value,
    statement: row.querySelector('[name="statement"]').value
  }));
  const change = await call(projectPath('/changes'), 'POST', {baseline_id: state.baseline.id, reason: fields.reason, criteria});
  $('change-editor').open = false;
  await viewBaseline(state.baseline.id);
  await Promise.all([readChanges(), viewChange(change.id), readTimeline()]);
  showMessage('变更已提交，等待业务签收人评审。');
});
form('review-change', async fields => {
  const change = await call(projectPath('/changes/') + state.change.id + '/decision', 'POST', fields);
  await Promise.all([readChanges(), viewChange(change.id), readWork(), readCases(), readTimeline()]);
  showMessage('评审结果已记录。');
});
form('create-sprint', async fields => {
  const sprint = await call(projectPath('/sprints'), 'POST', {...fields, capacity: Number(fields.capacity)});
  $('create-sprint').reset();
  await Promise.all([readSprints(), viewSprint(sprint.id), readTimeline()]);
});
form('create-work', async fields => {
  const work = await call(projectPath('/work-items'), 'POST',
    {...criterionInput(fields), owner_id: Number(fields.owner_id), effort: Number(fields.effort)});
  $('create-work').reset();
  await Promise.all([readWork(), viewWork(work.id), readTimeline()]);
});
form('schedule-work', async fields => {
  const work = await call(workPath(state.work.id) + '/schedule', 'PUT', {sprint_id: fields.sprint_id ? Number(fields.sprint_id) : null});
  await Promise.all([readSprints(), viewWork(work.id), readTimeline(), ...(state.sprint ? [viewSprint(state.sprint.id)] : [])]);
  showMessage('任务排期已保存。');
});
form('save-dependency', async fields => {
  const work = await call(workPath(state.work.id) + '/dependencies/' + fields.predecessor_id, 'PUT',
    {owner_id: Number(fields.owner_id), due_on: fields.due_on});
  await Promise.all([viewWork(work.id), readTimeline()]);
});
form('register-repository', async fields => {
  const repository = await call(projectPath('/repositories'), 'POST', fields);
  $('register-repository').reset();
  await Promise.all([readRepositories(), viewRepository(repository.id), readTimeline()]);
});
form('import-git', async fields => {
  let event;
  try {
    event = JSON.parse(fields.event);
  } catch {
    throw new Error('请输入完整的 JSON 事件。');
  }
  const repo = state.repository.id;
  const receipt = await call(projectPath('/repositories/') + repo + '/events', 'POST', event);
  await viewRepository(repo);
  const snapshotId = receipt.snapshot?.id || receipt.snapshot_id;
  if (snapshotId) await viewSnapshot(repo, snapshotId);
  showMessage('回执 ' + receipt.event_id + ' 已记录。');
});
form('link-git', async fields => {
  await call(workPath(state.work.id) + '/git-links', 'POST',
    {repository_id: Number(fields.repository_id), snapshot_id: Number(fields.snapshot_id)});
  await readWorkLinks(state.work.id);
  $('link-git').reset();
  showMessage('提交快照已关联到任务。');
});
$('more-projects').addEventListener('click', () => run(() => readProjects(true)));
$('more-accounts').addEventListener('click', () => run(() => readAccounts(true)));
$('more-baselines').addEventListener('click', () => run(() => readBaselines(true)));
$('more-events').addEventListener('click', () => run(() => readTimeline(true)));
$('logout').addEventListener('click', () => run(async () => {
  await call('/api/logout', 'POST');
  state.actor = null;
  await showSession();
}));
document.addEventListener('DOMContentLoaded', () => run(async () => {
  state.actor = (await call('/api/session')).actor;
  await showSession();
}), { once: true });
