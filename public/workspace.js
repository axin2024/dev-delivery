'use strict';

const $ = id => document.getElementById(id);
const state = {actor: null, csrf: null, project: null, scope: null, projectsAfter: null, accountsAfter: null,
  baselinesAfter: null, eventsAfter: null};
const eventTitles = {
  created: '创建项目', charter_revised: '修订项目章程', member_added: '加入项目成员', member_removed: '移出项目成员',
  requirement_added: '新增需求', criterion_added: '新增验收标准', criterion_revised: '修订验收标准',
  baseline_frozen: '冻结基线'
};
const errors = {
  invalid_credentials: '租户、邮箱或密码有误。',
  baseline_scope_changed: '范围已更新，请重新打开项目核对。',
  baseline_scope_already_frozen: '这份范围已冻结，请查看已冻结基线。',
  requirements_without_criteria: '请先给每条需求添加验收标准。',
  baseline_requires_criteria: '请先添加验收标准。',
  criterion_change_review_required: '此条款已冻结，后续修订须走变更评审。',
  project_version_conflict: '章程版本已变动，请重新打开项目核对。',
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
  const response = await fetch(path, options);
  const body = await response.json();
  if (body.csrf_token) state.csrf = body.csrf_token;
  if (!response.ok) {
    const detail = body.details?.field ? ' ' + body.details.field : '';
    throw new Error((errors[body.error] || body.error || '请求失败') + detail);
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
  showMessage('');
  try {
    await action();
  } catch (error) {
    showMessage(error.message, true);
  } finally {
    busy = false;
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
    const button = element.querySelector('button');
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
  $('baseline-detail').replaceChildren(textNode('h3', 'B' + baseline.sequence + '　' + baseline.title),
    textNode('p', '章程 v' + baseline.charter_version + '　' + baseline.charter_snapshot.charter),
    textNode('code', baseline.manifest_digest),
    ...baseline.criteria.map(item => showCriterion(item, false)));
}

async function readScope() {
  state.scope = await call(projectPath('/scope'));
  const requirements = new Map();
  for (const item of state.scope.uncovered_requirements) requirements.set(item.id, item);
  for (const item of state.scope.criteria) requirements.set(item.requirement.id, item.requirement);
  $('requirement-options').replaceChildren();
  for (const item of requirements.values()) $('requirement-options').add(new Option(item.code + '　' + item.title, item.id));
  const missing = state.scope.uncovered_requirements;
  $('scope').replaceChildren(...missing.map(item => textNode('p', item.code + '　待添加验收标准')),
    ...state.scope.criteria.map(item => showCriterion(item, true)));
  if (!missing.length && !state.scope.criteria.length) $('scope').append(textNode('p', '请先添加需求和验收标准。'));
}

async function viewProject(id) {
  state.project = await call('/api/projects/' + id);
  $('project-detail').hidden = false;
  $('project-title').textContent = state.project.title;
  $('project-charter').textContent = '章程 v' + state.project.version + '　' + state.project.charter;
  $('edit-project').elements.title.value = state.project.title;
  $('edit-project').elements.charter.value = state.project.charter;
  $('baseline-detail').replaceChildren();
  const canPlan = state.actor.roles.includes('admin') || state.actor.account_id === state.project.product_owner_id;
  $('planning').hidden = !canPlan;
  if (canPlan) await readScope();
  await readBaselines();
  await readTimeline();
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
$('more-projects').addEventListener('click', () => run(() => readProjects(true)));
$('more-accounts').addEventListener('click', () => run(() => readAccounts(true)));
$('more-baselines').addEventListener('click', () => run(() => readBaselines(true)));
$('more-events').addEventListener('click', () => run(() => readTimeline(true)));
$('logout').addEventListener('click', () => run(async () => {
  await call('/api/logout', 'POST');
  state.actor = null;
  await showSession();
}));
run(async () => {
  state.actor = (await call('/api/session')).actor;
  await showSession();
});
